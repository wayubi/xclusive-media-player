# Xclusive Media Player - Architecture Documentation

This document provides a comprehensive architectural overview of the Xclusive Media Player project for developers and future agents.

---

## Table of Contents

1. [High-Level Overview](#high-level-overview)
2. [System Architecture](#system-architecture)
3. [Frontend Architecture](#frontend-architecture)
4. [Backend Architecture](#backend-architecture)
5. [Database Architecture](#database-architecture)
6. [Docker Infrastructure](#docker-infrastructure)
7. [Android App Architecture](#android-app-architecture)
8. [Media Processing Pipeline](#media-processing-pipeline)
9. [UI/UX Architecture](#uiux-architecture)
10. [Performance Optimizations](#performance-optimizations)
11. [API Reference](#api-reference)
12. [Security Model](#security-model)

---

## High-Level Overview

Xclusive Media Player is a high-performance, browser-based media management and playback system designed for large media libraries. It consists of three main components:

| Component | Technology | Purpose |
|-----------|------------|---------|
| Web Application | PHP + Vanilla JS | Media browsing, grid display, playback |
| Backend Services | PHP-FPM + PHP-CLI | API, metadata extraction, database operations |
| Mobile Client | Android (Kotlin) | Native media player using ExoPlayer |

---

## System Architecture

```
┌─────────────────────────────────────────────────────────────────────────┐
│                              CLIENTS                                     │
├─────────────────────────────────┬───────────────────────────────────────┤
│         Web Browser             │         Android App                    │
│   (Chrome/Firefox/Safari)      │   (Fire TV/Tablet/Phone)              │
│                                 │                                        │
│   ┌───────────────────────┐     │   ┌─────────────────────────────┐      │
│   │   Grid Interface     │     │   │    WebView (Grid UI)       │      │
│   │   Fullscreen Player  │     │   │    ExoPlayer (Playback)    │      │
│   │   Media Pool (48)    │     │   │    JavaScript Bridge       │      │
│   └───────────┬───────────┘     │   └──────────────┬──────────────┘      │
│               │                 │                  │                      │
└───────────────┼─────────────────┼──────────────────┼──────────────────────┘
                │                 │                  │
                ▼                 ▼                  ▼
┌───────────────────────────────────────────────────────────────────────────┐
│                           DOCKER NETWORK                                   │
│                                                                           │
│  ┌─────────────┐    ┌─────────────┐    ┌─────────────┐                   │
│  │   nginx     │    │   php-fpm   │    │  php-cli    │                   │
│  │  (Port 80)  │◄──►│  (Port 9000)│    │ (API/Cron)  │                   │
│  └──────┬──────┘    └─────────────┘    └─────────────┘                   │
│         │                                                                  │
│         │         ┌──────────────────────────────────────────┐             │
│         │         │         Volumes (Media Files)           │             │
│         │         │   /volumes/pocket, /volumes/transmission │             │
│         │         │   /volumes/data18, /volumes/unsorted     │             │
│         │         └──────────────────────────────────────────┘             │
│         │                                                                  │
│         ▼                                                                  │
│  ┌──────────────────────────────────────────────────────────────────┐      │
│  │                    Persistent Data (SQLite)                       │      │
│  │         data/db/metadata.db    data/db/audit.db                 │      │
│  │         data/db/favorites.db                                    │      │
│  └──────────────────────────────────────────────────────────────────┘      │
└───────────────────────────────────────────────────────────────────────────┘
```

---

## Frontend Architecture

### Module Structure

The frontend is built with vanilla JavaScript ES6 modules, located in `src/assets/js/`:

```
src/assets/js/
├── main.js              # Entry point, bootstrap
├── state.js             # Centralized state management
├── grid.js              # Grid rendering (6-phase system)
├── mediaPool.js         # Media element pooling (48 elements)
├── mediaQueue.js        # Concurrent loading queue management
├── mediaContainer.js    # Lazy loading, element creation
├── fullscreen.js        # Fullscreen player
├── audit.js             # Audit system
├── favorites.js         # Favorites system
├── search.js            # Search/filtering
├── filter.js            # Filter management
├── ui.js                # UI utilities
├── utils.js             # Helper functions
├── terminal.js          # Boss screen terminal
├── events.js            # Event listeners
├── share.js             # Sharing functionality
├── audit.js             # Audit status handling
└── fullscreen.js        # Fullscreen player controls
```

### State Management (state.js)

The `state` object provides centralized state management for the entire application:

```javascript
// Core data
state.allVideos           // Filtered video list
state.originalVideos     // Original unfiltered list  
state.webToFsPathMap     // Web path → filesystem path mapping
state.auditStatusMap     // Audit status by file
state.favoritesMap       // Favorites by file

// UI state
state.startIndex         // Current grid page start
state.currentSearch      // Active search term
state.unauditedFilter    // Unaudited files filter
state.favoritesFilter     // Favorites filter
state.optimizationFilter  // Optimization filter
```

**Key Methods:**
- `init(config)` - Initialize from PHP bootstrap data
- `setFilter(type, options)` - Unified filter management
- `toggleFavorite(file)` - Async favorite toggle
- `deleteVideo(file)` - Remove video from all state
- `startAuditContext()` / `isValidAuditContext()` - Race condition prevention

### Media Pool System (mediaPool.js)

Pre-allocates 48 reusable `<video>` and `<audio>` elements to prevent DOM thrashing:

```javascript
const MAX_POOL_SIZE = 48;

mediaPool.videoPool  // Array of pre-created video elements
mediaPool.audioPool  // Array of pre-created audio elements
```

**Benefits:**
- Eliminates DOM creation overhead during scrolling
- Ensures TCP connections are properly released
- Prevents browser connection exhaustion

### Media Queue System (mediaQueue.js)

Manages concurrent media loading with rate limiting:

```javascript
let MAX_CONCURRENT_AUDIO = 6;
let MAX_CONCURRENT_VIDEO = 6;
const MEDIA_LOAD_TIMEOUT = 15000; // 15 seconds
```

**Completion Tracking:**
Uses multiple signals to ensure queue slots are always freed:
1. `canplay` - Media ready to play (preferred)
2. `loadedmetadata` - Metadata loaded (fallback)
3. `error` - Load failed (still frees slot)
4. `timeout` - 15-second fallback (guaranteed cleanup)

### Grid Rendering (grid.js)

6-phase rendering system:

```
Phase 1: Cleanup    → Recycle elements back to pool
Phase 2: Structure  → Create CSS grid containers
Phase 3: Populate    → Create media containers
Phase 4: Metadata    → Fetch file metadata
Phase 5: Loading     → Start media queues
Phase 6: Finalize    → Sync UI state
```

### Lazy Loading

Uses IntersectionObserver with 200px viewport margin:

```javascript
const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            // Load media when 200px from viewport
        }
    });
}, { rootMargin: '200px' });
```

**Priority Loading:**
1. Recent fullscreen video (if returning)
2. First grid cell (immediate visibility)
3. Other visible cells (lazy load)

---

## UI/UX Architecture

### Design System

The UI follows a cohesive design language with the following characteristics:

#### Color Palette (CSS Variables)

```css
:root {
  --bg: linear-gradient(135deg, #0a0a0f 0%, #1a0a1f 50%, #0f0a1a 100%);
  --surface: rgba(25, 25, 35, 0.95);
  --surface-hover: rgba(40, 35, 50, 0.95);
  --accent: #a855f7;           /* Purple - primary accent */
  --accent-secondary: #ec4899; /* Pink - secondary accent */
  --accent-glow: #c084fc;      /* Light purple - hover states */
  --text: #ffffff;
  --text-secondary: #b4b4c8;
  --border: rgba(139, 92, 246, 0.15);
  --border-glow: rgba(139, 92, 246, 0.3);
}
```

#### Typography

- **Primary Font:** Inter (sans-serif)
- **Monospace Font:** JetBrains Mono / Courier New
- **Font Weights:** 400 (regular), 500 (medium), 600 (semibold), 700 (bold), 800 (extra bold)

#### Visual Effects

- **Glassmorphism:** Heavy use of `backdrop-filter: blur()` for modern glass effects
- **Gradients:** Subtle purple/pink gradients for backgrounds and accents
- **Shadows:** Layered shadows with color tinting
- **Border Radius:** 12px (small), 20px (default)
- **Animations:** Cubic-bezier transitions with spring-like easing

### CSS Architecture

The stylesheet is modular, split into multiple specialized files:

```
src/assets/css/
├── app.css           # Main import file (entry point)
├── variables.css     # CSS custom properties (colors, spacing, effects)
├── base.css          # Reset, html/body, typography
├── ui.css            # Toolbar, buttons, forms, navigation
├── grid.css          # Media grid layout and containers
├── media.css         # Video/audio overlays and controls
├── video-states.css  # Visual states (unaudited, delete, unsupported)
├── favorites.css     # Heart icon, favorite states
├── search.css        # Search overlay modal
├── terminal.css      # Boss screen terminal interface
├── share.css         # Share functionality
├── text.css          # Text file viewer
└── responsive.css    # Mobile/tablet responsive styles
```

### Component Architecture

#### 1. Toolbar (Header)

Located at the top of the screen, the toolbar provides:

```html
<form id="options-form">
  <!-- File counter -->
  <span id="file-count">1 / 24</span>
  
  <!-- Navigation -->
  <button title="Home">🏠</button>
  <button title="Back">←</button>
  <select id="folder-select">...</select>
  
  <!-- Grid controls -->
  <select name="columns">...</select>
  <select name="rows">...</select>
  
  <!-- Playback -->
  <button id="mute-button">🔇</button>
  <button onclick="playAll()">▶️</button>
  <button onclick="shufflePlay()">🔀</button>
  <button onclick="playFavorites()">❤️</button>
  <button onclick="runAudit()">📋</button>
  
  <!-- Navigation -->
  <button onclick="prevGrid()">◀</button>
  <button onclick="nextGrid()">▶</button>
  
  <!-- Status displays -->
  <span id="favorites-text">❤️ 5</span>
  <span id="audit-text">⚠️ Not audited</span>
  <span id="optimization-text">⚡ All optimized</span>
</form>
```

#### 2. Media Grid

The core component displaying media items in a configurable grid:

**Grid Configuration:**
- Columns: 1-6 (configurable via URL: `?columns=3`)
- Rows: 1-6 (configurable via URL: `?rows=2`)
- Total cells: 1-36
- Mobile: 1×1 by default

**CSS Grid Layout:**
```css
#grid {
  display: grid;
  gap: 16px;
  grid-auto-rows: minmax(0, 1fr);
}
```

#### 3. Media Container

Each grid cell contains:

```
┌─────────────────────────────────────┐
│ ❤️                    [NEW]        │  ← Favorite heart, Unaudited badge
│                                     │
│   ┌───────────────────────────┐     │
│   │                           │     │
│   │    <video> or <audio>    │     │  ← Media element (lazy loaded)
│   │    or <img>              │     │
│   │                           │     │
│   └───────────────────────────┘     │
│                                     │
│   ┌────────────────────────────┐   │
│   │ ⛶ 🔇 🔊 📋 🗙             │   │  ← Central overlay (on hover)
│   └────────────────────────────┘   │
│                                     │
│ ─────────────────────────────────   │  ← Bottom gradient overlay
│ filename.mp4                        │  ← File info (on hover)
│ 1920×1080 • 1h 30m • 2.5GB • h264  │
└─────────────────────────────────────┘
```

**Container States:**

| State | Visual Indicator | CSS Class |
|-------|-----------------|-----------|
| Unaudited | Gold gradient border + "NEW" badge | `.video-container.unaudited` |
| Audited | Normal purple border | Default |
| Selected for Delete | Red border + "DELETE" badge + pulse animation | `.video-container.selected-for-delete` |
| Unsupported Codec | Gray border + "CODEC" badge + grayscale thumb | `.video-container.unsupported-video` |
| Favorited | Pink glowing heart | `.favorite-heart.favorited` |

#### 4. Overlays

**Central Overlay** (appears on hover):
- Fullscreen button (⛶)
- Mute/Unmute toggle (🔇/🔊)
- Audit toggle (📋)
- Delete button (🗙) - when delete mode enabled

**Bottom Overlay** (gradient + info):
- Filename
- Resolution (1920×1080)
- Duration (1h 30m)
- File size (2.5GB)
- Video codec (h264)
- FPS, bitrate
- Optimization status icon (⚡/🔧)
- Folder link (clickable)

#### 5. Fullscreen Player

A modal overlay with:

```css
position: fixed;
top: 0; left: 0;
width: 100%; height: 100%;
background: #000;
z-index: 9999;
```

**Features:**
- Native `<video>` controls
- Aspect ratio preservation
- Click/double-click to close
- Mouse wheel navigation
- Touch swipe navigation
- Keyboard controls (arrows, space, escape)
- Resume position when returning to grid

#### 6. Search Overlay

Modal overlay triggered by `/` key:

```css
#search-overlay {
  position: fixed;
  inset: 0;
  background: rgba(10, 10, 15, 0.96);
  backdrop-filter: blur(24px);
  z-index: 10000;
}
```

**Features:**
- Large centered input (1.5rem font)
- Real-time filtering
- Clear button
- ESC to close

#### 7. Boss Screen (Terminal)

Hidden feature activated by pressing `b`:

**Visual Effects:**
- CRT scanlines (repeating gradient)
- Screen flicker animation
- Animated border glow (color cycling)
- Matrix rain effect (command)

**Terminal Features:**
- Command history (up/down arrows)
- ASCII art header
- Colored output (green/red/blue/yellow)
- Fake system commands

**Available Commands:**
| Command | Description |
|---------|-------------|
| `help` | Show commands |
| `status` | System status |
| `clear` | Clear screen |
| `matrix` | Matrix rain |
| `whoami` | User info |
| `fortune` | Random message |

#### 8. Favorites System

**States:**
- Empty heart (🤍) - not favorited
- Filled heart (❤️) - favorited with pink glow animation

**Interactions:**
- Click heart to toggle
- Header count is clickable (filters to favorites)
- Play favorites button in toolbar

#### 9. Audit System

**Visual Indicators:**
- Yellow/gold border → Unaudited
- No border → Audited
- Click 📋 to audit visible files
- Double-click 📋 (within 400ms) to audit entire folder

**Context Tracking:**
```javascript
// Prevents race conditions when auditing during navigation
state.startAuditContext();
// ... audit operation ...
if (!state.isValidAuditContext(context)) {
  return; // Ignore stale response
}
```

### Responsive Design

#### Mobile (Default)

```css
/* Mobile defaults */
@media (max-width: 768px) {
  /* 1x1 grid */
  /* Simplified folder selector */
  /* Touch swipe navigation */
}
```

#### Desktop (Enhanced)

```css
@media (min-width: 769px) {
  /* 5x2 grid default */
  /* Full folder selector */
  /* Mouse hover effects */
}
```

### Interaction Patterns

#### 1. Lazy Loading

Media loads only when needed:

```javascript
// data-src pattern (doesn't trigger load)
<video data-src="file.mp4"></video>

// On intersection, transfer to src
video.src = video.dataset.src;
```

#### 2. Single Unmuted Policy

Only one video can be unmuted at a time:

```javascript
function enforceSingleUnmuted() {
  media.forEach(m => m.muted = true);
  target.muted = false;  // Only one unmuted
}
```

#### 3. Mute on Hover

When not globally muted, hovering unmuted the video:

```javascript
// Event handler in media container
container.addEventListener('mouseenter', () => {
  if (!state.muted) {
    previousVideo.muted = true;
    thisVideo.muted = false;
  }
});
```

#### 4. CTRL+Scroll Seeking

Seek ±5 seconds while holding CTRL:

```javascript
container.addEventListener('wheel', (e) => {
  if (e.ctrlKey) {
    e.preventDefault();
    const direction = e.deltaY > 0 ? 5 : -5;
    video.currentTime += direction;
  }
});
```

#### 5. Delete Mode

Number keys 0-9 select tiles for deletion:

```javascript
case '1': case '2': case '3': // ...
  toggleDeleteSelection(tileIndex);
  break;
case 'Delete':
  if (selectedCount === 0) selectAll();
  else confirmDelete();
```

### Accessibility

- **Keyboard navigation:** Full keyboard support
- **Focus indicators:** Visible focus states
- **Screen reader:** Semantic HTML structure
- **Reduced motion:** Respects `prefers-reduced-motion`
- **Touch targets:** Minimum 44px for mobile

### Animation System

**Transition Timing:**
```css
--transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
```

**Key Animations:**
- `shimmer` - Gold pulse for unaudited files
- `pulseDelete` - Red pulse for selected delete
- `heartGlow` - Pink glow for favorited items
- `fadeIn` - Search overlay entrance
- `slideUp` - Search input animation
- `crt-flicker` - Terminal CRT effect

---

## Backend Architecture

### Entry Points

| File | Purpose |
|------|---------|
| `index.php` | Main page, grid rendering, folder navigation |
| `api.php` | REST API endpoint for all actions |
| `post-handler.php` | Request proxy to php-cli service |

### PHP Class Structure

```
src/lib/
├── Database.php          # Abstract SQLite base class
├── Utils.php             # Utility functions
├── MetadataDatabase.php  # Media file metadata storage
├── AuditDatabase.php     # Audit status tracking
├── FavoritesDatabase.php # Favorites management
├── MetadataExtractor.php # ffprobe wrapper
├── audioCovers.php       # Album art extraction
├── MastodonClient.php    # Social sharing
│
└── actions/
    ├── ActionHandler.php    # Base action handler
    ├── DeleteAction.php     # File/folder deletion
    ├── MetadataAction.php   # Metadata retrieval
    ├── AuditAction.php     # Audit operations
    ├── AuditStatusAction.php # Batch audit status
    ├── FavoritesAction.php  # Favorites operations
    ├── ShareAction.php     # Social sharing
    └── TerminalAction.php  # Boss screen commands
```

### Request Flow

```
Browser Request
       │
       ▼
┌──────────────────┐
│   nginx:80       │ ─── Static files (CSS, JS, images)
│                  │ ─── Video streaming (range requests)
└────────┬─────────┘
         │ PHP-FPM
         ▼
┌──────────────────┐
│   index.php      │ ─── Grid rendering
│                  │ ─── Folder navigation
└────────┬─────────┘
         │
         ├────────────────────────┐
         ▼                        ▼
┌─────────────────┐     ┌──────────────────┐
│ post-handler.php│     │    api.php       │
│ (POST requests) │     │  (CLI requests)  │
└────────┬────────┘     └────────┬─────────┘
         │                       │
         │ Curl                   │ Direct
         ▼                       ▼
┌─────────────────┐     ┌──────────────────┐
│  php-cli:8080   │     │   php-cli:8080   │
│    api.php      │     │    api.php       │
└─────────────────┘     └──────────────────┘
```

---

## Database Architecture

Three SQLite databases provide persistent state:

### 1. metadata.db (`MetadataDatabase.php`)

Stores comprehensive media file metadata:

```sql
CREATE TABLE files (
    id INTEGER PRIMARY KEY,
    file_path TEXT UNIQUE,      -- Absolute path
    web_path TEXT,               -- URL path
    file_size INTEGER,
    modified_time INTEGER,
    extension TEXT,
    
    -- Media metadata
    duration REAL,
    bitrate INTEGER,
    container TEXT,              -- MP4, MKV, FLV, etc.
    video_codec TEXT,
    video_width INTEGER,
    video_height INTEGER,
    video_fps REAL,
    video_pix_fmt TEXT,
    audio_codec TEXT,
    audio_channels INTEGER,
    audio_sample_rate INTEGER,
    
    -- Optimization tracking
    is_optimized INTEGER DEFAULT 1,
    optimization_issues TEXT,
    
    -- Integrity
    xxhash TEXT,                 -- File checksum
    
    created_at INTEGER,
    updated_at INTEGER
);

CREATE INDEX idx_file_path ON files(file_path);
CREATE INDEX idx_updated_at ON files(updated_at);
```

### 2. audit.db (`AuditDatabase.php`)

Tracks which files have been reviewed:

```sql
-- File registry
CREATE TABLE files (
    id INTEGER PRIMARY KEY,
    file_path TEXT UNIQUE,
    file_size INTEGER,
    modified_time INTEGER,
    created_at INTEGER
);

-- Audit log
CREATE TABLE audit_log (
    id INTEGER PRIMARY KEY,
    file_id INTEGER REFERENCES files(id),
    audited_at INTEGER,
    audit_date TEXT          -- Format: YYMMDD
);

CREATE INDEX idx_audited_at ON audit_log(audited_at);
```

### 3. favorites.db (`FavoritesDatabase.php`)

Persists user favorites:

```sql
CREATE TABLE favorites (
    id INTEGER PRIMARY KEY,
    file_path TEXT UNIQUE,
    folder_path TEXT,
    favorited INTEGER DEFAULT 0,
    favorite_date TEXT
);
```

### Database Configuration

All databases use WAL (Write-Ahead Logging) mode for concurrent access:

```php
$this->db->exec('PRAGMA journal_mode = WAL;');
$this->db->exec('PRAGMA busy_timeout = 5000;');
```

---

## Docker Infrastructure

### Services

| Service | Image | Ports | Purpose |
|---------|-------|-------|---------|
| nginx | nginx:alpine | 8050:80 | Web server, media streaming |
| php-fpm | php:8.4-fpm | 9000 (internal) | Page generation |
| php-cli | php:8.4-cli | 9000 (internal) | API, cron jobs |

### nginx Configuration

**nginx.conf** - Global settings:
- Worker processes: auto
- Worker connections: 4096
- File caching: 500 files
- Connection limits: 200 per IP
- Gzip compression enabled

**default.conf** - Server configuration:
- Static assets: 30-day cache
- Video files: Range requests, throttling (50MB/s after 10MB)
- Audio files: Throttling (20MB/s after 5MB)
- PHP-FPM proxy for dynamic content

### PHP Images

**php-fpm/Dockerfile:**
```dockerfile
FROM php:8.4-fpm
RUN apt-get update && apt-get install -y ffmpeg
WORKDIR /var/www/html
```

**php-cli/Dockerfile:**
```dockerfile
FROM php:8.4-cli
RUN apt-get update && apt-get install -y ffmpeg cron util-linux xxhash xxd
COPY crontab /etc/cron.d/crontab
COPY entrypoint.sh /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]
```

### Cron Jobs

Runs hourly via php-cli:
```
0 * * * * root /usr/local/bin/run-refresh-metadata
```

### Volume Mounts

```yaml
# compose.yml
volumes:
  - ./src:/var/www/html           # Application code
  - ./volumes:/var/www/html/volumes  # Media files
  - ./.env:/var/www/.env:ro       # Environment
  - ./data/db:/var/www/db         # SQLite databases
```

---

## Android App Architecture

Located in `android-app/`, built with modern Android practices.

### Technology Stack

| Component | Technology |
|-----------|------------|
| Language | Kotlin 2.0+ |
| Min SDK | 26 (Android 8.0) |
| Target SDK | 36 |
| DI | Koin 3.5+ |
| Player | Media3 ExoPlayer |
| Architecture | MVVM |
| UI | WebView + Native Player |

### Package Structure

```
com.xclusive.mediaplayer/
├── XclusiveMediaPlayerApp.kt    # Application + Koin init
├── data/repository/
│   └── ConfigRepository.kt      # JSON config loader
├── di/
│   └── AppModule.kt             # Koin DI modules
├── player/
│   ├── PlayerManager.kt         # ExoPlayer wrapper
│   └── PlayerState.kt           # Player state sealed class
├── ui/
│   ├── MainActivity.kt          # Main activity
│   └── MainViewModel.kt        # MVVM ViewModel
├── util/
│   └── DeviceUtils.kt           # Device type detection
└── web/
    ├── WebViewManager.kt       # WebView configuration
    └── bridge/
        └── PlayerBridge.kt     # JS ↔ Native bridge
```

### Key Features

**Smart Orientation:**
- TV devices: Always landscape
- Tablets: Landscape (configurable)
- Phones: Sensor-based

**JavaScript Bridge:**
```javascript
// From JavaScript
window.AndroidPlayer.playWithExoPlayer(playlistJson, index, startTime);
window.AndroidPlayer.playVideo(url);
window.AndroidPlayer.stop();
```

**Configuration (res/raw/config.json):**
```json
{
  "server": {
    "host": "your-server.com",
    "port": 8050,
    "useHttps": true
  },
  "features": {
    "useExoPlayer": true
  },
  "ui": {
    "forceLandscapeOnTablets": true
  }
}
```

---

## Media Processing Pipeline

### Metadata Extraction (MetadataExtractor.php)

Uses ffprobe to extract:

```php
const VIDEO_EXTENSIONS = ['mp4', 'webm', 'mkv', 'mov', 'm4v', '3gp', 'flv', 'wmv', 'avi'];
const AUDIO_EXTENSIONS = ['mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'wma'];
```

**Extracted Data:**
- Duration, bitrate, container format
- Video: codec, resolution, FPS, pixel format
- Audio: codec, channels, sample rate
- Text: encoding (for NFO/TXT files)

### Optimization Detection

Identifies non-streaming files:

```php
const NON_STREAMING_CONTAINERS = ['avi', 'flv', 'wmv', 'mkv', 'mpeg', 'mpg'];
const NON_STREAMING_CODECS = ['vc1', 'wmv3', 'flv1', 'wmv2', 'mpeg4', 'wmv1', 'mpeg1video'];
```

**Checks:**
1. Container format (FLV, AVI → not stream-optimized)
2. Video codec (WMV3, VC1 → not supported)
3. Faststart atom position (moov atom must be at start for MP4)

### Audio Cover Art

Extracts embedded album art via ffmpeg:

```php
// Extract first frame as cover
ffmpeg -i input.mp3 -an -vcodec copy cover.jpg
```

Cached in `cache/audio-covers/` with SHA1-based filenames.

---

## Performance Optimizations

### Frontend

| Optimization | Implementation |
|--------------|----------------|
| Element Pooling | 48 pre-allocated video/audio elements |
| Lazy Loading | IntersectionObserver with 200px margin |
| Queue Rate Limiting | Max 6 concurrent loads (3 for large grids) |
| Priority Loading | Fullscreen → First cell → Others |
| Connection Multiplexing | HTTP/2 (via reverse proxy) |
| Completion Tracking | canplay/loadedmetadata/error/timeout |

### Backend

| Optimization | Implementation |
|--------------|----------------|
| Metadata Caching | SQLite with file mtime validation |
| Range Requests | nginx supports byte-range for seeking |
| Throttling | 50MB/s after 10MB burst (protects HDD) |
| File Caching | nginx open_file_cache (500 files) |
| WAL Mode | SQLite concurrent read/write |
| xxHash | Fast file integrity checks |

### Browser Connection Management

Problem: 36 videos × 3 connections = 108 (Firefox limit: 6)

Solutions:
1. **HTTP/2** - Multiplexes over single connection
2. **Lazy loading** - Only visible videos connect
3. **Pooling** - Reuses 48 elements
4. **Queue limits** - Caps concurrent loads

---

## API Reference

### Endpoints

All API calls are POST to `api.php`:

| Action | Parameters | Description |
|--------|------------|-------------|
| `delete` | `files[]` or `recursive`+`folder` | Delete files/folder |
| `audit` | `path`, `filenames[]` | Mark as audited |
| `audit_status_batch` | `file_paths[]` | Get audit statuses |
| `metadata` | `files[]` | Get single file metadata |
| `metadata_batch` | `files[]` | Get multiple metadata |
| `toggle_favorite` | `file` | Toggle favorite |
| `favorites_status_batch` | `file_paths[]` | Get favorite statuses |
| `get_favorites_count` | `folder_path` | Count folder favorites |
| `share` | `file`, `platform` | Social sharing |
| `terminal` | `command` | Boss screen commands |

### Request Format

```json
{
  "action": "metadata",
  "files": ["/volumes/video.mp4"]
}
```

### Response Format

```json
{
  "status": "ok",
  "metadata": {
    "/volumes/video.mp4": {
      "file": "video.mp4",
      "folder": "movies",
      "filesize": 1073741824,
      "duration": 7200.5,
      "video": {
        "codec": "h264",
        "width": 1920,
        "height": 1080,
        "fps": 23.976
      },
      "audio": {
        "codec": "aac",
        "channels": 2,
        "sample_rate": 48000
      }
    }
  }
}
```

---

## Security Model

### Delete Protection

1. **Secret code in .env:**
   ```
   DELETE_SECRET_CODE=your_secret_code
   ```

2. **Cookie-based session (30 days):**
   ```
   ?delete=your_secret_code  → Set cookie
   ?delete=off               → Clear cookie
   ```

3. **Dual validation:**
   - Frontend: Hides delete UI when disabled
   - Backend: Checks cookie before deletion

### File Operations

- **No trash/recycle bin** - Files deleted immediately
- **Recursive deletion** - Can delete folders + subfolders
- **Audit trail** - All operations logged

### Network Security

- **nginx headers:**
  ```nginx
  add_header X-Content-Type-Options nosniff;
  add_header X-Frame-Options DENY;
  add_header X-XSS-Protection "1; mode=block";
  ```

- **Connection limits:** 200 per IP
- **Hidden files:** Denied (`location ~ /\.`)

---

## Development Guidelines

### Architecture Principles

1. **State immutability:** Use `state.setFilter()` not direct mutation
2. **Async cleanup:** Always `await mediaPool.releaseAll()` before fullscreen
3. **Context tracking:** Use `state.startAuditContext()` for race protection
4. **Queue discipline:** Set `preload="auto"` BEFORE `src`
5. **Lazy first:** Only load what's visible

### Adding New Features

1. **New video format:**
   - Add extension to `src/assets/js/utils.js`
   - Add MIME type to `docker/nginx/nginx.conf`

2. **New API action:**
   - Create handler in `src/lib/actions/`
   - Register in `src/api.php` actionMap

3. **New database:**
   - Extend `Database` abstract class
   - Implement `createTables()` method

---

## Build & Deployment

### Development

```bash
docker-compose up -d
# Edit files in ./src
# Refresh browser
```

### Production

```bash
# Build images
docker-compose build

# Start services
docker-compose up -d

# View logs
docker-compose logs -f nginx
docker-compose logs -f php-cli

# Shell access
docker-compose exec nginx sh
docker-compose exec php-cli bash
```

### Android Build

```bash
cd android-app
./gradlew assembleRelease
# Output: app/build/outputs/apk/release/app-release.apk
```

---

## Glossary

| Term | Definition |
|------|------------|
| Grid | Main media browser view with configurable columns×rows |
| Fullscreen | Immersive video/audio player overlay |
| Audit | Marking files as reviewed |
| Favorite | User-marked favorite files |
| Pool | Pre-allocated media elements |
| Queue | Concurrent loading management |
| Lazy load | Load on viewport entry |
| Range request | HTTP partial content request |
| Faststart | MP4 moov atom at file start |
| xxHash | Fast non-cryptographic hash |

---

*Last updated: 2026-03-30*
