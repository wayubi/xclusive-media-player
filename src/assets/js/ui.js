// ui.js - UI components and overlay creation
import { state } from './state.js';
import { startFullscreenFrom } from './fullscreen.js';
import { renderGrid } from './grid.js';

// Track selected tiles for keyboard delete operations
let selectedTiles = new Set();
let selectAllMode = false;

export function isSelectAllMode() {
  return selectAllMode;
}

function showScaryDeleteConfirmation() {
  return new Promise((resolve) => {
    // Create modal overlay
    const overlay = document.createElement('div');
    overlay.id = 'scary-delete-modal';
    overlay.style.cssText = `
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.9);
      display: flex;
      justify-content: center;
      align-items: center;
      z-index: 999999;
      animation: fadeIn 0.3s ease;
    `;

    // Create modal content
    const modal = document.createElement('div');
    modal.style.cssText = `
      background: linear-gradient(135deg, #8B0000 0%, #4a0000 100%);
      border: 4px solid #ff0000;
      border-radius: 20px;
      padding: 40px;
      max-width: 600px;
      text-align: center;
      box-shadow: 0 0 50px rgba(255, 0, 0, 0.8), inset 0 0 30px rgba(0, 0, 0, 0.5);
      animation: shake 0.5s ease-in-out;
    `;

    // Warning icon
    const icon = document.createElement('div');
    icon.textContent = '⚠️';
    icon.style.cssText = `
      font-size: 80px;
      margin-bottom: 20px;
      animation: pulse 1s ease-in-out infinite;
    `;

    // Title
    const title = document.createElement('h2');
    title.textContent = '⚠️ DANGER ZONE ⚠️';
    title.style.cssText = `
      color: #ffff00;
      font-size: 32px;
      font-weight: bold;
      margin: 0 0 20px 0;
      text-shadow: 3px 3px 6px rgba(0, 0, 0, 0.8);
      letter-spacing: 3px;
    `;

    // Warning text
    const warning = document.createElement('p');
    warning.innerHTML = `
      <strong style="color: #ff0000; font-size: 24px;">YOU ARE ABOUT TO DELETE:</strong><br><br>
      <span style="color: #ffffff; font-size: 20px;">
        • ALL files in this folder<br>
        • ALL subfolders recursively<br>
        • THE FOLDER ITSELF<br><br>
      </span>
      <span style="color: #ff6666; font-size: 18px;">
        This action is <strong style="color: #ffff00;">PERMANENT</strong> and <strong style="color: #ffff00;">CANNOT BE UNDONE!</strong>
      </span>
    `;
    warning.style.cssText = `
      color: #ffffff;
      font-size: 18px;
      line-height: 1.6;
      margin: 20px 0;
    `;

    // Final warning
    const finalWarning = document.createElement('p');
    finalWarning.textContent = 'Are you absolutely sure you want to proceed?';
    finalWarning.style.cssText = `
      color: #ffff00;
      font-size: 22px;
      font-weight: bold;
      margin: 30px 0;
      text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.8);
    `;

    // Button container
    const buttonContainer = document.createElement('div');
    buttonContainer.style.cssText = `
      display: flex;
      gap: 20px;
      justify-content: center;
      margin-top: 30px;
    `;

    // NO button
    const noBtn = document.createElement('button');
    noBtn.textContent = 'NO, CANCEL';
    noBtn.style.cssText = `
      background: linear-gradient(135deg, #228B22, #006400);
      color: white;
      border: 3px solid #00ff00;
      padding: 15px 40px;
      font-size: 20px;
      font-weight: bold;
      border-radius: 10px;
      cursor: pointer;
      box-shadow: 0 5px 15px rgba(0, 255, 0, 0.4);
      transition: all 0.2s;
    `;
    noBtn.onmouseover = () => {
      noBtn.style.transform = 'scale(1.05)';
      noBtn.style.boxShadow = '0 8px 20px rgba(0, 255, 0, 0.6)';
    };
    noBtn.onmouseout = () => {
      noBtn.style.transform = 'scale(1)';
      noBtn.style.boxShadow = '0 5px 15px rgba(0, 255, 0, 0.4)';
    };

    // YES button
    const yesBtn = document.createElement('button');
    yesBtn.textContent = 'YES, DELETE EVERYTHING';
    yesBtn.style.cssText = `
      background: linear-gradient(135deg, #8B0000, #4a0000);
      color: white;
      border: 3px solid #ff0000;
      padding: 15px 40px;
      font-size: 20px;
      font-weight: bold;
      border-radius: 10px;
      cursor: pointer;
      box-shadow: 0 5px 15px rgba(255, 0, 0, 0.4);
      transition: all 0.2s;
    `;
    yesBtn.onmouseover = () => {
      yesBtn.style.transform = 'scale(1.05)';
      yesBtn.style.boxShadow = '0 8px 20px rgba(255, 0, 0, 0.8)';
    };
    yesBtn.onmouseout = () => {
      yesBtn.style.transform = 'scale(1)';
      yesBtn.style.boxShadow = '0 5px 15px rgba(255, 0, 0, 0.4)';
    };

    // Add click handlers
    noBtn.onclick = () => {
      document.body.removeChild(overlay);
      resolve(false);
    };

    yesBtn.onclick = () => {
      document.body.removeChild(overlay);
      resolve(true);
    };

    // Keyboard handler for ESC
    const keyHandler = (e) => {
      if (e.key === 'Escape') {
        document.body.removeChild(overlay);
        document.removeEventListener('keydown', keyHandler);
        resolve(false);
      }
    };
    document.addEventListener('keydown', keyHandler);

    // Assemble modal
    buttonContainer.appendChild(noBtn);
    buttonContainer.appendChild(yesBtn);
    modal.appendChild(icon);
    modal.appendChild(title);
    modal.appendChild(warning);
    modal.appendChild(finalWarning);
    modal.appendChild(buttonContainer);
    overlay.appendChild(modal);

    // Add animations
    const style = document.createElement('style');
    style.textContent = `
      @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
      }
      @keyframes shake {
        0%, 100% { transform: translateX(0); }
        10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
        20%, 40%, 60%, 80% { transform: translateX(5px); }
      }
      @keyframes pulse {
        0%, 100% { transform: scale(1); }
        50% { transform: scale(1.1); }
      }
    `;
    document.head.appendChild(style);

    // Show modal
    document.body.appendChild(overlay);
  });
}

