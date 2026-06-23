// fullscreen.js - Fullscreen player functionality
import { state } from './state.js?v=1782192653';
import { renderGrid } from './grid.js?v=1781077182';
import { mediaPool } from './mediaPool.js?v=1781077182';
import { isTerminalActive } from './terminal.js?v=1781077182';

let fullscreenDeleteUsed = false;

export function startFullscreenFrom(file, startTime = 0) {
  fullscreenDeleteUsed = false;
  // Check if this file has an unsupported codec
  if (state.hasUnsupportedCodec(file)) {
    const meta = state.getFileMetadata(file);
    const codec = meta?.video?.codec || 'unknown';
    const container = meta?.container || '';
    
    // Special message for FLV containers
    if (container.includes('flv')) {
      alert(`This file uses an FLV container format which cannot play in web browsers.\n\nFile: ${file.split('/').pop()}\n\nThe video codec (${codec}) may be supported, but FLV containers are not.\n\nTo play this file, you can:\n• Convert it to MP4 format\n• Download and play it locally in VLC or another media player`);
    } else {
      alert(`This file uses an unsupported video codec (${codec}).\n\nFile: ${file.split('/').pop()}\n\nTo play this file, you can:\n• Convert it to MP4 (H.264/AVC codec)\n• Download and play it locally in a media player`);
    }
    return;
  }

  // Check if this is a text file
  const ext = file.split('.').pop().toLowerCase();
  const isTextFile = ['txt', 'md', 'log', 'json', 'xml', 'csv', 'yaml', 'yml', 'conf', 'cfg', 'ini'].includes(ext);
  if (isTextFile) {
    // Text files have their own fullscreen viewer
    return;
  }

  state.fullscreenMode = 'tile';
  state.lastFullscreen = { file, time: startTime };
  startFullscreenPlayer(state.allVideos, state.allVideos.indexOf(file), startTime);
}

function isTextFile(file) {
  const ext = file.split('.').pop().toLowerCase();
  return ['txt', 'md', 'log', 'json', 'xml', 'csv', 'yaml', 'yml', 'conf', 'cfg', 'ini', , 'sfv'].includes(ext);
}

export function playAll() {
  state.fullscreenMode = 'playlist';
  // Filter out text files from playlist - use originalVideos to get ALL videos regardless of filters
  // const videoPlaylist = state.originalVideos.filter(file => !isTextFile(file));
  const videoPlaylist = state.allVideos.filter(file => !isTextFile(file));
  startFullscreenPlayer(videoPlaylist, state.startIndex);
}

export function shufflePlay() {
  state.fullscreenMode = 'playlist';
  // Filter out text files from playlist - use originalVideos to get ALL videos regardless of filters
  // const videoPlaylist = state.originalVideos.filter(file => !isTextFile(file));
  const videoPlaylist = state.allVideos.filter(file => !isTextFile(file));
  startFullscreenPlayer([...videoPlaylist].sort(() => Math.random() - 0.5), 0);
}

