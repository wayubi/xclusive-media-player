// search.js - Search and filter functionality
import { state } from './state.js';
import { renderGrid } from './grid.js';
import { isTerminalActive } from './terminal.js';

let searchableItems = [];

export function initSearch() {
  searchableItems = state.allFilesWithPaths.map(fullPath => {
    const webPath = state.webRoot + '/' + fullPath
      .replace(state.rootDirAbs + '/', '')
      .split('/')
      .map(encodeURIComponent)
      .join('/');

    const parts = fullPath.split('/');
    const filename = parts[parts.length - 1];
    const folderPath = parts.slice(0, -1).join('/');
    const folderNames = parts.slice(1, -1);

    return {
      webPath: webPath,
      filename: filename.toLowerCase(),
      folderNames: folderNames.map(n => n.toLowerCase()),
      fullFolderPath: folderPath.toLowerCase()
    };
  });
}

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
      const item = searchableItems.find(i => i.webPath === file);
      if (!item) return false;
      return item.filename.includes(term) || 
             item.folderNames.some(folder => folder.includes(term));
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