export function setSelectAllMode(value) {
  selectAllMode = value;
}

export function toggleTileSelection(index) {
  const containers = document.querySelectorAll('#grid .video-container');
  if (index < 0 || index >= containers.length) return false;
  
  const container = containers[index];
  const selectBtn = container.querySelector('button[data-file]');
  
  if (!selectBtn || selectBtn.dataset.selected === undefined) return false;
  
  const isSelected = selectBtn.dataset.selected === 'true';
  
  if (isSelected) {
    // Unselect - remove red styling
    selectBtn.dataset.selected = 'false';
    selectBtn.classList.remove('selected');
    container.classList.remove('selected-for-delete');
    selectedTiles.delete(index);
  } else {
    // Select - add red border and DELETE badge
    selectBtn.dataset.selected = 'true';
    selectBtn.classList.add('selected');
    container.classList.add('selected-for-delete');
    selectedTiles.add(index);
  }
  
  return true;
}

export async function confirmDelete() {
  const selected = Array.from(document.querySelectorAll('#grid .video-container button[data-selected="true"]'));
  const filesToDelete = selected.map(b => b.dataset.file);
  
  if (!filesToDelete.length && !selectAllMode) return;
  
  // If in select all mode, delete the entire folder recursively
  if (selectAllMode) {
    const currentFolder = getCurrentFolderPath();
    if (!currentFolder) {
      alert('Cannot determine current folder');
      return;
    }
    
    // Show scary confirmation dialog
    const confirmed = await showScaryDeleteConfirmation();
    if (!confirmed) return;
    
    fetch('post-handler.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ 
        action: 'delete', 
        recursive: true, 
        delete_folder: true,
        folder: currentFolder 
      })
    })
    .then(r => r.json())
    .then(data => {
      if (data.error) return alert('Delete error: ' + data.error);
      
      // Clear selection state
      selectedTiles.clear();
      selectAllMode = false;
      
      // Navigate to parent folder
      if (data.parent_path) {
        navigateToFolder(data.parent_path);
      } else {
        // Fallback: refresh the grid
        renderGrid();
      }
    })
    .catch(() => alert('Delete failed'));
  } else {
    // Original single/multi file deletion
    if (!confirm(`Delete ${filesToDelete.length} file(s)?`)) return;
    
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
        
        const origIdx = state.originalVideos.indexOf(f);
        if (origIdx !== -1) state.originalVideos.splice(origIdx, 1);
        
        delete state.auditStatusMap[f];
        delete state.webToFsPathMap[f];
        delete state.favoritesMap[f];
      });
      
      state.startIndex = Math.min(state.startIndex, Math.max(0, state.allVideos.length - state.totalCells));
      selectedTiles.clear();
      
      import('./audit.js').then(module => {
        module.updateAuditDisplay();
      });
      
      renderGrid();
    })
    .catch(() => alert('Delete failed'));
  }
}

export function selectAllFiles() {
  // Select all visible tiles
  const containers = document.querySelectorAll('#grid .video-container');
  containers.forEach((container, index) => {
    const selectBtn = container.querySelector('button[data-file]');
    if (selectBtn && selectBtn.dataset.selected === 'false') {
      selectBtn.dataset.selected = 'true';
      selectBtn.classList.add('selected');
      container.classList.add('selected-for-delete');
      selectedTiles.add(index);
    }
  });
  
  selectAllMode = true;
}

export function clearAllSelections() {
  const containers = document.querySelectorAll('#grid .video-container');
  containers.forEach((container) => {
    const selectBtn = container.querySelector('button[data-file]');
    if (selectBtn) {
      selectBtn.dataset.selected = 'false';
      selectBtn.classList.remove('selected');
    }
    container.classList.remove('selected-for-delete');
  });
  
  selectedTiles.clear();
  selectAllMode = false;
}

