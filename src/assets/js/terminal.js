// terminal.js - Functional terminal interface for real filesystem commands
import { state } from './state.js';

let terminalActive = false;
let terminalElement = null;
let outputElement = null;
let inputElement = null;
let commandHistory = [];
let historyIndex = -1;
let previousMuteStates = new Map();
let currentDirectory = '/volumes';  // Web path like "/volumes" or "/videos/action"
let terminalMode = 'transparent'; // 'privacy' or 'transparent'

const ALLOWED_COMMANDS = ['ls', 'cd', 'pwd', 'rm', 'rmdir', 'cat', 'mkdir', 'cp', 'mv', 'clear', 'help', 'exit', 'whoami'];

export function initTerminal() {
  // Terminal is created on first use
}

export function toggleTerminal(startWebPath = null, mode = 'transparent') {
  if (terminalActive) {
    hideTerminal();
  } else {
    showTerminal(startWebPath, mode);
  }
}

export function isTerminalActive() {
  return terminalActive;
}

function showTerminal(startWebPath = null, mode = 'transparent') {
  if (!terminalElement) {
    createTerminal();
  }

  // Set the mode
  terminalMode = mode;
  terminalElement.setAttribute('data-mode', mode);

  // Set starting directory if provided
  // startWebPath is relative to /volumes (e.g., "unsorted/hdd-700G/youtube" or "")
  if (startWebPath !== null) {
    // Remove leading slash if present to avoid double slashes
    const cleanPath = startWebPath.replace(/^\//, '');
    currentDirectory = '/volumes' + (cleanPath ? '/' + cleanPath : '');
  }

  terminalElement.classList.add('active');
  terminalActive = true;

  // Mute all video/audio elements and save their previous state
  previousMuteStates.clear();
  document.querySelectorAll('video, audio').forEach(media => {
    previousMuteStates.set(media, media.muted);
    media.muted = true;
  });

  // Focus the input
  setTimeout(() => {
    if (inputElement) {
      inputElement.focus();
    }
  }, 100);

  // Show welcome message
  clearOutput();
  printToTerminal('XCLUSIVE SECURE TERMINAL v2.0', 'success');
  printToTerminal('Type "help" for available commands', 'info');
  printToTerminal('');
  updatePrompt();
}

function hideTerminal() {
  if (terminalElement) {
    terminalElement.classList.remove('active');
    terminalElement.removeAttribute('data-mode');
  }
  terminalActive = false;
  terminalMode = 'transparent';

  // Restore previous mute states
  previousMuteStates.forEach((wasMuted, media) => {
    media.muted = wasMuted;
  });
  previousMuteStates.clear();

  // Update mute icons
  if (window.syncMuteIcons) {
    syncMuteIcons();
  }
}

function createTerminal() {
  terminalElement = document.createElement('div');
  terminalElement.id = 'terminal-overlay';
  terminalElement.innerHTML = `
    <div class="terminal-container">
      <div class="terminal-header">
        <span class="terminal-title">XCLUSIVE TERMINAL</span>
        <span class="terminal-close">×</span>
      </div>
      <div class="terminal-output"></div>
      <div class="terminal-input-line">
        <span class="terminal-prompt">root@xclusive:~$</span>
        <input type="text" class="terminal-input" spellcheck="false" autocomplete="off" autocapitalize="off">
      </div>
    </div>
  `;
  
  document.body.appendChild(terminalElement);
  
  outputElement = terminalElement.querySelector('.terminal-output');
  inputElement = terminalElement.querySelector('.terminal-input');
  
  // Handle input
  inputElement.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      const command = inputElement.value.trim();
      if (command) {
        commandHistory.push(command);
        historyIndex = commandHistory.length;
        processCommand(command);
      }
      inputElement.value = '';
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      if (historyIndex > 0) {
        historyIndex--;
        inputElement.value = commandHistory[historyIndex];
      }
    } else if (e.key === 'ArrowDown') {
      e.preventDefault();
      if (historyIndex < commandHistory.length - 1) {
        historyIndex++;
        inputElement.value = commandHistory[historyIndex];
      } else {
        historyIndex = commandHistory.length;
        inputElement.value = '';
      }
    } else if (e.key === 'Escape') {
      e.preventDefault();
      e.stopPropagation();
      hideTerminal();
    } else if (e.key === 'Tab') {
      e.preventDefault();
      handleTabCompletion();
    }
  });
  
  // Handle close button
  const closeBtn = terminalElement.querySelector('.terminal-close');
  if (closeBtn) {
    closeBtn.addEventListener('click', hideTerminal);
  }
  
  // Handle ESC on the terminal container
  terminalElement.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      e.preventDefault();
      e.stopPropagation();
      hideTerminal();
    }
  });
  
  // Focus input when clicking anywhere in terminal
  terminalElement.addEventListener('click', (e) => {
    if (e.target === terminalElement || e.target.classList.contains('terminal-container') || 
        e.target.classList.contains('terminal-output')) {
      inputElement.focus();
    }
  });
}

