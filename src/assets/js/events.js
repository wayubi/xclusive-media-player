// events.js - Event listeners and handlers
import { state } from './state.js';
import { nextGrid, prevGrid, renderGrid } from './grid.js';
import { playAll, shufflePlay } from './fullscreen.js';
import { initSearch, setupSearchListeners } from './search.js';
import { runAudit, auditCurrentView } from './audit.js';
import { setupUnauditedFilter } from './filter.js';
import { toggleTileSelection, confirmDelete, selectAllFiles, clearAllSelections, isSelectAllMode, getSelectedTileCount, syncMuteIcons } from './ui.js';

let scrollDebounce = false;

// Boss Screen state variables (must be defined before setupDeleteHotkeys is called)
let bossScreenActive = false;
let bossScreenElement = null;
let bossOutputElement = null;
let bossInputElement = null;
let bossCommandHistory = [];
let bossHistoryIndex = -1;
let bossPreviousMuteStates = new Map();

export function setupEventListeners() {
  // Initialize search
  initSearch();
  setupSearchListeners();
  
  // Setup unaudited filter
  setupUnauditedFilter();
  
  // Grid navigation
  setupGridNavigation();
  
  // Global controls
  setupGlobalControls();
  
  // Object fit toggle
  setupObjectFitToggle();
  
  // Delete hotkeys (number keys 1-9, 0 and DEL key)
  setupDeleteHotkeys();
}

function setupGridNavigation() {
  const grid = document.getElementById('grid');
  
  // Wheel navigation
  addWheelListener(grid);
  
  // Touch navigation
  let touchStartY = 0;
  grid.addEventListener('touchstart', e => {
    if (e.touches.length === 1) touchStartY = e.touches[0].clientY;
  }, { passive: true });

  grid.addEventListener('touchend', e => {
    const delta = e.changedTouches[0].clientY - touchStartY;
    if (Math.abs(delta) > 50) delta < 0 ? nextGrid() : prevGrid();
  }, { passive: true });
  
  // Options form wheel
  const optionsForm = document.getElementById('options-form');
  if (optionsForm) addWheelListener(optionsForm);
  
  // Mouse hover unmuting
  setupHoverUnmuting(grid);
}

function setupHoverUnmuting(grid) {
  // Use mousemove to continuously unmute whatever container the mouse is over
  grid.addEventListener('mousemove', (e) => {
    // Only work if not globally muted
    if (state.muted) return;
    
    const container = e.target.closest('.video-container');
    if (!container) return;
    
    const mediaEl = container.querySelector('video, audio');
    if (!mediaEl) return;
    
    // If this media is already unmuted, no need to do anything
    if (!mediaEl.muted) return;
    
    // Mute all media
    document.querySelectorAll('#grid video, #grid audio').forEach(m => m.muted = true);
    
    // Unmute the hovered one
    mediaEl.muted = false;
    mediaEl.play().catch(() => {});
    
    // Update mute icons on central overlay
    syncMuteIcons();
  });
}

function addWheelListener(element) {
  element.addEventListener('wheel', (e) => {
    e.preventDefault();
    if (scrollDebounce) return;
    
    // Check if CTRL is pressed for seeking
    if (e.ctrlKey) {
      seekMediaUnderCursor(e);
      return;
    }
    
    scrollDebounce = true;
    setTimeout(() => scrollDebounce = false, 200);
    e.deltaY < 0 ? prevGrid() : nextGrid();
  }, { passive: false });
}

function seekMediaUnderCursor(e) {
  // Find the element under the mouse cursor
  const element = document.elementFromPoint(e.clientX, e.clientY);
  if (!element) return;
  
  // Find the video container
  const container = element.closest('.video-container');
  if (!container) return;
  
  // Find video or audio element within the container
  const mediaEl = container.querySelector('video, audio');
  if (!mediaEl || !mediaEl.duration) return;
  
  // Calculate seek direction and amount
  const direction = e.deltaY < 0 ? -1 : 1;
  const seekAmount = direction * state.seekStepSeconds;
  
  // Clamp the new time between 0 and duration
  const newTime = Math.max(0, Math.min(mediaEl.duration, mediaEl.currentTime + seekAmount));
  mediaEl.currentTime = newTime;
  
  // Show visual feedback
  showSeekFeedback(container, direction, state.seekStepSeconds);
}

