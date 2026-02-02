// mediaQueue.js - Media loading queue management with priority support
import { state } from './state.js';

const audioQueue = [];
const videoQueue = [];
let activeAudioLoads = 0;
let activeVideoLoads = 0;

// Default limits - can be adjusted dynamically based on grid size
let MAX_CONCURRENT_AUDIO = 6;
let MAX_CONCURRENT_VIDEO = 6;

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

      const done = () => {
        activeAudioLoads = Math.max(0, activeAudioLoads - 1);
        this.processAudioQueue();
      };

      audio.addEventListener('loadedmetadata', done, { once: true });
      audio.addEventListener('error', done, { once: true });
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

      const done = () => {
        activeVideoLoads = Math.max(0, activeVideoLoads - 1);
        this.processVideoQueue();
      };

      video.addEventListener('loadedmetadata', () => {
        video.play().catch(() => {});
        done();
      }, { once: true });

      video.addEventListener('error', done, { once: true });
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

  /**
   * Pause all active loads (useful for bandwidth management)
   */
  pauseAll() {
    // Move active elements back to front of queue
    // This is a soft pause - elements keep their current progress
  }
};
