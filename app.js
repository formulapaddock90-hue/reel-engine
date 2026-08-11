/* ==========================================================================
   FormulaPaddock F1 Reel Engine — Embeddable Live Engine & Auto URL Extractor
   ========================================================================== */

const state = {
  activeSceneIndex: 0,
  isPlaying: false,
  isRecording: false,
  currentTime: 2.4,
  duration: 15.0,
  telemetryHud: true,
  brandWatermark: true,

  // Brand Identity State
  brand: {
    name: 'FORMULAPADDOCK.IT',
    color: '#e10600',
    logoUrl: '/api/image-proxy?url=' + encodeURIComponent('https://www.formulapaddock.it/wp-content/uploads/2026/05/preview.webp'),
    logoImg: null,
    cta: 'SEGUI FORMULAPADDOCK.IT SU INSTAGRAM E TIKTOK'
  },

  // Audio Engine State
  audio: {
    preset: 'downloads_0',
    musicVolume: 0.85,
    duckingEnabled: true,
    customAudioBuffer: null,
    previewMuted: false,
    youtubeUrl: '',
    downloadsTracks: []
  },
  
  // Active Article Data
  article: {
    url: 'https://formulapaddock.it',
    title: 'FormulaPaddock F1 News',
    scenes: [
      {
        id: 1,
        label: 'Scena 1 (Hook)',
        text: 'La Ferrari svela la nuova livrea e il fondo per la sfida a Monza!',
        duration: 4.0,
        image: 'assets/images/tech.jpg',
        loadedImg: null,
        words: [
          { text: 'FERRARI', start: 0.2, end: 0.9 },
          { text: 'SVELA', start: 0.9, end: 1.4 },
          { text: 'NUOVO', start: 1.4, end: 2.0 },
          { text: 'FONDO', start: 2.0, end: 2.6 },
          { text: 'A MONZA!', start: 2.6, end: 3.2 }
        ]
      },
      {
        id: 2,
        label: 'Scena 2 (Dettaglio)',
        text: 'L\'aggiornamento aerodinamico punta a ridurre la resistenza in rettilineo.',
        duration: 4.0,
        image: 'assets/images/cyberpunk.jpg',
        loadedImg: null,
        words: [
          { text: 'AGGIORNAMENTO', start: 4.2, end: 5.0 },
          { text: 'AERODINAMICO', start: 5.0, end: 6.0 },
          { text: 'RIDUCE', start: 6.0, end: 6.8 },
          { text: 'RESISTENZA', start: 6.8, end: 7.6 }
        ]
      },
      {
        id: 3,
        label: 'Scena 3 (Conclusione)',
        text: 'Charles Leclerc cerca il successo nel GP di casa davanti al pubblico italiano.',
        duration: 4.0,
        image: 'assets/images/nature.jpg',
        loadedImg: null,
        words: [
          { text: 'LECLERC', start: 8.2, end: 9.0 },
          { text: 'CERCA', start: 9.0, end: 9.6 },
          { text: 'LA VITTORIA', start: 9.6, end: 10.5 },
          { text: 'A MONZA!', start: 10.5, end: 11.5 }
        ]
      }
    ]
  },

  // Extracted WordPress Images
  articleImages: [
    { id: 'img1', url: 'assets/images/tech.jpg', title: 'Immagine 1' },
    { id: 'img2', url: 'assets/images/cyberpunk.jpg', title: 'Immagine 2' },
    { id: 'img3', url: 'assets/images/nature.jpg', title: 'Immagine 3' }
  ]
};

let playbackTimer = null;
let animFrameId = null;
const imageCache = {};

// WEB AUDIO API SYSTEM & REAL AUDIO PLAYER
let audioCtx = null;
let masterGain = null;
let musicGain = null;
let streamDestNode = null;
let audioElement = null;
let mediaElementSource = null;

