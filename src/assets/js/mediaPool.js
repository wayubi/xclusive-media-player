// mediaPool.js - Reusable media element pooling
const MAX_POOL_SIZE = 48;

export const mediaPool = {
  videoPool: [],
  audioPool: [],
  
  init() {
    for (let i = 0; i < MAX_POOL_SIZE; i++) {
      const video = document.createElement('video');
      video.preload = 'none';
      video.playsInline = true;
      video.loop = true;
      video.controls = false;
      this.videoPool.push(video);

      const audio = document.createElement('audio');
      audio.preload = 'none';
      audio.playsInline = true;
      audio.loop = true;
      this.audioPool.push(audio);
    }
  },
  
  getVideo() {
    return this.videoPool.pop() || document.createElement('video');
  },
  
  getAudio() {
    return this.audioPool.pop() || document.createElement('audio');
  },
  
  returnVideo(video) {
    if (this.videoPool.length < MAX_POOL_SIZE) {
      this.videoPool.push(video);
    }
  },
  
  returnAudio(audio) {
    if (this.audioPool.length < MAX_POOL_SIZE) {
      this.audioPool.push(audio);
    }
  },
  
  recycleElements(elements) {
    elements.forEach(el => {
      el.pause();
      el.removeAttribute('src');
      el.load();
      el.currentTime = 0;
      
      // Remove from DOM to ensure connection release
      if (el.parentNode) {
        el.parentNode.removeChild(el);
      }
      
      if (el.tagName === 'VIDEO') {
        this.returnVideo(el);
      } else if (el.tagName === 'AUDIO') {
        this.returnAudio(el);
      }
    });
  },
  
  // Release all grid media elements and return to pool
  // Use this before fullscreen to ensure connections are freed
  releaseAll() {
    const grid = document.getElementById('grid');
    if (!grid) return;
    
    const mediaElements = grid.querySelectorAll('video, audio');
    this.recycleElements(mediaElements);
    
    // Small delay to ensure browser releases TCP connections
    return new Promise(resolve => setTimeout(resolve, 50));
  },
  
  // Clear all active loading to free up bandwidth
  clearQueues() {
    // Reset any in-flight loading counters
    // This helps when entering fullscreen to free bandwidth
    if (typeof window !== 'undefined') {
      window._mediaLoadTimeout = Date.now();
    }
  }
};

// Initialize the pool
mediaPool.init();