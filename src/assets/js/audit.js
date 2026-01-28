// audit.js - Audit functionality with SQLite backend
import { state } from './state.js';
import { renderGrid } from './grid.js';

export function runAudit() {
  // Get only the currently visible files (startIndex to startIndex + totalCells)
  const startIdx = state.startIndex;
  const endIdx = state.startIndex + state.totalCells;
  const filesToAudit = state.allFilesWithPaths.slice(startIdx, endIdx);
  
  console.log('Audit request:', {
    startIndex: state.startIndex,
    totalCells: state.totalCells,
    startIdx: startIdx,
    endIdx: endIdx,
    fileCount: filesToAudit.length,
    totalFiles: state.allFilesWithPaths.length,
    auditingVisible: 'Only currently visible files'
  });

  if (filesToAudit.length === 0) {
    alert('No files to audit!');
    return;
  }

  // IMMEDIATELY update UI before API call
  // Map filesystem paths to web paths correctly
  filesToAudit.forEach((fsPath) => {
    // Find the index of this filesystem path in allFilesWithPaths
    const fsIndex = state.allFilesWithPaths.indexOf(fsPath);
    if (fsIndex !== -1 && fsIndex < state.originalVideos.length) {
      // Get the corresponding web path
      const webPath = state.originalVideos[fsIndex];
      if (webPath) {
        state.auditStatusMap[webPath] = true;
      }
    }
  });
  
  // Update display immediately
  updateAuditDisplay();
  
  // Remove 'unaudited' class from all containers immediately
  refreshGridAuditStatus();
  
  // Change audit button to show it's processing
  const auditButton = document.getElementById('audit');
  const originalText = auditButton ? auditButton.innerHTML : '';
  if (auditButton) {
    auditButton.innerHTML = '⏳';
    auditButton.disabled = true;
  }

  // Send absolute file paths to the API
  fetch('api.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      action: 'audit',
      file_paths: filesToAudit
    })
  })
  .then(r => r.json())
  .then(data => {
    console.log('Audit response:', data);
    
    if (auditButton) {
      auditButton.innerHTML = '✓';
      setTimeout(() => {
        auditButton.innerHTML = originalText;
        auditButton.disabled = false;
      }, 1500);
    }
    
    if (data.error) {
      // Revert changes if API failed
      filesToAudit.forEach((fsPath) => {
        const fsIndex = state.allFilesWithPaths.indexOf(fsPath);
        if (fsIndex !== -1 && fsIndex < state.originalVideos.length) {
          const webPath = state.originalVideos[fsIndex];
          if (webPath) {
            state.auditStatusMap[webPath] = false;
          }
        }
      });
      
      updateAuditDisplay();
      
      // Re-add unaudited classes
      const containers = document.querySelectorAll('#grid .video-container');
      containers.forEach((container, idx) => {
        const visibleFiles = state.getVisibleFiles();
        const file = visibleFiles[idx];
        
        if (file && !state.auditStatusMap[file]) {
          container.classList.add('unaudited');
        }
      });
      
      const auditText = document.getElementById('audit-text');
      if (auditText) {
        auditText.innerHTML = `<span style="color: #ff4444;">❌ Error: ${data.error}</span>`;
      }
      console.error('Audit error:', data);
      return;
    }
    
    // Success - UI is already updated, just refresh display to be sure
    updateAuditDisplay();
  })
  .catch(err => {
    console.error('Audit failed:', err);
    
    if (auditButton) {
      auditButton.innerHTML = originalText;
      auditButton.disabled = false;
    }
    
    // Revert changes if request failed
    filesToAudit.forEach((fsPath) => {
      const fsIndex = state.allFilesWithPaths.indexOf(fsPath);
      if (fsIndex !== -1 && fsIndex < state.originalVideos.length) {
        const webPath = state.originalVideos[fsIndex];
        if (webPath) {
          state.auditStatusMap[webPath] = false;
        }
      }
    });
    
    updateAuditDisplay();
    
    alert('Audit failed: ' + err.message);
  });
}

export function updateAuditDisplay() {
  // Count total audited and unaudited from current state
  const totalFiles = state.originalVideos.length;
  let auditedCount = 0;
  let latestDate = '';
  
  // Count audited files from the audit status map
  state.originalVideos.forEach(webPath => {
    if (state.auditStatusMap[webPath]) {
      auditedCount++;
    }
  });
  
  const unauditedCount = totalFiles - auditedCount;
  
  // Get latest audit date (use current date as proxy since we just audited)
  latestDate = new Date().toISOString().slice(2, 10).replace(/-/g, '');
  
  const auditText = document.getElementById('audit-text');
  if (auditText) {
    auditText.innerHTML = `
      📅 ${latestDate} • ✅ ${auditedCount} • 
      <span id="unaudited-count" title="Click to filter unaudited files">
        ⚠️ ${unauditedCount}
      </span>
    `;
    
    // Re-setup the unaudited filter click handler
    import('./filter.js').then(module => {
      module.setupUnauditedFilter();
    });
  }
}

function refreshGridAuditStatus() {
  // Update all containers in the grid to reflect current audit status
  const containers = document.querySelectorAll('#grid .video-container');
  
  containers.forEach((container, idx) => {
    const visibleFiles = state.getVisibleFiles();
    const file = visibleFiles[idx];
    
    if (file && state.auditStatusMap[file]) {
      container.classList.remove('unaudited');
    }
  });
}