// DOM References
const dom = {
  canvas: document.getElementById('reel-render-canvas'),
  urlForm: document.getElementById('url-extractor-form'),
  articleUrlInput: document.getElementById('article-url'),
  btnExtractUrl: document.getElementById('btn-extract-url'),
  extractionStatus: document.getElementById('extraction-status'),
  extractionStatusText: document.getElementById('extraction-status-text'),
  scenesContainer: document.getElementById('scenes-container'),
  extractedTitleDisplay: document.getElementById('extracted-title-display'),
  activeSceneBadge: document.getElementById('active-scene-badge'),
  imageGalleryGrid: document.getElementById('image-gallery-grid'),
  
  // Brand Controls
  brandNameInput: document.getElementById('brand-name-input'),
  brandColorSelect: document.getElementById('brand-color-select'),
  brandCustomColor: document.getElementById('brand-custom-color'),
  brandLogoFile: document.getElementById('brand-logo-file'),
  brandCtaText: document.getElementById('brand-cta-text'),
  headerLogoBox: document.getElementById('header-logo-box'),
  headerLogoText: document.getElementById('header-logo-text'),
  headerBrandTitle: document.getElementById('header-brand-title'),

  // Audio Controls
  musicTrackSelect: document.getElementById('music-track-select'),
  btnRandomMusic: document.getElementById('btn-random-music'),
  youtubeUrlBox: document.getElementById('youtube-url-box'),
  youtubeUrlInput: document.getElementById('youtube-url-input'),
  btnLoadYtAudio: document.getElementById('btn-load-yt-audio'),
  musicVolumeSlider: document.getElementById('music-volume-slider'),
  chkAudioDucking: document.getElementById('chk-audio-ducking'),
  btnToggleAudioPreview: document.getElementById('btn-toggle-audio-preview'),
  previewAudioIcon: document.getElementById('preview-audio-icon'),

  // Preview & Canvas
  chkTelemetryHud: document.getElementById('chk-telemetry-hud'),
  chkBrandWatermark: document.getElementById('chk-brand-watermark'),
  btnPlayPause: document.getElementById('btn-play-pause'),
  playIcon: document.getElementById('play-icon'),
  currentTimeText: document.getElementById('current-time-text'),
  totalTimeText: document.getElementById('total-time-text'),
  scrubberInput: document.getElementById('timeline-scrubber'),
  btnStartMediaRecorder: document.getElementById('btn-start-mediarecorder'),
  btnRenderRecordVideo: document.getElementById('btn-render-record-video'),
  recStatusText: document.getElementById('rec-status-text'),
  
  // Modals & Toast
  modalPhoneFallback: document.getElementById('modal-phone-fallback'),
  btnSendPhone: document.getElementById('btn-send-phone'),
  btnCloseQr: document.getElementById('btn-close-qr'),
  toastContainer: document.getElementById('toast-container')
};

let ctx = null;

function initApp() {
  if (dom.canvas) ctx = dom.canvas.getContext('2d');
  
  preloadImages();
  loadOfficialFormulaPaddockLogo();
  initAudioElement();
  loadDownloadsAudioFiles();
  bindUrlExtractor();
  bindBrandControls();
  bindAudioControls();
  bindPlayerControls();
  bindMediaRecorder();
  bindModals();
  renderScenes();
  renderGallery();
  
  // Auto-extract URL if passed via query parameter ?url=...
  const urlParams = new URLSearchParams(window.location.search);
  const initialUrl = urlParams.get('url');
  if (initialUrl) {
    if (dom.articleUrlInput) dom.articleUrlInput.value = initialUrl;
    fetchWordPressArticleViaLocalProxy(initialUrl);
  }

  // Start Canvas Engine
  startCanvasLoop();
}

function loadOfficialFormulaPaddockLogo() {
  const img = new Image();
  img.crossOrigin = 'anonymous';
  img.onload = () => {
    state.brand.logoImg = img;
  };
  img.src = state.brand.logoUrl;
}

// AUTOMATIC DOWNLOADS FOLDER MP3 DISCOVERY & RANDOM SELECTOR
async function loadDownloadsAudioFiles() {
  try {
    const res = await fetch('/api/downloads-audio');
    if (!res.ok) return;
    const data = await res.json();

    if (data.status === 'SUCCESS' && data.files && data.files.length > 0) {
      state.audio.downloadsTracks = data.files;
      
      if (dom.musicTrackSelect) {
        dom.musicTrackSelect.innerHTML = '';
        
        data.files.forEach((fileObj, idx) => {
          const opt = document.createElement('option');
          opt.value = `dl_${idx}`;
          opt.dataset.url = fileObj.url;
          opt.textContent = `🎵 ${fileObj.name}`;
          dom.musicTrackSelect.appendChild(opt);
        });

        const optYt = document.createElement('option');
        optYt.value = 'youtube_custom';
        optYt.textContent = '🔗 Incolla Altro Link Audio / MP3';
        dom.musicTrackSelect.appendChild(optYt);

        // Pick RANDOM Track automatically on load!
        selectRandomTrack(false);
      }
    }
  } catch (err) {
    console.warn('Downloads audio fetch info', err);
  }
}

// RANDOM TRACK SELECTOR FUNCTION 🎲
function selectRandomTrack(showUserToast = true) {
  const tracks = state.audio.downloadsTracks;
  if (!tracks || tracks.length === 0 || !dom.musicTrackSelect) return;

  const randomIdx = Math.floor(Math.random() * tracks.length);
  const randomTrack = tracks[randomIdx];

  dom.musicTrackSelect.selectedIndex = randomIdx;

  if (audioElement && randomTrack) {
    audioElement.src = randomTrack.url;
    if (state.isPlaying) audioElement.play();
  }

  if (showUserToast) {
    showToast(`🎲 Canzone casuale scelta: ${randomTrack.name}`);
  }
}

function initAudioElement() {
  if (audioElement) return;
  audioElement = new Audio();
  audioElement.crossOrigin = 'anonymous';
  audioElement.loop = true;
}

