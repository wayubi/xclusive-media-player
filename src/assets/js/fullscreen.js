// fullscreen.js - Fullscreen player functionality
import { state } from './state.js';
import { renderGrid } from './grid.js';

export function startFullscreenFrom(file, startTime = 0) {
  state.fullscreenMode = 'tile';
  document.querySelectorAll('#grid video, #grid audio').forEach(m => m.pause());
  state.lastFullscreen = { file, time: startTime };
  startFullscreenPlayer(state.allVideos, state.allVideos.indexOf(file), startTime);
}

export function playAll() {
  state.fullscreenMode = 'playlist';
  document.querySelectorAll('#grid audio, #grid video').forEach(m => m.pause());
  startFullscreenPlayer(state.allVideos, state.startIndex);
}

export function shufflePlay() {
  state.fullscreenMode = 'playlist';
  document.querySelectorAll('#grid audio, #grid video').forEach(m => m.pause());
  startFullscreenPlayer([...state.allVideos].sort(() => Math.random() - 0.5), 0);
}

function startFullscreenPlayer(playlist, index = 0, startTime = 0) {
  if (!playlist.length) return;
  let i = index;

  // Android ExoPlayer support
  if (window.AndroidPlayer && window.useExoPlayer) {
    AndroidPlayer.playFullscreen(JSON.stringify(playlist), i, startTime);
    return;
  }

  const container = createFullscreenContainer();
  let mediaEl, thumb;

  function createMedia(file, startTime = 0) {
    const ext = file.split('.').pop().toLowerCase();
    const isImage = ['jpg','jpeg','png','gif','webp'].includes(ext);
    const isAudio = ['mp3','wav','ogg'].includes(ext);

    if (mediaEl && state.lastFullscreen.file === file) {
      container.innerHTML = '';
      if (thumb) container.appendChild(thumb);
      container.appendChild(mediaEl);
      return;
    }

    container.innerHTML = '';

    if (isImage) {
      mediaEl = createImagePlayer(file, container, close);
    } else if (isAudio) {
      const result = createAudioPlayer(file, startTime, container, close);
      mediaEl = result.mediaEl;
      thumb = result.thumb;
    } else {
      mediaEl = createVideoPlayer(file, startTime, container, close);
    }

    const isSingleTile = state.fullscreenMode === 'tile';
    mediaEl.loop = isSingleTile && !isImage;
    if (!mediaEl.loop && !isImage) {
      mediaEl.onended = () => play(i + 1);
    }

    state.lastFullscreen.file = file;
    if (!isImage && !isAudio) {
      state.lastFullscreen.time = startTime;
    }
  }

  function play(idx) {
    i = (idx + playlist.length) % playlist.length;
    const file = playlist[i];

    if (mediaEl && state.lastFullscreen.file === file) {
      container.innerHTML = '';
      if (thumb) container.appendChild(thumb);
      container.appendChild(mediaEl);
      return;
    }

    if (mediaEl) {
      if (mediaEl.tagName === 'AUDIO' || mediaEl.tagName === 'VIDEO') {
        state.lastFullscreen.time = mediaEl.currentTime;
      }
      if (thumb) thumb.remove();
      mediaEl.remove();
      mediaEl = null;
      thumb = null;
    }

    createMedia(file, state.lastFullscreen.file === file ? state.lastFullscreen.time : 0);
  }

  function close() {
    if (mediaEl && mediaEl.tagName.toLowerCase() !== 'img') {
      state.lastFullscreen.time = mediaEl.currentTime;
    } else {
      state.lastFullscreen.time = 0;
    }
    state.lastFullscreen.file = playlist[i];

    state.startIndex = Math.floor(state.allVideos.indexOf(playlist[i]) / state.totalCells) * state.totalCells;
    renderGrid();

    if (thumb) thumb.remove();
    if (mediaEl) mediaEl.remove();
    container.remove();
    document.removeEventListener('keydown', keyHandler);
  }

  createMedia(playlist[i], startTime);

  // Event handlers
  setupFullscreenEvents(container, play, close, () => i);
  const keyHandler = setupKeyboardHandler(playlist, play, close, () => i);
  document.addEventListener('keydown', keyHandler);
}

function createFullscreenContainer() {
  const container = document.createElement('div');
  container.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:#000;display:flex;flex-direction:column;align-items:center;justify-content:center;z-index:9999;';
  document.body.appendChild(container);
  return container;
}