function getCurrentFolderPath() {
  // currentPath is an absolute filesystem path string (e.g., "/root/xclusive/volumes/MyFolder")
  if (!state.currentPath || state.currentPath === '') {
    return '/volumes';
  }
  
  // Convert filesystem path to web path
  // e.g., "/root/xclusive/volumes/MyFolder" → "/volumes/MyFolder"
  const volumesIndex = state.currentPath.indexOf('/volumes/');
  if (volumesIndex !== -1) {
    return state.currentPath.substring(volumesIndex);
  }
  
  // If it doesn't contain /volumes/, just return /volumes
  return '/volumes';
}

function navigateToFolder(path) {
  // Navigate to a folder by modifying the URL
  const url = new URL(window.location.href);
  
  if (path === '/volumes' || path === '') {
    // Root folder - clear path params
    url.searchParams.delete('path[]');
  } else {
    // Convert path to array format
    const cleanPath = path.replace('/volumes/', '').replace('/volumes', '');
    if (cleanPath) {
      const pathParts = cleanPath.split('/').filter(p => p);
      url.searchParams.delete('path[]');
      pathParts.forEach(part => {
        url.searchParams.append('path[]', part);
      });
    }
  }
  
  // Add cache buster
  url.searchParams.set('t', Date.now().toString());
  
  window.location.href = url.toString();
}

export function getSelectedTileCount() {
  return selectedTiles.size;
}

export function clearSelectedTiles() {
  selectedTiles.clear();
}

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

  // Check if this is an unsupported video
  const isUnsupported = container.classList.contains('unsupported-video');
  
  // Check if this is a text file
  const isTextFile = container.classList.contains('text-file-container');

  // Select/Delete button - only show if deletes are enabled
  if (state.deleteEnabled) {
    const selectBtn = createSelectButton(file);
    overlay.appendChild(selectBtn);
  }

  // Audit button
  const auditBtn = createAuditButton(file, container);
  overlay.appendChild(auditBtn);

  // Fullscreen/View button - skip for unsupported videos
  if (!isUnsupported) {
    const fsBtn = document.createElement('button');
    fsBtn.innerHTML = '⛶';
    fsBtn.title = isTextFile ? 'View Fullscreen' : 'Fullscreen';
    fsBtn.onclick = e => {
      e.stopPropagation();
      if (isTextFile) {
        // For text files, import and call showTextFullscreen
        import('./mediaContainer.js').then(module => {
          module.showTextFullscreen(file);
        });
      } else {
        const time = mediaEl && typeof mediaEl.currentTime === 'number' ? mediaEl.currentTime : 0;
        startFullscreenFrom(file, time);
      }
    };
    overlay.appendChild(fsBtn);
  }

  // Mute button for audio/video - skip for unsupported videos and text files
  if (!isUnsupported && !isTextFile && mediaEl && (mediaEl.tagName === 'VIDEO' || mediaEl.tagName === 'AUDIO')) {
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
    
    // Find the index of this button's container
    const container = selectBtn.closest('.video-container');
    const containers = document.querySelectorAll('#grid .video-container');
    const index = Array.from(containers).indexOf(container);
    
    if (selectBtn.dataset.selected === 'false') {
      // Select - add red border and DELETE badge
      selectBtn.dataset.selected = 'true';
      selectBtn.classList.add('selected');
      container.classList.add('selected-for-delete');
      if (index !== -1) selectedTiles.add(index);
      return; // Stop here on first click
    }
    
    // Second click - show delete dialog (handled by DEL key or second click)
    confirmDelete();
  };
  
  return selectBtn;
}

function createAuditButton(file, container) {
  const auditBtn = document.createElement('button');
  auditBtn.innerHTML = '📋';
  auditBtn.title = 'Audit this file';
  
  auditBtn.onclick = async (e) => {
    e.stopPropagation();
    
    // Capture audit context to prevent race conditions
    const auditContext = state.startAuditContext();
    
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
      
      // Check if user navigated away during the audit
      const contextStillValid = state.isValidAuditContext(auditContext);
      
      if (data.error) {
        // Only revert changes if user is still viewing the same file
        if (contextStillValid) {
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
        } else {
          console.log('Audit failed but user navigated away - not reverting stale changes');
        }
        return;
      }
      
      // Success! Clear context
      state.clearAuditContext();
      
      // Only update button if we're still in context
      if (contextStillValid) {
        auditBtn.innerHTML = '✓';
        
        setTimeout(() => {
          auditBtn.innerHTML = '📋';
          auditBtn.disabled = false;
        }, 1500);
      }
      
    } catch (err) {
      // Check if user navigated away during the audit
      const contextStillValid = state.isValidAuditContext(auditContext);
      
      // Only revert changes if user is still viewing the same file
      if (contextStillValid) {
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
      } else {
        console.log('Audit failed but user navigated away - not reverting stale changes');
      }
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