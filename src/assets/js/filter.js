// filter.js - Filtering functionality
import { state } from './state.js?v=1781077253';
import { renderGrid, updateFileCount } from './grid.js?v=1781077182';

export function toggleUnauditedFilter() {
  state.setFilter('unaudited');
  renderGrid();
  updateFileCount();
}

export function setupUnauditedFilter() {
  const unauditedCount = document.getElementById('unaudited-count');
  if (unauditedCount) {
    unauditedCount.addEventListener('click', (e) => {
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
  
  const clickTarget = optimizationCount || optimizationText;
  if (clickTarget) {
    clickTarget.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      toggleOptimizationFilter();
    });
  }
}