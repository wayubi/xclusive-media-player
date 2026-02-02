// mediaContainer.js - Create media containers for grid
import { state } from './state.js';
import { mediaPool } from './mediaPool.js';
import { addCentralOverlay, addFileInfoOverlay } from './ui.js';
import { startFullscreenFrom } from './fullscreen.js';

// Lazy loading offset - start loading when element is within this many pixels of viewport
export const LAZY_LOAD_OFFSET = 200;

/**
 * Create a media container for a file
 * Supports lazy loading - media won't load until visible or prioritized
 */
export function createMediaContainer(file, index = 0) {
  const container = document.createElement('div');
  container.className = 'video-container';

  // Check if file is audited
  const isAudited = state.isFileAudited(file);
  if (!isAudited) {
    container.classList.add('unaudited');
  }

  // Add favorite heart icon
  const heart = document.createElement('div');
  heart.className = 'favorite-heart';
  const isFavorited = state.isFavorited(file);
  heart.textContent = isFavorited ? '❤️' : '🤍';
  if (isFavorited) {
    heart.classList.add('favorited');
  }
  heart.onclick = (e) => {
    e.stopPropagation();
    import('./favorites.js').then(module => {
      module.toggleFavorite(file, heart);
    });
  };
  container.appendChild(heart);

  const ext = file.split('.').pop().toLowerCase();
  const isAudio = ['mp3','wav','ogg'].includes(ext);
  const isVideo = ['mp4', 'webm', 'mkv', 'mov', 'm4v', '3gp', 'flv', 'wmv'].includes(ext);
  const isImage = ['jpg','jpeg','png','gif','webp'].includes(ext);

  let mediaEl = null;

  if (isVideo || isAudio) {
    mediaEl = createLazyMediaElement(file, isVideo, isAudio, container);
  } else if (isImage) {
    mediaEl = createImageElement(file, container);
  } else {
    container.innerHTML = `<div style="color:red;padding:4px;">Unsupported: ${file}</div>`;
  }

  addCentralOverlay(container, mediaEl, file);
  addFileInfoOverlay(container, file, isAudited);

  return container;
}

/**
 * Create a media element with lazy loading support
 * Uses data-src pattern - actual loading happens when element is visible
 */
function createLazyMediaElement(file, isVideo, isAudio, container) {
  const mediaEl = isVideo ? mediaPool.getVideo() : mediaPool.getAudio();

  // Reset the reused element completely
  mediaEl.pause();
  mediaEl.removeAttribute('src');
  mediaEl.currentTime = 0;

  // Store src for lazy loading (don't load immediately)
  mediaEl.dataset.src = file;
  mediaEl.dataset.file = file;

  // Set attributes that don't trigger loading
  mediaEl.loop = true;
  mediaEl.playsInline = true;
  mediaEl.controls = false;
  mediaEl.preload = 'metadata'; // Only load metadata, not full video

  if (isVideo) {
    mediaEl.poster = state.audioThumbs[file] || 'cache/no-cover-vid.jpg';
  }

  // Mute by default (unmuting handled by enforceSingleUnmuted)
  mediaEl.muted = true;

  if (isAudio) {
    container.style.cssText = 'display:flex;flex-direction:column;justify-content:center;align-items:center;';
    const img = document.createElement('img');
    img.style.cssText = 'width:100%;height:100%;object-fit:cover;cursor:pointer;border-radius:8px;';
    img.src = state.audioThumbs[file] || 'cache/no-cover.jpg';
    img.onclick = () => startFullscreenFrom(file, mediaEl.currentTime);
    container.appendChild(img);
  }

  container.appendChild(mediaEl);

  return mediaEl;
}

function createImageElement(file, container) {
  const img = document.createElement('img');
  img.loading = 'lazy';
  img.decoding = 'async';
  img.src = file;
  container.appendChild(img);
  return img;
}
