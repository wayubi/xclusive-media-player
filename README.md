# Xclusive Media Player

**Xclusive Media Player** is a high-performance, browser-based media management and playback system designed for large media libraries. Built with modern web technologies and optimized for concurrent video streaming, it features a responsive grid interface, advanced lazy loading, connection pooling, and enterprise-grade media handling capabilities.

Perfect for media professionals, content curators, and anyone managing large collections of videos, audio, and images.

---

## ✨ Features

### Media Management
- **Multi-format support**: MP3, WAV, OGG, FLAC, MP4, WebM, MKV, MOV, AVI, and common image formats (JPG, PNG, GIF, WebP, SVG)
- **Smart codec detection**: Automatically detects unsupported codecs (WMV3, FLV1, MPEG4, etc.) and FLV container formats
- **Unsupported video handling**: Videos with unsupported codecs or FLV containers are shown with visual indicators and helpful error messages
- **Smart folder navigation**: Browse nested directories with breadcrumb-style path indicators
- **Rich metadata display**: View codec, resolution (width×height), duration, bitrate, file size, FPS, and folder path
- **Advanced search**: Filter by filename or folder path with instant results
- **Batch operations**: Audit or delete multiple files with confirmation dialogs

### Playback & Display
- **Customizable grid interface**: 1×1 to 6×6 layouts (up to 36 concurrent video cells)
- **Lazy loading with IntersectionObserver**: Videos only load when visible, conserving bandwidth
- **Priority loading**: Recent fullscreen video and first grid cell load first
- **Media element pooling**: Reusable video/audio elements prevent DOM thrashing and connection exhaustion
- **Concurrent loading queues**: Rate-limited to 3-6 simultaneous loads (prevents browser overload)
- **Fullscreen player**: Immersive playback with keyboard navigation (arrows, Escape, Delete)
- **Playlist modes**: Sequential play all or shuffle playback
- **Auto-generated covers**: Audio files display embedded artwork with fallback placeholders
- **Object-fit toggle**: Press 'C' to switch between cover and contain display modes
- **Mute management**: Single unmuted video policy (prevents audio chaos)
- **Mouse hover unmuting**: Move mouse over videos to unmute them automatically (when not globally muted)
- **CTRL+SCROLL seek**: Hold CTRL and scroll to seek video/audio forward/backward by 5 seconds
- **Boss screen**: Press 'B' for cyberpunk terminal interface with interactive commands

### Audit System
- **SQLite-based tracking**: Persistent audit status with optimistic UI updates
- **Context-aware auditing**: Prevents race conditions when navigating during audits
- **Visual indicators**:
  - Yellow border = Unaudited (new files)
  - No border = Audited (reviewed files)
  - Heart icon = Favorited files
- **Smart batch auditing**: Single-click audits visible files, double-click audits entire folder
- **Real-time counters**: Live unaudited/favorites counts in header
- **Conflict resolution**: Handles overlapping audits gracefully

### Favorites System
- **Database-backed favorites**: Persistent ❤️ marking across sessions
- **One-click filtering**: View only favorited items
- **Favorites playlist**: Play all favorites sequentially
- **Cross-folder favorites**: Favorites persist across folder navigation

### Security & File Management
- **Delete protection**: Secret code-based authorization with cookie persistence (30 days)
- **Permanent deletion**: Files and folders are deleted immediately (no trash/recycle bin)
- **Recursive deletion**: Can delete entire folders including all subfolders and hidden files
- **Dual validation**: Frontend and backend verification for all destructive operations
- **Secure defaults**: Delete functionality disabled by default
- **Audit trails**: All operations logged for accountability

### Performance Optimizations
- **Media element pooling**: 48 reusable video/audio elements reduce DOM overhead
- **Lazy loading**: Videos load only when entering viewport (200px offset)
- **Connection management**: Rate limiting prevents Firefox connection exhaustion
- **Range request support**: Efficient seeking/scrubbing with partial content loading
- **File descriptor caching**: Nginx open_file_cache for frequently accessed videos
- **HTTP/2 multiplexing**: Single connection streams multiple videos simultaneously
- **Hybrid caching**: Small files cached in RAM, large files streamed directly
- **Metadata caching**: ffprobe results cached in `/tmp/.metadata/` for fast reloading
- **Smart codec detection**: Container format detection (FLV, MP4, etc.) prevents failed playback attempts

