// favorites.js - Favorites functionality
import { state } from './state.js';
import { renderGrid, updateFileCount } from './grid.js';
import { startFullscreenPlayer } from './fullscreen.js';

export async function toggleFavorite(file, heartElement) {
  const isFavorited = await state.toggleFavorite(file);
  
  if (isFavorited !== null) {
    updateHeartIcon(heartElement, isFavorited);
    await updateFavoritesDisplay();
  }
}

export function updateHeartIcon(heartElement, isFavorited) {
  if (isFavorited) {
    heartElement.textContent = '❤️';
    heartElement.classList.add('favorited');
  } else {
    heartElement.textContent = '🤍';
    heartElement.classList.remove('favorited');
  }
}

export async function updateFavoritesDisplay() {
  if (!state.currentPath || state.currentPath === '') {
    return;
  }

  // Fetch updated count from server
  try {
    const response = await fetch('api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'get_favorites_count',
        folder_path: state.currentPath
      })
    });
    
    const data = await response.json();
    
    if (!data.error) {
      const favoritesCount = data.count;
      const favoritesText = document.getElementById('favorites-text');
      
      if (favoritesText) {
        favoritesText.innerHTML = `
          <span id="favorites-count" title="Click to filter favorites">
            ❤️ ${favoritesCount}
          </span>
        `;
        
        // Re-setup the favorites filter click handler
        setupFavoritesFilter();
      }
    }
  } catch (err) {
    console.error('Failed to update favorites display:', err);
  }
}

export function toggleFavoritesFilter() {
  state.setFilter('favorites');
  renderGrid();
  updateFileCount();
}

export function playFavorites() {
  const favorites = state.getFavoriteFiles();
  
  if (favorites.length === 0) {
    alert('No favorites to play!');
    return;
  }
  
  state.fullscreenMode = 'playlist';
  document.querySelectorAll('#grid audio, #grid video').forEach(m => m.pause());
  
  // Import and use the fullscreen player
  import('./fullscreen.js').then(module => {
    module.startFullscreenPlayer(favorites, 0);
  });
}

export function setupFavoritesFilter() {
  const favoritesCount = document.getElementById('favorites-count');
  if (favoritesCount) {
    favoritesCount.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      toggleFavoritesFilter();
    });
  }
}