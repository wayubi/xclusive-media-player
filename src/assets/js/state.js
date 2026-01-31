// state.js - Centralized state management
export const state = {
  // Core data
  allVideos: [],
  originalVideos: [],
  allFilesWithPaths: [],
  webToFsPathMap: {}, // NEW: Map web paths to filesystem paths
  audioThumbs: {},
  auditStatusMap: {},
  favoritesMap: {}, // NEW: Track favorited files
  
  // Configuration
  muted: false,
  totalCells: 0,
  selectedColumns: 0,
  webRoot: '',
  rootDirAbs: '',
  currentPath: '',
  deleteEnabled: false, // NEW: Track if deletes are enabled
  
  // UI state
  startIndex: 0,
  lastFullscreen: { file: null, time: 0 },
  fullscreenMode: 'tile',
  currentSearch: '',
  coverEnabled: true,
  unauditedFilter: false,
  favoritesFilter: false, // NEW: Track favorites filter state
  
  // Initialize state from bootstrap data
  init(config) {
    this.allVideos = [...config.allVideos];
    this.originalVideos = [...config.allVideos];
    this.allFilesWithPaths = config.allFilesWithPaths;
    
    // Create web-to-filesystem path mapping
    this.webToFsPathMap = {};
    config.allVideos.forEach((webPath, index) => {
      this.webToFsPathMap[webPath] = config.allFilesWithPaths[index];
    });
    
    this.audioThumbs = config.audioThumbs;
    this.auditStatusMap = config.auditStatusMap;
    this.favoritesMap = config.favoritesMap || {};
    this.muted = config.muted;
    this.totalCells = config.totalCells;
    this.selectedColumns = config.selectedColumns;
    this.webRoot = config.webRoot;
    this.rootDirAbs = config.rootDirAbs;
    this.currentPath = config.currentPath;
    this.deleteEnabled = config.deleteEnabled || false;
  },
  
  // Helper methods
  isFileVisible(file) {
    const end = Math.min(this.startIndex + this.totalCells, this.allVideos.length);
    return this.allVideos.slice(this.startIndex, end).includes(file);
  },
  
  getVisibleFiles() {
    return this.allVideos.slice(
      this.startIndex, 
      Math.min(this.startIndex + this.totalCells, this.allVideos.length)
    );
  },
  
  isFileAudited(file) {
    // Use the audit status map - file is the web path
    return this.auditStatusMap[file] === true;
  },
  
  // Favorites methods (database-backed)
  async toggleFavorite(file) {
    const fsPath = this.webToFsPathMap[file];
    
    if (!fsPath) {
      console.error('Could not find filesystem path for:', file);
      return null;
    }
    
    try {
      const response = await fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'toggle_favorite',
          file: file
        })
      });
      
      const data = await response.json();
      
      if (data.error) {
        console.error('Toggle favorite error:', data.error);
        return null;
      }
      
      // Update local state
      this.favoritesMap[file] = data.favorited;
      
      return data.favorited;
    } catch (err) {
      console.error('Failed to toggle favorite:', err);
      return null;
    }
  },
  
  isFavorited(file) {
    return this.favoritesMap[file] === true;
  },
  
  getFavoriteFiles() {
    return this.originalVideos.filter(file => this.isFavorited(file));
  },
  
  getFavoritesCount() {
    return Object.keys(this.favoritesMap).filter(file => 
      this.favoritesMap[file] && this.originalVideos.includes(file)
    ).length;
  }
};