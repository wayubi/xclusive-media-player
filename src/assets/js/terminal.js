// terminal.js - Functional terminal interface for real filesystem commands
import { state } from './state.js';
import { decodeBase64UTF8 } from './utils.js';

let terminalActive = false;
let terminalElement = null;
let outputElement = null;
let inputElement = null;
let commandHistory = [];
let historyIndex = -1;
let previousMuteStates = new Map();
let currentDirectory = '/volumes';  // Web path like "/volumes" or "/videos/action"
let terminalMode = 'transparent'; // 'privacy' or 'transparent'
let availableScripts = []; // Store available script names

// No command whitelist - all commands go directly to shell

export function initTerminal() {
  // Load available scripts
  loadScripts();
}

async function loadScripts() {
  try {
    const response = await fetch('/post-handler.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'list_scripts' })
    });
    const data = await response.json();
    if (data.status === 'ok' && data.scripts) {
      availableScripts = data.scripts;
    }
  } catch (error) {
    console.error('Failed to load scripts:', error);
  }
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
  // Echo the command
  printToTerminal(`${terminalElement.querySelector('.terminal-prompt').textContent} ${commandLine}`);
  
  // Handle local commands that don't need shell
  const trimmedCmd = commandLine.trim().toLowerCase();
  const firstWord = trimmedCmd.split(/\s+/)[0];
  
  switch (firstWord) {
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
        // Decode base64-encoded output with UTF-8 support
        printToTerminal(decodeBase64UTF8(data.output));
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
  printToTerminal('SHELL COMMANDS:', 'info');
  printToTerminal('  Any bash command works: ls -la, cat file.txt, grep pattern files');
  printToTerminal('  Pipes and redirects: ls -la | grep mp4, cat file > output.txt');
  printToTerminal('  ffmpeg, ffprobe, and custom scripts available');
  printToTerminal('');
  printToTerminal('Built-in Commands:', 'info');
  printToTerminal('  cd <path>          - Change directory');
  printToTerminal('  pwd                - Print working directory');
  printToTerminal('  clear              - Clear terminal screen');
  printToTerminal('  exit               - Close terminal');
  printToTerminal('  help               - Show this help message');
  printToTerminal('');
  printToTerminal('Notes:', 'warning');
  printToTerminal('  - Full bash shell with pipes, wildcards, and redirects');
  printToTerminal('  - All paths relative to current directory');
  printToTerminal('  - Jailed to /volumes for security');
  printToTerminal('');
  
  // Show available scripts if any
  if (availableScripts.length > 0) {
    printToTerminal('Available Scripts:', 'info');
    availableScripts.forEach(script => {
      printToTerminal(`  ${script}`);
    });
    printToTerminal('');
  }
}

async function runScript(scriptName, args) {
  printToTerminal(`Running script: ${scriptName}...`, 'info');
  printToTerminal('');
  
  try {
    const response = await fetch('/post-handler.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'terminal',
        script: scriptName,
        args: args,
        currentDir: currentDirectory
      })
    });
    
    // Read the stream
    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    
    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      
      const text = decoder.decode(value, { stream: true });
      if (text) {
        printToTerminal(text);
      }
    }
    
    printToTerminal('');
    printToTerminal(`Script completed: ${scriptName}`, 'success');
  } catch (error) {
    printToTerminal(`Error running script: ${error.message}`, 'error');
  }
}

