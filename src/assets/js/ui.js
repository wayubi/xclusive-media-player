// ui.js - UI components and overlay creation
import { state } from './state.js';
import { startFullscreenFrom } from './fullscreen.js';
import { renderGrid } from './grid.js';
import { isTerminalActive } from './terminal.js';

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
    overlay.className = 'delete-modal-overlay';

    // Create modal content
    const modal = document.createElement('div');
    modal.className = 'delete-modal-card';

    // Warning icon
    const icon = document.createElement('div');
    icon.textContent = '⚠️';
    icon.className = 'delete-modal-icon';

    // Title
    const title = document.createElement('h2');
    title.textContent = '⚠️ DANGER ZONE ⚠️';
    title.className = 'delete-modal-title';

    // Warning text
    const warning = document.createElement('p');
    warning.className = 'delete-modal-warning';
    warning.innerHTML = `
      <strong class="delete-danger-text">YOU ARE ABOUT TO DELETE:</strong><br><br>
      <span class="delete-white-text">
        • ALL files in this folder<br>
        • ALL subfolders recursively<br>
        • THE FOLDER ITSELF<br><br>
      </span>
      <span class="delete-muted-text">
        This action is <strong class="delete-em-text">PERMANENT</strong> and <strong class="delete-em-text">CANNOT BE UNDONE!</strong>
      </span>
    `;

    // Final warning
    const finalWarning = document.createElement('p');
    finalWarning.textContent = 'Are you absolutely sure you want to proceed?';
    finalWarning.className = 'delete-modal-final';

    // Button container
    const buttonContainer = document.createElement('div');
    buttonContainer.className = 'delete-modal-actions';

    // NO button
    const noBtn = document.createElement('button');
    noBtn.textContent = 'NO, CANCEL';
    noBtn.className = 'delete-btn-cancel';

    // YES button
    const yesBtn = document.createElement('button');
    yesBtn.textContent = 'YES, DELETE EVERYTHING';
    yesBtn.className = 'delete-btn-confirm';

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
      // Don't process if terminal is active
      if (isTerminalActive()) return;
      
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
    if (!currentFolder || currentFolder === '/volumes') {
      alert('Cannot delete root volumes folder');
      return;
    }
    
    // Show scary confirmation dialog
    const confirmed = await showScaryDeleteConfirmation();
    if (!confirmed) return;
    
    fetch('api.php', {
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

      if (data.status === 'partial') {
        const msg = data.message + '\n\nFavorited files skipped:\n' +
          data.skipped_favorited.map(f => '  • ' + f.split('/').pop()).join('\n');
        alert(msg);

        if (data.parent_path) {
          navigateToFolder(data.parent_path);
        } else {
          renderGrid();
        }
        return;
      }

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
    
    fetch('api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'delete', files: filesToDelete })
    })
    .then(r => r.json())
    .then(data => {
      if (data.error) return alert('Delete error: ' + data.error);

      const skipped = [];
      filesToDelete.forEach(f => {
        const result = data.results?.[f];
        if (result === 'deleted') {
          const idx = state.allVideos.indexOf(f);
          if (idx !== -1) state.allVideos.splice(idx, 1);

          const origIdx = state.originalVideos.indexOf(f);
          if (origIdx !== -1) state.originalVideos.splice(origIdx, 1);

          delete state.auditStatusMap[f];
          delete state.webToFsPathMap[f];
          delete state.favoritesMap[f];
        } else if (result === 'skipped_favorited') {
          skipped.push(f);
        }
      });

      if (skipped.length > 0) {
        alert(`Deleted ${filesToDelete.length - skipped.length} file(s).\n\nSkipped ${skipped.length} favorited file(s):\n` +
          skipped.map(f => '  • ' + f.split('/').pop()).join('\n'));
      }
      
      state.startIndex = Math.min(state.startIndex, Math.max(0, state.allVideos.length - state.totalCells));
      selectedTiles.clear();
      
      import('./audit.js').then(module => {
        module.updateAuditDisplay();
      });
      
      renderGrid();
      
      // Update folder count in dropdown
      const folderSelect = document.getElementById('folder-select');
      if (folderSelect) {
        const currentOption = folderSelect.querySelector('option[value=""]');
        if (currentOption) {
          const text = currentOption.textContent.trim();
          const match = text.match(/^(.+\s)(\(\d+\))$/);
          if (match) {
            const totalCount = state.originalVideos.length;
            currentOption.textContent = match[1] + '(' + totalCount + ')';
          }
        }
      }
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

export function selectAllVisibleTiles() {
  // Select all visible tiles WITHOUT setting selectAllMode (normal multi-select)
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
  // NOTE: Do NOT set selectAllMode = true here
}

export function areAllVisibleTilesSelected() {
  const containers = document.querySelectorAll('#grid .video-container');
  if (containers.length === 0) return false;
  
  return Array.from(containers).every(container => {
    const selectBtn = container.querySelector('button[data-file]');
    return selectBtn && selectBtn.dataset.selected === 'true';
  });
}

function getCurrentFolderPath() {
  if (!state.currentPath || state.currentPath === '') {
    return null;
  }
  
  // Simply prepend /volumes
  return '/volumes' + state.currentPath;
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
  const overlay = document.createElement('div');
  overlay.className = 'overlay';

  const filenameElem = document.createElement('div');
  filenameElem.className = 'overlay-title';
  filenameElem.textContent = file;

  const metaElem = document.createElement('div');
  metaElem.className = 'overlay-meta';

  // Add audit status indicator
  if (!isAudited) {
    const auditStatus = document.createElement('div');
    auditStatus.textContent = '⚠️ NEW';
    auditStatus.className = 'audit-new-badge';
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
  if (state.permissions.includes('delete')) {
    const selectBtn = createSelectButton(file);
    overlay.appendChild(selectBtn);
  }

  // Audit button - only show if audit permission granted
  if (state.permissions.includes('audit')) {
    const auditBtn = createAuditButton(file, container);
    overlay.appendChild(auditBtn);
  }

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

  // Share button - show for all media elements (video, image, audio)
  if (!isUnsupported && !isTextFile && mediaEl && (mediaEl.tagName === 'VIDEO' || mediaEl.tagName === 'IMG' || mediaEl.tagName === 'AUDIO')) {
    const isVideo = mediaEl.tagName === 'VIDEO';
    const isAudio = mediaEl.tagName === 'AUDIO';
    const shareBtn = document.createElement('button');
    shareBtn.innerHTML = '📤';
    shareBtn.title = 'Share to Mastodon';
    shareBtn.onclick = e => {
      e.stopPropagation();
      import('./share.js').then(module => {
        module.openShareModal(file, isVideo, isAudio);
      });
    };
    overlay.appendChild(shareBtn);
  }

  container.appendChild(overlay);
}

function createSelectButton(file) {
  const selectBtn = document.createElement('button');
  selectBtn.innerHTML = '✕';
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