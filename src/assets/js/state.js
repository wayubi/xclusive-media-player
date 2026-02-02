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
  
  // Audit context tracking to prevent race conditions
  currentAuditContext: null,
  
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
  },
  
  // Delete a video from all state structures
  deleteVideo(file) {
    let removed = false;
    
    // Remove from arrays
    const allIdx = this.allVideos.indexOf(file);
    if (allIdx !== -1) {
      this.allVideos.splice(allIdx, 1);
      removed = true;
    }
    
    const origIdx = this.originalVideos.indexOf(file);
    if (origIdx !== -1) {
      this.originalVideos.splice(origIdx, 1);
    }
    
    // Remove from all maps
    delete this.auditStatusMap[file];
    delete this.webToFsPathMap[file];
    delete this.favoritesMap[file];
    
    return removed;
  },
  
  // Unified filter method for all filter types
  setFilter(type, options = {}) {
    const { searchTerm, filterFn } = options;
    
    switch (type) {
      case 'unaudited':
        this.unauditedFilter = !this.unauditedFilter;
        this.favoritesFilter = false;
        this.currentSearch = '';
        
        if (this.unauditedFilter) {
          this.allVideos = this.originalVideos.filter(file => !this.isFileAudited(file));
        } else {
          this.allVideos = [...this.originalVideos];
        }
        break;
        
      case 'favorites':
        this.favoritesFilter = !this.favoritesFilter;
        this.unauditedFilter = false;
        this.currentSearch = '';
        
        if (this.favoritesFilter) {
          this.allVideos = this.getFavoriteFiles();
        } else {
          this.allVideos = [...this.originalVideos];
        }
        break;
        
      case 'search':
        this.currentSearch = searchTerm || '';
        this.unauditedFilter = false;
        
        if (filterFn && this.currentSearch) {
          this.allVideos = this.originalVideos.filter(filterFn);
        } else {
          this.allVideos = [...this.originalVideos];
        }
        break;
        
      case null:
      case 'clear':
        // Clear all filters
        this.unauditedFilter = false;
        this.favoritesFilter = false;
        this.currentSearch = '';
        this.allVideos = [...this.originalVideos];
        break;
    }
    
    this.startIndex = 0;
    return this.allVideos.length;
  },
  
  // Audit context tracking methods to prevent race conditions
  // when navigating during pending audits
  startAuditContext() {
    this.currentAuditContext = {
      startIndex: this.startIndex,
      allVideosSnapshot: [...this.allVideos],
      currentPath: this.currentPath,
      timestamp: Date.now()
    };
    return this.currentAuditContext;
  },
  
  isValidAuditContext(context) {
    if (!context) return false;
    
    // Check if we're still in the same view (same path and same visible files)
    const samePath = this.currentPath === context.currentPath;
    const sameStartIndex = this.startIndex === context.startIndex;
    const sameVisibleFiles = this.allVideos.length === context.allVideosSnapshot.length &&
      this.allVideos.every((file, idx) => file === context.allVideosSnapshot[idx]);
    
    return samePath && sameStartIndex && sameVisibleFiles;
  },
  
  clearAuditContext() {
    this.currentAuditContext = null;
  }
};