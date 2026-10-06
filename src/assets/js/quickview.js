// quickview.js - In-grid quick view: expand a single selected tile with motion

let activeContainer = null;
let placeholder = null;
let backdrop = null;
let generation = 0;
let collapseTimer = null;

export function isQuickViewActive() {
  return activeContainer !== null;
}

function getGrid() {
  return document.getElementById('grid');
}

export function isQuickViewEligible(container) {
  if (!container) return false;
  if (container.classList.contains('unsupported-video')) return false;
  if (container.classList.contains('text-file-container')) return false;
  return !!container.querySelector('video, audio, img');
}

function ensureBackdrop(grid) {
  if (!backdrop || !backdrop.isConnected) {
    backdrop = document.createElement('div');
    backdrop.className = 'quick-view-backdrop';
    backdrop.addEventListener('click', () => {
      document.dispatchEvent(new CustomEvent('quickview:dismiss'));
    });
    grid.appendChild(backdrop);
  }
  return backdrop;
}

/**
 * Expand the tile at the given index to a centered 80% box.
 */
export function showQuickView(index) {
  const grid = getGrid();
  if (!grid) return;

  const containers = grid.querySelectorAll('.video-container');
  const container = containers[index];

  if (!isQuickViewEligible(container)) {
    if (activeContainer && activeContainer === container) hideQuickView();
    return;
  }

  // Already expanded on this tile
  if (activeContainer === container) return;

  // Switching targets: collapse the previous one instantly
  if (activeContainer) hideQuickView(true);

  const gridRect = grid.getBoundingClientRect();
  const rect = container.getBoundingClientRect();

  // Placeholder keeps the tile's grid cell occupied so siblings don't reflow
  placeholder = document.createElement('div');
  placeholder.className = 'quick-view-placeholder';
  container.parentNode.insertBefore(placeholder, container);

  activeContainer = container;

  // Start from the tile's current position/size, with transitions disabled
  container.style.transition = 'none';
  container.style.top = (rect.top - gridRect.top) + 'px';
  container.style.left = (rect.left - gridRect.left) + 'px';
  container.style.width = rect.width + 'px';
  container.style.height = rect.height + 'px';
  container.classList.add('quick-view');

  const bd = ensureBackdrop(grid);

  // Force reflow so the start state is committed before we animate
  void container.offsetWidth;

  // Animate to the centered 80% target
  const w = gridRect.width * 0.8;
  const h = gridRect.height * 0.8;
  container.style.transition = '';
  container.style.top = ((gridRect.height - h) / 2) + 'px';
  container.style.left = ((gridRect.width - w) / 2) + 'px';
  container.style.width = w + 'px';
  container.style.height = h + 'px';

  bd.classList.add('visible');
}

/**
 * Collapse the active quick view back to its grid cell.
 */
export function hideQuickView(immediate = false) {
  const container = activeContainer;
  if (!container) return;

  const ph = placeholder;
  const bd = backdrop;

  if (immediate || !container.isConnected || !ph || !ph.isConnected) {
    finalizeCollapse(container, ph, bd);
    return;
  }

  const grid = getGrid();
  if (!grid) {
    finalizeCollapse(container, ph, bd);
    return;
  }

  const gridRect = grid.getBoundingClientRect();
  const phRect = ph.getBoundingClientRect();

  generation++;
  const gen = generation;

  if (bd) bd.classList.remove('visible');

  container.style.top = (phRect.top - gridRect.top) + 'px';
  container.style.left = (phRect.left - gridRect.left) + 'px';
  container.style.width = phRect.width + 'px';
  container.style.height = phRect.height + 'px';

  let done = false;
  const finish = () => {
    if (gen !== generation) return;
    finalizeCollapse(container, ph, bd);
  };

  const onEnd = (e) => {
    if (e.target !== container) return;
    done = true;
    container.removeEventListener('transitionend', onEnd);
    finish();
  };
  container.addEventListener('transitionend', onEnd);

  // Safety net if transitionend never fires (e.g. display changes)
  clearTimeout(collapseTimer);
  collapseTimer = setTimeout(() => {
    if (done) return;
    container.removeEventListener('transitionend', onEnd);
    finish();
  }, 800);
}

function finalizeCollapse(container, ph, bd) {
  if (container) {
    container.classList.remove('quick-view');
    container.style.transition = '';
    container.style.top = '';
    container.style.left = '';
    container.style.width = '';
    container.style.height = '';
  }
  if (ph && ph.parentNode) ph.parentNode.removeChild(ph);
  if (bd && bd.parentNode) bd.parentNode.removeChild(bd);

  activeContainer = null;
  placeholder = null;
  backdrop = null;
}

/**
 * Hard reset (used when the grid is torn down and re-rendered).
 */
export function resetQuickView() {
  generation++;
  clearTimeout(collapseTimer);

  if (activeContainer) {
    activeContainer.classList.remove('quick-view');
    activeContainer.style.transition = '';
    activeContainer.style.top = '';
    activeContainer.style.left = '';
    activeContainer.style.width = '';
    activeContainer.style.height = '';
  }
  if (placeholder && placeholder.parentNode) placeholder.parentNode.removeChild(placeholder);
  if (backdrop && backdrop.parentNode) backdrop.parentNode.removeChild(backdrop);

  activeContainer = null;
  placeholder = null;
  backdrop = null;
}