function createImagePlayer(file, container, close) {
  const img = document.createElement('img');
  img.src = file;
  img.style.cssText = 'max-width:95vw;max-height:92vh;object-fit:contain;border-radius:8px;box-shadow:0 0 40px rgba(0,0,0,0.6);cursor:pointer;';
  img.ondblclick = close;
  container.appendChild(img);
  return img;
}

function createAudioPlayer(file, startTime, container, close) {
  const audio = document.createElement('audio');
  audio.src = file;
  audio.currentTime = startTime;
  audio.autoplay = true;
  audio.controls = false;
  audio.playsInline = false;
  audio.muted = state.muted;
  audio.style.cssText = 'width:100%;height:40px;margin-bottom:6px;border-radius:6px;';

  const thumb = document.createElement('img');
  thumb.src = state.audioThumbs[file] || 'cache/no-cover.jpg';
  thumb.style.cssText = 'max-width:95vw;max-height:80vh;object-fit:contain;margin-bottom:6px;border-radius:8px;cursor:pointer;';
  thumb.ondblclick = close;

  container.appendChild(thumb);
  container.appendChild(audio);

  return { mediaEl: audio, thumb };
}

function createVideoPlayer(file, startTime, container, close) {
  const video = document.createElement('video');
  video.src = file;
  video.currentTime = startTime;
  video.autoplay = true;
  video.controls = true;
  video.playsInline = true;
  video.muted = state.muted;
  video.style.cssText = 'max-width:95vw;max-height:92vh;object-fit:contain;border-radius:8px;box-shadow:0 0 40px rgba(0,0,0,0.6);cursor:pointer;';
  video.ondblclick = close;

  video.addEventListener('loadedmetadata', () => {
    const aspect = video.videoWidth / video.videoHeight;
    video.style.width = aspect >= 1 ? '95vw' : 'auto';
    video.style.height = aspect < 1 ? '92vh' : 'auto';
    video.play().catch(() => {});
  }, { once: true });

  container.appendChild(video);
  return video;
}

function setupFullscreenEvents(container, play, close, getIndex) {
  // Wheel navigation
  container.addEventListener('wheel', e => {
    e.preventDefault();
    const i = getIndex();
    e.deltaY > 0 ? play(i + 1) : play(i - 1);
  }, { passive: false });

  // Touch navigation
  let touchY = 0;
  container.addEventListener('touchstart', e => {
    if (e.touches.length === 1) touchY = e.touches[0].clientY;
  }, { passive: true });

  container.addEventListener('touchend', e => {
    const i = getIndex();
    const delta = e.changedTouches[0].clientY - touchY;
    if (Math.abs(delta) > 50) delta < 0 ? play(i + 1) : play(i - 1);
  }, { passive: true });

  // Click to close
  container.addEventListener('click', e => {
    if (e.target === container) close();
  });
}

function setupKeyboardHandler(playlist, play, close, getIndex) {
  return async (e) => {
    const i = getIndex();
    
    if (e.key === 'Escape' || [38, 40].includes(e.keyCode)) {
      e.preventDefault();
      return close();
    }
    
    if (e.keyCode === 37) {
      e.preventDefault();
      play(i - 1);
      return;
    }
    
    if (e.keyCode === 39) {
      e.preventDefault();
      play(i + 1);
      return;
    }

    if (e.key === 'Delete') {
      if (!confirm('Delete this file?')) return;
      
      const del = playlist[i];
      try {
        const resp = await fetch('post-handler.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'delete', files: [del] })
        });
        
        const data = await resp.json();
        if (data.error) throw new Error(data.error);

        playlist.splice(i, 1);
        
        // Remove from allVideos
        const idx = state.allVideos.indexOf(del);
        if (idx !== -1) state.allVideos.splice(idx, 1);
        
        // Remove from originalVideos
        const origIdx = state.originalVideos.indexOf(del);
        if (origIdx !== -1) state.originalVideos.splice(origIdx, 1);
        
        // Remove from audit status map
        delete state.auditStatusMap[del];
        
        // Remove from allFilesWithPaths
        const fileIdx = state.allVideos.indexOf(del);
        if (fileIdx !== -1 && fileIdx < state.allFilesWithPaths.length) {
          state.allFilesWithPaths.splice(fileIdx, 1);
        }
        
        // Update audit display
        import('./audit.js').then(module => {
          module.updateAuditDisplay();
        });
        
        renderGrid();

        if (!playlist.length) return close();
        const newI = i % playlist.length;
        play(newI);
      } catch (err) {
        console.error(err);
        alert('Delete failed');
      }
    }
  };
}