function showSeekFeedback(container, direction, seconds) {
  // Remove any existing feedback
  const existingFeedback = container.querySelector('.seek-feedback');
  if (existingFeedback) {
    existingFeedback.remove();
  }
  
  // Create feedback element (styles are defined in CSS)
  const feedback = document.createElement('div');
  feedback.className = 'seek-feedback';
  const arrow = direction > 0 ? '⏩' : '⏪';
  const sign = direction > 0 ? '+' : '-';
  feedback.textContent = `${arrow} ${sign}${seconds}s`;
  
  container.appendChild(feedback);
  
  // Fade out and remove after 1 second
  setTimeout(() => {
    feedback.style.opacity = '0';
    setTimeout(() => feedback.remove(), 400);
  }, 1000);
}

function setupGlobalControls() {
  // Expose functions to global scope for HTML onclick handlers
  window.nextGrid = nextGrid;
  window.prevGrid = prevGrid;
  window.playAll = playAll;
  window.shufflePlay = shufflePlay;
  window.toggleMute = toggleMute;
  window.runAudit = runAudit;
  
  // NEW: Add playFavorites
  window.playFavorites = () => {
    import('./favorites.js').then(module => {
      module.playFavorites();
    });
  };
}

function toggleMute() {
  state.muted = !state.muted;
  const btn = document.getElementById('mute-button');
  if (btn) btn.innerHTML = state.muted ? '🔇' : '🔊';
  if (state.muted) state.lastFullscreen = { file: null, time: 0 };
  renderGrid();
}

function setupObjectFitToggle() {
  // Create CSS class for object-fit toggle
  const style = document.createElement('style');
  style.textContent = `
    .no-object-fit img,
    .no-object-fit video {
      object-fit: contain;
    }
  `;
  document.head.appendChild(style);

  // Listen for "c" key
  document.addEventListener('keydown', (e) => {
    if (e.key.toLowerCase() === 'c') {
      toggleObjectFit();
    }
  });
}

function toggleObjectFit() {
  state.coverEnabled = !state.coverEnabled;
  const grid = document.getElementById('grid');
  
  if (state.coverEnabled) {
    grid.classList.remove('no-object-fit');
  } else {
    grid.classList.add('no-object-fit');
  }
}

function setupDeleteHotkeys() {
  document.addEventListener('keydown', (e) => {
    // If boss screen is active, don't process other hotkeys
    if (bossScreenActive) {
      // ESC is handled by the boss screen itself
      if (e.key === 'Escape') {
        e.preventDefault();
        e.stopPropagation();
        hideBossScreen();
      }
      return;
    }
    
    const key = e.key;
    
    // Check if we're in fullscreen mode by looking for the fullscreen container
    const isFullscreenActive = document.querySelector('div[style*="z-index:9999"]') !== null;
    
    // ESC key - clear delete selections (only when not in fullscreen)
    if (key === 'Escape') {
      if (isFullscreenActive) {
        // Let fullscreen.js handle ESC for exiting fullscreen
        return;
      }
      
      if (!state.deleteEnabled) return;
      
      const selectedCount = getSelectedTileCount();
      const inSelectAllMode = isSelectAllMode();
      
      // Only process if there are selections
      if (selectedCount > 0 || inSelectAllMode) {
        e.preventDefault();
        clearAllSelections();
      }
      return;
    }
    
    // Map keys to tile indices: 1->0, 2->1, ..., 9->8, 0->9
    let tileIndex = -1;
    if (key >= '1' && key <= '9') {
      tileIndex = parseInt(key) - 1; // 1 becomes 0, 9 becomes 8
    } else if (key === '0') {
      tileIndex = 9; // 0 becomes 9 (10th tile)
    } else if (key === 'Delete' || key.toLowerCase() === 'd') {
      // DEL key - two phase delete
      // Skip if in fullscreen mode (fullscreen.js handles delete for single video)
      if (isFullscreenActive) return;
      if (!state.deleteEnabled) return;
      e.preventDefault();
      
      const selectedCount = getSelectedTileCount();
      
      if (selectedCount === 0 && !isSelectAllMode()) {
        // First Delete press with nothing selected - select all
        selectAllFiles();
      } else {
        // Second Delete press or items already selected - confirm and delete
        confirmDelete();
      }
      return;
    } else if (key.toLowerCase() === 'a') {
      // 'a' key - audit current view
      e.preventDefault();
      auditCurrentView();
      return;
    }
    
    // If we have a valid tile index, toggle its selection (only when delete is enabled)
    if (tileIndex !== -1) {
      if (!state.deleteEnabled) return;
      const containers = document.querySelectorAll('#grid .video-container');
      // Only process if the tile exists (e.g., ignore 7-0 on a 6-tile grid)
      if (tileIndex < containers.length) {
        e.preventDefault();
        toggleTileSelection(tileIndex);
      }
    }
    
    // 'b' key - boss screen
    if (key.toLowerCase() === 'b') {
      // Only block if fullscreen is active (delete mode is fine)
      if (isFullscreenActive) return;
      e.preventDefault();
      toggleBossScreen();
      return;
    }
  });
}