export async function startFullscreenPlayer(playlist, index = 0, startTime = 0) {
  if (!playlist.length) return;
  
  document.body.classList.add('fullscreen-active');
  
  let i = index;

  // Filter out unsupported videos and text files from playlist
  const supportedPlaylist = playlist.filter(file => !state.hasUnsupportedCodec(file) && !isTextFile(file));

  // If no supported videos, show message and return
  if (supportedPlaylist.length === 0) {
    alert('No supported video files in this folder.\n\nThis folder contains videos with unsupported codecs (WMV3, FLV1, MPEG4, etc.) or only text files. Please add compatible video files.');
    return;
  }

  // Find the closest supported video to the requested index
  if (state.hasUnsupportedCodec(playlist[i]) || isTextFile(playlist[i])) {
    // Find next supported video
    let foundIndex = -1;
    for (let j = 0; j < playlist.length; j++) {
      const checkIdx = (i + j) % playlist.length;
      if (!state.hasUnsupportedCodec(playlist[checkIdx]) && !isTextFile(playlist[checkIdx])) {
        foundIndex = supportedPlaylist.indexOf(playlist[checkIdx]);
        break;
      }
    }
    i = foundIndex >= 0 ? foundIndex : 0;
  } else {
    // Convert original index to filtered playlist index
    i = supportedPlaylist.indexOf(playlist[index]);
    if (i === -1) i = 0;
  }

  // Android ExoPlayer support
  if (window.AndroidPlayer && window.useExoPlayer) {
    AndroidPlayer.playFullscreen(JSON.stringify(supportedPlaylist), i, startTime);
    return;
  }

  // Phase 1: Release all grid media elements to free browser connections
  // This is critical to prevent seeking hangs in fullscreen
  await mediaPool.releaseAll();
  mediaPool.clearQueues();

  const container = createFullscreenContainer();
  let mediaEl, thumb;

  function createMedia(file, startTime = 0) {
    const ext = file.split('.').pop().toLowerCase();
    const isImage = ['jpg','jpeg','png','gif','webp','heic'].includes(ext);
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
    i = (idx + supportedPlaylist.length) % supportedPlaylist.length;
    const file = supportedPlaylist[i];

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
    state.lastFullscreen.file = supportedPlaylist[i];

    state.startIndex = Math.floor(state.allVideos.indexOf(supportedPlaylist[i]) / state.totalCells) * state.totalCells;

    // Clean up fullscreen elements
    if (thumb) thumb.remove();
    if (mediaEl) mediaEl.remove();
    container.remove();
    document.removeEventListener('keydown', keyHandler);
    document.body.classList.remove('fullscreen-active');

    // Force complete grid re-render to restore video sources
    renderGrid();
  }

  createMedia(supportedPlaylist[i], startTime);

  // Event handlers
  setupFullscreenEvents(container, play, close, () => i);
  const keyHandler = setupKeyboardHandler(supportedPlaylist, play, close, () => i);
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

  // Double-click on background to close
  container.addEventListener('dblclick', e => {
    if (e.target === container) close();
  });
}

function deleteCurrentVideo(file, playlist, currentIndex, play, close) {
  const filename = file.split('/').pop();
  if (!confirm(`Delete "${filename}"?`)) {
    fullscreenDeleteUsed = false;
    return;
  }
  
  fetch('api.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'delete', files: [file] })
  })
  .then(r => r.json())
  .then(data => {
    if (data.error) {
      alert('Delete error: ' + data.error);
      fullscreenDeleteUsed = false;
      return;
    }
    
    const idx = state.allVideos.indexOf(file);
    if (idx > -1) state.allVideos.splice(idx, 1);
    delete state.auditStatusMap[file];
    delete state.webToFsPathMap[file];
    delete state.favoritesMap[file];
    
    // Remove from local playlist
    const playlistIndex = playlist.indexOf(file);
    if (playlistIndex > -1) {
      playlist.splice(playlistIndex, 1);
    }
    
    // If no videos left, close fullscreen
    if (playlist.length === 0) {
      close();
      return;
    }
    
    // Adjust index if we deleted before current position
    let newIndex = currentIndex;
    if (playlistIndex < currentIndex) {
      newIndex = currentIndex - 1;
    }
    // Wrap if needed
    if (newIndex >= playlist.length) {
      newIndex = 0;
    }
    
    // Play the next video
    play(newIndex);
    fullscreenDeleteUsed = false;
  })
  .catch(() => {
    alert('Delete failed');
    fullscreenDeleteUsed = false;
  });
}

function setupKeyboardHandler(playlist, play, close, getIndex) {
  return function keyHandler(e) {
    // Don't process if terminal is active
    if (isTerminalActive()) return;
    
    const i = getIndex();

    switch (e.key) {
      case 'ArrowRight':
      case 'ArrowDown':
      case ' ':
        e.preventDefault();
        play(i + 1);
        break;
      case 'ArrowLeft':
      case 'ArrowUp':
        e.preventDefault();
        play(i - 1);
        break;
      case 'Escape':
      case 'q':
      case 'Q':
        e.preventDefault();
        close();
        break;
      case 'Delete':
      case 'd':
        e.stopPropagation();
        if (fullscreenDeleteUsed) return;
        e.preventDefault();
        fullscreenDeleteUsed = true;
        deleteCurrentVideo(playlist[i], playlist, i, play, close);
        break;
    }
  };
}
