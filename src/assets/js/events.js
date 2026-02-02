// events.js - Event listeners and handlers
import { state } from './state.js';
import { nextGrid, prevGrid, renderGrid } from './grid.js';
import { playAll, shufflePlay } from './fullscreen.js';
import { initSearch, setupSearchListeners } from './search.js';
import { runAudit } from './audit.js';
import { setupUnauditedFilter } from './filter.js';
import { toggleTileSelection, confirmDelete, selectAllFiles, clearAllSelections, isSelectAllMode, getSelectedTileCount } from './ui.js';

let scrollDebounce = false;

export function setupEventListeners() {
  // Initialize search
  initSearch();
  setupSearchListeners();
  
  // Setup unaudited filter
  setupUnauditedFilter();
  
  // Grid navigation
  setupGridNavigation();
  
  // Global controls
  setupGlobalControls();
  
  // Object fit toggle
  setupObjectFitToggle();
  
  // Delete hotkeys (number keys 1-9, 0 and DEL key)
  setupDeleteHotkeys();
}

function setupGridNavigation() {
  const grid = document.getElementById('grid');
  
  // Wheel navigation
  addWheelListener(grid);
  
  // Touch navigation
  let touchStartY = 0;
  grid.addEventListener('touchstart', e => {
    if (e.touches.length === 1) touchStartY = e.touches[0].clientY;
  }, { passive: true });

  grid.addEventListener('touchend', e => {
    const delta = e.changedTouches[0].clientY - touchStartY;
    if (Math.abs(delta) > 50) delta < 0 ? nextGrid() : prevGrid();
  }, { passive: true });
  
  // Options form wheel
  const optionsForm = document.getElementById('options-form');
  if (optionsForm) addWheelListener(optionsForm);
}

function addWheelListener(element) {
  element.addEventListener('wheel', (e) => {
    e.preventDefault();
    if (scrollDebounce) return;
    scrollDebounce = true;
    setTimeout(() => scrollDebounce = false, 200);
    e.deltaY < 0 ? prevGrid() : nextGrid();
  }, { passive: false });
}

function setupGlobalControls() {
  // Expose functions to global scope for HTML onclick handlers
  window.nextGrid = nextGrid;
  window.prevGrid = prevGrid;
  window.playAll = playAll;
  window.shufflePlay = shufflePlay;
  window.toggleMute = toggleMute;
  window.runAudit = runAudit;
  
  // NEW: Add playFavorites
  window.playFavorites = () => {
    import('./favorites.js').then(module => {
      module.playFavorites();
    });
  };
}

function toggleMute() {
  state.muted = !state.muted;
  const btn = document.getElementById('mute-button');
  if (btn) btn.innerHTML = state.muted ? '🔇' : '🔊';
  if (state.muted) state.lastFullscreen = { file: null, time: 0 };
  renderGrid();
}

function setupObjectFitToggle() {
  // Create CSS class for object-fit toggle
  const style = document.createElement('style');
  style.textContent = `
    .no-object-fit img,
    .no-object-fit video {
      object-fit: contain;
    }
  `;
  document.head.appendChild(style);

  // Listen for "c" key
  document.addEventListener('keydown', (e) => {
    if (e.key.toLowerCase() === 'c') {
      toggleObjectFit();
    }
  });
}

function toggleObjectFit() {
  state.coverEnabled = !state.coverEnabled;
  const grid = document.getElementById('grid');
  
  if (state.coverEnabled) {
    grid.classList.remove('no-object-fit');
  } else {
    grid.classList.add('no-object-fit');
  }
}

function setupDeleteHotkeys() {
  document.addEventListener('keydown', (e) => {
    const key = e.key;
    
    // Check if we're in fullscreen mode by looking for the fullscreen container
    const isFullscreenActive = document.querySelector('div[style*="z-index:9999"]') !== null;
    
    // ESC key - clear delete selections (only when not in fullscreen)
    if (key === 'Escape') {
      if (isFullscreenActive) {
        // Let fullscreen.js handle ESC for exiting fullscreen
        return;
      }
      
      if (!state.deleteEnabled) return;
      
      const selectedCount = getSelectedTileCount();
      const inSelectAllMode = isSelectAllMode();
      
      // Only process if there are selections
      if (selectedCount > 0 || inSelectAllMode) {
        e.preventDefault();
        clearAllSelections();
      }
      return;
    }
    
    // Map keys to tile indices: 1->0, 2->1, ..., 9->8, 0->9
    let tileIndex = -1;
    if (key >= '1' && key <= '9') {
      tileIndex = parseInt(key) - 1; // 1 becomes 0, 9 becomes 8
    } else if (key === '0') {
      tileIndex = 9; // 0 becomes 9 (10th tile)
    } else if (key === 'Delete') {
      // DEL key - two phase delete
      if (!state.deleteEnabled) return;
      e.preventDefault();
      
      const selectedCount = getSelectedTileCount();
      
      if (selectedCount === 0 && !isSelectAllMode()) {
        // First Delete press with nothing selected - select all
        selectAllFiles();
      } else {
        // Second Delete press or items already selected - confirm and delete
        confirmDelete();
      }
      return;
    }
    
    // If we have a valid tile index, toggle its selection (only when delete is enabled)
    if (tileIndex !== -1) {
      if (!state.deleteEnabled) return;
      const containers = document.querySelectorAll('#grid .video-container');
      // Only process if the tile exists (e.g., ignore 7-0 on a 6-tile grid)
      if (tileIndex < containers.length) {
        e.preventDefault();
        toggleTileSelection(tileIndex);
      }
    }
  });
}