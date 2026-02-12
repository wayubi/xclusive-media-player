#!/usr/bin/env bash
shopt -s nullglob

mkdir -p .trash
log="flv_to_mp4_errors.log"

files=( *.flv )
(( ${#files[@]} == 0 )) && {
  echo "No FLV files found. Nothing to do."
  exit 0
}

for f in *.flv; do
  base="${f%.flv}"
  mp4="${base}.mp4"

  # Avoid name collisions
  if [[ -f "$mp4" ]]; then
    i=1
    while [[ -f "${base}.${i}.mp4" ]]; do
      ((i++))
    done
    mp4="${base}.${i}.mp4"
  fi

  # Detect codecs
  vcodec=$(ffprobe -v error -select_streams v:0 \
    -show_entries stream=codec_name -of csv=p=0 "$f")

  acodec=$(ffprobe -v error -select_streams a:0 \
    -show_entries stream=codec_name -of csv=p=0 "$f")

  # Try remux if already MP4-compatible
  if [[ "$vcodec" == "h264" && "$acodec" == "aac" ]]; then
    if ffmpeg -nostdin -i "$f" -c copy "$mp4"; then
      touch -r "$f" "$mp4"
      mv "$f" .trash/
      echo "Remuxed: $f"
      continue
    fi
  fi

  # Read bitrates (fallback safe defaults)
  vbit=$(ffprobe -v error -select_streams v:0 \
    -show_entries stream=bit_rate -of csv=p=0 "$f")
  abit=$(ffprobe -v error -select_streams a:0 \
    -show_entries stream=bit_rate -of csv=p=0 "$f")

  # Sanitize missing / insane values
  [[ -z "$vbit" || "$vbit" -lt 100000 ]] && vbit=400000
  [[ -z "$abit" || "$abit" -lt 32000 ]]  && abit=64000

  # Scale targets
  vtarget=$(( vbit * 100 / 100 ))   # 100% of source video bitrate
  atarget=$(( abit * 90 / 100 ))    # 90% for AAC efficiency

  # Clamp ranges (avoid bloat)
  (( vtarget > 2000000 )) && vtarget=2000000
  (( atarget > 160000 ))  && atarget=160000

  echo "Re-encoding: $f (v=${vtarget} a=${atarget})"

  if ffmpeg -nostdin -i "$f" \
      -c:v libx264 -preset slow \
      -b:v "${vtarget}" -maxrate "$((vtarget * 11 / 10))" -bufsize "$((vtarget * 2))" \
      -pix_fmt yuv420p \
      -c:a aac -b:a "${atarget}" \
      "$mp4"; then

    touch -r "$f" "$mp4"
    mv "$f" .trash/
  else
    echo "FAILED: $f" >> "$log"
  fi
done
