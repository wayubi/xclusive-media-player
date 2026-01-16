// mediaContainer.js - Create media containers for grid
import { state } from './state.js';
import { mediaPool } from './mediaPool.js';
import { mediaQueue } from './mediaQueue.js';
import { addCentralOverlay, addFileInfoOverlay } from './ui.js';
import { startFullscreenFrom } from './fullscreen.js';

export function createMediaContainer(file) {
  const container = document.createElement('div');
  container.className = 'video-container';

  const ext = file.split('.').pop().toLowerCase();
  const isAudio = ['mp3','wav','ogg'].includes(ext);
  const isVideo = ['mp4', 'webm', 'mkv', 'mov', 'm4v', '3gp', 'flv', 'wmv'].includes(ext);
  const isImage = ['jpg','jpeg','png','gif','webp'].includes(ext);

  let mediaEl = null;

  if (isVideo || isAudio) {
    mediaEl = createMediaElement(file, isVideo, isAudio, container);
  } else if (isImage) {
    mediaEl = createImageElement(file, container);
  } else {
    container.innerHTML = `<div style="color:red;padding:4px;">Unsupported: ${file}</div>`;
  }

  addCentralOverlay(container, mediaEl, file);
  addFileInfoOverlay(container, file);

  // Restore time if this was the last fullscreen item
  if ((isVideo || isAudio) && state.lastFullscreen.file === file && state.lastFullscreen.time > 0) {
    mediaEl.addEventListener('loadedmetadata', () => {
      mediaEl.currentTime = state.lastFullscreen.time;
    }, { once: true });
  }

  return container;
}

function createMediaElement(file, isVideo, isAudio, container) {
  const mediaEl = isVideo ? mediaPool.getVideo() : mediaPool.getAudio();

  // Reset the reused element
  mediaEl.pause();
  mediaEl.src = '';
  mediaEl.currentTime = 0;
  mediaEl.dataset.src = file;
  mediaEl.dataset.file = file;

  // Re-apply common properties
  mediaEl.loop = true;
  mediaEl.playsInline = true;
  mediaEl.controls = false;
  mediaEl.preload = 'auto';

  if (isVideo) {
    mediaEl.poster = state.audioThumbs[file] || 'cache/no-cover-vid.jpg';
  }

  // Mute / unmute logic
  const isRecentFullscreen = state.lastFullscreen.file === file && state.isFileVisible(file);
  let shouldBeUnmuted = false;
  
  if (!state.muted) {
    if (isRecentFullscreen) {
      shouldBeUnmuted = true;
    } else {
      const visibleMedia = state.getVisibleFiles()
        .filter(f => /\.(mp4|webm|mkv|mp3|wav|ogg)$/i.test(f));
      if (visibleMedia[0] === file) shouldBeUnmuted = true;
    }
  }
  
  mediaEl.muted = !shouldBeUnmuted;

  if (isAudio) {
    container.style.cssText = 'display:flex;flex-direction:column;justify-content:center;align-items:center;';
    const img = document.createElement('img');
    img.style.cssText = 'width:100%;height:100%;object-fit:cover;cursor:pointer;border-radius:8px;';
    img.src = state.audioThumbs[file] || 'cache/no-cover.jpg';
    img.onclick = () => startFullscreenFrom(file, mediaEl.currentTime);
    container.appendChild(img);
  }

  if (isVideo) mediaQueue.addVideo(mediaEl);
  if (isAudio) mediaQueue.addAudio(mediaEl);

  container.appendChild(mediaEl);
  
  return mediaEl;
}

function createImageElement(file, container) {
  const img = document.createElement('img');
  img.loading = 'lazy';
  img.decoding = 'async';
  img.dataset.src = file;
  container.appendChild(img);
  return img;
}