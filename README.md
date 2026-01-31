# Xclusive Media Player

**Xclusive Media Player** is a powerful, browser-based media management system featuring a sleek grid interface for organizing, auditing, and playing your personal media collection. Built with modern web technologies and containerized with Docker, it provides an intuitive experience for managing large media libraries with advanced features like audit tracking, favorites, and secure file management.

---

## ✨ Features

### Media Management
- **Multi-format support**: MP3, WAV, OGG, MP4, WebM, MKV, MOV, and common image formats (JPG, PNG, GIF, WebP)
- **Smart folder navigation**: Browse nested directories with visual indicators for folder status
- **File metadata display**: View codec, resolution, duration, bitrate, and file size information
- **Batch operations**: Audit or delete multiple files at once
- **Advanced search**: Filter by filename or folder path with instant results

### Playback & Display
- **Grid interface**: Customizable rows (1-6) and columns (1-6) for optimal viewing
- **Fullscreen player**: Click any media item for immersive fullscreen playback
- **Playlist modes**: Play all files sequentially or shuffle for random playback
- **Auto-generated covers**: Displays album art for audio files with fallback placeholders
- **Responsive design**: Optimized for desktop, tablet, and mobile devices
- **Object-fit toggle**: Press 'C' to switch between cover and contain modes

### Audit System
- **SQLite-based tracking**: Persistent audit status across sessions
- **Visual indicators**: 
  - ✅ All files audited (green checkmark)
  - ⚠️ Partially audited (yellow warning)
  - 🆕 No files audited (new badge)
- **Smart filtering**: Click unaudited count to show only unaudited files
- **Folder-level stats**: See audit status and file counts for each folder
- **Batch auditing**: Single-click to audit visible files, double-click for entire folder

### Favorites System
- **Database-backed favorites**: Mark important files with the ❤️ heart icon
- **Quick filtering**: Click favorites count to view only favorited items
- **Playlist support**: Play all favorites with one click
- **Persistent storage**: Favorites are saved to SQLite database

### Security
- **Delete protection**: Secret code-based authorization for file deletion
- **Cookie-based sessions**: 30-day authorization cookies
- **Backend validation**: All delete requests verified server-side
- **Easy toggle**: Enable/disable deletes via URL parameters

---

## 🚀 Installation

### Prerequisites
- Docker and Docker Compose
- A media collection to manage

### Quick Start

1. **Clone the repository**:
   ```bash
   git clone https://github.com/wayubi/xclusive-media-player.git
   cd xclusive-media-player
   ```

2. **Configure delete protection** (optional):
   ```bash
   # Create .env file in project root
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
   - Place media files in the `./volumes` folder
   - Or update the volume mount in `compose.yml` to point to your existing media directory

---

## ⚙️ Configuration

### Volume Mounts

The application uses the following directory structure:
```
./src              → Application source code
./volumes          → Your media files (customize this path)
./data/db          → SQLite databases for audit and favorites
```

To use your existing media library, edit `compose.yml`:
```yaml
volumes:
  - /path/to/your/media:/var/www/html/volumes
```

### URL Parameters

Customize the interface using URL parameters:

**Grid Layout**:
- `?columns=3` — Number of columns (1-6)
- `?rows=2` — Number of rows (1-6)

**Audio Settings**:
- `?muted=true` — Start with audio muted (default)
- `?muted=false` — Start with audio enabled

**Folder Navigation**:
- `?path[]=folder1&path[]=folder2` — Navigate to specific folder

**Delete Protection**:
- `?delete=your_secret_code` — Enable delete functionality
- `?delete=off` — Disable delete functionality

### Delete Protection Setup

1. **Create `.env` file** in project root:
   ```env
   DELETE_SECRET_CODE=my_secret_delete_code_2026
   ```

2. **Choose a strong secret code** that only you know

3. **Enable deletes** when needed:
   ```
   http://localhost:8050?delete=my_secret_delete_code_2026
   ```

4. **Disable deletes** after use:
   ```
   http://localhost:8050?delete=off
   ```

**Security Notes**:
- Cookie expires after 30 days
- Delete requests are validated on both frontend and backend
- Secret code is stored outside the web root
- No delete buttons appear without authorization

---

## 📖 Usage Guide

### Basic Navigation

**Folder Browser**:
- Click the folder dropdown to see subfolders
- Each folder shows:
  - Audit status icon (✅/⚠️/🆕)
  - Folder name
  - Total file count in parentheses

**Grid Controls**:
- `◀` Previous page
- `▶` Next page
- Mouse wheel or touch gestures to navigate

**Playback Buttons**:
- `▶️` Play all files from current position
- `🔀` Shuffle play all files
- `❤️` Play all favorited files
- `🔇/🔊` Toggle mute

### File Operations

**Individual Files**:
- Click file to open fullscreen player
- Hover to reveal control buttons:
  - `📋` Audit this file
  - `⛶` Open in fullscreen
  - `🗙` Select/Delete (when enabled)
  - `🔇/🔊` Mute/Unmute
- Click `❤️` heart to favorite/unfavorite

**Fullscreen Mode**:
- Arrow keys or scroll to navigate
- Double-click or Escape to exit
- Delete key to remove file (when enabled)

**Batch Auditing**:
- Single-click `📋` button: Audit visible files
- Double-click `📋` button: Audit entire folder

### Search & Filtering

**Search**:
- Press `/` to open search
- Type filename or folder name
- Press Enter to filter
- Press Escape to close

**Filter Options**:
- Click `⚠️ X` (unaudited count) to show only unaudited files
- Click `❤️ X` (favorites count) to show only favorites
- Search bar filters by name or path

### Audit System

The audit system helps you track which files you've reviewed:

1. **Mark as audited**: Click the `📋` button on any file
2. **View status**: 
   - Yellow border = Unaudited
   - No border = Audited
3. **Track progress**: See counts in top bar (e.g., "✅ 150 • ⚠️ 50")
4. **Filter unaudited**: Click the unaudited count to focus on new files

### Favorites

1. **Add favorite**: Click the `🤍` heart icon (turns to `❤️`)
2. **Remove favorite**: Click the `❤️` icon again
3. **View all favorites**: Click the favorites count
4. **Play favorites**: Click the `❤️` button in the toolbar

---

## 🐳 Docker Architecture

The application runs in three containers:

1. **nginx** - Web server and reverse proxy (port 8050)
2. **php-fpm** - Handles web requests and file operations
3. **php-cli** - API backend for metadata and operations

### Container Details

```yaml
services:
  nginx:
    - Serves static assets (CSS, JS, images)
    - Routes PHP requests to php-fpm
    - Proxies API calls to php-cli
    
  php-fpm:
    - Generates dynamic pages
    - Handles folder navigation
    - Creates audio thumbnails
    
  php-cli:
    - Metadata extraction (ffprobe)
    - File operations (delete, move)
    - SQLite database management