function initAudioContext() {
  if (audioCtx) return;
  const AudioContextClass = window.AudioContext || window.webkitAudioContext;
  audioCtx = new AudioContextClass();

  masterGain = audioCtx.createGain();
  musicGain = audioCtx.createGain();

  masterGain.gain.value = 1.0;
  musicGain.gain.value = state.audio.musicVolume;

  musicGain.connect(masterGain);
  masterGain.connect(audioCtx.destination);

  streamDestNode = audioCtx.createMediaStreamDestination();
  masterGain.connect(streamDestNode);

  if (audioElement && !mediaElementSource) {
    try {
      mediaElementSource = audioCtx.createMediaElementSource(audioElement);
      mediaElementSource.connect(musicGain);
    } catch (e) {
      console.warn('MediaElementSource warning', e);
    }
  }
}

function preloadImages() {
  state.article.scenes.forEach(scene => {
    loadImage(scene.image, (img) => {
      scene.loadedImg = img;
    });
  });
}

function loadImage(src, callback) {
  if (!src) return;

  if (imageCache[src]) {
    callback(imageCache[src]);
    return;
  }

  const img = new Image();
  img.crossOrigin = 'anonymous';
  img.onload = () => {
    imageCache[src] = img;
    callback(img);
  };
  img.onerror = () => {
    const fallback = new Image();
    fallback.onload = () => callback(fallback);
    fallback.src = 'assets/images/tech.jpg';
  };
  img.src = src;
}

// LOCAL API SERVER SCRAPER
function bindUrlExtractor() {
  if (!dom.urlForm) return;

  dom.urlForm.addEventListener('submit', (e) => {
    e.preventDefault();
    const url = dom.articleUrlInput.value.trim();
    if (!url) return;
    fetchWordPressArticleViaLocalProxy(url);
  });
}

async function fetchWordPressArticleViaLocalProxy(targetUrl) {
  if (dom.extractionStatus) dom.extractionStatus.classList.remove('hidden');
  if (dom.extractionStatusText) dom.extractionStatusText.textContent = `Estrazione server locale di testo e foto per: ${targetUrl}...`;
  if (dom.btnExtractUrl) dom.btnExtractUrl.disabled = true;

  try {
    const res = await fetch(`/api/scrape?url=${encodeURIComponent(targetUrl)}`);
    if (!res.ok) throw new Error(`Server error ${res.status}`);
    const data = await res.json();

    if (data.status === 'SUCCESS') {
      const pageTitle = data.title || 'Notizia F1 FormulaPaddock';
      const fullText = (data.paragraphs && data.paragraphs.length > 0) ? data.paragraphs.join(' ') : pageTitle;

      processArticleTextIntoScenes(pageTitle, fullText);

      // Process WordPress Images via local image proxy
      if (data.images && data.images.length > 0) {
        state.articleImages = data.images.map((url, i) => ({
          id: `wp_img_${i}`,
          url: `/api/image-proxy?url=${encodeURIComponent(url)}`,
          originalUrl: url,
          title: `Foto WordPress ${i+1}`
        }));

        if (state.articleImages[0]) assignImageToScene(0, state.articleImages[0].url);
        if (state.articleImages[1]) assignImageToScene(1, state.articleImages[1].url);
        else if (state.articleImages[0]) assignImageToScene(1, state.articleImages[0].url);
        
        if (state.articleImages[2]) assignImageToScene(2, state.articleImages[2].url);
        else if (state.articleImages[0]) assignImageToScene(2, state.articleImages[0].url);

        showToast(`⚡ Estratti testo e ${state.articleImages.length} foto dall'articolo WordPress!`);
      } else {
        showToast('⚡ Testo dell\'articolo estratto con successo!');
      }

      // Re-roll random track on new article extraction! 🎲
      selectRandomTrack(true);
      renderGallery();
    } else {
      throw new Error(data.error || 'Scraping failed');
    }

  } catch (err) {
    showToast('⚠️ Impossibile accedere all\'URL dell\'articolo.');
  } finally {
    if (dom.extractionStatus) dom.extractionStatus.classList.add('hidden');
    if (dom.btnExtractUrl) dom.btnExtractUrl.disabled = false;
  }
}

function processArticleTextIntoScenes(title, text) {
  state.article.title = title;
  if (dom.extractedTitleDisplay) dom.extractedTitleDisplay.textContent = title.substring(0, 32) + '...';

  const rawSentences = text.match(/[^.!?]+[.!?]+/g) || [text];
  const cleanSentences = rawSentences
    .map(s => s.replace(/\s+/g, ' ').trim())
    .filter(s => s.length > 15 && !s.includes('Cookie') && !s.includes('Uncategorized'));

  const scene1Text = cleanSentences[0] || title || 'Nuovo aggiornamento F1';
  const scene2Text = cleanSentences[1] || cleanSentences[0] || 'Dettagli tecnici e telemetria gara F1';
  const scene3Text = cleanSentences[2] || cleanSentences[cleanSentences.length - 1] || 'Leggi tutti gli aggiornamenti su FormulaPaddock.it';

  state.article.scenes[0].text = cleanSentence(scene1Text, 80);
  state.article.scenes[1].text = cleanSentence(scene2Text, 80);
  state.article.scenes[2].text = cleanSentence(scene3Text, 80);

  state.article.scenes.forEach((scene, index) => {
    const parts = scene.text.toUpperCase().split(' ');
    scene.words = parts.map((p, i) => ({
      text: p,
      start: index * 4.0 + (i * 0.7),
      end: index * 4.0 + ((i + 1) * 0.7)
    }));
  });

  renderScenes();
}

