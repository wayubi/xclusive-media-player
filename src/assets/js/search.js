// search.js - Search and filter functionality
import { state } from './state.js?v=1782192653';
import { renderGrid } from './grid.js?v=1781077182';
import { isTerminalActive } from './terminal.js?v=1781077182';

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

export function showPathBrowser() {
  const overlay = document.getElementById('path-overlay');
  const input = document.getElementById('path-input');
  const pathDisplay = document.getElementById('path-display');
  if (!overlay || !input || !pathDisplay) return;
  
  const currentPath = state.currentPath || '/';
  pathDisplay.textContent = currentPath;
  input.value = currentPath;
  
  overlay.style.display = 'flex';
  input.focus();
  input.select();
}

export function closePathBrowser() {
  const overlay = document.getElementById('path-overlay');
  const input = document.getElementById('path-input');
  
  if (overlay) overlay.style.display = 'none';
  if (input) input.blur();
}

export function applyPath(path) {
  if (!path) {
    closePathBrowser();
    return;
  }
  
  let newPath = path.trim();
  if (!newPath.startsWith('/')) {
    newPath = '/' + newPath;
  }
  if (!newPath.endsWith('/')) {
    newPath += '/';
  }
  
  if (newPath === (state.currentPath || '/')) {
    closePathBrowser();
    return;
  }
  
  // Use URL object to preserve existing parameters
  const url = new URL(window.location.href);
  url.searchParams.delete('path[]');
  
  const cleanPath = newPath.replace('/volumes/', '').replace('/volumes', '');
  if (cleanPath) {
    const pathParts = cleanPath.split('/').filter(p => p);
    pathParts.forEach(part => {
      url.searchParams.append('path[]', part);
    });
  }
  
  // Cache buster to prevent browser caching
  url.searchParams.set('t', Date.now().toString());
  
  closePathBrowser();
  window.location.href = url.toString();
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
  const pathOverlay = document.getElementById('path-overlay');
  const pathInput = document.getElementById('path-input');

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

  pathInput?.addEventListener('keydown', e => {
    if (e.key === 'Enter') {
      e.preventDefault();
      applyPath(pathInput.value);
    } else if (e.key === 'Escape') {
      e.preventDefault();
      closePathBrowser();
    }
  });

  document.getElementById('path-go')?.addEventListener('click', () => {
    const pathInput = document.getElementById('path-input');
    if (pathInput) applyPath(pathInput.value);
  });

  document.addEventListener('keydown', e => {
    // Don't process if terminal is active
    if (isTerminalActive()) return;
    
    if (document.activeElement.matches('input, textarea')) {
      if (e.key === 'Escape') {
        closeSearch();
        closePathBrowser();
      }
      return;
    }
    
    // "s" opens search
    if (e.key.toLowerCase() === 's') {
      e.preventDefault();
      showSearch();
    }
    // "/" opens path browser
    else if (e.key === '/') {
      e.preventDefault();
      showPathBrowser();
    } else if (e.key === 'Escape' && (overlay.style.display === 'flex' || pathOverlay?.style.display === 'flex')) {
      closeSearch();
      closePathBrowser();
    }
  });
}