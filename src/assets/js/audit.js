// audit.js - Audit functionality
import { state } from './state.js';

export function runAudit(count) {
  fetch('post-handler.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      action: 'audit',
      path: state.auditPath,
      count
    })
  })
  .then(r => r.json())
  .then(data => {
    const auditText = document.getElementById('audit-text');
    if (auditText) {
      auditText.innerText = data.error 
        ? `[ Error: ${data.error} ]` 
        : `[ ${data.text} ]`;
    }
  })
  .catch(() => alert('Audit failed'));
}