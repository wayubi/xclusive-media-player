// audit.js - Audit functionality with SQLite backend
import { state } from './state.js';
import { renderGrid } from './grid.js';

let lastAuditClickTime = 0;
let auditTimeout = null;
const DOUBLE_CLICK_THRESHOLD = 400; // ms - casual double-click window

export function runAudit() {
  const now = Date.now();
  const timeSinceLastClick = now - lastAuditClickTime;
  const isDoubleClick = timeSinceLastClick < DOUBLE_CLICK_THRESHOLD && timeSinceLastClick > 0;
  
  // Clear any pending single-click timeout
  if (auditTimeout) {
    clearTimeout(auditTimeout);
    auditTimeout = null;
  }
  
  if (isDoubleClick) {
    // Double-click detected: audit ALL files in the folder
    lastAuditClickTime = 0; // Reset to prevent triple-clicks
    auditAllFiles();
  } else {
    // First click: wait to see if there's a second click
    lastAuditClickTime = now;
    auditTimeout = setTimeout(() => {
      auditCurrentView();
      auditTimeout = null;
    }, DOUBLE_CLICK_THRESHOLD);
  }
}

export function auditCurrentView() {
  // Get only the currently visible files (startIndex to startIndex + totalCells)
  const startIdx = state.startIndex;
  const endIdx = state.startIndex + state.totalCells;
  
  // Get the visible web paths (this respects filters)
  const visibleWebPaths = state.allVideos.slice(startIdx, endIdx);
  
  // Convert web paths to filesystem paths for the API
  // Use the webToFsPathMap to get the correct filesystem path
  const filesToAudit = visibleWebPaths.map(webPath => {
    return state.webToFsPathMap[webPath];
  }).filter(path => path); // Remove any undefined entries
  
  console.log('Audit request (current view):', {
    startIndex: state.startIndex,
    totalCells: state.totalCells,
    startIdx: startIdx,
    endIdx: endIdx,
    fileCount: filesToAudit.length,
    visibleWebPaths: visibleWebPaths.length,
    auditingVisible: 'Only currently visible files (respects filters)'
  });

  if (filesToAudit.length === 0) {
    alert('No files to audit!');
    return;
  }
  
  performAudit(filesToAudit, visibleWebPaths, 'current view');
}

function auditAllFiles() {
  // Audit ALL files in the current folder (all original videos)
  const allWebPaths = state.originalVideos;
  
  // Convert all web paths to filesystem paths using the map
  const filesToAudit = allWebPaths.map(webPath => {
    return state.webToFsPathMap[webPath];
  }).filter(path => path);
  
  console.log('Audit request (ALL FILES):', {
    fileCount: filesToAudit.length,
    auditingAll: 'Auditing entire folder'
  });
  
  if (filesToAudit.length === 0) {
    alert('No files to audit!');
    return;
  }
  
  if (!confirm(`Audit ALL ${filesToAudit.length} files in this folder?`)) {
    return;
  }
  
  performAudit(filesToAudit, allWebPaths, 'all files');
}

function performAudit(filesToAudit, webPaths, mode) {
  // Capture audit context before starting to prevent race conditions
  // when user navigates while audit is in progress
  const auditContext = state.startAuditContext();
  
  // IMMEDIATELY update UI before API call
  // Update audit status for the web paths
  webPaths.forEach((webPath) => {
    state.auditStatusMap[webPath] = true;
  });
  
  // Update display immediately
  updateAuditDisplay();
  
  // Remove 'unaudited' class from all containers immediately
  refreshGridAuditStatus();
  
  // Change audit button to show it's processing
  const auditButton = document.getElementById('audit');
  const originalText = auditButton ? auditButton.innerHTML : '';
  if (auditButton) {
    auditButton.innerHTML = mode === 'all files' ? '⏳ ALL' : '⏳';
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
    console.log(`Audit response (${mode}):`, data);
    
    // Check if user navigated away during the audit
    const contextStillValid = state.isValidAuditContext(auditContext);
    
    if (auditButton) {
      auditButton.innerHTML = '✓';
      setTimeout(() => {
        auditButton.innerHTML = originalText;
        auditButton.disabled = false;
      }, 1500);
    }
    
    if (data.error) {
      // Only revert changes if user is still in the same view
      if (contextStillValid) {
        webPaths.forEach((webPath) => {
          state.auditStatusMap[webPath] = false;
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
      } else {
        console.log('Audit failed but user navigated away - not reverting stale changes');
      }
      
      console.error('Audit error:', data);
      return;
    }
    
    // Success - clear context and refresh display
    state.clearAuditContext();
    
    // Only update UI if user is still in the same view
    if (contextStillValid) {
      updateAuditDisplay();
    }
  })
  .catch(err => {
    console.error('Audit failed:', err);
    
    // Check if user navigated away during the audit
    const contextStillValid = state.isValidAuditContext(auditContext);
    
    if (auditButton) {
      auditButton.innerHTML = originalText;
      auditButton.disabled = false;
    }
    
    // Only revert changes if user is still in the same view
    if (contextStillValid) {
      webPaths.forEach((webPath) => {
        state.auditStatusMap[webPath] = false;
      });
      
      updateAuditDisplay();
      alert('Audit failed: ' + err.message);
    } else {
      console.log('Audit failed but user navigated away - not reverting stale changes');
    }
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