// Boss Screen functionality

function toggleBossScreen() {
  if (bossScreenActive) {
    hideBossScreen();
  } else {
    showBossScreen();
  }
}

function showBossScreen() {
  if (!bossScreenElement) {
    createBossScreen();
  }

  bossScreenElement.classList.add('active');
  bossScreenActive = true;

  // Mute all video/audio elements and save their previous state
  // Include both grid videos AND fullscreen videos
  bossPreviousMuteStates.clear();
  document.querySelectorAll('video, audio').forEach(media => {
    bossPreviousMuteStates.set(media, media.muted);
    media.muted = true;
  });

  // Focus the input
  setTimeout(() => {
    if (bossInputElement) {
      bossInputElement.focus();
    }
  }, 100);

  // Add initial welcome message
  clearBossOutput();
  printToBoss('XCLUSIVE SECURE TERMINAL v9.0', 'success');
  printToBoss('Type "help" for available commands', 'info');
  printToBoss('');
}

function hideBossScreen() {
  if (bossScreenElement) {
    bossScreenElement.classList.remove('active');
  }
  bossScreenActive = false;

  // Restore previous mute states
  bossPreviousMuteStates.forEach((wasMuted, media) => {
    media.muted = wasMuted;
  });
  bossPreviousMuteStates.clear();

  // Update mute icons
  syncMuteIcons();
}

function createBossScreen() {
  bossScreenElement = document.createElement('div');
  bossScreenElement.id = 'boss-screen';
  bossScreenElement.innerHTML = `
    <div class="boss-terminal">
      <pre class="boss-ascii">
█████████████████████████████████████████████████████████████████████████████████████████████████
██                                                                              ██
██   ▓▓▓▓▓▓▓▓  ▓▓   ▓▓ ▓▓▓▓▓▓ ▓▓      ▓▓ ▓▓▓▓▓▓▓▓▓ ▓▓      ▓▓ ▓▓▓▓▓▓▓▓▓   ██
██   ▓▓    ▓▓  ▓▓ ▓▓   ▓▓    ▓▓      ▓▓ ▓▓       ▓▓      ▓▓ ▓▓         ██
██   ▓▓    ▓▓   ▓▓▓    ▓▓    ▓▓      ▓▓ ▓▓▓▓▓▓   ▓▓      ▓▓ ▓▓▓▓▓▓     ██
██   ▓▓▓▓▓▓▓▓    ▓▓     ▓▓    ▓▓      ▓▓ ▓▓       ▓▓      ▓▓ ▓▓         ██
██   ▓▓          ▓▓  ▓▓▓▓▓▓   ▓▓▓▓▓▓▓ ▓▓ ▓▓▓▓▓▓▓▓▓ ▓▓▓▓▓▓▓ ▓▓ ▓▓▓▓▓▓▓▓▓   ██
██   ▓▓         ▓▓▓                                                       ██
██                                                                              ██
███████████████████████████████████████████████████████████████████████████████████████████████████████████
      </pre>
      <div class="boss-output"></div>
      <div class="boss-input-line">
        <span class="boss-prompt">root@xclusive:~$</span>
        <input type="text" class="boss-input" spellcheck="false" autocomplete="off">
        <span class="boss-cursor"></span>
      </div>
      <div class="boss-help-text">Press ESC to exit | Type 'help' for commands</div>
    </div>
  `;
  
  document.body.appendChild(bossScreenElement);
  
  bossOutputElement = bossScreenElement.querySelector('.boss-output');
  bossInputElement = bossScreenElement.querySelector('.boss-input');
  
  // Handle input
  bossInputElement.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      const command = bossInputElement.value.trim();
      if (command) {
        bossCommandHistory.push(command);
        bossHistoryIndex = bossCommandHistory.length;
        processBossCommand(command);
      }
      bossInputElement.value = '';
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      if (bossHistoryIndex > 0) {
        bossHistoryIndex--;
        bossInputElement.value = bossCommandHistory[bossHistoryIndex];
      }
    } else if (e.key === 'ArrowDown') {
      e.preventDefault();
      if (bossHistoryIndex < bossCommandHistory.length - 1) {
        bossHistoryIndex++;
        bossInputElement.value = bossCommandHistory[bossHistoryIndex];
      } else {
        bossHistoryIndex = bossCommandHistory.length;
        bossInputElement.value = '';
      }
    } else if (e.key === 'Escape') {
      e.preventDefault();
      e.stopPropagation();
      hideBossScreen();
    }
  });
  
  // Handle ESC on the boss screen container
  bossScreenElement.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      e.preventDefault();
      e.stopPropagation();
      hideBossScreen();
    }
  });
}

