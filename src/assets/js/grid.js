// grid.js - Grid rendering and navigation
import { state } from './state.js';
import { mediaPool } from './mediaPool.js';
import { mediaQueue } from './mediaQueue.js';
import { createMediaContainer, LAZY_LOAD_OFFSET } from './mediaContainer.js';
import { syncMuteIcons } from './ui.js';

// IntersectionObserver for lazy loading media
let gridObserver = null;

/**
 * Main grid render function - orchestrates all phases
 * Now split into logical phases for maintainability
 */
export function renderGrid() {
  const grid = document.getElementById('grid');
  if (!grid) return;

  // Phase 1: Cleanup - Recycle existing media elements
  cleanupGrid(grid);

  // Phase 2: Structure - Setup grid layout and containers
  const visibleFiles = prepareGridStructure(grid);

  // Phase 3: Populate - Create media containers (lazy loading)
  populateGridContainers(grid, visibleFiles);

  // Phase 4: Metadata - Fetch file metadata
  fetchMetadataBatch(grid, visibleFiles);

  // Phase 5: Media Loading - Start with prioritized lazy loading
  startPrioritizedLoading(grid, visibleFiles);

  // Phase 6: UI Finalization - Sync state and update counters
  finalizeGridUI(grid);
}

/**
 * Phase 1: Cleanup
 * Properly recycle all media elements back to the pool
 */
function cleanupGrid(grid) {
  // Disconnect any existing observer
  if (gridObserver) {
    gridObserver.disconnect();
    gridObserver = null;
  }

  // Recycle existing media elements
  const existingMedia = grid.querySelectorAll('video, audio');
  mediaPool.recycleElements(existingMedia);

  // Clear the grid DOM
  grid.innerHTML = '';
}

/**
 * Phase 2: Structure
 * Setup CSS grid layout and calculate visible files
 */
function prepareGridStructure(grid) {
  const visibleCount = Math.min(
    state.totalCells,
    Math.max(0, state.allVideos.length - state.startIndex)
  );

  const cols = Math.min(visibleCount, state.selectedColumns);
  const rows = Math.ceil(visibleCount / cols);

  // Configure grid CSS
  grid.style.gridTemplateColumns = `repeat(${cols}, 1fr)`;
  grid.style.gridTemplateRows = `repeat(${rows}, minmax(0, 1fr))`;

  const visibleFiles = state.getVisibleFiles();

  // Clear lastFullscreen if not visible
  if (state.lastFullscreen.file && !state.isFileVisible(state.lastFullscreen.file)) {
    state.lastFullscreen = { file: null, time: 0 };
  }

  return visibleFiles;
}

/**
 * Phase 3: Populate
 * Create media containers without loading media yet
 * Uses lazy loading pattern via IntersectionObserver
 */
function populateGridContainers(grid, visibleFiles) {
  const fragment = document.createDocumentFragment();

  visibleFiles.forEach((file, index) => {
    const container = createMediaContainer(file, index);
    fragment.appendChild(container);
  });

  grid.appendChild(fragment);
}

/**
 * Phase 4: Metadata
 * Fetch file metadata in batch for visible items
 */
