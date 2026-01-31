// ui.js - UI components and overlay creation
import { state } from './state.js';
import { startFullscreenFrom } from './fullscreen.js';
import { renderGrid } from './grid.js';

export function addFileInfoOverlay(container, file, isAudited) {
  container.style.position ||= 'relative';

  const overlay = document.createElement('div');
  overlay.className = 'overlay';

  const filenameElem = document.createElement('div');
  filenameElem.textContent = file;

  const metaElem = document.createElement('div');

  // Add audit status indicator
  if (!isAudited) {
    const auditStatus = document.createElement('div');
    auditStatus.textContent = '⚠️ NEW';
    auditStatus.style.cssText = 'color: #ffcc00; font-weight: bold; font-size: 11px; margin-top: 2px;';
    overlay.appendChild(filenameElem);
    overlay.appendChild(auditStatus);
  } else {
    overlay.appendChild(filenameElem);
  }

  overlay.appendChild(metaElem);
  container.appendChild(overlay);
}

export function addCentralOverlay(container, mediaEl, file) {
  const overlay = document.createElement('div');
  overlay.className = 'central-overlay';

  // Select/Delete button
  const selectBtn = createSelectButton(file);
  overlay.appendChild(selectBtn);

  // Audit button
  const auditBtn = createAuditButton(file, container);
  overlay.appendChild(auditBtn);

  // Fullscreen button
  const fsBtn = document.createElement('button');
  fsBtn.innerHTML = '⛶';
  fsBtn.title = 'Fullscreen';
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
}

function createSelectButton(file) {
  const selectBtn = document.createElement('button');
  selectBtn.innerHTML = '🗙';
  selectBtn.title = 'Select/Delete';
  selectBtn.dataset.file = file;
  selectBtn.dataset.selected = 'false';
  
  selectBtn.onclick = e => {
    e.stopPropagation();
    
    if (selectBtn.dataset.selected === 'false') {
      selectBtn.dataset.selected = 'true';
      return; // Stop here on first click
    }
    
    // Second click - show delete dialog
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
          // Remove from allVideos
          const idx = state.allVideos.indexOf(f);
          if (idx !== -1) state.allVideos.splice(idx, 1);
          
          // Remove from originalVideos
          const origIdx = state.originalVideos.indexOf(f);
          if (origIdx !== -1) state.originalVideos.splice(origIdx, 1);
          
          // Remove from audit status map
          delete state.auditStatusMap[f];
          
          // Remove from webToFsPathMap
          delete state.webToFsPathMap[f];
          
          // Remove from favorites map
          delete state.favoritesMap[f];
        });
        
        state.startIndex = Math.min(state.startIndex, Math.max(0, state.allVideos.length - state.totalCells));
        
        // Update the audit display to reflect the new counts
        import('./audit.js').then(module => {
          module.updateAuditDisplay();
        });
        
        renderGrid();
      })
      .catch(() => alert('Delete failed'));
  };
  
  return selectBtn;
}

function createAuditButton(file, container) {
  const auditBtn = document.createElement('button');
  auditBtn.innerHTML = '📋';
  auditBtn.title = 'Audit this file';
  
  auditBtn.onclick = async (e) => {
    e.stopPropagation();
    
    // Immediate visual feedback - change button appearance
    auditBtn.innerHTML = '⏳';
    auditBtn.disabled = true;
    
    // Get the filesystem path for this file using the map
    const webPath = file;
    const fsPath = state.webToFsPathMap[webPath];
    
    if (!fsPath) {
      auditBtn.innerHTML = '❌';
      setTimeout(() => {
        auditBtn.innerHTML = '📋';
        auditBtn.disabled = false;
      }, 1500);
      console.error('Could not find filesystem path for:', webPath);
      return;
    }
    
    // IMMEDIATELY update UI before API call
    state.auditStatusMap[webPath] = true;
    container.classList.remove('unaudited');
    
    // Update counter immediately
    import('./audit.js').then(module => {
      module.updateAuditDisplay();
    });
    
    try {
      // Now make the API call in the background
      const response = await fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'audit',
          file_paths: [fsPath]
        })
      });
      
      const data = await response.json();
      
      if (data.error) {
        // If API fails, revert the changes
        state.auditStatusMap[webPath] = false;
        container.classList.add('unaudited');
        
        import('./audit.js').then(module => {
          module.updateAuditDisplay();
        });
        
        auditBtn.innerHTML = '❌';
        setTimeout(() => {
          auditBtn.innerHTML = '📋';
          auditBtn.disabled = false;
        }, 1500);
        
        alert('Audit failed: ' + data.error);
        return;
      }
      
      // Success!
      auditBtn.innerHTML = '✓';
      
      setTimeout(() => {
        auditBtn.innerHTML = '📋';
        auditBtn.disabled = false;
      }, 1500);
      
    } catch (err) {
      // If request fails, revert the changes
      state.auditStatusMap[webPath] = false;
      container.classList.add('unaudited');
      
      import('./audit.js').then(module => {
        module.updateAuditDisplay();
      });
      
      console.error('Audit failed:', err);
      auditBtn.innerHTML = '❌';
      setTimeout(() => {
        auditBtn.innerHTML = '📋';
        auditBtn.disabled = false;
      }, 1500);
      
      alert('Audit failed: ' + err.message);
    }
  };
  
  return auditBtn;
}

function createMuteButton(mediaEl) {
  const muteBtn = document.createElement('button');
  muteBtn.className = 'mute-btn';
  muteBtn.innerHTML = mediaEl.muted ? '🔇' : '🔊';
  muteBtn.title = 'Toggle mute';
  
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