async function runFfmpegCommand(cmd, args, fullCommandLine) {
  printToTerminal(`Running ${cmd}...`, 'info');
  
  try {
    const response = await fetch('/post-handler.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'terminal',
        command: cmd,
        args: args,
        fullCommand: fullCommandLine,
        currentDir: currentDirectory
      })
    });
    
    // Read the stream
    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    
    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      
      const text = decoder.decode(value, { stream: true });
      if (text) {
        printToTerminal(text);
      }
    }
    
    printToTerminal('');
    printToTerminal(`${cmd} completed`, 'success');
  } catch (error) {
    printToTerminal(`Error running ${cmd}: ${error.message}`, 'error');
  }
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
  
  // Get the last argument (what we're trying to complete)
  let partial = '';
  let prefix = '';
  
  // Check if we're completing after 'cd' command
  const isCdCommand = (() => {
    const trimmed = textBeforeCursor.trim().toLowerCase();
    return trimmed === 'cd' || trimmed.startsWith('cd ') || trimmed.startsWith('cd\t');
  })();
  
  // Find the last space to determine the partial text
  const lastSpaceIndex = textBeforeCursor.lastIndexOf(' ');
  if (lastSpaceIndex === -1) {
    // No space found, we're completing the command/script itself
    partial = textBeforeCursor.toLowerCase();
    
    // Scripts available for completion
    const matches = availableScripts.filter(c => 
      c.toLowerCase().startsWith(partial)
    );
    
    if (matches.length === 0) return;
    
    if (matches.length === 1) {
      inputElement.value = matches[0] + ' ';
      inputElement.selectionStart = inputElement.selectionEnd = inputElement.value.length;
    } else {
      // Find common prefix
      const commonPrefix = findCommonPrefix(matches);
      if (commonPrefix.length > partial.length) {
        inputElement.value = commonPrefix;
        inputElement.selectionStart = inputElement.selectionEnd = commonPrefix.length;
      } else {
        printToTerminal('');
        printToTerminal(`Scripts: ${matches.join('  ')}`, 'info');
        printToTerminal('');
      }
    }
    return;
  }
  
  // We have a space, complete file/directory names
  prefix = textBeforeCursor.substring(0, lastSpaceIndex + 1);
  partial = textBeforeCursor.substring(lastSpaceIndex + 1);
  
  if (!partial) return;
  
  // Get directory contents via API
  try {
    const response = await fetch('/post-handler.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'terminal',
        command: 'ls -la',
        currentDir: currentDirectory
      })
    });
    
    const data = await response.json();
    
    if (data.error || !data.output) return;
    
    // Decode base64 output from the API with UTF-8 support
    const decodedOutput = decodeBase64UTF8(data.output);
    
    // Parse the directory listing
    const lines = decodedOutput.split('\n');
    const entries = [];
    const isDirectory = {}; // Track which entries are directories
    
    for (const line of lines) {
      if (line.trim() === '' || line.startsWith('total')) continue;
      
      // Check if it's a directory (starts with 'd')
      const isDir = line.startsWith('d');
      
      // Extract entry name - ls -la format: permissions links owner group size month day time name
      // Filename starts after the time field (8th field), handle filenames with spaces
      const match = line.match(/^([-drwxsStTl+]{10})\s+(\d+)\s+(\S+)\s+(\S+)\s+(\d+)\s+(\w{3})\s+(\d{1,2})\s+(\d{1,2}:\d{2}|\d{4})\s+(.+)$/);
      if (match) {
        let entryName = match[9].replace(/\/$/, '');
        // Skip . and .. directories
        if (entryName === '.' || entryName === '..') continue;
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
      let completedName = matches[0];
      // Add trailing slash for directories, and space for cd commands
      if (isDirectory[completedName]) {
        completedName += '/';
      }
      const completed = prefix + completedName;
      const suffix = currentValue.substring(cursorPosition);
      inputElement.value = completed + suffix;
      inputElement.selectionStart = inputElement.selectionEnd = completed.length;
    } else {
      // Multiple matches - find common prefix
      const commonPrefix = findCommonPrefix(matches);
      if (commonPrefix.length > partial.length) {
        // Complete to common prefix
        let completedName = commonPrefix;
        // If common prefix is a full directory name, add slash and potentially space
        const fullMatches = matches.filter(m => m.toLowerCase() === commonPrefix.toLowerCase());
        if (fullMatches.length > 0 && isDirectory[fullMatches[0]]) {
          completedName += '/';
        }
        const completed = prefix + completedName;
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
