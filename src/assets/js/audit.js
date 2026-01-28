// audit.js - Audit functionality with SQLite backend
import { state } from './state.js';

export function runAudit() {
  // Get files up to current viewing position (startIndex + currently visible files)
  const endIndex = state.startIndex + state.totalCells;
  const filesToAudit = state.allFilesWithPaths.slice(0, endIndex);
  
  console.log('Audit request:', {
    startIndex: state.startIndex,
    totalCells: state.totalCells,
    endIndex: endIndex,
    fileCount: filesToAudit.length,
    totalFiles: state.allFilesWithPaths.length
  });

  if (filesToAudit.length === 0) {
    alert('No files to audit!');
    return;
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
    
    const auditText = document.getElementById('audit-text');
    if (auditText) {
      if (data.error) {
        auditText.innerHTML = `<span style="color: #ff4444;">❌ Error: ${data.error}</span>`;
        console.error('Audit error:', data);
      } else {
        const unauditedCount = state.allFilesWithPaths.length - data.total_audited;
        auditText.innerHTML = `
          📅 ${data.date} • ✅ ${data.total_audited} • 
          <span id="unaudited-count" style="cursor: pointer; text-decoration: underline;" title="Click to filter unaudited files">
            ⚠️ ${unauditedCount}
          </span>
        `;
        
        // Update audit status map for the audited files
        filesToAudit.forEach((fsPath, idx) => {
          const webPath = state.allVideos[idx];
          if (webPath) {
            state.auditStatusMap[webPath] = true;
          }
        });
        
        // Re-setup the unaudited filter click handler
        import('./filter.js').then(module => {
          module.setupUnauditedFilter();
        });
        
        // Show success message
        // alert(`Successfully audited ${data.count} files!`);
      }
    }
  })
  .catch(err => {
    console.error('Audit failed:', err);
    alert('Audit failed: ' + err.message);
  });
}