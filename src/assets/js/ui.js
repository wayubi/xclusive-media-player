// ui.js - UI components and overlay creation
import { state } from './state.js';
import { startFullscreenFrom } from './fullscreen.js';
import { renderGrid } from './grid.js';

const buttonStyle = 'font-size:20px;padding:6px 10px;border:none;border-radius:6px;background:rgba(0,0,0,0.6);color:white;cursor:pointer;pointer-events:auto;';
const centralOverlayStyle = 'position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);display:flex;gap:10px;z-index:10;opacity:0;transition:opacity 0.2s;pointer-events:none;';

export function addFileInfoOverlay(container, file) {
  container.style.position ||= 'relative';

  const overlay = document.createElement('div');
  overlay.className = 'overlay';
  overlay.style.cssText = `
    background: rgba(0,0,0,0.6);
    color: white;
    padding: 4px 6px;
    font-size: 14px;
    border-radius: 4px;
    pointer-events: none;
    display: inline-block;
  `;

  const filenameElem = document.createElement('div');
  filenameElem.textContent = file;
  filenameElem.style.fontWeight = 'bold';

  const metaElem = document.createElement('div');
  metaElem.style.fontSize = '12px';
  metaElem.style.marginTop = '2px';

  overlay.appendChild(filenameElem);
  overlay.appendChild(metaElem);
  container.appendChild(overlay);
}

export function addCentralOverlay(container, mediaEl, file) {
  const overlay = document.createElement('div');
  overlay.style.cssText = centralOverlayStyle + 'display:flex;justify-content:space-between;';

  // Select/Delete button
  const selectBtn = createSelectButton(file);
  overlay.appendChild(selectBtn);

  // Fullscreen button
  const fsBtn = document.createElement('button');
  fsBtn.innerHTML = '⛶';
  fsBtn.style.cssText = buttonStyle;
  fsBtn.onclick = e => {
    e.stopPropagation();
    const time = mediaEl && typeof mediaEl.currentTime === 'number' ? mediaEl.currentTime : 0;
    startFullscreenFrom(file, time);
  };
  overlay.appendChild(fsBtn);

  // Mute button for audio/video
  if (mediaEl && (mediaEl.tagName === 'VIDEO' || mediaEl.tagName === 'AUDIO')) {
    const muteBtn = createMuteButton(mediaEl);
    overlay.appendChild(muteBtn);
  }

  container.appendChild(overlay);
  container.addEventListener('mouseenter', () => overlay.style.opacity = '1');
  container.addEventListener('mouseleave', () => overlay.style.opacity = '0');
}

function createSelectButton(file) {
  const selectBtn = document.createElement('button');
  selectBtn.innerHTML = '🗙';
  selectBtn.style.cssText = buttonStyle + 'background:gray;';
  selectBtn.dataset.file = file;
  selectBtn.dataset.selected = 'false';
  
  selectBtn.onclick = e => {
    e.stopPropagation();
    
    if (selectBtn.dataset.selected === 'false') {
      selectBtn.dataset.selected = 'true';
      selectBtn.style.background = 'red';
    } else {
      const selected = Array.from(document.querySelectorAll('#grid .video-container button[data-selected="true"]'));
      const filesToDelete = selected.map(b => b.dataset.file);
      
      if (!filesToDelete.length || !confirm(`Delete ${filesToDelete.length} file(s)?`)) return;
      
      fetch('post-handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete', files: filesToDelete })
      })
      .then(r => r.json())
      .then(data => {
        if (data.error) return alert('Delete error: ' + data.error);
        
        filesToDelete.forEach(f => {
          const idx = state.allVideos.indexOf(f);
          if (idx !== -1) state.allVideos.splice(idx, 1);
        });
        
        state.startIndex = Math.min(state.startIndex, Math.max(0, state.allVideos.length - state.totalCells));
        renderGrid();
      })
      .catch(() => alert('Delete failed'));
    }
  };
  
  return selectBtn;
}

function createMuteButton(mediaEl) {
  const muteBtn = document.createElement('button');
  muteBtn.className = 'mute-btn';
  muteBtn.innerHTML = mediaEl.muted ? '🔇' : '🔊';
  muteBtn.style.cssText = buttonStyle;
  
  muteBtn.onclick = e => {
    e.stopPropagation();
    document.querySelectorAll('#grid audio, #grid video').forEach(m => m.muted = true);
    mediaEl.muted = false;
    state.lastFullscreen = { file: null, time: 0 };
    mediaEl.play().catch(() => {});
    syncMuteIcons();
  };
  
  return muteBtn;
}

export function syncMuteIcons() {
  document.querySelectorAll('#grid .video-container').forEach(container => {
    const media = container.querySelector('video, audio');
    const btn = container.querySelector('.mute-btn');
    if (media && btn) btn.innerHTML = media.muted ? '🔇' : '🔊';
  });
}