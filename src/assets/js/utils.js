// utils.js - Utility functions
import { state } from './state.js?v=1781077253';

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
  return ['mp4', 'webm', 'mkv', 'mov', 'm4v', '3gp', 'flv', 'wmv', 'avi', 'mpg', 'mpeg'].includes(ext);
}

export function isImageFile(filename) {
  const ext = getFileExtension(filename);
  return ['jpg', 'jpeg', 'png', 'gif', 'webp','heic'].includes(ext);
}

export function isUnsupportedVideoFile(file) {
  // Check codec from stored metadata
  return state.hasUnsupportedCodec(file);
}

/**
 * Decode base64 string to UTF-8 text
 * Unlike atob(), this properly handles multi-byte UTF-8 characters
 * @param {string} base64 - Base64 encoded string
 * @returns {string} Decoded UTF-8 string
 */
export function decodeBase64UTF8(base64) {
  try {
    const binaryString = atob(base64);
    const bytes = new Uint8Array(binaryString.length);
    for (let i = 0; i < binaryString.length; i++) {
      bytes[i] = binaryString.charCodeAt(i);
    }
    return new TextDecoder('utf-8').decode(bytes);
  } catch (e) {
    console.warn('Failed to decode base64 UTF-8:', e);
    return base64;
  }
}