function cleanSentence(str, maxLen) {
  let cleaned = str.replace(/\s+/g, ' ').trim();
  if (cleaned.length > maxLen) {
    cleaned = cleaned.substring(0, maxLen) + '...';
  }
  return cleaned;
}

function assignImageToScene(sceneIndex, url) {
  const scene = state.article.scenes[sceneIndex];
  if (!scene) return;
  scene.image = url;
  
  loadImage(url, (img) => {
    scene.loadedImg = img;
  });
  
  renderScenes();
  renderGallery();
}

function renderScenes() {
  if (!dom.scenesContainer) return;
  dom.scenesContainer.innerHTML = '';

  state.article.scenes.forEach((scene, index) => {
    const card = document.createElement('div');
    card.className = `scene-edit-card ${index === state.activeSceneIndex ? 'active' : ''}`;
    card.innerHTML = `
      <div class="sec-head">
        <span>${scene.label}</span>
        <span>${scene.duration}s</span>
      </div>
      <textarea class="scene-text-input" rows="2">${scene.text}</textarea>
      <div style="display:flex; align-items:center; gap:6px; margin-top:4px;">
        <img src="${scene.image}" style="width:24px; height:24px; border-radius:4px; object-fit:cover;">
        <span style="font-size:0.7rem; color:var(--text-muted);">Foto WordPress</span>
      </div>
    `;

    card.addEventListener('click', (e) => {
      if (e.target.tagName === 'TEXTAREA') return;
      state.activeSceneIndex = index;
      if (dom.activeSceneBadge) dom.activeSceneBadge.textContent = scene.label;
      renderScenes();
      renderGallery();
      showToast(`🎯 Attivata ${scene.label}: scegli la foto dell'articolo sotto!`);
    });

    const textarea = card.querySelector('textarea');
    textarea.addEventListener('input', (e) => {
      scene.text = e.target.value;
      const parts = scene.text.toUpperCase().split(' ');
      scene.words = parts.map((p, i) => ({
        text: p,
        start: index * 4.0 + (i * 0.7),
        end: index * 4.0 + ((i + 1) * 0.7)
      }));
    });

    dom.scenesContainer.appendChild(card);
  });
}

function renderGallery() {
  if (!dom.imageGalleryGrid) return;
  dom.imageGalleryGrid.innerHTML = '';

  const activeScene = state.article.scenes[state.activeSceneIndex];
  state.articleImages.forEach(item => {
    const isSelected = activeScene.image === item.url;
    const card = document.createElement('div');
    card.className = `gallery-item ${isSelected ? 'selected' : ''}`;
    card.innerHTML = `
      <img src="${item.url}" alt="${item.title}">
      ${isSelected ? '<span class="badge-sel">ATTIVA</span>' : ''}
    `;

    card.addEventListener('click', () => {
      assignImageToScene(state.activeSceneIndex, item.url);
      showToast(`🖼️ Foto WordPress assegnata alla ${activeScene.label}!`);
    });

    dom.imageGalleryGrid.appendChild(card);
  });
}

// BRAND IDENTITY CONTROLS
function bindBrandControls() {
  if (dom.brandNameInput) {
    dom.brandNameInput.addEventListener('input', (e) => {
      const val = e.target.value.trim().toUpperCase() || 'FORMULAPADDOCK.IT';
      state.brand.name = val;
      if (dom.headerBrandTitle) dom.headerBrandTitle.textContent = `${val} Reel Engine`;
      if (dom.headerLogoText) dom.headerLogoText.textContent = val.substring(0, 2);
    });
  }

  if (dom.brandColorSelect) {
    dom.brandColorSelect.addEventListener('change', (e) => {
      if (e.target.value === 'custom') {
        dom.brandCustomColor.classList.remove('hidden');
        state.brand.color = dom.brandCustomColor.value;
      } else {
        dom.brandCustomColor.classList.add('hidden');
        state.brand.color = e.target.value;
      }
      updateBrandColorUI();
    });
  }

  if (dom.brandCustomColor) {
    dom.brandCustomColor.addEventListener('input', (e) => {
      state.brand.color = e.target.value;
      updateBrandColorUI();
    });
  }

  if (dom.brandLogoFile) {
    dom.brandLogoFile.addEventListener('change', (e) => {
      const file = e.target.files[0];
      if (!file) return;
      const reader = new FileReader();
      reader.onload = (event) => {
        const img = new Image();
        img.onload = () => {
          state.brand.logoImg = img;
          showToast('🛡️ Logo Personalizzato caricato sul Canvas!');
        };
        img.src = event.target.result;
      };
      reader.readAsDataURL(file);
    });
  }

  if (dom.brandCtaText) {
    dom.brandCtaText.addEventListener('input', (e) => {
      state.brand.cta = e.target.value.trim().toUpperCase();
    });
  }
}