function updatePrompt() {
  if (!inputElement) return;
  const promptEl = terminalElement.querySelector('.terminal-prompt');
  if (promptEl) {
    // Shorten the path for display
    let displayPath = currentDirectory;
    if (displayPath.startsWith('/volumes')) {
      displayPath = displayPath.replace('/volumes', '~');
    }
    promptEl.textContent = `root@xclusive:${displayPath}$`;
  }
}

function printToTerminal(text, type = '') {
  if (!outputElement) return;
  
  // Handle multiline text
  const lines = text.split('\n');
  lines.forEach(line => {
    const lineEl = document.createElement('div');
    lineEl.className = 'terminal-line';
    if (type) lineEl.classList.add(type);
    lineEl.textContent = line;
    outputElement.appendChild(lineEl);
  });
  
  outputElement.scrollTop = outputElement.scrollHeight;
}

function clearOutput() {
  if (!outputElement) return;
  outputElement.innerHTML = '';
}

async function processCommand(commandLine) {
  const args = parseCommand(commandLine);
  const cmd = args[0]?.toLowerCase();
  
  // Echo the command
  printToTerminal(`${terminalElement.querySelector('.terminal-prompt').textContent} ${commandLine}`);
  
  // Handle local commands that don't need API
  switch (cmd) {
    case 'help':
    case '?':
      showHelp();
      return;
      
    case 'clear':
    case 'cls':
      clearOutput();
      return;
      
    case 'exit':
    case 'quit':
    case 'q':
      printToTerminal('Closing terminal...', 'info');
      setTimeout(() => hideTerminal(), 300);
      return;
      
    case 'matrix':
      printToTerminal('Initiating matrix visualization...', 'success');
      startMatrixRain();
      return;
      
    case 'whoami':
      printToTerminal('root', 'info');
      return;
  }
  
  // Validate command
  if (!ALLOWED_COMMANDS.includes(cmd)) {
    printToTerminal(`Command not found: ${cmd}`, 'error');
    printToTerminal('Type "help" for available commands', 'info');
    return;
  }
  
  // Execute command via API through post-handler (routes to root-privileged php-cli)
  try {
    const response = await fetch('/post-handler.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'terminal',
        command: commandLine,
        currentDir: currentDirectory
      })
    });
    
    const data = await response.json();
    
    if (data.error) {
      printToTerminal(data.error, 'error');
    } else {
      if (data.output) {
        printToTerminal(data.output);
      }
      // Update current directory if it changed
      if (data.currentDir) {
        currentDirectory = data.currentDir;
        updatePrompt();
      }
    }
  } catch (error) {
    printToTerminal(`Error executing command: ${error.message}`, 'error');
  }
}

function parseCommand(commandLine) {
  const args = [];
  let current = '';
  let inQuotes = false;
  let quoteChar = '';
  
  for (let i = 0; i < commandLine.length; i++) {
    const char = commandLine[i];
    
    if ((char === '"' || char === "'") && !inQuotes) {
      inQuotes = true;
      quoteChar = char;
    } else if (char === quoteChar && inQuotes) {
      inQuotes = false;
      quoteChar = '';
    } else if (char === ' ' && !inQuotes) {
      if (current) {
        args.push(current);
        current = '';
      }
    } else {
      current += char;
    }
  }
  
  if (current) {
    args.push(current);
  }
  
  return args;
}

function showHelp() {
  printToTerminal('');
  printToTerminal('AVAILABLE COMMANDS:', 'success');
  printToTerminal('');
  printToTerminal('Filesystem Commands:', 'info');
  printToTerminal('  ls [path]          - List directory contents');
  printToTerminal('  cd <path>          - Change directory');
  printToTerminal('  pwd                - Print working directory');
  printToTerminal('  cat <file>         - Display file contents');
  printToTerminal('  mkdir <dir>        - Create directory');
  printToTerminal('  cp <src> <dst>     - Copy file');
  printToTerminal('  mv <src> <dst>     - Move/rename file');
  printToTerminal('  rm <file>          - Remove file');
  printToTerminal('  rmdir <dir>        - Remove empty directory');
  printToTerminal('');
  printToTerminal('Terminal Commands:', 'info');
  printToTerminal('  help               - Show this help message');
  printToTerminal('  clear              - Clear terminal screen');
  printToTerminal('  exit               - Close terminal');
  printToTerminal('  whoami             - Display current user');
  printToTerminal('  matrix             - Matrix rain effect');
  printToTerminal('');
  printToTerminal('Notes:', 'warning');
  printToTerminal('  - All paths are relative to /volumes');
  printToTerminal('  - rm/rmdir requires delete authorization');
  printToTerminal('  - Use quotes for paths with spaces: cd "my folder"');
  printToTerminal('');
}