---

## 🏗️ Architecture Overview

### Frontend Architecture

```
Browser
├── State Management (centralized)
│   ├── Video/Audio arrays
│   ├── Audit status map
│   ├── Favorites map
│   └── UI state (grid position, filters)
│
├── Media Pool (48 elements)
│   ├── Video pool (pre-created <video> elements)
│   └── Audio pool (pre-created <audio> elements)
│
├── Media Queue (concurrent loading)
│   ├── Audio queue (max 6 concurrent)
│   ├── Video queue (max 6 concurrent, 3 for large grids)
│   └── Completion tracking (canplay/loadedmetadata/error/timeout)
│
├── Lazy Loading
│   ├── IntersectionObserver (200px viewport margin)
│   ├── Priority: Recent fullscreen → First cell → Others
│   └── Staged: Metadata → Thumbnail → Playback
│
└── Grid Rendering (6 phases)
    ├── Cleanup (recycle elements)
    ├── Structure (CSS grid setup)
    ├── Populate (create containers)
    ├── Metadata (fetch file info)
    ├── Loading (start queues)
    └── Finalize (sync UI state)
```

### Backend Architecture

```
Docker Compose Stack
├── nginx (Port 8050)
│   ├── Static file serving (CSS, JS, images)
│   ├── Video streaming with range requests
│   ├── Throttling (50MB/s after 10MB burst)
│   └── Connection limits (200 per IP)
│
├── php-fpm
│   ├── Page generation (index.php)
│   ├── Folder traversal
│   ├── Audio thumbnail extraction
│   └── Authentication
│
├── php-cli (API)
│   ├── Metadata extraction (ffprobe)
│   ├── SQLite operations
│   ├── File operations (delete)
│   └── Audit/favorites management
│
└── Reverse Proxy (Optional)
    ├── SSL termination
    ├── HTTP/2 multiplexing
    ├── Hybrid caching (<100MB cached, >100MB streamed)
    └── 32GB RAM cache (tmpfs)
```

### Database Schema

