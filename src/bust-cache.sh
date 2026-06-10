#!/bin/bash
# bust-cache.sh - Add per-file mtime cache busters to CSS @import and JS import statements.
# Run after editing any CSS or JS file. Idempotent — safe to run repeatedly.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
CSS_DIR="$SCRIPT_DIR/assets/css"
JS_DIR="$SCRIPT_DIR/assets/js"

# Phase 1 — snapshot all mtimes before any file is touched (prevents feedback loop)
declare -A css_mtimes
declare -A js_mtimes

for f in "$CSS_DIR"/*.css; do
    [ -f "$f" ] || continue
    css_mtimes[$(basename "$f")]=$(stat -c %Y "$f")
done

for f in "$JS_DIR"/*.js; do
    [ -f "$f" ] || continue
    js_mtimes[$(basename "$f")]=$(stat -c %Y "$f")
done

# ---- CSS: app.css ----
echo "=== CSS: app.css ==="

CSS_FILE="$CSS_DIR/app.css"

# Strip all existing ?v=NUMBERS tokens
sed -i "s|?v=[0-9]*||g" "$CSS_FILE"

# Add per-file mtime cache busters to each @import
grep -oP "@import url\('\./[^']+\.css'\)" "$CSS_FILE" | sort -u | while read -r import_line; do
    filename=$(echo "$import_line" | grep -oP "'\./\K[^']+")
    mtime="${css_mtimes[$filename]:-0}"
    if [ "$mtime" != "0" ]; then
        sed -i "s|@import url('./$filename');|@import url('./$filename?v=$mtime');|" "$CSS_FILE"
        echo "  $filename -> v=$mtime"
    else
        echo "  WARNING: $filename not found, skipping" >&2
    fi
done

# ---- JS files ----
echo ""
echo "=== JS files ==="

for JS_FILE in "$JS_DIR"/*.js; do
    basename=$(basename "$JS_FILE")

    # Strip all existing ?v=NUMBERS tokens
    sed -i "s|?v=[0-9]*||g" "$JS_FILE"

    # Collect unique import targets
    imports=$(grep -oP "(?:from |import\()'\./[^']+\.js'" "$JS_FILE" 2>/dev/null | sort -u || true)

    if [ -n "$imports" ]; then
        while read -r import_ref; do
            [ -z "$import_ref" ] && continue
            filename=$(echo "$import_ref" | grep -oP "'\./\K[^']+")
            mtime="${js_mtimes[$filename]:-0}"
            if [ "$mtime" != "0" ]; then
                sed -i "s|from './$filename'|from './$filename?v=$mtime'|g" "$JS_FILE"
                sed -i "s|import('./$filename')|import('./$filename?v=$mtime')|g" "$JS_FILE"
                echo "  $basename -> ./$filename?v=$mtime"
            else
                echo "  WARNING: $basename imports ./$filename (not found)" >&2
            fi
        done <<< "$imports"
    fi
done

# Phase 3 — restore original mtimes so the script itself doesn't invalidate caches
for f in "$CSS_DIR"/*.css; do
    [ -f "$f" ] || continue
    bn=$(basename "$f")
    orig="${css_mtimes[$bn]:-0}"
    [ "$orig" != "0" ] && touch -d "@$orig" "$f"
done

for f in "$JS_DIR"/*.js; do
    [ -f "$f" ] || continue
    bn=$(basename "$f")
    orig="${js_mtimes[$bn]:-0}"
    [ "$orig" != "0" ] && touch -d "@$orig" "$f"
done

echo ""
echo "Done."