function startMatrixRain() {
  const rain = document.createElement('div');
  rain.className = 'matrix-rain';
  rain.style.cssText = `
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    pointer-events: none;
    z-index: 999999;
    background: rgba(0, 0, 0, 0.9);
    overflow: hidden;
  `;
  
  document.body.appendChild(rain);
  
  const chars = 'アイウエオカキクケコサシスセソタチツテトナニヌネノハヒフヘホマミムメモャヤユヨラリルレロヲンメートリックス0123456789';
  const columns = Math.floor(window.innerWidth / 14);
  
  for (let i = 0; i < columns; i++) {
    const column = document.createElement('div');
    column.style.cssText = `
      position: absolute;
      top: -100%;
      left: ${i * 14}px;
      font-family: 'Courier New', monospace;
      font-size: 14px;
      color: #0f0;
      text-shadow: 0 0 5px #0f0;
      animation: matrix-fall ${Math.random() * 3 + 2}s linear infinite;
      animation-delay: ${Math.random() * 2}s;
      white-space: pre;
      line-height: 14px;
    `;
    
    let text = '';
    for (let j = 0; j < 50; j++) {
      text += chars[Math.floor(Math.random() * chars.length)] + '\n';
    }
    column.textContent = text;
    rain.appendChild(column);
  }
  
  // Add animation keyframes if not already present
  if (!document.getElementById('matrix-animation')) {
    const style = document.createElement('style');
    style.id = 'matrix-animation';
    style.textContent = `
      @keyframes matrix-fall {
        0% { transform: translateY(-100%); }
        100% { transform: translateY(100vh); }
      }
    `;
    document.head.appendChild(style);
  }
  
  // Remove after 5 seconds
  setTimeout(() => {
    rain.remove();
  }, 5000);
}

// Export for use in events.js
export { hideTerminal };

async function handleTabCompletion() {
  if (!inputElement) return;
  
  const currentValue = inputElement.value;
  const cursorPosition = inputElement.selectionStart || currentValue.length;
  
  // Get the text before cursor
  const textBeforeCursor = currentValue.substring(0, cursorPosition);
  
  // Parse command to find what we're trying to complete
  const args = parseCommand(textBeforeCursor);
  const cmd = args[0]?.toLowerCase();
  
  // Commands that accept directory/file paths
  const pathCommands = ['cd', 'ls', 'cat', 'mkdir', 'rm', 'rmdir', 'cp', 'mv'];
  
  if (!pathCommands.includes(cmd)) return;
  
  // Get the last argument (what we're trying to complete)
  let partial = '';
  let prefix = '';
  
  // Find the last space to determine the partial text
  const lastSpaceIndex = textBeforeCursor.lastIndexOf(' ');
  if (lastSpaceIndex === -1) {
    // No space found, we're completing the command itself
    return;
  }
  
  prefix = textBeforeCursor.substring(0, lastSpaceIndex + 1);
  partial = textBeforeCursor.substring(lastSpaceIndex + 1);
  
  if (!partial && cmd !== 'cd') return;
  
  // Get directory contents via API through post-handler (routes to root-privileged php-cli)
  try {
    const response = await fetch('/post-handler.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'terminal',
        command: 'ls',
        currentDir: currentDirectory
      })
    });
    
    const data = await response.json();
    
    if (data.error || !data.output) return;
    
    // Parse the directory listing
    const lines = data.output.split('\n');
    const entries = [];
    const isDirectory = {}; // Track which entries are directories
    
    for (const line of lines) {
      if (line.trim() === '') continue;
      
      // Check if it's a directory (starts with 'd')
      const isDir = line.startsWith('d');
      
      // Extract entry name (last column)
      const parts = line.split(/\s+/);
      if (parts.length >= 5) {
        const entryName = parts[parts.length - 1].replace(/\/$/, '');
        entries.push(entryName);
        isDirectory[entryName] = isDir;
      }
    }
    
    // Find matches (both files and directories)
    const matches = entries.filter(entry => 
      entry.toLowerCase().startsWith(partial.toLowerCase())
    );
    
    if (matches.length === 0) return;
    
    if (matches.length === 1) {
      // Single match - complete it
      const completed = prefix + matches[0];
      const suffix = currentValue.substring(cursorPosition);
      inputElement.value = completed + suffix;
      inputElement.selectionStart = inputElement.selectionEnd = completed.length;
    } else {
      // Multiple matches - find common prefix
      const commonPrefix = findCommonPrefix(matches);
      if (commonPrefix.length > partial.length) {
        // Complete to common prefix
        const completed = prefix + commonPrefix;
        const suffix = currentValue.substring(cursorPosition);
        inputElement.value = completed + suffix;
        inputElement.selectionStart = inputElement.selectionEnd = completed.length;
      } else {
        // Show all matches with directories marked
        const displayMatches = matches.map(m => isDirectory[m] ? m + '/' : m);
        printToTerminal('');
        printToTerminal(`Matches: ${displayMatches.join('  ')}`, 'info');
        printToTerminal('');
      }
    }
  } catch (error) {
    console.error('Tab completion error:', error);
  }
}

function findCommonPrefix(strings) {
  if (strings.length === 0) return '';
  if (strings.length === 1) return strings[0];
  
  let prefix = '';
  const first = strings[0];
  
  for (let i = 0; i < first.length; i++) {
    const char = first[i];
    if (strings.every(s => s[i] === char)) {
      prefix += char;
    } else {
      break;
    }
  }
  
  return prefix;
}
