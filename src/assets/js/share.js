// share.js - Mastodon sharing functionality
import { state } from './state.js';

const COOKIE_DAYS = 365;
let shareModal = null;
let currentShareFile = null;
let keyHandler = null;

let emojiData = null;
const FREQUENTLY_USED_KEY = 'mastodon_emoji_frequently_used';
const MAX_FREQUENT = 12;

const MENTIONS_KEY = 'mastodon_mentions';
const THREAD_POST_ID_KEY = 'mastodon_last_post_id';
const THREAD_ACTIVE_KEY = 'mastodon_thread_active';

function getLastThreadPostId() {
    try { return sessionStorage.getItem(THREAD_POST_ID_KEY) || null; }
    catch { return null; }
}

function setLastThreadPostId(id) {
    try {
        if (id) sessionStorage.setItem(THREAD_POST_ID_KEY, id);
        else sessionStorage.removeItem(THREAD_POST_ID_KEY);
    } catch {}
}

function getThreadActive() {
    try { return sessionStorage.getItem(THREAD_ACTIVE_KEY) === 'true'; }
    catch { return false; }
}

function setThreadActive(active) {
    try {
        if (active) sessionStorage.setItem(THREAD_ACTIVE_KEY, 'true');
        else sessionStorage.removeItem(THREAD_ACTIVE_KEY);
    } catch {}
}

function getSavedMentions() {
    try { return JSON.parse(localStorage.getItem(MENTIONS_KEY)) || []; }
    catch { return []; }
}

function saveMentions(arr) {
    localStorage.setItem(MENTIONS_KEY, JSON.stringify(arr));
}

function extractMentions(text) {
    const regex = /@[\w.]+(?:@[\w.-]+)?/g;
    const mentions = new Set();
    let match;
    while ((match = regex.exec(text)) !== null) mentions.add(match[0]);
    return mentions;
}

async function loadEmojiLib() {
    if (!emojiData) {
        const response = await fetch('./assets/js/lib/emojilib-v4.0.2/emoji-en-US.json');
        emojiData = await response.json();
    }
    return emojiData;
}

function getFrequentlyUsed() {
    try {
        const stored = localStorage.getItem(FREQUENTLY_USED_KEY);
        return stored ? JSON.parse(stored) : {};
    } catch {
        return {};
    }
}

function incrementEmojiUsage(emoji) {
    const freq = getFrequentlyUsed();
    freq[emoji] = (freq[emoji] || 0) + 1;
    localStorage.setItem(FREQUENTLY_USED_KEY, JSON.stringify(freq));
}

function getTopEmojis(count) {
    const freq = getFrequentlyUsed();
    return Object.entries(freq)
        .sort((a, b) => b[1] - a[1])
        .slice(0, count)
        .map(([emoji]) => emoji);
}

function searchEmojis(query, lib) {
    if (!query.trim()) return [];
    const q = query.toLowerCase();
    const results = [];
    for (const [emoji, keywords] of Object.entries(lib)) {
        if (Array.isArray(keywords)) {
            for (const keyword of keywords) {
                if (keyword.toLowerCase().includes(q)) {
                    results.push(emoji);
                    break;
                }
            }
        }
    }
    return results;
}

function renderMentionTags() {
    const list = getSavedMentions();
    const container = document.getElementById('mention-list');
    if (!container) return;

    container.innerHTML = '';

    for (const name of list) {
        if (!name) continue;
        const tag = document.createElement('span');
        tag.className = 'mention-tag';

        const text = document.createTextNode('@' + name);

        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'mention-remove';
        removeBtn.textContent = '✕';
        removeBtn.title = 'Remove ' + name;
        removeBtn.addEventListener('click', () => {
            const arr = getSavedMentions().filter(n => n !== name);
            saveMentions(arr);
            renderMentionTags();
        });

        tag.append(text, removeBtn);
        container.appendChild(tag);
    }

    const addInput = document.createElement('input');
    addInput.type = 'text';
    addInput.className = 'mention-add-input';
    addInput.placeholder = '+ Add';
    addInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            const raw = addInput.value.trim();
            if (!raw) return;
            const clean = raw.replace(/^@/, '').toLowerCase();
            const arr = getSavedMentions();
            if (clean && !arr.includes(clean)) {
                arr.push(clean);
                saveMentions(arr);
            }
            addInput.value = '';
            renderMentionTags();
        }
    });
    container.appendChild(addInput);
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function getFileExtension(filename) {
    const parts = filename.split('.');
    return parts.length > 1 ? parts.pop().toLowerCase() : '';
}

