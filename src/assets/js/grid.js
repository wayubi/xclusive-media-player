// grid.js - Grid rendering and navigation
import { state } from './state.js';
import { mediaPool } from './mediaPool.js';
import { mediaQueue } from './mediaQueue.js';
import { createMediaContainer } from './mediaContainer.js';
import { syncMuteIcons } from './ui.js';

export function renderGrid() {
  const grid = document.getElementById('grid');

  // Recycle existing media elements
  const existingMedia = grid.querySelectorAll('video, audio');
  mediaPool.recycleElements(existingMedia);

  // Clear the grid
  grid.innerHTML = '';

  const visibleCount = Math.min(
    state.totalCells,
    Math.max(0, state.allVideos.length - state.startIndex)
  );

  const cols = Math.min(visibleCount, state.selectedColumns);
  const rows = Math.ceil(visibleCount / cols);
  grid.style.gridTemplateColumns = `repeat(${cols}, 1fr)`;
  grid.style.gridTemplateRows = `repeat(${rows}, minmax(0, 1fr))`;

  const visible = state.getVisibleFiles();
  
  if (state.lastFullscreen.file && !state.isFileVisible(state.lastFullscreen.file)) {
    state.lastFullscreen = { file: null, time: 0 };
  }

  // Create containers
  const fragment = document.createDocumentFragment();
  visible.forEach(file => fragment.appendChild(createMediaContainer(file)));
  grid.appendChild(fragment);

  // Fetch metadata batch
  fetchMetadataBatch(grid, visible);

  // Process media queues
  mediaQueue.processAudioQueue();
  mediaQueue.processVideoQueue();

  // Resume recent fullscreen media
  const recentMedia = [...grid.querySelectorAll('audio, video')]
    .find(m => m.dataset.file === state.lastFullscreen.file);
    
  if (recentMedia) {
    recentMedia.currentTime = state.lastFullscreen.time || 0;
    recentMedia.play().catch(() => {});
  }

  enforceSingleUnmuted();
  syncMuteIcons();

  updateFileCount();
}

function fetchMetadataBatch(grid, visible) {
  const visibleFiles = visible.map(f => decodeURIComponent(f));

  fetch('api.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ 
      action: 'metadata_batch', 
      files: visibleFiles 
    })
  })
  .then(r => r.json())
  .then(metas => {
    Array.from(grid.children).forEach((container, idx) => {
      const file = visible[idx];
      const meta = metas[file] || {};

      const filenameElem = container.querySelector('.overlay > div:first-child');
      const metaElem = container.querySelector('.overlay > div:last-child');

      if (filenameElem) {
        filenameElem.textContent = meta.file || decodeURIComponent(file.split('/').pop());
      }

      if (metaElem) {
        const parts = buildMetadataParts(meta);
        metaElem.textContent = parts.join(' • ');
      }
    });
  })
  .catch(() => {
    // Fallback: show filenames only
  });
}

function buildMetadataParts(meta) {
  const parts = [];
  
  if (meta.folder) parts.push(meta.folder);
  if (meta.video?.width && meta.video?.height) {
    parts.push(`${meta.video.width}×${meta.video.height}`);
  }
  if (meta.duration) {
    let sec = Math.floor(meta.duration);
    const h = Math.floor(sec / 3600); sec %= 3600;
    const m = Math.floor(sec / 60); sec %= 60;
    parts.push(`${h ? h + 'h ' : ''}${m ? m + 'm ' : ''}${sec}s`);
  }
  if (meta.filesize) {
    parts.push((meta.filesize / 1024 / 1024).toFixed(2) + ' MB');
  }
  if (meta.video) {
    if (meta.video.codec) parts.push(meta.video.codec);
    if (meta.video.fps) parts.push(`${meta.video.fps} FPS`);
  }
  if (meta.bitrate) {
    parts.push(Math.round(meta.bitrate / 1000) + ' kbps');
  }
  
  return parts;
}

export function nextGrid() {
  state.startIndex = (state.startIndex + state.totalCells) % state.allVideos.length;
  renderGrid();
}

export function prevGrid() {
  state.startIndex = (state.startIndex - state.totalCells + state.allVideos.length) % state.allVideos.length;
  renderGrid();
}

function enforceSingleUnmuted() {
  const media = Array.from(document.querySelectorAll('#grid video, #grid audio'));
  if (!media.length) return;

  let target = null;
  if (!state.muted) {
    if (state.lastFullscreen.file) {
      target = media.find(m => m.dataset.file === state.lastFullscreen.file);
    }
    if (!target) target = media[0];
  }

  media.forEach(m => m.muted = true);
  if (target) {
    target.muted = false;
    target.play().catch(() => {});
  }
}

function updateFileCount() {
  const countElem = document.getElementById('file-count');
  if (!countElem) return;
  
  countElem.innerText = state.currentSearch 
    ? `Filtered: ${state.startIndex + 1} / ${state.allVideos.length} (of ${state.originalVideos.length})`
    : `${state.startIndex + 1} / ${state.allVideos.length}`;
}