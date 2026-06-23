// filter.js - Filtering functionality
import { state } from './state.js';
import { renderGrid, updateFileCount } from './grid.js';

export function toggleUnauditedFilter() {
  state.setFilter('unaudited');
  renderGrid();
  updateFileCount();
}

export function setupUnauditedFilter() {
  const el = document.getElementById('unaudited-count');
  if (el) {
    const clone = el.cloneNode(true);
    el.parentNode.replaceChild(clone, el);
    clone.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      toggleUnauditedFilter();
    });
  }
}

export function toggleOptimizationFilter() {
  state.setFilter('optimization');
  renderGrid();
  updateFileCount();
}

export function setupOptimizationFilter() {
  const optimizationCount = document.getElementById('optimization-count');
  const optimizationText = document.getElementById('optimization-text');
  
  const el = optimizationCount || optimizationText;
  if (el) {
    const clone = el.cloneNode(true);
    el.parentNode.replaceChild(clone, el);
    clone.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      toggleOptimizationFilter();
    });
  }
}