// mediaQueue.js - Media loading queue management with robust completion signals
import { state } from './state.js';

const audioQueue = [];
const videoQueue = [];
let activeAudioLoads = 0;
let activeVideoLoads = 0;

// Default limits - can be adjusted dynamically based on grid size
let MAX_CONCURRENT_AUDIO = 6;
let MAX_CONCURRENT_VIDEO = 6;

// Timeout for stuck media loads (15 seconds)
const MEDIA_LOAD_TIMEOUT = 15000;

/**
 * Create a robust media load completion tracker
 * Uses multiple signals: canplay, loadedmetadata, error, and timeout fallback
 * Ensures queue slot is always freed even if media is malformed or stalls
 */
function trackMediaLoad(mediaElement, onComplete) {
  let completed = false;
  let timeoutId = null;

  const cleanup = () => {
    if (completed) return;
    completed = true;

    // Remove all listeners
    mediaElement.removeEventListener('canplay', handleCanPlay);
    mediaElement.removeEventListener('loadedmetadata', handleLoadedMetadata);
    mediaElement.removeEventListener('error', handleError);
    
    // Clear timeout
    if (timeoutId) {
      clearTimeout(timeoutId);
      timeoutId = null;
    }

    // Call completion callback
    onComplete();
  };

  const handleCanPlay = () => {
    // Best signal - media is ready to play (enough data buffered)
    cleanup();
  };

  const handleLoadedMetadata = () => {
    // Good fallback - metadata is loaded (duration, dimensions)
    cleanup();
  };

  const handleError = () => {
    // Error occurred - still need to free up queue slot
    console.warn('Media failed to load:', mediaElement.src || mediaElement.dataset?.src);
    cleanup();
  };

  // Attach listeners
  mediaElement.addEventListener('canplay', handleCanPlay, { once: true });
  mediaElement.addEventListener('loadedmetadata', handleLoadedMetadata, { once: true });
  mediaElement.addEventListener('error', handleError, { once: true });

  // Ultimate timeout fallback - ensures queue never gets stuck
  timeoutId = setTimeout(() => {
    if (!completed) {
      console.warn('Media load timeout (15s):', mediaElement.src || mediaElement.dataset?.src);
      cleanup();
    }
  }, MEDIA_LOAD_TIMEOUT);
}

export const mediaQueue = {
  /**
   * Set maximum concurrent loads (useful for large grids)
   */
  setMaxConcurrent(max) {
    MAX_CONCURRENT_AUDIO = max;
    MAX_CONCURRENT_VIDEO = max;
  },

  /**
   * Get current limits
   */
  getLimits() {
    return {
      audio: MAX_CONCURRENT_AUDIO,
      video: MAX_CONCURRENT_VIDEO,
      audioQueueLength: audioQueue.length,
      videoQueueLength: videoQueue.length,
      activeAudioLoads,
      activeVideoLoads
    };
  },

  /**
   * Process audio loading queue
   */
  processAudioQueue() {
    while (activeAudioLoads < MAX_CONCURRENT_AUDIO && audioQueue.length) {
      const audio = audioQueue.shift();
      if (!audio?.dataset?.src) continue;

      activeAudioLoads++;
      audio.src = audio.dataset.src;
      delete audio.dataset.src;
      audio.load();

      // Use robust completion tracking
      trackMediaLoad(audio, () => {
        activeAudioLoads = Math.max(0, activeAudioLoads - 1);
        this.processAudioQueue();
      });
    }
  },

  /**
   * Process video loading queue
   */
  processVideoQueue() {
    while (activeVideoLoads < MAX_CONCURRENT_VIDEO && videoQueue.length) {
      const video = videoQueue.shift();
      if (!video?.dataset?.src) continue;

      activeVideoLoads++;
      video.src = video.dataset.src;
      delete video.dataset.src;
      video.load();

      // Use robust completion tracking with auto-play
      trackMediaLoad(video, () => {
        // Try to play once loaded (canplay is best for this)
        video.play().catch(() => {});
        
        activeVideoLoads = Math.max(0, activeVideoLoads - 1);
        this.processVideoQueue();
      });
    }
  },

  /**
   * Add audio to queue (used by lazy loader)
   */
  addAudio(audio) {
    if (audio?.dataset?.src) {
      audioQueue.push(audio);
    }
  },

  /**
   * Add video to queue (used by lazy loader)
   */
  addVideo(video) {
    if (video?.dataset?.src) {
      videoQueue.push(video);
    }
  },

  /**
   * Clear all queues (useful when entering fullscreen)
   */
  clearQueues() {
    audioQueue.length = 0;
    videoQueue.length = 0;
    activeAudioLoads = 0;
    activeVideoLoads = 0;
  },

};