function updateBrandColorUI() {
  if (dom.headerLogoBox) dom.headerLogoBox.style.background = state.brand.color;
  showToast(`🎨 Colore Brand aggiornato: ${state.brand.color}`);
}

// REAL AUDIO TRACK PLAYER
function bindAudioControls() {
  if (dom.btnRandomMusic) {
    dom.btnRandomMusic.addEventListener('click', () => {
      selectRandomTrack(true);
    });
  }

  if (dom.musicTrackSelect) {
    dom.musicTrackSelect.addEventListener('change', (e) => {
      const selOpt = dom.musicTrackSelect.options[dom.musicTrackSelect.selectedIndex];
      
      if (selOpt.value === 'youtube_custom') {
        dom.youtubeUrlBox.classList.remove('hidden');
      } else {
        dom.youtubeUrlBox.classList.add('hidden');
        const trackUrl = selOpt.dataset.url;
        if (audioElement && trackUrl) {
          audioElement.src = trackUrl;
          if (state.isPlaying) audioElement.play();
        }
      }
      showToast(`🎵 Traccia impostata: ${selOpt.text}`);
    });
  }

  if (dom.btnLoadYtAudio) {
    dom.btnLoadYtAudio.addEventListener('click', () => {
      const ytUrl = dom.youtubeUrlInput.value.trim();
      if (!ytUrl) return;
      state.audio.youtubeUrl = ytUrl;
      if (audioElement) {
        audioElement.src = ytUrl;
        if (state.isPlaying) audioElement.play();
      }
      showToast(`🔗 Link Audio sintonizzato!`);
    });
  }

  if (dom.musicVolumeSlider) {
    dom.musicVolumeSlider.addEventListener('input', (e) => {
      state.audio.musicVolume = parseFloat(e.target.value) / 100;
      if (musicGain) musicGain.gain.value = state.audio.musicVolume;
      if (audioElement) audioElement.volume = state.audio.musicVolume;
    });
  }

  if (dom.chkAudioDucking) {
    dom.chkAudioDucking.addEventListener('change', (e) => {
      state.audio.duckingEnabled = e.target.checked;
    });
  }

  if (dom.btnToggleAudioPreview) {
    dom.btnToggleAudioPreview.addEventListener('click', () => {
      initAudioContext();
      if (audioCtx.state === 'suspended') audioCtx.resume();

      state.audio.previewMuted = !state.audio.previewMuted;
      if (masterGain) masterGain.gain.value = state.audio.previewMuted ? 0 : 1.0;
      if (audioElement) audioElement.muted = state.audio.previewMuted;

      dom.previewAudioIcon.setAttribute('data-lucide', state.audio.previewMuted ? 'volume-x' : 'volume-2');
      if (window.lucide) lucide.createIcons();

      showToast(state.audio.previewMuted ? '🔇 Audio Muto' : '🔊 Audio Attivo!');
    });
  }
}

function startRealAudioPlayback() {
  initAudioContext();
  if (audioCtx.state === 'suspended') audioCtx.resume();

  if (audioElement) {
    audioElement.currentTime = state.currentTime;
    audioElement.volume = state.audio.musicVolume;
    audioElement.play().catch(err => console.warn('Audio playback info:', err));
  }
}

function stopRealAudioPlayback() {
  if (audioElement) {
    audioElement.pause();
  }
}

function updateLiveAudioParams(time) {
  if (!audioCtx) return;

  // Audio Ducking during active subtitle words
  let isSpeaking = false;
  let sceneIdx = Math.floor(time / 4.0);
  if (sceneIdx > 2) sceneIdx = 2;
  const currentScene = state.article.scenes[sceneIdx];

  if (currentScene && currentScene.words && time < 12.0) {
    currentScene.words.forEach(w => {
      if (time >= w.start && time <= w.end) isSpeaking = true;
    });
  }

  if (state.audio.duckingEnabled && musicGain) {
    const targetGain = isSpeaking ? state.audio.musicVolume * 0.3 : state.audio.musicVolume;
    musicGain.gain.setTargetAtTime(targetGain, audioCtx.currentTime, 0.05);
  }
}

// CANVAS 2D RENDER ENGINE WITH SCENES & SOCIAL CTA OUTRO END CARD (12s to 15s)
function startCanvasLoop() {
  function renderFrame() {
    drawCanvasReelFrame();
    animFrameId = requestAnimationFrame(renderFrame);
  }
  renderFrame();
}

