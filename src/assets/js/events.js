// events.js - Event listeners and handlers
import { state } from './state.js';
import { nextGrid, prevGrid, renderGrid } from './grid.js';
import { playAll, shufflePlay } from './fullscreen.js';
import { initSearch, setupSearchListeners } from './search.js';
import { runAudit, auditCurrentView } from './audit.js';
import { setupUnauditedFilter } from './filter.js';
import { toggleTileSelection, confirmDelete, selectAllFiles, clearAllSelections, isSelectAllMode, getSelectedTileCount, syncMuteIcons } from './ui.js';
import { toggleTerminal, isTerminalActive, hideTerminal } from './terminal.js';

let scrollDebounce = false;

let overlayIdleTimer = null;
const OVERLAY_IDLE_DELAY = 500;
const MOVEMENT_THRESHOLD = 10;

let lastMouseX = 0;
let lastMouseY = 0;
let isFirstMouseMove = true;

function handleMouseMove(e) {
  if (isFirstMouseMove) {
    isFirstMouseMove = false;
    lastMouseX = e.clientX;
    lastMouseY = e.clientY;
    return;
  }
  
  const dx = e.clientX - lastMouseX;
  const dy = e.clientY - lastMouseY;
  const distance = Math.sqrt(dx * dx + dy * dy);
  
  if (distance < MOVEMENT_THRESHOLD) {
    return;
  }
  
  lastMouseX = e.clientX;
  lastMouseY = e.clientY;
  
  if (overlayIdleTimer) {
    clearTimeout(overlayIdleTimer);
  }
  document.body.classList.remove('overlays-idle');
  overlayIdleTimer = setTimeout(() => {
    document.body.classList.add('overlays-idle');
  }, OVERLAY_IDLE_DELAY);
}

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
  
  // Overlay idle timeout - hide overlays after 3 seconds of no mouse movement
  document.addEventListener('mousemove', handleMouseMove);
  document.addEventListener('mouseenter', handleMouseMove);
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
  
  // Arrow key navigation
  document.addEventListener('keydown', (e) => {
    // Don't process if terminal is active
    if (isTerminalActive()) return;
    
    // Don't process if fullscreen is active
    if (document.querySelector('div[style*="z-index:9999"]')) return;
    
    // Don't process if search overlay is open
    const searchOverlay = document.getElementById('search-overlay');
    if (searchOverlay && searchOverlay.style.display === 'flex') return;
    
    // Don't process if delete modal is open
    if (document.getElementById('scary-delete-modal')) return;
    
    switch(e.key) {
      case 'ArrowRight':
        e.preventDefault();
        nextGrid();
        break;
      case 'ArrowLeft':
        e.preventDefault();
        prevGrid();
        break;
    }
  });
  
  // Options form wheel
  const optionsForm = document.getElementById('options-form');
  if (optionsForm) addWheelListener(optionsForm);
  
  // Mouse hover unmuting
  setupHoverUnmuting(grid);
}

function setupHoverUnmuting(grid) {
  // Use mousemove to continuously unmute whatever container the mouse is over
  grid.addEventListener('mousemove', (e) => {
    // Only work if not globally muted
    if (state.muted) return;
    
    const container = e.target.closest('.video-container');
    if (!container) return;
    
    const mediaEl = container.querySelector('video, audio');
    if (!mediaEl) return;
    
    // If this media is already unmuted, no need to do anything
    if (!mediaEl.muted) return;
    
    // Mute all media
    document.querySelectorAll('#grid video, #grid audio').forEach(m => m.muted = true);
    
    // Unmute the hovered one
    mediaEl.muted = false;
    mediaEl.play().catch(() => {});
    
    // Update mute icons on central overlay
    syncMuteIcons();
  });
}

function addWheelListener(element) {
  element.addEventListener('wheel', (e) => {
    e.preventDefault();
    if (scrollDebounce) return;
    
    // Check if CTRL is pressed for seeking
    if (e.ctrlKey) {
      seekMediaUnderCursor(e);
      return;
    }
    
    scrollDebounce = true;
    setTimeout(() => scrollDebounce = false, 200);
    e.deltaY < 0 ? prevGrid() : nextGrid();
  }, { passive: false });
}

function seekMediaUnderCursor(e) {
  // Find the element under the mouse cursor
  const element = document.elementFromPoint(e.clientX, e.clientY);
  if (!element) return;
  
  // Find the video container
  const container = element.closest('.video-container');
  if (!container) return;
  
  // Find video or audio element within the container
  const mediaEl = container.querySelector('video, audio');
  if (!mediaEl || !mediaEl.duration) return;
  
  // Calculate seek direction and amount
  const direction = e.deltaY < 0 ? -1 : 1;
  const seekAmount = direction * state.seekStepSeconds;
  
  // Clamp the new time between 0 and duration
  const newTime = Math.max(0, Math.min(mediaEl.duration, mediaEl.currentTime + seekAmount));
  mediaEl.currentTime = newTime;
  
  // Show visual feedback
  showSeekFeedback(container, direction, state.seekStepSeconds);
}

function showSeekFeedback(container, direction, seconds) {
  // Remove any existing feedback
  const existingFeedback = container.querySelector('.seek-feedback');
  if (existingFeedback) {
    existingFeedback.remove();
  }
  
  // Create feedback element (styles are defined in CSS)
  const feedback = document.createElement('div');
  feedback.className = 'seek-feedback';
  const arrow = direction > 0 ? '⏩' : '⏪';
  const sign = direction > 0 ? '+' : '-';
  feedback.textContent = `${arrow} ${sign}${seconds}s`;
  
  container.appendChild(feedback);
  
  // Fade out and remove after 1 second
  setTimeout(() => {
    feedback.style.opacity = '0';
    setTimeout(() => feedback.remove(), 400);
  }, 1000);
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
    // Don't process if terminal is active
    if (isTerminalActive()) return;
    
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
    // If terminal is active, only handle ESC to close it
    if (isTerminalActive()) {
      if (e.key === 'Escape') {
        e.preventDefault();
        e.stopPropagation();
        hideTerminal();
      }
      return;
    }
    
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
    } else if (key === 'Delete' || key.toLowerCase() === 'd') {
      // DEL key - two phase delete
      // Skip if in fullscreen mode (fullscreen.js handles delete for single video)
      if (isFullscreenActive) return;
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
    } else if (key.toLowerCase() === 'a') {
      // 'a' key - audit current view
      e.preventDefault();
      auditCurrentView();
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
    
    // '~' key (backtick/tilde) - toggle terminal (transparent mode)
    if (key === '`' || key === '~') {
      // Only block if fullscreen is active (delete mode is fine)
      if (isFullscreenActive) return;
      e.preventDefault();
      // Pass the current web path (relative to /volumes) with transparent mode
      toggleTerminal(state.currentPath || '', 'transparent');
      return;
    }
    
    // 'b' key - toggle terminal (privacy mode / boss screen style)
    if (key.toLowerCase() === 'b') {
      // Only block if fullscreen is active (delete mode is fine)
      if (isFullscreenActive) return;
      e.preventDefault();
      // Pass the current web path (relative to /volumes) with privacy mode
      toggleTerminal(state.currentPath || '', 'privacy');
      return;
    }
  });
}

// Terminal interface is now handled by terminal.js module