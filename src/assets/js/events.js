// events.js - Event listeners and handlers
import { state } from './state.js';
import { nextGrid, prevGrid, renderGrid } from './grid.js';
import { playAll, shufflePlay } from './fullscreen.js';
import { initSearch, setupSearchListeners } from './search.js';
import { runAudit } from './audit.js';
import { setupUnauditedFilter } from './filter.js';

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