function drawCanvasReelFrame() {
  if (!ctx || !dom.canvas) return;

  const width = dom.canvas.width;   // 1080
  const height = dom.canvas.height; // 1920
  const time = state.currentTime;

  if (state.isPlaying) {
    updateLiveAudioParams(time);
  }

  ctx.fillStyle = '#07080b';
  ctx.fillRect(0, 0, width, height);

  // SCENE RENDERER (0.0s to 12.0s) or SOCIAL CTA OUTRO END CARD (12.0s to 15.0s)
  if (time < 12.0) {
    let sceneIdx = 0;
    if (time >= 4.0 && time < 8.0) sceneIdx = 1;
    else if (time >= 8.0) sceneIdx = 2;

    const currentScene = state.article.scenes[sceneIdx];

    // Background Image with Ken Burns Zoom
    if (currentScene && currentScene.loadedImg) {
      ctx.save();
      const zoom = 1 + (time % 4.0) * 0.02;
      const dx = (width - width * zoom) / 2;
      const dy = (height - height * zoom) / 2;

      ctx.drawImage(currentScene.loadedImg, dx, dy, width * zoom, height * zoom);
      ctx.restore();
    }

    // Radial Vignette
    const grad = ctx.createRadialGradient(width/2, height/2, width*0.3, width/2, height/2, height*0.8);
    grad.addColorStop(0, 'rgba(0,0,0,0.1)');
    grad.addColorStop(1, 'rgba(0,0,0,0.75)');
    ctx.fillStyle = grad;
    ctx.fillRect(0, 0, width, height);

    // Custom Brand Watermark Badge (Top Left)
    if (state.brandWatermark) {
      ctx.save();
      ctx.fillStyle = 'rgba(0, 0, 0, 0.85)';
      ctx.fillRect(40, 60, 420, 80);
      ctx.strokeStyle = state.brand.color || '#e10600';
      ctx.lineWidth = 4;
      ctx.strokeRect(40, 60, 420, 80);

      if (state.brand.logoImg) {
        ctx.drawImage(state.brand.logoImg, 52, 70, 60, 60);
        ctx.fillStyle = '#ffffff';
        ctx.font = 'bold 32px Outfit, sans-serif';
        ctx.fillText(state.brand.name, 126, 112);
      } else {
        ctx.fillStyle = '#ffffff';
        ctx.font = 'bold 34px Outfit, sans-serif';
        ctx.fillText(state.brand.name, 56, 112);
      }

      ctx.restore();
    }

    // Telemetry Badge (Top Right)
    if (state.telemetryHud) {
      ctx.save();
      const simulatedSpeed = Math.floor(334 + (time * 1.8));

      ctx.fillStyle = 'rgba(0, 0, 0, 0.85)';
      ctx.fillRect(width - 360, 60, 320, 160);
      ctx.strokeStyle = 'rgba(255, 255, 255, 0.2)';
      ctx.lineWidth = 3;
      ctx.strokeRect(width - 360, 60, 320, 160);

      ctx.fillStyle = '#ffeb3b';
      ctx.font = '900 64px "JetBrains Mono", monospace';
      ctx.fillText(`${simulatedSpeed}`, width - 330, 130);

      ctx.fillStyle = '#a0aec0';
      ctx.font = 'bold 22px Outfit, sans-serif';
      ctx.fillText('KM/H', width - 200, 130);

      ctx.fillStyle = 'rgba(0, 230, 118, 0.2)';
      ctx.fillRect(width - 330, 150, 160, 40);
      ctx.strokeStyle = '#00e676';
      ctx.lineWidth = 2;
      ctx.strokeRect(width - 330, 150, 160, 40);

      ctx.fillStyle = '#00e676';
      ctx.font = 'bold 20px Outfit, sans-serif';
      ctx.fillText('DRS ATTIVO', width - 310, 178);

      ctx.restore();
    }

    // Subtitle Bar (Bottom)
    if (currentScene) {
      ctx.save();
      
      const boxY = height - 360;
      ctx.fillStyle = 'rgba(0, 0, 0, 0.9)';
      ctx.fillRect(60, boxY, width - 120, 240);

      ctx.fillStyle = state.brand.color || '#e10600';
      ctx.fillRect(60, boxY, 16, 240);

      ctx.fillStyle = '#ffeb3b';
      ctx.font = 'bold 26px Outfit, sans-serif';
      ctx.fillText(`${state.brand.name} • REEL F1`, 100, boxY + 48);

      let activeW = null;
      if (currentScene.words) {
        currentScene.words.forEach(w => {
          if (time >= w.start && time <= w.end) activeW = w;
        });
      }

      const fullText = currentScene.text.toUpperCase();
      ctx.fillStyle = '#ffffff';
      ctx.font = '900 48px Outfit, sans-serif';
      
      wrapText(ctx, fullText, 100, boxY + 115, width - 200, 56, activeW ? activeW.text : '');

      ctx.restore();
    }

  } else {
    // SOCIAL CTA OUTRO END CARD (12.0s to 15.0s)
    drawSocialCtaOutroCard(width, height, time);
  }
}

