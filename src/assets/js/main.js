// main.js - Entry point and initialization
import { state } from './state.js';
import { renderGrid } from './grid.js';
import { setupEventListeners } from './events.js';
import { setVhUnit } from './utils.js';

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
  currentPath
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
  currentPath
});

// Initialize
document.addEventListener('DOMContentLoaded', () => {
  setVhUnit();
  setupEventListeners();
  renderGrid();
  
  // Initialize favorites display
  import('./favorites.js').then(module => {
    module.updateFavoritesDisplay();
    module.setupFavoritesFilter();
  });
});

window.addEventListener('resize', setVhUnit);
window.addEventListener('orientationchange', setVhUnit);