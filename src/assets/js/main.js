// main.js - Entry point and initialization
import { state } from './state.js?v=1782192653';
import { renderGrid, updateOptimizationDisplay } from './grid.js?v=1781077182';
import { setupEventListeners } from './events.js?v=1782192659';
import { setVhUnit } from './utils.js?v=1781077182';
import { initTerminal } from './terminal.js?v=1781077182';

// Bootstrap from PHP
const {
  allVideos,
  allFilesWithPaths,
  audioThumbs,
  auditStatusMap,
  favoritesMap,
  optimizationStatusMap,
  muted,
  totalCells,
  selectedColumns,
  webRoot,
  rootDirAbs,
  currentPath,
  permissions
} = window.APP;

// Initialize state
state.init({
  allVideos,
  allFilesWithPaths,
  audioThumbs,
  auditStatusMap,
  favoritesMap,
  optimizationStatusMap,
  muted,
  totalCells,
  selectedColumns,
  webRoot,
  rootDirAbs,
  currentPath,
  permissions
});

// Initialize
document.addEventListener('DOMContentLoaded', () => {
  setVhUnit();
  setupEventListeners();
  renderGrid();
  initTerminal();
  
  // Initialize favorites display
  import('./favorites.js?v=1781077182').then(module => {
    module.updateFavoritesDisplay();
    module.setupFavoritesFilter();
  });
  
  // Initialize optimization filter
  import('./filter.js?v=1781077182').then(module => {
    module.setupOptimizationFilter();
  });
  
  // Update optimization display with initial cached data
  updateOptimizationDisplay();
});

window.addEventListener('resize', setVhUnit);
window.addEventListener('orientationchange', setVhUnit);