// DRAW VISUAL SOCIAL CTA OUTRO END CARD (12.0s to 15.0s)
function drawSocialCtaOutroCard(width, height, time) {
  ctx.save();

  // Dark Carbon Background
  ctx.fillStyle = '#08090d';
  ctx.fillRect(0, 0, width, height);

  // Animated Scuderia Red / Brand Accent Grid Lines
  ctx.strokeStyle = state.brand.color || '#e10600';
  ctx.lineWidth = 4;
  ctx.strokeRect(40, 40, width - 80, height - 80);

  // Inner Glow Box
  ctx.fillStyle = 'rgba(20, 23, 36, 0.95)';
  ctx.fillRect(80, 240, width - 160, height - 480);
  ctx.strokeStyle = 'rgba(255, 255, 255, 0.15)';
  ctx.lineWidth = 2;
  ctx.strokeRect(80, 240, width - 160, height - 480);

  // Big Official FormulaPaddock Logo / Badge in Center
  if (state.brand.logoImg) {
    ctx.drawImage(state.brand.logoImg, width/2 - 100, 360, 200, 200);
  } else {
    ctx.fillStyle = state.brand.color || '#e10600';
    ctx.fillRect(width/2 - 90, 360, 180, 180);
    ctx.fillStyle = '#ffffff';
    ctx.font = '900 90px Outfit, sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText(state.brand.name.substring(0, 2), width/2, 480);
    ctx.textAlign = 'left';
  }

  // Brand Name Title
  ctx.fillStyle = '#ffffff';
  ctx.font = '900 64px Outfit, sans-serif';
  ctx.textAlign = 'center';
  ctx.fillText(state.brand.name, width/2, 660);

  // Subtitle Yellow Divider
  ctx.fillStyle = '#ffeb3b';
  ctx.fillRect(width/2 - 120, 710, 240, 8);

  // Social Call-To-Action Text
  const ctaText = state.brand.cta || 'SEGUI FORMULAPADDOCK.IT SU INSTAGRAM E TIKTOK';
  ctx.fillStyle = '#ffffff';
  ctx.font = '800 42px Outfit, sans-serif';
  
  wrapTextCentered(ctx, ctaText, width/2, 800, width - 260, 52);

  // Animated Follow Badge (Pulse Effect)
  const pulse = 1 + Math.sin((time - 12.0) * 6) * 0.05;
  const badgeW = 440 * pulse;
  const badgeH = 100 * pulse;

  ctx.fillStyle = state.brand.color || '#e10600';
  ctx.fillRect(width/2 - badgeW/2, 1200 - badgeH/2, badgeW, badgeH);

  ctx.fillStyle = '#ffffff';
  ctx.font = '900 40px Outfit, sans-serif';
  ctx.fillText('🏁 SEGUI ORA 🏁', width/2, 1215);

  ctx.textAlign = 'left';
  ctx.restore();
}

function wrapText(context, text, x, y, maxWidth, lineHeight, activeWord) {
  const words = text.split(' ');
  let line = '';

  for (let n = 0; n < words.length; n++) {
    const testLine = line + words[n] + ' ';
    const metrics = context.measureText(testLine);
    if (metrics.width > maxWidth && n > 0) {
      drawTextLine(context, line, x, y, activeWord);
      line = words[n] + ' ';
      y += lineHeight;
    } else {
      line = testLine;
    }
  }
  drawTextLine(context, line, x, y, activeWord);
}

function drawTextLine(context, lineStr, x, y, activeWord) {
  if (activeWord && lineStr.includes(activeWord)) {
    context.fillStyle = '#ffeb3b';
  } else {
    context.fillStyle = '#ffffff';
  }
  context.fillText(lineStr, x, y);
}

function wrapTextCentered(context, text, x, y, maxWidth, lineHeight) {
  const words = text.split(' ');
  let line = '';

  for (let n = 0; n < words.length; n++) {
    const testLine = line + words[n] + ' ';
    const metrics = context.measureText(testLine);
    if (metrics.width > maxWidth && n > 0) {
      context.fillText(line, x, y);
      line = words[n] + ' ';
      y += lineHeight;
    } else {
      line = testLine;
    }
  }
  context.fillText(line, x, y);
}

// Controls & MediaRecorder Video + Audio Exporter with Google Drive Auto-Save
function bindPlayerControls() {
  if (dom.btnPlayPause) {
    dom.btnPlayPause.addEventListener('click', togglePlayPause);
  }
  if (dom.scrubberInput) {
    dom.scrubberInput.addEventListener('input', (e) => {
      state.currentTime = parseFloat(e.target.value);
      if (audioElement) audioElement.currentTime = state.currentTime;
      updatePlayerTimeUI();
    });
  }
  if (dom.chkTelemetryHud) {
    dom.chkTelemetryHud.addEventListener('change', (e) => {
      state.telemetryHud = e.target.checked;
    });
  }
  if (dom.chkBrandWatermark) {
    dom.chkBrandWatermark.addEventListener('change', (e) => {
      state.brandWatermark = e.target.checked;
    });
  }
}