function isVideoFile(filename) {
    const ext = getFileExtension(filename);
    return ['mp4', 'webm', 'mov', 'm4v', 'mkv', 'avi', 'wmv', 'flv', '3gp', 'mpg', 'mpeg', 'ogv'].includes(ext);
}

function isImageFile(filename) {
    const ext = getFileExtension(filename);
    return ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic'].includes(ext);
}

function isAudioFile(filename) {
    const ext = getFileExtension(filename);
    return ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac'].includes(ext);
}

export function openShareModal(file, isVideo, isAudio) {
    currentShareFile = file;
    
    if (shareModal) {
        shareModal.remove();
    }

    const instance = getCookie('mastodon_instance') || '';
    const token = getCookie('mastodon_token') || '';

    shareModal = document.createElement('div');
    shareModal.id = 'share-modal';

    const filename = decodeURIComponent(file.split('/').pop());
    
    // file already starts with /volumes, so use it directly
    let mediaPreview;
    if (isVideo) {
        mediaPreview = `<video class="media-preview" src="${file}" autoplay muted loop playsinline></video>`;
    } else if (isAudio) {
        mediaPreview = `<audio class="media-preview" src="${file}" controls></audio>`;
    } else if (isImageFile(filename)) {
        mediaPreview = `<img class="media-preview" src="${file}" alt="${escapeHtml(filename)}">`;
    } else {
        // Generic file icon for non-media files
        mediaPreview = `<div class="file-preview"><span class="file-icon">📄</span><span class="file-name">${escapeHtml(filename)}</span></div>`;
    }

    shareModal.innerHTML = `
        <div class="modal-content">
            <h2>📤 Share to Mastodon</h2>
            
            <div class="media-container">
                ${mediaPreview}
            </div>
            <div class="filename">${filename}</div>
            
            <div class="error" id="share-error"></div>
            <div class="success" id="share-success"></div>
            
            <div class="form-group">
                <label for="mastodon-instance">Mastodon Instance</label>
                <input type="text" id="mastodon-instance" placeholder="mastodon.social" value="${escapeHtml(instance)}">
                <div class="help-text">e.g., mastodon.social, fosstodon.org</div>
            </div>
            
            <div class="form-group">
                <label for="mastodon-token">Access Token</label>
                <input type="password" id="mastodon-token" placeholder="Your Mastodon access token" value="${escapeHtml(token)}">
                <div class="help-text">Get it from your Mastodon account settings → Development → New application</div>
            </div>
            
            <div class="tagged-contacts" id="tagged-contacts">
                <label>Tagged Contacts</label>
                <div id="mention-list"></div>
            </div>

            <div class="form-group">
                <label for="share-status">Status Text</label>
                <textarea id="share-status" placeholder="Check this out! @user@example.com"></textarea>
                <div class="emoji-picker">
                    <input type="text" id="emoji-search" class="emoji-search" placeholder="Search emojis (e.g., hot, love, cat)">
                    <div class="emoji-section frequently-used hidden">
                        <div class="emoji-section-title">Frequently Used</div>
                        <div class="emoji-grid" id="frequently-used-grid"></div>
                    </div>
                    <div class="emoji-section all-emojis">
                        <div class="emoji-section-title">All Emojis</div>
                        <div class="emoji-grid" id="all-emoji-grid"></div>
                    </div>
                </div>
                <div class="help-text">Use @user@instance format to mention people on other instances</div>
            </div>
            
            <div class="form-group">
                <label for="share-visibility">Visibility</label>
                <select id="share-visibility">
                    <option value="direct" selected>Direct (mention only)</option>
                    <option value="private">Private (followers only)</option>
                    <option value="unlisted">Unlisted</option>
                    <option value="public">Public</option>
                </select>
            </div>
            
            <div class="checkbox-group">
                <input type="checkbox" id="share-sensitive" checked>
                <label for="share-sensitive">Mark as sensitive (blur video)</label>
            </div>
            
            <div class="checkbox-group">
                <input type="checkbox" id="share-reply-thread">
                <label for="share-reply-thread">Reply to previous post</label>
            </div>
            
            <div class="checkbox-group">
                <input type="checkbox" id="save-credentials" ${instance && token ? 'checked' : ''}>
                <label for="save-credentials">Save instance and token for next time</label>
            </div>
            
            <div class="button-row">
                <button class="btn-cancel" id="share-cancel">Cancel</button>
                <button class="btn-share" id="share-submit">Share to Mastodon</button>
            </div>
        </div>
    `;

    document.body.appendChild(shareModal);

    const cancelBtn = document.getElementById('share-cancel');
    const submitBtn = document.getElementById('share-submit');
    const errorEl = document.getElementById('share-error');
    const successEl = document.getElementById('share-success');

    cancelBtn.onclick = closeShareModal;
    shareModal.onclick = (e) => {
        if (e.target === shareModal) closeShareModal();
    };

    keyHandler = (e) => {
        if (e.key === 'Escape') {
            closeShareModal();
        }
    };
    document.addEventListener('keydown', keyHandler);

    const emojiSearchInput = document.getElementById('emoji-search');
    const frequentlyUsedSection = document.querySelector('.frequently-used');
    const frequentlyUsedGrid = document.getElementById('frequently-used-grid');
    const allEmojiGrid = document.getElementById('all-emoji-grid');

    function renderEmojiButtons(emojis, container) {
        container.innerHTML = '';
        for (const emoji of emojis) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'emoji-btn';
            btn.dataset.emoji = emoji;
            btn.textContent = emoji;
            btn.addEventListener('click', () => {
                incrementEmojiUsage(emoji);
                const textarea = document.getElementById('share-status');
                insertAtCursor(textarea, emoji);
                textarea.focus();
            });
            container.appendChild(btn);
        }
    }

    async function initEmojiPicker() {
        const lib = await loadEmojiLib();
        
        const topEmojis = getTopEmojis(MAX_FREQUENT);
        if (topEmojis.length > 0) {
            frequentlyUsedSection.classList.remove('hidden');
            renderEmojiButtons(topEmojis, frequentlyUsedGrid);
        }

        const allEmojis = Object.keys(lib);
        renderEmojiButtons(allEmojis, allEmojiGrid);
    }

    emojiSearchInput.addEventListener('input', async (e) => {
        const query = e.target.value.trim();
        const lib = await loadEmojiLib();
        
        if (query) {
            const results = searchEmojis(query, lib);
            allEmojiGrid.innerHTML = '';
            if (results.length > 0) {
                renderEmojiButtons(results.slice(0, 100), allEmojiGrid);
            } else {
                allEmojiGrid.innerHTML = '<div class="no-results">No emojis found</div>';
            }
            frequentlyUsedSection.classList.add('hidden');
        } else {
            const topEmojis = getTopEmojis(MAX_FREQUENT);
            if (topEmojis.length > 0) {
                frequentlyUsedSection.classList.remove('hidden');
            }
            const allEmojis = Object.keys(lib);
            renderEmojiButtons(allEmojis, allEmojiGrid);
        }
    });

    initEmojiPicker();
    renderMentionTags();

    submitBtn.onclick = async () => {
        const instanceInput = document.getElementById('mastodon-instance');
        const tokenInput = document.getElementById('mastodon-token');
        const statusInput = document.getElementById('share-status');
        const visibilityInput = document.getElementById('share-visibility');
        const sensitiveInput = document.getElementById('share-sensitive');
        const saveCheckbox = document.getElementById('save-credentials');

        const instanceVal = instanceInput.value.trim();
        const tokenVal = tokenInput.value.trim();
        const statusVal = statusInput.value.trim();
        const visibilityVal = visibilityInput.value;
        const sensitiveVal = sensitiveInput.checked;

        if (!instanceVal) {
            showError('Please enter your Mastodon instance');
            return;
        }

        if (!tokenVal) {
            showError('Please enter your access token');
            return;
        }

        if (saveCheckbox.checked) {
            setCookie('mastodon_instance', instanceVal, COOKIE_DAYS);
            setCookie('mastodon_token', tokenVal, COOKIE_DAYS);
        } else {
            deleteCookie('mastodon_instance');
            deleteCookie('mastodon_token');
        }

        const savedNames = getSavedMentions();
        const prefix = savedNames.map(n => '@' + n).join(' ') + (savedNames.length ? ' ' : '');
        const fullStatus = prefix + statusVal;

        const typedMentions = extractMentions(statusVal);
        for (const m of typedMentions) {
            const clean = m.replace(/^@/, '');
            if (clean && !savedNames.includes(clean)) {
                savedNames.push(clean);
            }
        }
        saveMentions(savedNames);

        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner"></span>Sharing...';
        hideError();
        hideSuccess();

            const replyChecked = document.getElementById('share-reply-thread').checked;
            const lastId = getLastThreadPostId();

            const body = {
                action: 'share',
                file: currentShareFile,
                status: fullStatus,
                visibility: visibilityVal,
                sensitive: sensitiveVal,
                instance: instanceVal,
                token: tokenVal
            };
            if (replyChecked && lastId) {
                body.in_reply_to_id = lastId;
            }

            try {
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body)
                });

                const data = await response.json();

                if (data.error) {
                    showError(data.error);
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = 'Share to Mastodon';
                    return;
                }

                if (data.postId) {
                    setLastThreadPostId(data.postId);
                    setThreadActive(replyChecked);
                }

                showSuccess(`Shared successfully! <a href="${data.postUrl}" target="_blank">View post</a>`);
                submitBtn.classList.add('hidden');
                cancelBtn.textContent = 'Close';

        } catch (err) {
            showError('Share failed: ' + err.message);
            submitBtn.disabled = false;
            submitBtn.innerHTML = 'Share to Mastodon';
        }
    };

    function showError(msg) {
        errorEl.textContent = msg;
        errorEl.classList.remove('hidden');
        successEl.classList.add('hidden');
    }

    function hideError() {
        errorEl.classList.add('hidden');
    }

    function showSuccess(msg) {
        successEl.innerHTML = msg;
        successEl.classList.remove('hidden');
        errorEl.classList.add('hidden');
    }

    function hideSuccess() {
        successEl.classList.add('hidden');
    }
}

export function closeShareModal() {
    if (keyHandler) {
        document.removeEventListener('keydown', keyHandler);
        keyHandler = null;
    }
    if (shareModal) {
        shareModal.remove();
        shareModal = null;
    }
    currentShareFile = null;
}

function setCookie(name, value, days) {
    const expires = new Date(Date.now() + days * 864e5).toUTCString();
    document.cookie = name + '=' + encodeURIComponent(value) + '; expires=' + expires + '; path=/; SameSite=Lax';
}

function getCookie(name) {
    const value = document.cookie.split('; ').find(row => row.startsWith(name + '='));
    return value ? decodeURIComponent(value.split('=')[1]) : '';
}

function deleteCookie(name) {
    document.cookie = '=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
}

function insertAtCursor(textarea, text) {
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const value = textarea.value;
    textarea.value = value.substring(0, start) + text + value.substring(end);
    textarea.selectionStart = textarea.selectionEnd = start + text.length;
}
