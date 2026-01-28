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
      const decodedFile = decodeURIComponent(file);
      const meta = metas[decodedFile] || {};

      const filenameElem = container.querySelector('.overlay > div:first-child');
      const metaElem = container.querySelector('.overlay > div:last-child');

      if (filenameElem) {
        filenameElem.textContent = meta.file || decodeURIComponent(file.split('/').pop());
      }

      if (metaElem) {
        // Store the file path for folder navigation
        metaElem.dataset.filePath = file;
        const parts = buildMetadataParts(meta);
        metaElem.innerHTML = parts.join(' • ');
      }
    });
  })
  .catch(() => {
    // Fallback: show filenames only
  });
}

function buildMetadataParts(meta) {
  const parts = [];
  
  if (meta.folder) {
    // Create clickable folder link with pointer-events enabled
    const folderLink = `<span class="folder-link" style="cursor: pointer; text-decoration: underline; pointer-events: auto;">${meta.folder}</span>`;
    parts.push(folderLink);
  }
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

// Add event delegation for folder clicks
document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('grid')?.addEventListener('click', (e) => {
    const folderLink = e.target.closest('.folder-link');
    if (folderLink) {
      e.preventDefault();
      e.stopPropagation();
      
      // Get the file path from the metadata element
      const metaElem = folderLink.closest('div[data-file-path]');
      if (metaElem && metaElem.dataset.filePath) {
        const filePath = decodeURIComponent(metaElem.dataset.filePath);
        
        // Extract directory path from file URL
        // Example: /volumes/pocket/Latif/civitai.com/alberist/file.mp4
        // Should become: pocket/Latif/civitai.com/alberist
        const pathParts = filePath.split('/').filter(p => p); // Remove empty strings
        
        // Remove 'volumes' prefix if present
        if (pathParts[0] === 'volumes') {
          pathParts.shift();
        }
        
        // Remove filename (last part)
        pathParts.pop();
        
        const folderPath = pathParts.join('/');
        
        if (folderPath) {
          navigateToFolder(folderPath);
        }
      }
    }
  });
});

function navigateToFolder(folderPath) {
  // Build the new URL with path segments
  const pathSegments = folderPath.split('/').filter(p => p);
  const params = new URLSearchParams();
  
  pathSegments.forEach(segment => {
    params.append('path[]', segment);
  });
  
  // Preserve current grid settings
  const currentParams = new URLSearchParams(window.location.search);
  if (currentParams.has('columns')) params.set('columns', currentParams.get('columns'));
  if (currentParams.has('rows')) params.set('rows', currentParams.get('rows'));
  if (currentParams.has('muted')) params.set('muted', currentParams.get('muted'));
  
  // Add cache buster
  params.set('t', Date.now().toString());
  
  // Navigate to the folder
  window.location.href = `index.php?${params.toString()}`;
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
  
  if (state.unauditedFilter) {
    countElem.innerText = `Unaudited: ${state.startIndex + 1} / ${state.allVideos.length} (of ${state.originalVideos.length})`;
  } else if (state.currentSearch) {
    countElem.innerText = `Filtered: ${state.startIndex + 1} / ${state.allVideos.length} (of ${state.originalVideos.length})`;
  } else {
    countElem.innerText = `${state.startIndex + 1} / ${state.allVideos.length}`;
  }
}