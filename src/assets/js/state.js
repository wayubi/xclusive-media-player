// state.js - Centralized state management
export const state = {
  // Core data
  allVideos: [],
  originalVideos: [],
  allFilesWithPaths: [],
  audioThumbs: {},
  auditStatusMap: {},
  
  // Configuration
  muted: false,
  totalCells: 0,
  selectedColumns: 0,
  webRoot: '',
  rootDirAbs: '',
  currentPath: '',
  
  // UI state
  startIndex: 0,
  lastFullscreen: { file: null, time: 0 },
  fullscreenMode: 'tile',
  currentSearch: '',
  coverEnabled: true,
  unauditedFilter: false,
  
  // Initialize state from bootstrap data
  init(config) {
    this.allVideos = [...config.allVideos];
    this.originalVideos = [...config.allVideos];
    this.allFilesWithPaths = config.allFilesWithPaths;
    this.audioThumbs = config.audioThumbs;
    this.auditStatusMap = config.auditStatusMap;
    this.muted = config.muted;
    this.totalCells = config.totalCells;
    this.selectedColumns = config.selectedColumns;
    this.webRoot = config.webRoot;
    this.rootDirAbs = config.rootDirAbs;
    this.currentPath = config.currentPath;
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
  }
};