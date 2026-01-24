// audit.js - Audit functionality
import { state } from './state.js';

export function runAudit() {
  // Extract filenames from all current files
  // allFilesWithPaths contains filesystem paths like: /full/path/to/volumes/folder/file.mp4
  // We need just the filename
  const filenames = state.allFilesWithPaths.map(path => {
    // Handle both forward and back slashes
    const normalized = path.replace(/\\/g, '/');
    const parts = normalized.split('/');
    return parts[parts.length - 1];
  }).filter(f => f && f.length > 0); // Remove any empty entries

  console.log('Audit request:', {
    path: state.auditPath,
    filenameCount: filenames.length,
    totalFiles: state.allFilesWithPaths.length,
    firstFew: filenames.slice(0, 5),
    samplePath: state.allFilesWithPaths[0]
  });

  if (filenames.length === 0) {
    alert('No files to audit!');
    return;
  }

  fetch('post-handler.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      action: 'audit',
      path: state.auditPath,
      filenames: filenames
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
        auditText.innerText = `[ ${data.date} / ${data.count} / 0 ]`;
        // Update state with new audited filenames
        state.auditedFilenames = filenames;
      }
    }
  })
  .catch(err => {
    console.error('Audit failed:', err);
    alert('Audit failed');
  });
}