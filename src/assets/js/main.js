// main.js - Entry point and initialization
import { state } from './state.js';
import { renderGrid } from './grid.js';
import { setupEventListeners } from './events.js';
import { setVhUnit } from './utils.js';
import { initTerminal } from './terminal.js';

// Bootstrap from PHP — deferred after paint
document.addEventListener('DOMContentLoaded', () => {
  requestAnimationFrame(() => {
    const cfg = window.APP;
    state.init(cfg);

    setVhUnit();
    setupEventListeners();
    renderGrid();

    if (state.permissions.includes('terminal')) {
      initTerminal();
    }

    if (state.permissions.includes('favorites')) {
      import('./favorites.js').then(module => {
        module.updateFavoritesDisplay();
        module.setupFavoritesFilter();
      });
    }

    import('./filter.js').then(module => {
      module.setupOptimizationFilter();
    });
  });
});

window.addEventListener('resize', setVhUnit);
window.addEventListener('orientationchange', setVhUnit);