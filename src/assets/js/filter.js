// filter.js - Filtering functionality
import { state } from './state.js';
import { renderGrid } from './grid.js';

export function toggleUnauditedFilter() {
  state.unauditedFilter = !state.unauditedFilter;
  
  if (state.unauditedFilter) {
    // Filter to show only unaudited files
    state.allVideos = state.originalVideos.filter(file => {
      return !state.isFileAudited(file);
    });
    state.currentSearch = ''; // Clear any existing search
  } else {
    // Restore all files
    state.allVideos = [...state.originalVideos];
  }
  
  state.startIndex = 0;
  renderGrid();
  updateFileCount();
}

function updateFileCount() {
  const countElem = document.getElementById('file-count');
  if (!countElem) return;
  
  if (state.unauditedFilter) {
    countElem.innerText = `Unaudited: ${state.startIndex + 1} / ${state.allVideos.length} (of ${state.originalVideos.length})`;
  } else if (state.currentSearch) {
    countElem.innerText = `Filtered: ${state.startIndex + 1} / ${state.allVideos.length} (of ${state.originalVideos.length})`;
  } else {
    countElem.innerText = `${state.startIndex + 1} / ${state.allVideos.length}`;
  }
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