function fetchMetadataBatch(grid, visibleFiles) {
  const visibleFilesDecoded = visibleFiles.map(f => decodeURIComponent(f));

  fetch('api.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      action: 'metadata_batch',
      files: visibleFilesDecoded
    })
  })
  .then(r => r.json())
  .then(metas => {
    Array.from(grid.children).forEach((container, idx) => {
      const file = visibleFiles[idx];
      const decodedFile = decodeURIComponent(file);
      const meta = metas[decodedFile] || {};

      const filenameElem = container.querySelector('.overlay > div:first-child');
      const metaElem = container.querySelector('.overlay > div:last-child');

      if (filenameElem) {
        filenameElem.textContent = meta.file || decodeURIComponent(file.split('/').pop());
      }

      if (metaElem) {
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

/**
 * Phase 5: Media Loading
 * Start with prioritized loading using IntersectionObserver
 * Priority: 1. Recent fullscreen video, 2. First cell, 3. Others lazy loaded
 */
function startPrioritizedLoading(grid, visibleFiles) {
  // Find the priority media element (recent fullscreen or first)
  const mediaElements = Array.from(grid.querySelectorAll('audio[data-src], video[data-src]'));
  let priorityElement = null;

  // Priority 1: Recent fullscreen video
  if (state.lastFullscreen.file && state.lastFullscreen.time > 0) {
    priorityElement = mediaElements.find(m => m.dataset.file === state.lastFullscreen.file);
  }

  // Priority 2: First media element
  if (!priorityElement && mediaElements.length > 0) {
    priorityElement = mediaElements[0];
  }

  // Immediately load priority element
  if (priorityElement) {
    loadMediaElement(priorityElement, true);
  }

  // Setup lazy loading for remaining elements
  const elementsToLazyLoad = mediaElements.filter(m => m !== priorityElement);

  if (elementsToLazyLoad.length > 0) {
    setupLazyLoading(elementsToLazyLoad);
  }

  // Process queues with reduced concurrency for large grids
  const gridSize = visibleFiles.length;
  const maxConcurrent = gridSize > 6 ? 3 : 6; // Reduce for large grids
  mediaQueue.setMaxConcurrent(maxConcurrent);
  mediaQueue.processAudioQueue();
  mediaQueue.processVideoQueue();
}

/**
 * Load a specific media element
 */
function loadMediaElement(element, isPriority = false) {
  if (!element.dataset.src) return;

  element.src = element.dataset.src;
  delete element.dataset.src;
  element.load();

  if (element.tagName === 'VIDEO') {
    element.addEventListener('loadedmetadata', () => {
      element.play().catch(() => {});
    }, { once: true });
  }

  // Restore time if this was the recent fullscreen
  if (isPriority && state.lastFullscreen.file === element.dataset.file) {
    element.addEventListener('loadedmetadata', () => {
      element.currentTime = state.lastFullscreen.time || 0;
    }, { once: true });
  }
}

/**
 * Setup IntersectionObserver for lazy loading
 */
function setupLazyLoading(elements) {
  // Disconnect any existing observer
  if (gridObserver) {
    gridObserver.disconnect();
  }

  // Create new observer
  gridObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        const element = entry.target;
        loadMediaElement(element);
        gridObserver.unobserve(element);
      }
    });
  }, {
    root: null,
    rootMargin: `${LAZY_LOAD_OFFSET}px`,
    threshold: 0.1
  });

  // Observe all elements
  elements.forEach(el => gridObserver.observe(el));
}

/**
 * Phase 6: UI Finalization
 * Sync mute state, enforce single unmuted rule, update counters
 */
function finalizeGridUI(grid) {
  // Resume recent fullscreen media if present
  if (state.lastFullscreen.file && state.lastFullscreen.time > 0) {
    const recentMedia = [...grid.querySelectorAll('audio, video')]
      .find(m => m.dataset.file === state.lastFullscreen.file);

    if (recentMedia && !recentMedia.src) {
      // Will be loaded by priority loading above
    } else if (recentMedia) {
      recentMedia.currentTime = state.lastFullscreen.time || 0;
      recentMedia.play().catch(() => {});
    }
  }

  enforceSingleUnmuted();
  syncMuteIcons();
  updateFileCount();
}

/**
 * Build metadata display parts
 */
function buildMetadataParts(meta) {
  const parts = [];

  if (meta.folder) {
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

// Event delegation for folder clicks
document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('grid')?.addEventListener('click', (e) => {
    const folderLink = e.target.closest('.folder-link');
    if (folderLink) {
      e.preventDefault();
      e.stopPropagation();

      const metaElem = folderLink.closest('div[data-file-path]');
      if (metaElem && metaElem.dataset.filePath) {
        const filePath = decodeURIComponent(metaElem.dataset.filePath);
        const pathParts = filePath.split('/').filter(p => p);

        if (pathParts[0] === 'volumes') {
          pathParts.shift();
        }

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
  const pathSegments = folderPath.split('/').filter(p => p);
  const params = new URLSearchParams();

  pathSegments.forEach(segment => {
    params.append('path[]', segment);
  });

  const currentParams = new URLSearchParams(window.location.search);
  if (currentParams.has('columns')) params.set('columns', currentParams.get('columns'));
  if (currentParams.has('rows')) params.set('rows', currentParams.get('rows'));
  if (currentParams.has('muted')) params.set('muted', currentParams.get('muted'));

  params.set('t', Date.now().toString());
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
