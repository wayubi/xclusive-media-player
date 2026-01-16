// state.js - Centralized state management
export const state = {
  // Core data
  allVideos: [],
  originalVideos: [],
  allFilesWithPaths: [],
  audioThumbs: {},
  
  // Configuration
  muted: false,
  totalCells: 0,
  selectedColumns: 0,
  webRoot: '',
  rootDirAbs: '',
  auditPath: '',
  
  // UI state
  startIndex: 0,
  lastFullscreen: { file: null, time: 0 },
  fullscreenMode: 'tile',
  currentSearch: '',
  coverEnabled: true,
  
  // Initialize state from bootstrap data
  init(config) {
    this.allVideos = [...config.allVideos];
    this.originalVideos = [...config.allVideos];
    this.allFilesWithPaths = config.allFilesWithPaths;
    this.audioThumbs = config.audioThumbs;
    this.muted = config.muted;
    this.totalCells = config.totalCells;
    this.selectedColumns = config.selectedColumns;
    this.webRoot = config.webRoot;
    this.rootDirAbs = config.rootDirAbs;
    this.auditPath = config.auditPath;
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
  }
};