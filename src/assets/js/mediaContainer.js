// mediaContainer.js - Create media containers for grid
import { state } from './state.js';
import { mediaPool } from './mediaPool.js';
import { addCentralOverlay, addFileInfoOverlay } from './ui.js';
import { startFullscreenFrom } from './fullscreen.js';
import { isTerminalActive } from './terminal.js';

// Lazy loading offset - start loading when element is within this many pixels of viewport
export const LAZY_LOAD_OFFSET = 200;

/**
 * Create a media container for a file
 * Supports lazy loading - media won't load until visible or prioritized
 */
export function createMediaContainer(file, index = 0) {
  const container = document.createElement('div');
  container.className = 'video-container';
  container.dataset.file = file;

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

  // Determine file type by extension
  const ext = file.split('.').pop().toLowerCase();
  const isAudio = ['mp3','wav','ogg'].includes(ext);
  const isVideo = ['mp4', 'webm', 'mkv', 'mov', 'm4v', '3gp', 'flv', 'wmv', 'avi', 'mpg', 'mpeg'].includes(ext);
  const isImage = ['jpg','jpeg','png','gif','webp'].includes(ext);
  const isText = ['txt', 'md', 'log', 'json', 'xml', 'csv', 'yaml', 'yml', 'conf', 'cfg', 'ini', 'nfo', 'sfv'].includes(ext);

  let mediaEl = null;

  // Check if we already have metadata for this file (from previous fetch)
  const existingMeta = state.getFileMetadata(file);
  if (existingMeta && isVideo && state.hasUnsupportedCodec(file)) {
    // We already know it's unsupported, create placeholder
    mediaEl = createUnsupportedPlaceholder(container, file);
  } else if (isVideo || isAudio) {
    // Create video/audio element (will be transformed to unsupported if needed after metadata)
    mediaEl = createLazyMediaElement(file, isVideo, isAudio, container);
  } else if (isImage) {
    mediaEl = createImageElement(file, container);
  } else if (isText) {
    mediaEl = createTextContainer(file, container);
  } else {
    container.innerHTML = `<div style="color:red;padding:4px;">Unsupported: ${file}</div>`;
  }

  addCentralOverlay(container, mediaEl, file);
  addFileInfoOverlay(container, file, isAudited);

  return container;
}

/**
 * Transform a container to show unsupported video (called after metadata fetch)
 */
export function transformToUnsupportedVideo(container, file) {
  // Skip if already transformed
  if (container.classList.contains('unsupported-video')) return;

  // Add unsupported video class for styling
  container.classList.add('unsupported-video');

  // Find and remove the video element (recycle it)
  const videoEl = container.querySelector('video');
  if (videoEl) {
    // Cancel any pending loads
    videoEl.removeAttribute('src');
    videoEl.removeAttribute('data-src');
    mediaPool.recycleElements([videoEl]);
    videoEl.remove();
  }

  // Remove fullscreen and mute buttons from central overlay (keep delete and audit)
  const centralOverlay = container.querySelector('.central-overlay');
  if (centralOverlay) {
    const buttons = centralOverlay.querySelectorAll('button');
    buttons.forEach(btn => {
      const btnText = btn.innerHTML;
      const isFullscreen = btnText === '⛶';
      const isMute = btnText === '🔇' || btnText === '🔊';
      if (isFullscreen || isMute) {
        btn.remove();
      }
    });
  }

  // Create unsupported video wrapper
  if (!container.querySelector('.unsupported-video-wrapper')) {
    const wrapper = document.createElement('div');
    wrapper.className = 'unsupported-video-wrapper';

    // Thumbnail image
    const img = document.createElement('img');
    img.src = state.audioThumbs[file] || 'cache/no-cover-vid.jpg';
    img.className = 'unsupported-video-thumb';
    img.loading = 'lazy';
    wrapper.appendChild(img);

    // Warning overlay
    const warning = document.createElement('div');
    warning.className = 'unsupported-video-warning';
    warning.innerHTML = '⚠️ Not Playable';
    wrapper.appendChild(warning);

    // Format badge with codec name or error status
    const meta = state.getFileMetadata(file);
    let codecName, alertMessage;
    
    if (!meta || Object.keys(meta).length === 0) {
      // No metadata at all
      codecName = 'NO META';
      alertMessage = `This file has no metadata and cannot be played.\n\nFile: ${file.split('/').pop()}\n\nThis may be a corrupted or fake video file.`;
    } else if (!meta.video || !meta.video.codec) {
      // Has metadata but no video codec info
      codecName = 'NO VIDEO';
      alertMessage = `This file has no video stream and cannot be played.\n\nFile: ${file.split('/').pop()}\n\nThis may be an audio-only file or corrupted.`;
    } else {
      // Has unsupported codec
      codecName = meta.video.codec.toUpperCase();
      alertMessage = `This file uses an unsupported video codec (${meta.video.codec}).\n\nFile: ${file.split('/').pop()}\n\nTo play this file, you can:\n• Convert it to MP4 (H.264/AVC codec)\n• Download and play it locally in a media player`;
    }
    
    const badge = document.createElement('div');
    badge.className = 'unsupported-video-badge';
    badge.textContent = codecName;
    wrapper.appendChild(badge);

    // Click to show alert
    wrapper.onclick = (e) => {
      e.stopPropagation();
      alert(alertMessage);
    };

    // Insert before overlays
    const overlay = container.querySelector('.overlay');
    if (overlay) {
      container.insertBefore(wrapper, overlay);
    } else {
      container.appendChild(wrapper);
    }
  }
}

/**
 * Create placeholder for unsupported videos (used when metadata already known)
 */
