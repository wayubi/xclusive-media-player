// mediaPool.js - Reusable media element pooling
const MAX_POOL_SIZE = 48;

export const mediaPool = {
  videoPool: [],
  audioPool: [],
  
  init() {
    for (let i = 0; i < MAX_POOL_SIZE; i++) {
      const video = document.createElement('video');
      video.preload = 'auto';
      video.playsInline = true;
      video.loop = true;
      video.controls = false;
      this.videoPool.push(video);

      const audio = document.createElement('audio');
      audio.preload = 'auto';
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
      el.src = '';
      el.currentTime = 0;
      
      if (el.tagName === 'VIDEO') {
        this.returnVideo(el);
      } else if (el.tagName === 'AUDIO') {
        this.returnAudio(el);
      }
    });
  }
};

// Initialize the pool
mediaPool.init();