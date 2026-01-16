// utils.js - Utility functions
export function setVhUnit() {
  document.documentElement.style.setProperty('--vh', `${window.innerHeight * 0.01}px`);
}

export function computeGridDimensions(count, maxCols) {
  if (count <= maxCols) {
    return { cols: count, rows: 1 };
  }

  const cols = maxCols;
  const rows = Math.ceil(count / cols);
  return { cols, rows };
}

export function getFileExtension(filename) {
  return filename.split('.').pop().toLowerCase();
}

export function isAudioFile(filename) {
  const ext = getFileExtension(filename);
  return ['mp3', 'wav', 'ogg'].includes(ext);
}

export function isVideoFile(filename) {
  const ext = getFileExtension(filename);
  return ['mp4', 'webm', 'mkv', 'mov', 'm4v', '3gp', 'flv', 'wmv'].includes(ext);
}

export function isImageFile(filename) {
  const ext = getFileExtension(filename);
  return ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext);
}