function togglePlayPause() {
  state.isPlaying = !state.isPlaying;
  
  if (state.isPlaying) {
    dom.playIcon.setAttribute('data-lucide', 'pause');
    startRealAudioPlayback();

    playbackTimer = setInterval(() => {
      state.currentTime += 0.1;
      if (state.currentTime >= state.duration) {
        state.currentTime = 0;
      }
      updatePlayerTimeUI();
    }, 100);
  } else {
    dom.playIcon.setAttribute('data-lucide', 'play');
    clearInterval(playbackTimer);
    stopRealAudioPlayback();
  }
  if (window.lucide) lucide.createIcons();
}

function updatePlayerTimeUI() {
  if (dom.currentTimeText) dom.currentTimeText.textContent = formatTime(state.currentTime);
  if (dom.totalTimeText) dom.totalTimeText.textContent = formatTime(state.duration);
  if (dom.scrubberInput) dom.scrubberInput.value = state.currentTime;
}

function formatTime(sec) {
  const m = Math.floor(sec / 60);
  const s = (sec % 60).toFixed(2);
  return `${m < 10 ? '0' : ''}${m}:${s < 10 ? '0' : ''}${s}`;
}

function bindMediaRecorder() {
  if (dom.btnStartMediaRecorder) {
    dom.btnStartMediaRecorder.addEventListener('click', startMediaRecorderExport);
  }
  if (dom.btnRenderRecordVideo) {
    dom.btnRenderRecordVideo.addEventListener('click', startMediaRecorderExport);
  }
}

function startMediaRecorderExport() {
  if (state.isRecording || !dom.canvas) return;
  state.isRecording = true;

  initAudioContext();
  if (audioCtx.state === 'suspended') audioCtx.resume();

  if (dom.recStatusText) {
    dom.recStatusText.innerHTML = `<i data-lucide="loader" class="spin"></i> Registrazione Video 1080x1920 MP4 + Auto-Save Drive 1zDqtrdpLBxC7q_2kB42tZ9f9_eyABz5K...`;
  }
  if (window.lucide) lucide.createIcons();

  state.currentTime = 0;
  if (!state.isPlaying) togglePlayPause();

  const videoStream = dom.canvas.captureStream(60);
  const combinedStream = new MediaStream();

  videoStream.getVideoTracks().forEach(track => combinedStream.addTrack(track));

  if (streamDestNode && streamDestNode.stream) {
    streamDestNode.stream.getAudioTracks().forEach(track => combinedStream.addTrack(track));
  }

  const recordedChunks = [];
  let options = { mimeType: 'video/webm;codecs=vp9,opus' };
  if (!MediaRecorder.isTypeSupported(options.mimeType)) {
    options = { mimeType: 'video/webm' };
  }

  const mediaRecorder = new MediaRecorder(combinedStream, options);
  mediaRecorder.ondataavailable = (event) => {
    if (event.data.size > 0) recordedChunks.push(event.data);
  };

  mediaRecorder.onstop = async () => {
    state.isRecording = false;
    if (state.isPlaying) togglePlayPause();

    const blob = new Blob(recordedChunks, { type: options.mimeType });
    const url = URL.createObjectURL(blob);

    const a = document.createElement('a');
    a.href = url;
    a.download = `FormulaPaddock_F1Reel_Drive_${Date.now()}.webm`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);

    try {
      const driveRes = await fetch('/api/save-drive', {
        method: 'POST',
        headers: { 'Content-Type': 'video/webm' },
        body: blob
      });
      const driveData = await driveRes.json();
      
      if (driveData.status === 'SUCCESS') {
        showToast(`☁️ Reel salvato automaticamente su Google Drive (Cartella ${driveData.drive_folder_id})!`);
        if (dom.recStatusText) {
          dom.recStatusText.innerHTML = `<i data-lucide="check-circle-2"></i> Salvato su Google Drive (1zDqtrdpLBxC7q_2kB42tZ9f9_eyABz5K)!`;
        }
      }
    } catch (e) {
      console.warn('Drive save info:', e);
    }

    if (window.lucide) lucide.createIcons();
  };

  mediaRecorder.start();

  setTimeout(() => {
    if (mediaRecorder.state !== 'inactive') mediaRecorder.stop();
  }, 15000);
}

function bindModals() {
  if (dom.btnSendPhone) {
    dom.btnSendPhone.addEventListener('click', () => {
      dom.modalPhoneFallback.classList.remove('hidden');
    });
  }
  if (dom.btnCloseQr) {
    dom.btnCloseQr.addEventListener('click', () => {
      dom.modalPhoneFallback.classList.add('hidden');
    });
  }
}

function showToast(msg) {
  if (!dom.toastContainer) return;
  const t = document.createElement('div');
  t.className = 'toast';
  t.innerHTML = `<i data-lucide="sparkles"></i> <span>${msg}</span>`;
  dom.toastContainer.appendChild(t);
  if (window.lucide) lucide.createIcons();
  setTimeout(() => t.remove(), 3500);
}

document.addEventListener('DOMContentLoaded', initApp);
