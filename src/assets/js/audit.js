// audit.js - Audit functionality
import { state } from './state.js';

export function runAudit() {
  // Get files up to current viewing position (startIndex + currently visible files)
  const endIndex = state.startIndex + state.totalCells;
  const filesToAudit = state.allFilesWithPaths.slice(0, endIndex);
  
  // Extract filenames from the files we want to audit
  const newFilenames = filesToAudit.map(path => {
    const normalized = path.replace(/\\/g, '/');
    const parts = normalized.split('/');
    return parts[parts.length - 1];
  }).filter(f => f && f.length > 0);

  // Merge with existing audited filenames (union to avoid duplicates)
  const mergedFilenames = [...new Set([...state.auditedFilenames, ...newFilenames])];

  console.log('Audit request:', {
    path: state.auditPath,
    startIndex: state.startIndex,
    totalCells: state.totalCells,
    endIndex: endIndex,
    newFilenameCount: newFilenames.length,
    existingAuditedCount: state.auditedFilenames.length,
    mergedCount: mergedFilenames.length,
    totalFiles: state.allFilesWithPaths.length,
    firstFew: newFilenames.slice(0, 5)
  });

  if (mergedFilenames.length === 0) {
    alert('No files to audit!');
    return;
  }

  fetch('post-handler.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      action: 'audit',
      path: state.auditPath,
      filenames: mergedFilenames
    })
  })
  .then(r => r.json())
  .then(data => {
    console.log('Audit response:', data);
    
    const auditText = document.getElementById('audit-text');
    if (auditText) {
      if (data.error) {
        auditText.innerText = `[ Error: ${data.error} ]`;
        console.error('Audit error:', data);
      } else {
        const unauditedCount = state.allFilesWithPaths.length - data.count;
        auditText.innerText = `[ ${data.date} / ${data.count} / ${unauditedCount} ]`;
        // Update state with merged audited filenames
        state.auditedFilenames = mergedFilenames;
      }
    }
  })
  .catch(err => {
    console.error('Audit failed:', err);
    alert('Audit failed');
  });
}