**Audit Database (`data/db/audit.db`)**
```sql
CREATE TABLE audit_status (
    file_path TEXT PRIMARY KEY,
    audited BOOLEAN DEFAULT 0,
    audit_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

**Favorites Database (`data/db/favorites.db`)**
```sql
CREATE TABLE favorites (
    file_path TEXT PRIMARY KEY,
    folder_path TEXT,
    favorited BOOLEAN DEFAULT 0,
    favorite_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

---

## 🚀 Installation

### Prerequisites
- Docker 20.10+ and Docker Compose 2.0+
- 4GB+ RAM (8GB+ recommended for large libraries)
- Media files in supported formats

### Quick Start

1. **Clone the repository**:
   ```bash
   git clone https://github.com/wayubi/xclusive-media-player.git
   cd xclusive-media-player
   ```

2. **Configure delete protection** (optional but recommended):
   ```bash
   cat > .env << EOF
   DELETE_SECRET_CODE=your_secret_code_here
   EOF
   ```

3. **Start the application**:
   ```bash
   docker-compose up -d
   ```

4. **Access in your browser**:
   ```
   http://localhost:8050
   ```

5. **Add your media**:
   - Place media files in `./volumes` folder
   - Or update the volume mount in `compose.yml`:
   ```yaml
   volumes:
     - /path/to/your/media:/var/www/html/volumes
   ```

### Production Deployment with Reverse Proxy

For external access with SSL:

1. **Copy optimized reverse proxy config**:
   ```bash
   # On your reverse proxy server
   cp docker/nginx/reverse-proxy-optimized.conf /etc/nginx/sites-available/xclusive.conf
   ln -sf /etc/nginx/sites-available/xclusive.conf /etc/nginx/sites-enabled/
   ```

2. **Configure cache zone** in reverse proxy nginx.conf:
   ```nginx
   proxy_cache_path /var/cache/nginx levels=1:2 keys_zone=media_cache:100m 
                    max_size=32g inactive=7d use_temp_path=off;
   ```

3. **Enable with tmpfs for 32GB RAM cache**:
   ```yaml
   # docker-compose.yml on reverse proxy
   tmpfs:
     - /var/cache/nginx:size=32g
   ```

4. **Reload nginx**:
   ```bash
   nginx -t && systemctl reload nginx
   ```

---

## ⚙️ Configuration

### URL Parameters

Control the interface via URL:

**Grid Layout**:
- `?columns=3` — Number of columns (1-6, desktop only)
- `?rows=2` — Number of rows (1-6, desktop only)
- Example: `?columns=3&rows=2` for 6-video grid

**Audio Settings**:
- `?muted=true` — Start with audio muted (default)
- `?muted=false` — Start with audio enabled

**Folder Navigation**:
- `?path[]=folder1&path[]=subfolder` — Navigate to nested folder
- `?goto=..` — Go to parent folder
- `?goto_folder=subfolder` — Navigate into specific subfolder

**Delete Protection**:
- `?delete=your_secret_code` — Enable delete functionality (30-day cookie)
- `?delete=off` — Disable delete functionality

**Cache Control**:
- `?t=timestamp` — Cache buster (auto-added on navigation)

**Complete Example**:
```
http://localhost:8050?columns=3&rows=2&muted=false&path[]=movies&path[]=action
```

### Performance Tuning

**For Large Libraries (10,000+ files)**:

1. **Increase PHP memory** in `docker/php-fpm/php.ini`:
   ```ini
   memory_limit = 512M
   max_execution_time = 300
   ```

2. **Optimize nginx worker processes** in `docker/nginx/nginx.conf`:
   ```nginx
   worker_processes auto;
   worker_connections 4096;
   ```

3. **Enable file caching** (already enabled):
   ```nginx
   open_file_cache max=500 inactive=30s;
   ```

**For Slow Storage (5400 RPM HDD)**:

The configuration already includes throttling to protect drives:
- 50MB/s sustained rate after 10MB burst
- File descriptor caching reduces seeks
- Hybrid reverse proxy caching keeps hot files in RAM

---

## 📖 Usage Guide

### Keyboard Shortcuts

| Key | Action | Context |
|-----|--------|---------|
| `/` | Open search overlay | Global |
| `Esc` | Close search / Exit fullscreen / Clear delete selections | Search/Fullscreen/Grid (delete mode) |
| `c` | Toggle object-fit (cover/contain) | Global |
| `b` | Open/close boss screen (cyberpunk terminal) | Global |
| `Delete` | Delete current file | Fullscreen (when enabled) |
| `CTRL` + `Scroll` | Seek video/audio ±5 seconds | Grid (on media items) |
| `←` / `→` | Navigate playlist | Fullscreen |
| `↑` / `↓` | Exit fullscreen | Fullscreen |
| `Enter` | Execute search | Search overlay |
| `Mouse Wheel` | Navigate grid pages | Grid |
| `Touch Swipe` | Navigate grid (mobile) | Grid (mobile) |

### Delete Hotkeys (Grid View)

When delete mode is enabled (`?delete=your_secret_code`), use these hotkeys for quick file deletion:

| Key | Action | Notes |
|-----|--------|-------|
| `1` - `9` | Select tile 1-9 for deletion | Press again to deselect |
| `0` | Select tile 10 for deletion | Only works if 10+ tiles visible |
| `Delete` (nothing selected) | Select ALL items | Selects all files in current folder |
| `Delete` (items selected) | Confirm deletion | Deletes selected files or entire folder |
| `Esc` | Clear selections | Removes all delete selections |

**How it works:**
- Number keys toggle the delete selection state for each tile (1-9, 0 for 10th)
- **Delete key is two-phase**:
  1. First press (nothing selected): Selects ALL items in current folder including subfolders
  2. Second press: Shows confirmation dialog and permanently deletes everything
- **Recursive deletion**: Deletes all files, hidden files, subfolders, and the folder itself
- Selected tiles show **three visual indicators**:
  - 🔴 **Red border** around the tile (replaces yellow NEW border)
  - 🔴 **Red "DELETE" badge** in top-right corner (replaces NEW badge)
  - 🔴 **Red pulsing 🗙 button** when you hover over the tile
- Keys for non-existent tiles are ignored (e.g., 7-0 on a 6-tile grid)
- **Grid navigation clears selections**: Pressing Next/Previous or scrolling clears all delete selections
- **ESC clears selections**: Press Escape anytime to cancel delete selections (not in fullscreen)
- After folder deletion, automatically navigates to parent folder
- Ideal for 5×2 (10 tile) grids for rapid curation workflows
- **Permanent deletion**: Files are deleted immediately (no trash/recycle bin)

### Fullscreen Player

**Entry Points**:
- Click ⛶ (fullscreen icon) on any media item
- Click directly on video/audio/image in grid
- Use `▶️` Play All button (plays ALL files in folder from beginning)
- Use `🔀` Shuffle Play button (shuffles ALL files in folder)
- Use `❤️` Play Favorites button (favorites only)

**Note**: Play All and Shuffle always play ALL videos in the current folder, regardless of active filters or grid pagination. Files with unsupported codecs or FLV containers are automatically skipped.

**Navigation**:
- **Arrow Left/Right**: Previous/next file in playlist
- **Arrow Up/Down or Escape**: Exit fullscreen
- **Mouse Wheel**: Navigate playlist
- **Double-click video**: Exit fullscreen
- **Click background**: Exit fullscreen

**Controls**:
- **Space**: Play/pause (when focused)
- **Delete key**: Delete current file (confirmation dialog, requires delete mode)

**Features**:
- Auto-resumes playback position when returning to grid
- Shows metadata overlay (filename, codec, resolution, duration)
- Works with videos, audio (shows cover art), and images
- **Android ExoPlayer support**: Native fullscreen on Android WebView via `window.AndroidPlayer` bridge

### Grid Interaction Features

**Mouse Hover Unmuting**:
- When videos are not globally muted, hovering over any video/audio item will automatically unmute it
- Previous video mutes automatically (only one unmuted at a time)
- Mute icons update in real-time on the central overlay
- Works seamlessly with existing mute management

**CTRL+SCROLL Seeking**:
- Hold `CTRL` and scroll up/down on any video/audio item to seek
- Seeks forward/backward by 5 seconds (configurable in `state.js`)
- Visual feedback shows seek direction and amount (⏩ +5s / ⏪ -5s)
- Works on both grid items and fullscreen videos
- Non-intrusive overlay positioned to avoid blocking controls

### Batch Operations

**Auditing**:
1. **Single-click** `📋` button: Audit only currently visible files
2. **Double-click** `📋` button (within 400ms): Audit ALL files in the entire folder
3. Yellow borders disappear when files are marked as audited
4. Real-time counters update in the header

**Favorites**:
1. Click `🤍` (empty heart) on any file to favorite it
2. Click `❤️` (filled heart) to unfavorite
3. Click the favorites count (`❤️ X`) in the header to filter view
4. Click the `❤️` play button in toolbar to play all favorites as a playlist

### Search & Filter

**Search**:
1. Press `/` or click search icon to open search overlay
2. Type filename or folder path (case-insensitive)
3. Press `Enter` to execute search
4. Results appear instantly, filtering the grid
5. Press `Esc` or click ✕ to clear and close

**Filters** (click counts in header):
- **Unaudited** (`⚠️ X`): Show only files not yet audited
- **Favorites** (`❤️ X`): Show only favorited files
- Only one filter can be active at a time

---

## 🔧 Troubleshooting

### "Blocked" in Firefox Network Tab

**Cause**: Firefox limits 6 connections per domain
**Solution**: 
- HTTP/2 enabled in reverse proxy (multiplexes over single connection)
- Or reduce grid size temporarily
- This is browser limitation, not application error

### Large Videos Not Loading

**Checklist**:
1. ✓ Nginx throttling not too aggressive (currently 50MB/s)
2. ✓ `preload="auto"` set before `src` (fixed in recent update)
3. ✓ Video files accessible: `ls -lh volumes/`
4. ✓ Check codec support: Files with "FLV" badge are FLV containers (not supported in browsers)

### FLV Files Show "FLV" Badge and Won't Play

**Cause**: FLV (Flash Video) container format is not supported in modern web browsers, even if the video codec (H264) is compatible

**Solution**:
- Convert FLV files to MP4 format using ffmpeg:
  ```bash
  ffmpeg -i input.flv -c copy output.mp4
  ```
- Or download and play locally in VLC media player
- MP4 files with `.flv` extension will play normally (they're actually MP4 containers)

**Technical Details**:
- The app detects true FLV containers via ffprobe's `format_name` field
- Files showing "FLV" badge have `format_name: "flv"` (true FLV containers)
- Files with `.flv` extension but `format_name: "mov,mp4..."` will play normally
4. ✓ Nginx error logs: `docker-compose logs nginx`

### Slow Grid Loading (5x2, 6x6)

**Optimizations applied**:
- Lazy loading (only visible videos load)
- 3 concurrent limit for large grids
- Media element pooling
- If still slow: Check disk I/O with `iostat -x 1`

### Audit/Favorites Not Persisting

**Check**:
1. `./data/db/` directory exists and is writable
2. SQLite files not corrupted: `sqlite3 data/db/audit.db ".tables"`
3. Disk not full: `df -h`

### Memory Issues

**Symptoms**: Container crashes with large libraries
**Solution**:
```bash
# Increase Docker memory limit
docker-compose down
docker-compose up -d --memory=2g
```

---

## 🏛️ Technical Deep Dive

### Media Loading Strategy

The application uses a sophisticated 3-tier loading system:

**Tier 1: Priority Load**
- Recent fullscreen video (if returning from player)
- First cell in grid (immediate visibility)

**Tier 2: Lazy Load**
- IntersectionObserver triggers when video enters viewport
- 200px margin for smooth scrolling
- `preload="auto"` ensures frames load, not just metadata

**Tier 3: Queue Processing**
- Rate-limited to prevent browser overload
- 6 concurrent for small grids, 3 for large grids
- Completion tracked via canplay/loadedmetadata/error/timeout

### Connection Management

**Problem**: 36 videos × 3 connections each = 108 connections (Firefox limit: 6)

**Solutions**:
1. **HTTP/2** (reverse proxy): Multiplexes all streams over 1 connection
2. **Lazy loading**: Only visible videos open connections
3. **Pooling**: Reuses 48 media elements instead of creating 36 new ones
4. **Throttling**: Prevents bandwidth saturation
5. **Queue limits**: Caps concurrent loads to 3-6

### Race Condition Prevention

**Audit Context Tracking**:
```javascript
// Captures current view state before API call
const auditContext = state.startAuditContext();

// On response, verify we're still in same view
if (!state.isValidAuditContext(auditContext)) {
  // User navigated away - ignore stale response
  return;
}
```

Prevents UI corruption when:
- User audits file then navigates quickly
- API response arrives after folder change
- Multiple audits overlap

### Fullscreen Cleanup

**Critical for preventing seeking hangs**:
```javascript
// Before opening fullscreen
await mediaPool.releaseAll();  // Return all grid elements to pool
mediaPool.clearQueues();        // Stop all loading
// Small delay ensures TCP connections released
```

This frees up browser connections for the fullscreen video to seek efficiently.

### API Endpoints (`api.php`)

| Action | Method | Parameters | Description | Response |
|--------|--------|------------|-------------|----------|
| `delete` | POST | `files[]` | Delete specific files permanently | `{status, results}` |
| `delete` | POST | `recursive=true`, `delete_folder=true`, `folder` | Recursively delete folder and all contents (including hidden files) | `{status, deleted: {files, hidden_files, subfolders}, parent_path}` |
| `audit` | POST | `file_paths[]` | Mark files as audited | `{status, date, count, stats}` |
| `audit_status_batch` | POST | `file_paths[]` | Get audit status for files | `{status, audit_statuses}` |
| `metadata` | POST | `files[]` | Get full metadata (single) | Metadata object |
| `metadata_batch` | POST | `files[]` | Get metadata for multiple | `{file: metadata}` |
| `toggle_favorite` | POST | `file` | Toggle favorite status | `{status, favorited, file}` |
| `favorites_status_batch` | POST | `file_paths[]` | Get favorites status | `{status, favorite_statuses}` |
| `get_favorites_count` | POST | `folder_path` | Count favorites in folder | `{status, count}` |

**Metadata Extraction**:
- Uses `ffprobe` for video/audio metadata
- Extracts: codec, resolution, duration, bitrate, FPS, file size, folder path, **container format**
- Cached in `/tmp/.metadata/` (SHA1 hash filenames, 7-day TTL)
- Audio cover art extracted via `getID3` library, cached in `cache/audio-covers/`
- Container format detection (FLV, MP4, MKV, etc.) for unsupported format identification

### Hidden Features & Easter Eggs

**Navigation Shortcuts**:
1. **Double-click Audit**: Click audit button twice (within 400ms) to audit ALL files in folder
2. **Folder Links**: Click folder name in metadata overlay to navigate directly to that folder
3. **Wheel Navigation**: Mouse wheel works on grid AND on the options form
4. **Touch Gestures**: Swipe up/down on mobile to navigate grid pages

**Visual Feedback**:
5. **Unaudited Files**: Gold animated border + "NEW" shimmer badge
6. **Favorited Items**: Pink animated glow effect on ❤️ hearts
7. **Selected for Delete**: Red border + "DELETE" badge (replaces NEW) + red pulsing 🗙 button
8. **Loading State**: ⏳ spinner on buttons during operations

**Technical**:
9. **Android Bridge**: `window.AndroidPlayer` API for native Android ExoPlayer integration
10. **Auto Cache Buster**: `?t=timestamp` auto-added to all navigation to prevent caching
11. **Resume Playback**: Returns to exact grid position and playback time after fullscreen
12. **Smart Mute**: Only one video can be unmuted at a time (prevents audio chaos)

**Mobile Optimizations**:
- Default 1×1 grid on mobile (vs 3×2 on desktop)
- Touch swipe detection for navigation
- Reduced grid gap (8px vs 16px)
- Simplified folder selector (120-160px vs 240-400px)

### Boss Screen (Cyberpunk Terminal)

**Activation**: Press `b` anywhere to open the interactive terminal overlay

**Features**:
- Full-screen cyberpunk-themed terminal interface
- Blocks all grid/video interaction while active
- Mutes all audio automatically when opened
- Restores previous mute states when closed

**Terminal Commands**:

| Command | Description |
|---------|-------------|
| `help` | Show available commands |
| `status` | Display system status (videos, storage, CPU, memory) |
| `clear` | Clear terminal screen |
| `exit` / `quit` | Close boss screen |
| `matrix` | Trigger matrix rain animation |
| `whoami` | Display user profile information |
| `ls` | List fake directory contents |
| `decrypt` | Multi-layer decryption animation |
| `hack` | Progress bar hack simulation |
| `fortune` | Random fortune cookie message |
| `selfdestruct` | Fake self-destruct countdown (joke) |
| `konami` | Konami code Easter egg |

**Controls**:
- **Open**: `b` key
- **Close**: `ESC` key or type `exit`
- **Command History**: Up/Down arrow keys
- **Input**: Type commands and press Enter

---

## 🐳 Docker Reference

### Services

| Service | Port | Purpose | Scaling |
|---------|------|---------|---------|
| nginx | 8050 | Web server, static assets, video streaming | Fixed |
| php-fpm | 9000 (internal) | Page generation, thumbnails | Fixed |
| php-cli | 9000 (internal) | API, metadata, database | Fixed |

### Environment Variables

| Variable | Default | Purpose |
|----------|---------|---------|
| `DELETE_SECRET_CODE` | (none) | Enables delete functionality |
| `PHP_MEMORY_LIMIT` | 256M | Memory for large libraries |
| `NGINX_WORKER_PROCESSES` | auto | Tune for your CPU cores |

### Useful Commands

```bash
# View real-time logs
docker-compose logs -f nginx
docker-compose logs -f php-cli

# Restart specific service
docker-compose restart nginx

# Shell access for debugging
docker-compose exec nginx sh
docker-compose exec php-cli bash

# Check database
docker-compose exec php-cli sqlite3 /var/www/html/data/db/audit.db "SELECT * FROM audit_status LIMIT 10;"

# Rebuild after config changes
docker-compose down && docker-compose up -d --build
```

---

## 🗂️ Project Structure

```
xclusive-media-player/
├── 📁 src/                          # Application source
│   ├── 📄 index.php                 # Main entry, grid configuration
│   ├── 📄 api.php                   # REST API endpoint
│   ├── 📄 post-handler.php          # Request proxy/router
│   ├── 📁 lib/                      # PHP libraries
│   │   ├── 📄 AuditDatabase.php     # Audit tracking
│   │   ├── 📄 FavoritesDatabase.php # Favorites management
│   │   └── 📄 audioCovers.php       # Album art extraction
│   ├── 📁 assets/
│   │   ├── 📁 css/
│   │   │   └── 📄 app.css          # Stylesheet
│   │   └── 📁 js/                   # ES6 modules
│   │       ├── 📄 main.js          # Entry point
│   │       ├── 📄 state.js         # Centralized state management
│   │       ├── 📄 grid.js          # Grid rendering (6 phases)
│   │       ├── 📄 mediaContainer.js # Lazy loading, element creation
│   │       ├── 📄 mediaPool.js     # Element pooling (48 elements)
│   │       ├── 📄 mediaQueue.js    # Concurrent loading queues
│   │       ├── 📄 fullscreen.js    # Fullscreen player
│   │       ├── 📄 audit.js         # Audit system
│   │       ├── 📄 favorites.js     # Favorites system
│   │       ├── 📄 search.js        # Search/filtering
│   │       └── 📄 ...              # Other modules
│   └── 📁 cache/                    # Generated thumbnails
│
├── 📁 docker/                       # Docker configurations
│   ├── 📁 nginx/
│   │   ├── 📄 nginx.conf           # Main nginx config
│   │   ├── 📄 default.conf         # Server blocks
│   │   └── 📄 reverse-proxy-optimized.conf  # Production reverse proxy
│   ├── 📁 php-fpm/                  # PHP-FPM configuration
│   └── 📁 php-cli/                  # PHP-CLI configuration
│
├── 📁 volumes/                      # Your media files (mounted)
│   └── 📁 ...                       # Your folder structure
│
├── 📁 data/                         # Persistent data
│   └── 📁 db/                       # SQLite databases
│       ├── 📄 audit.db             # Audit status
│       └── 📄 favorites.db         # Favorites
│
├── 📄 compose.yml                   # Docker Compose config
├── 📄 .env                          # Environment variables (create this)
└── 📄 README.md                     # This file
```

---

## 🤝 Development

### Local Development

Changes to `./src` are reflected immediately (no rebuild needed):

```bash
# Start with live reloading
docker-compose up

# Edit files in ./src
# Refresh browser to see changes
```

### Adding Features

**Example: New Video Format**

1. Add extension in `src/assets/js/utils.js`:
   ```javascript
   export function isVideoFile(filename) {
     const ext = getFileExtension(filename);
     return ['mp4', 'webm', 'mkv', 'mov', 'newformat'].includes(ext);
   }
   ```

2. Add nginx mime type in `docker/nginx/nginx.conf`:
   ```nginx
   types {
     video/newformat newformat;
   }
   ```

3. Test with sample file

### Architecture Principles

1. **State immutability**: Use `state.setFilter()`, not direct mutation
2. **Async cleanup**: Always `await mediaPool.releaseAll()` before fullscreen
3. **Context tracking**: Use `state.startAuditContext()` for race condition protection
4. **Queue discipline**: Always set `preload="auto"` BEFORE `src`
5. **Lazy first**: Only load what's visible

---

## 📈 Performance Benchmarks

| Grid Size | Videos | Load Time | Memory | Concurrent Connections |
|-----------|--------|-----------|--------|----------------------|
| 1×1 | 1 | <1s | 50MB | 2-3 |
| 2×2 | 4 | 2-3s | 150MB | 8-12 |
| 3×2 | 6 | 3-4s | 200MB | 12-18 |
| 4×3 | 12 | 5-6s | 400MB | 24-36 |
| 6×6 | 36 | 10-12s | 800MB | 72-108* |

*With HTTP/2 reverse proxy: 6-12 connections (multiplexed)

---

## 📝 License

MIT License - See [LICENSE](LICENSE)

## 🙏 Credits

- Architecture inspired by modern media management needs
- Lazy loading via IntersectionObserver API
- Audio metadata via ffmpeg/ffprobe
- Icons via emoji and system fonts

---

## 📧 Support & Issues

For bugs, feature requests, or questions:
1. Check [Troubleshooting](#-troubleshooting) section
2. Search existing issues
3. Open new issue with:
   - Browser version
   - Grid size used
   - Error messages from console
   - Nginx/php logs

---

**Built for media professionals who demand performance** 🎬🎵