function printToBoss(text, type = '') {
  if (!bossOutputElement) return;
  
  const line = document.createElement('div');
  line.className = 'output-line';
  if (type) line.classList.add(type);
  line.textContent = text;
  bossOutputElement.appendChild(line);
  bossOutputElement.scrollTop = bossOutputElement.scrollHeight;
}

function clearBossOutput() {
  if (!bossOutputElement) return;
  bossOutputElement.innerHTML = '';
}

function processBossCommand(command) {
  const cmd = command.toLowerCase().trim();
  const args = cmd.split(' ');
  const mainCmd = args[0];
  
  // Echo the command
  printToBoss(`root@xclusive:~$ ${command}`);
  
  switch (mainCmd) {
    case 'help':
    case '?':
      printToBoss('');
      printToBoss('AVAILABLE COMMANDS:', 'success');
      printToBoss('  help      - Show this help message');
      printToBoss('  status    - Display system status');
      printToBoss('  clear     - Clear terminal screen');
      printToBoss('  exit      - Exit terminal');
      printToBoss('  matrix    - Enable matrix mode');
      printToBoss('  whoami    - Display user information');
      printToBoss('  ls        - List directory contents');
      printToBoss('  decrypt   - Decrypt classified data');
      printToBoss('  hack      - Initiate hack sequence');
      printToBoss('  fortune   - Read your fortune');
      printToBoss('  selfdestruct - [WARNING] System self-destruct');
      printToBoss('');
      break;
      
    case 'status':
      const totalVideos = state.originalVideos.length;
      const totalSize = Math.floor(Math.random() * 500) + 100;
      const uptime = Math.floor(Math.random() * 100);
      printToBoss('');
      printToBoss('SYSTEM STATUS:', 'info');
      printToBoss(`  Videos:      ${totalVideos} files`);
      printToBoss(`  Storage:     ${totalSize}TB`);
      printToBoss(`  CPU Usage:   ${Math.floor(Math.random() * 30) + 10}%`);
      printToBoss(`  Memory:      ${Math.floor(Math.random() * 40) + 30}%`);
      printToBoss(`  Uptime:      ${uptime} days`);
      printToBoss(`  Encryption:  AES-4096 [ACTIVE]`);
      printToBoss(`  Proxy:       TOR+VPN [ENABLED]`);
      printToBoss('');
      break;
      
    case 'clear':
    case 'cls':
      clearBossOutput();
      break;
      
    case 'exit':
    case 'quit':
    case 'q':
      printToBoss('Logging out...', 'info');
      setTimeout(() => hideBossScreen(), 500);
      break;
      
    case 'matrix':
      printToBoss('Initiating matrix visualization...', 'success');
      startMatrixRain();
      break;
      
    case 'whoami':
      printToBoss('');
      printToBoss('USER PROFILE:', 'info');
      printToBoss('  User:     root');
      printToBoss('  Group:    xclusive');
      printToBoss('  Clearance: Level 9 (Top Secret)');
      printToBoss('  Status:   Active');
      printToBoss('  Location: Classified');
      printToBoss('');
      break;
      
    case 'ls':
    case 'dir':
      printToBoss('');
      printToBoss('DIRECTORY CONTENTS:', 'info');
      printToBoss('  drwxr-xr-x  classified/');
      printToBoss('  drwxr-xr-x  encrypted/');
      printToBoss('  -rw-r--r--  README.txt');
      printToBoss('  -rw-------  secrets.key');
      printToBoss('  -rwxr-xr-x  launch.sh');
      printToBoss('  -rw-r--r--  data.bin');
      printToBoss('');
      break;
      
    case 'decrypt':
      printToBoss('');
      printToBoss('Attempting decryption...', 'warning');
      setTimeout(() => {
        printToBoss('Decrypting layer 1... [OK]', 'success');
      }, 300);
      setTimeout(() => {
        printToBoss('Decrypting layer 2... [OK]', 'success');
      }, 600);
      setTimeout(() => {
        printToBoss('Decrypting layer 3... [OK]', 'success');
      }, 900);
      setTimeout(() => {
        printToBoss('');
        printToBoss('DECRYPTION COMPLETE:', 'success');
        printToBoss('  "The cake is a lie."', 'info');
        printToBoss('  -- Anonymous, 2007', 'info');
        printToBoss('');
      }, 1200);
      break;
      
    case 'hack':
      printToBoss('');
      printToBoss('INITIATING HACK SEQUENCE...', 'warning');
      let progress = 0;
      const hackInterval = setInterval(() => {
        progress += Math.floor(Math.random() * 15) + 5;
        if (progress >= 100) {
          progress = 100;
          clearInterval(hackInterval);
          printToBoss(`Progress: [${'█'.repeat(20)}] 100%`, 'success');
          printToBoss('');
          printToBoss('ACCESS GRANTED!', 'success');
          printToBoss('  You found the Easter egg!', 'info');
          printToBoss('  sudo access: DENIED (nice try though)', 'error');
          printToBoss('');
        } else {
          const bars = Math.floor(progress / 5);
          printToBoss(`Progress: [${'█'.repeat(bars)}${'░'.repeat(20 - bars)}] ${progress}%`);
        }
      }, 200);
      break;
      
    case 'fortune':
      const fortunes = [
        'The early bird gets the worm, but the second mouse gets the cheese.',
        'A journey of a thousand miles begins with a single step.',
        'Your code will compile on the first try... eventually.',
        'The bugs you fear are the ones you created yesterday.',
        'He who laughs last probably didn\'t get the joke.',
        'Your next feature will be someone\'s favorite bug.',
        'The cloud is just someone else\'s computer.',
        'rm -rf / is not a valid debugging strategy.',
        'git commit -m "fix stuff" will haunt you later.',
        'Stack Overflow is down. Good luck.'
      ];
      const fortune = fortunes[Math.floor(Math.random() * fortunes.length)];
      printToBoss('');
      printToBoss('☯ FORTUNE COOKIE:', 'success');
      printToBoss(`  "${fortune}"`, 'info');
      printToBoss('');
      break;
      
    case 'selfdestruct':
    case 'self-destruct':
      printToBoss('');
      printToBoss('☠ WARNING: SELF-DESTRUCT SEQUENCE INITIATED ☠', 'error');
      printToBoss('');
      let countdown = 10;
      const countdownInterval = setInterval(() => {
        if (countdown <= 0) {
          clearInterval(countdownInterval);
          printToBoss('');
          printToBoss('JUST KIDDING! ☺', 'success');
          printToBoss('System self-destruct is disabled.', 'info');
          printToBoss('(But nice reflexes!)', 'info');
          printToBoss('');
        } else {
          printToBoss(`  ${countdown}...`, 'warning');
          countdown--;
        }
      }, 1000);
      break;
      
    case 'konami':
      printToBoss('');
      printToBoss('↑ ↑ ↓ ↓ ← → ← → B A', 'success');
      printToBoss('CHEAT CODE ACTIVATED!', 'success');
      printToBoss('You have unlocked: absolutely nothing.', 'info');
      printToBoss('But you get +10 internet points! ✨', 'info');
      printToBoss('');
      break;
      
    default:
      if (cmd) {
        printToBoss(`Command not found: ${mainCmd}`, 'error');
        printToBoss('Type "help" for available commands', 'info');
      }
  }
}

function startMatrixRain() {
  const rain = document.createElement('div');
  rain.className = 'matrix-rain';
  bossScreenElement.appendChild(rain);
  
  const chars = 'アイウエオカキクケコサシスセソタチツテトナニヌネノハヒフヘホマミムメモャヤユヨラリルレロヲンメートリックス★☠☯☺☻♠♣♥♦';
  const columns = Math.floor(window.innerWidth / 14);
  
  for (let i = 0; i < columns; i++) {
    const column = document.createElement('div');
    column.className = 'matrix-column';
    column.style.left = `${i * 14}px`;
    column.style.animationDuration = `${Math.random() * 3 + 2}s`;
    column.style.animationDelay = `${Math.random() * 2}s`;
    
    let text = '';
    for (let j = 0; j < 50; j++) {
      text += chars[Math.floor(Math.random() * chars.length)] + '\n';
    }
    column.textContent = text;
    rain.appendChild(column);
  }
  
  // Remove after 5 seconds
  setTimeout(() => {
    rain.remove();
  }, 5000);
}