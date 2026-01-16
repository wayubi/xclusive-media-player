// mediaQueue.js - Media loading queue management
import { state } from './state.js';

const audioQueue = [];
const videoQueue = [];
let activeAudioLoads = 0;
let activeVideoLoads = 0;

export const mediaQueue = {
  processAudioQueue() {
    while (activeAudioLoads < state.totalCells && audioQueue.length) {
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
  
  processVideoQueue() {
    while (activeVideoLoads < state.totalCells && videoQueue.length) {
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
  
  addAudio(audio) {
    audioQueue.push(audio);
  },
  
  addVideo(video) {
    videoQueue.push(video);
  }
};