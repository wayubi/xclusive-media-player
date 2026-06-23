// events.js - Event listeners and handlers
import { state } from './state.js';
import { nextGrid, prevGrid, renderGrid } from './grid.js';
import { playAll, shufflePlay } from './fullscreen.js';
import { setupSearchListeners } from './search.js';
import { runAudit, auditCurrentView } from './audit.js';
import { setupUnauditedFilter } from './filter.js';
import { toggleTileSelection, confirmDelete, selectAllFiles, clearAllSelections, isSelectAllMode, getSelectedTileCount, syncMuteIcons, selectAllVisibleTiles, areAllVisibleTilesSelected } from './ui.js';
import { toggleTerminal, isTerminalActive, hideTerminal } from './terminal.js';

let scrollDebounce = false;

let overlayIdleTimer = null;
const OVERLAY_IDLE_DELAY = 1000;
const MOVEMENT_THRESHOLD_SQ = 100;

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
  if (dx * dx + dy * dy < MOVEMENT_THRESHOLD_SQ) return;
  
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
  document.addEventListener('mousemove', handleMouseMove, { passive: true });
  document.addEventListener('mouseenter', handleMouseMove, { passive: true });
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
    
    // Don't process if fullscreen player is active
    if (document.body.classList.contains('fullscreen-active')) return;
    
    // Don't process if search overlay is open
    const searchOverlay = document.getElementById('search-overlay');
    if (searchOverlay && searchOverlay.style.display === 'flex') return;
    
    // Don't process if delete modal is open
    if (document.getElementById('scary-delete-modal')) return;
    
    // Don't process if user is typing in an input field
    const activeElement = document.activeElement;
    if (activeElement.tagName === 'INPUT' || 
        activeElement.tagName === 'TEXTAREA' || 
        activeElement.tagName === 'SELECT') {
      return;
    }
    
    // Don't process if share modal is open
    if (document.getElementById('share-modal')) return;
    
    // Note: ArrowLeft/ArrowRight are NOT intercepted here
    // This allows D-pad navigation between menu buttons and focus movement
    // Use the ◀/▶ buttons or other navigation methods for page scrolling
  });
  
  // Options form wheel
  const optionsForm = document.getElementById('options-form');
  if (optionsForm) addWheelListener(optionsForm);
  
  // Mouse hover unmuting
  setupHoverUnmuting(grid);
}

let _lastUnmutedEl = null;
function setupHoverUnmuting(grid) {
  grid.addEventListener('mousemove', (e) => {
    if (state.muted) return;
    
    const container = e.target.closest('.video-container');
    if (!container) return;
    
    const mediaEl = container.querySelector('video, audio');
    if (!mediaEl || !mediaEl.muted) return;
    
    // Mute the previously-unmuted element instead of scanning all
    if (_lastUnmutedEl && _lastUnmutedEl !== mediaEl) {
      _lastUnmutedEl.muted = true;
    }
    
    mediaEl.muted = false;
    _lastUnmutedEl = mediaEl;
    mediaEl.play().catch(() => {});
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
  
  window.playFavorites = () => {
    if (!state.permissions.includes('favorites')) return;
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
  const bodyClassList = document.body.classList;
  document.addEventListener('keydown', (e) => {
    if (isTerminalActive()) {
      if (e.key === 'Escape') {
        e.preventDefault();
        e.stopPropagation();
        hideTerminal();
      }
      return;
    }

    const key = e.key;
    const isFullscreenActive = bodyClassList.contains('fullscreen-active');
    const tag = document.activeElement?.tagName;

    // Quick bail for non-handled keys
    const isNumKey = (key >= '1' && key <= '9') || key === '0';
    const isActionKey = key === 'Escape' || key === 'Delete' || key.toLowerCase() === 'd' ||
      key.toLowerCase() === 'a' || key === '.' || key === '`' || key === '~' ||
      key.toLowerCase() === 'b';
    if (!isNumKey && !isActionKey) return;

    // Don't process if user is typing in an input field
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;

    // ESC key - clear delete selections (only when not in fullscreen)
    if (key === 'Escape') {
      if (isFullscreenActive) return;
      if (!state.permissions.includes('delete')) return;
      const selectedCount = getSelectedTileCount();
      if (selectedCount > 0 || isSelectAllMode()) {
        e.preventDefault();
        clearAllSelections();
      }
      return;
    }

    let tileIndex = -1;
    if (key >= '1' && key <= '9') {
      tileIndex = parseInt(key) - 1;
    } else if (key === '0') {
      tileIndex = 9;
    } else if (key === 'Delete' || key.toLowerCase() === 'd') {
      if (isFullscreenActive) return;
      if (!state.permissions.includes('delete')) return;
      e.preventDefault();
      const selectedCount = getSelectedTileCount();
      if (selectedCount === 0 && !isSelectAllMode()) {
        selectAllFiles();
      } else {
        confirmDelete();
      }
      return;
    } else if (key.toLowerCase() === 'a') {
      if (!state.permissions.includes('audit')) return;
      e.preventDefault();
      runAudit();
      return;
    } else if (key === '.') {
      e.preventDefault();
      if (!state.permissions.includes('delete')) return;
      if (areAllVisibleTilesSelected()) {
        clearAllSelections();
      } else {
        selectAllVisibleTiles();
      }
      return;
    }

    if (tileIndex !== -1) {
      if (!state.permissions.includes('delete')) return;
      const containers = document.querySelectorAll('#grid .video-container');
      if (tileIndex < containers.length) {
        e.preventDefault();
        toggleTileSelection(tileIndex);
      }
      return;
    }

    if (key === '`' || key === '~') {
      if (isFullscreenActive) return;
      if (!state.permissions.includes('terminal')) return;
      e.preventDefault();
      toggleTerminal(state.currentPath || '', 'transparent');
      return;
    }

    if (key.toLowerCase() === 'b') {
      if (isFullscreenActive) return;
      if (!state.permissions.includes('terminal')) return;
      e.preventDefault();
      toggleTerminal(state.currentPath || '', 'privacy');
      return;
    }
  });
}

// Terminal interface is now handled by terminal.js module