```

### Accessing Logs

```bash
# View all logs
docker-compose logs -f

# View specific service
docker-compose logs -f nginx
docker-compose logs -f php-fpm
docker-compose logs -f php-cli
```

### Rebuilding Containers

```bash
# Rebuild after changes
docker-compose down
docker-compose build --no-cache
docker-compose up -d
```

---

## 🗂️ File Structure

```
xclusive-media-player/
├── .env                    # Delete protection secret (create this)
├── compose.yml             # Docker Compose configuration
├── src/                    # Application source code
│   ├── index.php          # Main entry point
│   ├── api.php            # API endpoint
│   ├── post-handler.php   # Request proxy
│   ├── lib/               # PHP libraries
│   │   ├── AuditDatabase.php
│   │   ├── FavoritesDatabase.php
│   │   └── audioCovers.php
│   └── assets/
│       ├── css/
│       │   └── app.css    # Styles
│       └── js/            # JavaScript modules
│           ├── main.js
│           ├── state.js
│           ├── grid.js
│           ├── audit.js
│           ├── favorites.js
│           ├── fullscreen.js
│           └── ...
├── volumes/               # Your media files (customize)
├── data/
│   └── db/               # SQLite databases
│       ├── audit.db
│       └── favorites.db
└── docker/               # Docker configurations
    ├── nginx/
    ├── php-fpm/
    └── php-cli/
```

---

## 🔧 Troubleshooting

### Media files not appearing
- Check volume mount in `compose.yml`
- Ensure files are in supported formats
- Check file permissions (should be readable by www-data)

### Thumbnails not generating
- Verify ffmpeg is installed in containers
- Check `./cache` directory permissions
- Review php-cli logs: `docker-compose logs php-cli`

### Delete not working
- Ensure `.env` file exists with correct secret code
- Verify you've enabled deletes via URL parameter
- Check browser cookies are enabled
- Review api.php logs for 403 errors

### Database issues
- Check `./data/db` directory exists and is writable
- Restart containers: `docker-compose restart`
- Examine SQLite files: `sqlite3 ./data/db/audit.db`

### Performance with large libraries
- Increase PHP memory limit in Docker configs
- Consider pagination adjustments in source code
- Use SSD storage for database files

---

## 🎨 Customization

### Changing Colors/Theme

Edit `src/assets/css/app.css` and modify the CSS variables:

```css
:root {
  --accent: #a855f7;           /* Purple accent */
  --accent-secondary: #ec4899; /* Pink secondary */
  --bg: linear-gradient(...);  /* Background gradient */
}
```

### Adding File Types

Edit `src/assets/js/utils.js` to add supported extensions:

```javascript
export function isVideoFile(filename) {
  const ext = getFileExtension(filename);
  return ['mp4', 'webm', 'mkv', 'your_format'].includes(ext);
}
```

### Adjusting Grid Defaults

Edit `src/index.php`:

```php
$selected_columns = $is_mobile ? 1 : max(1, min(6, (int)($_GET['columns'] ?? 3)));
$selected_rows    = $is_mobile ? 1 : max(1, min(6, (int)($_GET['rows'] ?? 2)));
```

---

## 🤝 Contributing

Contributions are welcome! Here's how to get started:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

### Development Setup

```bash
# Clone your fork
git clone https://github.com/your-username/xclusive-media-player.git

# Start development environment
docker-compose up

# Make changes to files in ./src
# Changes are reflected immediately (no rebuild needed)
```

---

## 📝 License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.

---

## 🙏 Acknowledgments

- Built with PHP, JavaScript (ES6 modules), and modern CSS
- Uses ffmpeg/ffprobe for metadata extraction
- Icons and emoji for visual indicators
- Inspired by modern media management needs

---

## 📧 Support

For issues, questions, or suggestions:
- Open an issue on GitHub
- Check existing issues for solutions
- Review troubleshooting section above

---

**Enjoy managing your media collection with Xclusive Media Player!** 🎵🎬