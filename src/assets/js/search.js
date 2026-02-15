// search.js - Search and filter functionality
import { state } from './state.js';
import { renderGrid } from './grid.js';
import { isTerminalActive } from './terminal.js';

export function applySearch(term) {
  term = (term || '').trim().toLowerCase();
  
  if (!term) {
    state.setFilter(null);
    renderGrid();
    closeSearch();
    return;
  }

  state.setFilter('search', {
    searchTerm: term,
    filterFn: file => {
      // file is like '/volumes/videos/movie.mp4'
      const filename = file.split('/').pop().toLowerCase();
      const folderPath = file.substring(0, file.lastIndexOf('/')).toLowerCase();
      
      return filename.includes(term) || folderPath.includes(term);
    }
  });
  
  renderGrid();
  closeSearch();
}

export function showSearch() {
  const overlay = document.getElementById('search-overlay');
  const input = document.getElementById('search-input');
  if (!overlay || !input) return;
  
  overlay.style.display = 'flex';
  input.value = state.currentSearch;
  input.focus();
  input.select();
}

export function closeSearch() {
  const overlay = document.getElementById('search-overlay');
  const input = document.getElementById('search-input');
  
  if (overlay) overlay.style.display = 'none';
  if (input) input.blur();
}

export function setupSearchListeners() {
  const overlay = document.getElementById('search-overlay');
  const input = document.getElementById('search-input');
  const clearBtn = document.getElementById('search-clear');

  if (!input || !overlay) return;

  clearBtn?.addEventListener('click', () => {
    input.value = '';
    applySearch('');
  });

  input.addEventListener('keydown', e => {
    if (e.key === 'Enter') {
      e.preventDefault();
      applySearch(input.value);
    } else if (e.key === 'Escape') {
      e.preventDefault();
      closeSearch();
    }
  });

  document.addEventListener('keydown', e => {
    // Don't process if terminal is active
    if (isTerminalActive()) return;
    
    if (document.activeElement.matches('input, textarea')) {
      if (e.key === 'Escape') closeSearch();
      return;
    }
    
    if (e.key === '/') {
      e.preventDefault();
      showSearch();
    } else if (e.key === 'Escape' && overlay.style.display === 'flex') {
      closeSearch();
    }
  });
}