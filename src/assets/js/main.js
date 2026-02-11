// main.js - Entry point and initialization
import { state } from './state.js';
import { renderGrid } from './grid.js';
import { setupEventListeners } from './events.js';
import { setVhUnit } from './utils.js';
import { initTerminal } from './terminal.js';

// Bootstrap from PHP
const {
  allVideos,
  allFilesWithPaths,
  audioThumbs,
  auditStatusMap,
  favoritesMap,
  muted,
  totalCells,
  selectedColumns,
  webRoot,
  rootDirAbs,
  currentPath,
  deleteEnabled
} = window.APP;

// Initialize state
state.init({
  allVideos,
  allFilesWithPaths,
  audioThumbs,
  auditStatusMap,
  favoritesMap,
  muted,
  totalCells,
  selectedColumns,
  webRoot,
  rootDirAbs,
  currentPath,
  deleteEnabled
});

// Initialize
document.addEventListener('DOMContentLoaded', () => {
  setVhUnit();
  setupEventListeners();
  renderGrid();
  initTerminal();
  
  // Initialize favorites display
  import('./favorites.js').then(module => {
    module.updateFavoritesDisplay();
    module.setupFavoritesFilter();
  });
});

window.addEventListener('resize', setVhUnit);
window.addEventListener('orientationchange', setVhUnit);