function createUnsupportedPlaceholder(container, file) {
  container.classList.add('unsupported-video');

  const wrapper = document.createElement('div');
  wrapper.className = 'unsupported-video-wrapper';

  // Thumbnail image
  const img = document.createElement('img');
  img.src = state.audioThumbs[file] || 'cache/no-cover-vid.jpg';
  img.className = 'unsupported-video-thumb';
  img.loading = 'lazy';
  wrapper.appendChild(img);

  // Warning overlay
  const warning = document.createElement('div');
  warning.className = 'unsupported-video-warning';
  warning.innerHTML = '⚠️ Not Playable';
  wrapper.appendChild(warning);

  // Format badge with codec name or error status
  const meta = state.getFileMetadata(file);
  let codecName, alertMessage;

  if (!meta || Object.keys(meta).length === 0) {
    // No metadata at all
    codecName = 'NO META';
    alertMessage = `This file has no metadata and cannot be played.\n\nFile: ${file.split('/').pop()}\n\nThis may be a corrupted or fake video file.`;
  } else if (meta.container && meta.container.includes('flv')) {
    // FLV container - can't play in browsers even with H264
    codecName = 'FLV';
    alertMessage = `This file uses an FLV container format which cannot play in web browsers.\n\nFile: ${file.split('/').pop()}\n\nThe video codec (${meta.video?.codec || 'unknown'}) may be supported, but FLV containers are not.\n\nTo play this file, you can:\n• Convert it to MP4 format\n• Download and play it locally in VLC or another media player`;
  } else if (!meta.video || !meta.video.codec) {
    // Has metadata but no video codec info
    codecName = 'NO VIDEO';
    alertMessage = `This file has no video stream and cannot be played.\n\nFile: ${file.split('/').pop()}\n\nThis may be an audio-only file or corrupted.`;
  } else {
    // Has unsupported codec
    codecName = meta.video.codec.toUpperCase();
    alertMessage = `This file uses an unsupported video codec (${meta.video.codec}).\n\nFile: ${file.split('/').pop()}\n\nTo play this file, you can:\n• Convert it to MP4 (H.264/AVC codec)\n• Download and play it locally in a media player`;
  }

  const badge = document.createElement('div');
  badge.className = 'unsupported-video-badge';
  badge.textContent = codecName;
  wrapper.appendChild(badge);

  // Click to show alert
  wrapper.onclick = (e) => {
    e.stopPropagation();
    alert(alertMessage);
  };

  container.appendChild(wrapper);

  return wrapper;
}

/**
 * Create a text file container with lazy loading
 */
function createTextContainer(file, container) {
  container.classList.add('text-file-container');

  const wrapper = document.createElement('div');
  wrapper.className = 'text-file-wrapper';
  wrapper.dataset.src = file;

  // Initial placeholder
  const placeholder = document.createElement('div');
  placeholder.className = 'text-file-placeholder';
  placeholder.innerHTML = '📄 Loading...';
  wrapper.appendChild(placeholder);

  container.appendChild(wrapper);

  return wrapper;
}

/**
 * Load text content for a container (called from grid.js lazy loader)
 */
export function loadTextContent(wrapper) {
  const file = wrapper.dataset.src;
  if (!file || wrapper.dataset.loaded) return;

  wrapper.dataset.loaded = 'true';

  fetch(file)
    .then(response => {
      if (!response.ok) throw new Error('Failed to load');
      return response.text();
    })
    .then(text => {
      // Clear placeholder
      wrapper.innerHTML = '';

      // Create pre element for formatted text
      const pre = document.createElement('pre');
      pre.className = 'text-file-content';
      pre.textContent = text;
      wrapper.appendChild(pre);
    })
    .catch(() => {
      wrapper.innerHTML = '<div class="text-file-placeholder" style="color: #ff6666;">❌ Error loading file</div>';
    });
}

/**
 * Show text file in fullscreen
 */
export function showTextFullscreen(file) {
  const container = document.createElement('div');
  container.className = 'text-fullscreen-container';

  // Close button
  const closeBtn = document.createElement('button');
  closeBtn.className = 'text-fullscreen-close';
  closeBtn.innerHTML = '✕';
  closeBtn.onclick = () => container.remove();
  container.appendChild(closeBtn);

  // Title
  const title = document.createElement('div');
  title.className = 'text-fullscreen-title';
  title.textContent = decodeURIComponent(file.split('/').pop());
  container.appendChild(title);

  // Content area
  const contentArea = document.createElement('div');
  contentArea.className = 'text-fullscreen-content';
  contentArea.innerHTML = '<div class="text-loading">Loading...</div>';
  container.appendChild(contentArea);

  document.body.appendChild(container);

  // Load content
  fetch(file)
    .then(response => {
      if (!response.ok) throw new Error('Failed to load');
      return response.text();
    })
    .then(text => {
      const pre = document.createElement('pre');
      pre.textContent = text;
      contentArea.innerHTML = '';
      contentArea.appendChild(pre);
    })
    .catch(() => {
      contentArea.innerHTML = '<div class="text-loading" style="color: #ff6666;">Error loading file</div>';
    });

  // Close on Escape key
  const keyHandler = (e) => {
    // Don't process if terminal is active
    if (isTerminalActive()) return;
    
    if (e.key === 'Escape') {
      container.remove();
      document.removeEventListener('keydown', keyHandler);
    }
  };
  document.addEventListener('keydown', keyHandler);

  // Close on click outside content
  container.addEventListener('click', (e) => {
    if (e.target === container) {
      container.remove();
      document.removeEventListener('keydown', keyHandler);
    }
  });
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
