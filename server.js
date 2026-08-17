const express = require('express');
const fetch = require('node-fetch');
const cheerio = require('cheerio');
const cors = require('cors');
const path = require('path');
const fs = require('fs');
const { execFile, execSync } = require('child_process');
const https = require('https');
const http = require('http');
const { URL } = require('url');

// ─── Trova percorso ffmpeg ───
function findFFmpeg() {
  if (process.platform === 'win32') {
    const wingetPath = 'C:\\Users\\formu\\AppData\\Local\\Microsoft\\WinGet\\Packages\\Gyan.FFmpeg_Microsoft.Winget.Source_8wekyb3d8bbwe\\ffmpeg-9.0-full_build\\bin\\ffmpeg.exe';
    if (fs.existsSync(wingetPath)) return wingetPath;
    try {
      const result = execSync('cmd /c where ffmpeg', { encoding: 'utf8', timeout: 3000 }).trim().split('\n')[0].trim();
      if (result && fs.existsSync(result)) return result;
    } catch {}
  }
  return 'ffmpeg';
}

const FFMPEG_BIN = findFFmpeg();
console.log('FFmpeg binary:', FFMPEG_BIN);

const app = express();
const PORT = process.env.PORT || 3000;

app.use(cors());
app.use(express.json({ limit: '50mb' }));
app.use(express.urlencoded({ extended: true, limit: '50mb' }));
app.use(express.static('public'));

app.get('/health', (req, res) => {
  res.json({ status: 'ok', service: 'FormulaPaddock F1 Reel Engine 2.0', uptime: process.uptime() });
});

const MUSIC_DIR = path.join(__dirname, 'music');
const OUTPUT_DIR = path.join(__dirname, 'output');
const TEMP_DIR = path.join(__dirname, 'temp');
[MUSIC_DIR, OUTPUT_DIR, TEMP_DIR].forEach(d => {
  if (!fs.existsSync(d)) fs.mkdirSync(d, { recursive: true });
});

// ─── Download file ───
function downloadFile(url, dest) {
  return new Promise((resolve, reject) => {
    const parsedUrl = new URL(url);
    const proto = parsedUrl.protocol === 'https:' ? https : http;
    const file = fs.createWriteStream(dest);
    const request = proto.get(url, {
      headers: {
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36',
        'Accept': 'image/webp,image/apng,image/*,*/*;q=0.8',
        'Referer': parsedUrl.origin
      },
      timeout: 15000
    }, (response) => {
      if (response.statusCode === 301 || response.statusCode === 302) {
        file.close();
        fs.unlink(dest, () => {});
        return downloadFile(response.headers.location, dest).then(resolve).catch(reject);
      }
      if (response.statusCode !== 200) {
        file.close();
        fs.unlink(dest, () => {});
        return reject(new Error('HTTP ' + response.statusCode));
      }
      response.pipe(file);
      file.on('finish', () => file.close(resolve));
    });
    request.on('error', err => { fs.unlink(dest, () => {}); reject(err); });
    request.on('timeout', () => { request.destroy(); fs.unlink(dest, () => {}); reject(new Error('Timeout')); });
  });
}

// ─── WordPress API & Scraping ───
async function scrapeUrl(url) {
  const response = await fetch(url, {
    headers: { 'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36' },
    timeout: 15000
  });
  if (!response.ok) throw new Error('Impossibile raggiungere l\'URL: HTTP ' + response.status);
  const html = await response.text();
  const $ = cheerio.load(html);

  let title =
    $('meta[property="og:title"]').attr('content') ||
    $('meta[name="twitter:title"]').attr('content') ||
    $('title').text() ||
    $('h1').first().text() ||
    'FormulaPaddock';
  title = title.replace(/\s+/g, ' ').trim().substring(0, 80);

  let description =
    $('meta[property="og:description"]').attr('content') ||
    $('meta[name="description"]').attr('content') ||
    $('meta[name="twitter:description"]').attr('content') ||
    '';
  description = description.replace(/\s+/g, ' ').trim().substring(0, 120);

  const imageUrls = new Set();
  const baseUrl = new URL(url);

  const resolveUrl = (src) => {
    if (!src) return null;
    try {
      if (src.startsWith('//')) return baseUrl.protocol + src;
      if (src.startsWith('/')) return baseUrl.protocol + '//' + baseUrl.host + src;
      if (src.startsWith('http')) return src;
      return null;
    } catch { return null; }
  };

  ['og:image', 'twitter:image', 'og:image:secure_url'].forEach(prop => {
    const val = $('meta[property="' + prop + '"]').attr('content') ||
                $('meta[name="' + prop + '"]').attr('content');
    const r = resolveUrl(val);
    if (r) imageUrls.add(r);
  });

  $('img').each((_, el) => {
    const src = $(el).attr('src') || $(el).attr('data-src') || $(el).attr('data-lazy-src');
    const width = parseInt($(el).attr('width') || '0');
    const height = parseInt($(el).attr('height') || '0');
    if (width && width < 200) return;
    if (height && height < 150) return;
    const r = resolveUrl(src);
    if (r && !r.includes('logo') && !r.includes('icon') && !r.includes('avatar') && !r.includes('emoji')) {
      imageUrls.add(r);
    }
  });

  return { title, description, images: [...imageUrls].slice(0, 8) };
}

// ─── Google Drive & WordPress Library Integration ───
const GOOGLE_DRIVE_UPLOADS = 'G:\\Il mio Drive\\uploads';

const F1_SYNONYMS = {
  'abu dhabi': ['abu dhabi', 'yas marina'],
  'australia': ['australia', 'melbourne', 'albert park'],
  'austria': ['austria', 'spielberg', 'red bull ring'],
  'brasile': ['brasile', 'brazil', 'interlagos', 'sao paolo', 'san paolo'],
  'canada': ['canada', 'montreal', 'gilles villeneuve'],
  'cina': ['cina', 'china', 'shanghai'],
  'giappone': ['giappone', 'japan', 'suzuka'],
  'jeddah': ['jeddah', 'arabia', 'saudi'],
  'las vegas': ['las vegas', 'vegas'],
  'mclaren': ['mclaren', 'norris', 'piastri', 'papaya', 'woking'],
  'messico': ['messico', 'mexico', 'hermanos rodriguez'],
  'miami': ['miami'],
  'monaco': ['monaco', 'monte carlo', 'montecarlo'],
  'pirelli': ['pirelli', 'gomme', 'tyres', 'pneumatici'],
  'qatar': ['qatar', 'losail', 'lusail'],
  'silverstone': ['silverstone', 'gran bretagna', 'british', 'inghilterra', 'enstone'],
  'spa': ['spa', 'francorchamps', 'belgio', 'belgian'],
  'spagna': ['spagna', 'spain', 'barcellona', 'barcelona', 'catalunya'],
  'ungheria': ['ungheria', 'hungary', 'hungaroring', 'budapest'],
  'usa': ['usa', 'austin', 'cota', 'texas', 'stati uniti'],
  'test': ['test', 'sakhir', 'pre-season']
};

function findMatchingDriveFolder(searchTerms) {
  if (!fs.existsSync(GOOGLE_DRIVE_UPLOADS)) return null;
  try {
    const folders = fs.readdirSync(GOOGLE_DRIVE_UPLOADS, { withFileTypes: true })
      .filter(d => d.isDirectory())
      .map(d => d.name);

    const text = (Array.isArray(searchTerms) ? searchTerms.join(' ') : String(searchTerms)).toLowerCase();
    let bestFolder = null;
    let maxScore = 0;

    for (const f of folders) {
      const fLower = f.toLowerCase();
      let score = 0;

      // Token match diretto
      const fTokens = fLower.split(/[\s-_]+/);
      for (const t of fTokens) {
        if (['test', '2025', '2026', '25', '26'].includes(t)) continue;
        if (t.length > 3 && text.includes(t)) {
          score += 10;
        }
      }

      // Match tramite sinonimi F1
      for (const [key, syns] of Object.entries(F1_SYNONYMS)) {
        if (fLower.includes(key)) {
          for (const s of syns) {
            if (text.includes(s)) score += 15;
          }
        }
      }

      if (score > maxScore) {
        maxScore = score;
        bestFolder = path.join(GOOGLE_DRIVE_UPLOADS, f);
      }
    }
    return bestFolder;
  } catch (e) {
    console.warn('Google Drive folder search error:', e.message);
    return null;
  }
}

function getImagesFromDriveFolder(folderPath) {
  if (!folderPath || !fs.existsSync(folderPath)) return [];
  try {
    const files = fs.readdirSync(folderPath)
      .filter(f => /\.(jpe?g|png|webp)$/i.test(f))
      .map(f => path.join(folderPath, f));
    return files;
  } catch (e) {
    return [];
  }
}

// ─── Estrazione Intelligente FormulaPaddock (Google Drive + Categorie WP) ───
async function getFormulaPaddockMedia(articleUrl) {
  let urlObj;
  try {
    urlObj = new URL(articleUrl);
  } catch (e) {
    throw new Error('URL non valido');
  }

  // Estrai slug dal pathname
  const segments = urlObj.pathname.split('/').filter(Boolean);
  const slug = segments[segments.length - 1] || '';

  let post = null;
  let title = '';
  let description = '';
  let mainFeaturedImage = null;
  let categoryIds = [];
  let categoryName = 'FormulaPaddock';

  // 1. Cerca il post specifico tramite WP REST API
  if (slug) {
    try {
      const apiUrl = `https://www.formulapaddock.it/wp-json/wp/v2/posts?slug=${encodeURIComponent(slug)}&_embed`;
      const res = await fetch(apiUrl, {
        headers: { 'User-Agent': 'Mozilla/5.0' },
        timeout: 10000
      });
      if (res.ok) {
        const posts = await res.json();
        if (Array.isArray(posts) && posts.length > 0) {
          post = posts[0];
        }
      }
    } catch (err) {
      console.warn('WP REST API slug search warning:', err.message);
    }
  }

  // Se trovato tramite API WP
  if (post) {
    title = cheerio.load(post.title?.rendered || '').text().trim();
    const rawExcerpt = post.excerpt?.rendered || post.content?.rendered || '';
    description = cheerio.load(rawExcerpt).text().replace(/\s+/g, ' ').trim().substring(0, 120);

    if (post._embedded && post._embedded['wp:featuredmedia'] && post._embedded['wp:featuredmedia'][0]) {
      mainFeaturedImage = post._embedded['wp:featuredmedia'][0].source_url;
    }

    categoryIds = post.categories || [];
    if (post._embedded && post._embedded['wp:term'] && post._embedded['wp:term'][0]) {
      const catObj = post._embedded['wp:term'][0].find(t => t.taxonomy === 'category');
      if (catObj) categoryName = catObj.name;
    }
  }

  // Fallback scraping se necessario
  if (!mainFeaturedImage) {
    const scraped = await scrapeUrl(articleUrl);
    title = title || scraped.title;
    description = description || scraped.description;
    if (scraped.images.length > 0) {
      mainFeaturedImage = scraped.images[0];
    }
  }

  // 2. Prova prima a pescare immagini pertinenti da Google Drive (G:\Il mio Drive\uploads)
  const searchKeywords = [title, slug, categoryName, description, post?.content?.rendered || ''];
  const matchedDriveFolder = findMatchingDriveFolder(searchKeywords);
  let driveImages = [];
  if (matchedDriveFolder) {
    driveImages = getImagesFromDriveFolder(matchedDriveFolder);
    console.log(`[Drive] Trovata cartella: ${path.basename(matchedDriveFolder)} (${driveImages.length} immagini)`);
  }

  const categoryImagePool = new Set();

  if (driveImages.length >= 2) {
    driveImages.forEach(img => categoryImagePool.add(img));
  } else {
    // Fallback: cerca negli articoli WP della stessa categoria
    if (categoryIds.length > 0) {
      try {
        const catApiUrl = `https://www.formulapaddock.it/wp-json/wp/v2/posts?categories=${categoryIds.join(',')}&per_page=20&_embed`;
        const catRes = await fetch(catApiUrl, { headers: { 'User-Agent': 'Mozilla/5.0' }, timeout: 10000 });
        if (catRes.ok) {
          const catPosts = await catRes.json();
          if (Array.isArray(catPosts)) {
            catPosts.forEach(p => {
              if (p._embedded && p._embedded['wp:featuredmedia'] && p._embedded['wp:featuredmedia'][0]) {
                const src = p._embedded['wp:featuredmedia'][0].source_url;
                if (src && src !== mainFeaturedImage) categoryImagePool.add(src);
              }
            });
          }
        }
      } catch (err) {
        console.warn('WP Category query warning:', err.message);
      }
    }

    // Se ancora poche immagini, cerca da tutte le cartelle Google Drive a caso
    if (categoryImagePool.size < 2 && fs.existsSync(GOOGLE_DRIVE_UPLOADS)) {
      try {
        const allFolders = fs.readdirSync(GOOGLE_DRIVE_UPLOADS, { withFileTypes: true })
          .filter(d => d.isDirectory())
          .map(d => path.join(GOOGLE_DRIVE_UPLOADS, d.name));
        for (const f of allFolders) {
          const imgs = getImagesFromDriveFolder(f);
          imgs.forEach(i => categoryImagePool.add(i));
          if (categoryImagePool.size >= 10) break;
        }
      } catch (e) {}
    }
  }

  // Mescola e prendi 2 immagini casuali
  const poolArray = [...categoryImagePool].sort(() => Math.random() - 0.5);
  const selectedExtra = poolArray.slice(0, 2);

  const finalImages = [mainFeaturedImage, ...selectedExtra].filter(Boolean);

  return {
    title: title || 'FormulaPaddock',
    description,
    categoryName: matchedDriveFolder ? path.basename(matchedDriveFolder) : categoryName,
    images: finalImages
  };
}

// ─── Musica casuale ───
function getRandomMusic() {
  if (!fs.existsSync(MUSIC_DIR)) return null;
  const files = fs.readdirSync(MUSIC_DIR).filter(f => /\.(mp3|m4a|wav|aac|ogg|flac)$/i.test(f));
  if (files.length === 0) return null;
  return path.join(MUSIC_DIR, files[Math.floor(Math.random() * files.length)]);
}

// ─── Logo ───
const LOGO_URL = 'https://www.formulapaddock.it/wp-content/uploads/2026/05/preview.webp';
const LOGO_PATH = path.join(__dirname, 'temp', 'logo_fp.webp');

async function ensureLogo() {
  if (!fs.existsSync(LOGO_PATH) || fs.statSync(LOGO_PATH).size < 100) {
    try { await downloadFile(LOGO_URL, LOGO_PATH); } catch (e) {
      console.warn('Logo non scaricabile:', e.message);
      return null;
    }
  }
  return LOGO_PATH;
}

// ─── Sanitize testo per FFmpeg filtergraph ───
// Rimuove tutti i caratteri che possono rompere il parsing del filtergraph
function sanitizeFFmpegText(str, maxLen) {
  return str
    .replace(/[\u0080-\uFFFF]/g, '')           // rimuovi non-ASCII
    .replace(/[&:'"\\<>=,;[\]{}()|@#!?%*+]/g, ' ')  // char speciali FFmpeg
    .replace(/\s+/g, ' ')
    .trim()
    .substring(0, maxLen);
}

// ─── Build FFmpeg command ───
function buildFFmpegCommand(imagePaths, logoPath, musicPath, outputPath, title, description) {
  const W = 1080;
  const H = 1920;
  const DURATION = 6;
  const FADE_DUR = 0.5;
  const numImages = imagePaths.length;

  const clipDur = (DURATION + FADE_DUR * (numImages - 1)) / numImages;
  const clipDurStr = clipDur.toFixed(3);

  const args = ['-y'];

  // Inputs: immagini
  imagePaths.forEach(p => args.push('-loop', '1', '-t', clipDurStr, '-i', p));

  // Input logo
  const logoIdx = numImages;
  const hasLogo = logoPath && fs.existsSync(logoPath);
  if (hasLogo) args.push('-loop', '1', '-t', String(DURATION), '-i', logoPath);

  // Input audio
  const audioIdx = hasLogo ? logoIdx + 1 : logoIdx;
  const hasAudio = musicPath && fs.existsSync(musicPath);
  if (hasAudio) args.push('-i', musicPath);

  // Font: copia locale nella dir del progetto (drive F: -> usa F\:/ per evitare il bug del colon nel filtergraph)
  const fontFile = __dirname.replace(/\\/g, '/').replace(/^([A-Z]):/, '$1\\:') + '/arialbd.ttf';

  // Testi sicuri
  const safeTitle = sanitizeFFmpegText(title, 55);
  const safeDesc  = sanitizeFFmpegText(description, 60);

  // Split titolo in due righe
  let t1 = safeTitle, t2 = '';
  if (safeTitle.length > 28) {
    const m = safeTitle.lastIndexOf(' ', 28);
    if (m > 10) { t1 = safeTitle.substring(0, m); t2 = safeTitle.substring(m + 1); }
  }

  const F = []; // filtergraph parts

  // 1. Scale + Ken Burns CENTRATO per ogni immagine
  imagePaths.forEach((_, i) => {
    const frames = Math.round(clipDur * 25);
    const oversizeW = Math.round(W * 1.12);
    const oversizeH = Math.round(H * 1.12);
    // Pan sottile attorno al centro (in_w-out_w)/2 per evitare decentramento
    const panSign = i % 2 === 0 ? '+' : '-';
    F.push(
      `[${i}:v]` +
      `scale=${oversizeW}:${oversizeH}:force_original_aspect_ratio=increase,` +
      `crop=w=${W}:h=${H}:x='(in_w-out_w)/2${panSign}((n/${frames}-0.5)*40)':y='(in_h-out_h)/2${panSign}((n/${frames}-0.5)*40)',` +
      `fps=25,setsar=1[kb${i}]`
    );
  });

  // 2. Crossfade tra le clip + Fade-in iniziale
  if (numImages === 1) {
    F.push(`[kb0]fade=t=in:st=0:d=0.4[vmerged]`);
  } else {
    const off0 = (clipDur - FADE_DUR).toFixed(3);
    F.push(`[kb0][kb1]xfade=transition=fade:duration=${FADE_DUR}:offset=${off0}[xf0]`);
    for (let i = 2; i < numImages; i++) {
      const off = (clipDur * i - FADE_DUR * (i - 1) - FADE_DUR).toFixed(3);
      F.push(`[xf${i - 2}][kb${i}]xfade=transition=fade:duration=${FADE_DUR}:offset=${off}[xf${i - 1}]`);
    }
    F.push(`[xf${numImages - 2}]trim=0:${DURATION},setpts=PTS-STARTPTS,fade=t=in:st=0:d=0.4[vmerged]`);
  }

  // 3. INTRO BADGE in alto (attivo nei primi 4.6s)
  F.push(
    `[vmerged]drawbox=x=60:y=70:w=370:h=56:color=0xE8002D@0.95:t=fill:enable='between(t,0.2,4.6)'[vbadge_bg]`
  );
  F.push(
    `[vbadge_bg]drawtext=fontfile='${fontFile}':text='FORMULA PADDOCK':` +
    `fontsize=24:fontcolor=white:x=85:y=86:shadowcolor=black@0.6:shadowx=1:shadowy=1:enable='between(t,0.2,4.6)'[vbadge]`
  );

  // 4. Box scuro testo in basso (attivo da t=0 a t=4.6)
  F.push(`[vbadge]drawbox=x=0:y=1360:w=${W}:h=560:color=black@0.72:t=fill:enable='between(t,0,4.6)'[vbg]`);

  // 5. Linea decorativa rossa
  F.push(`[vbg]drawbox=x=50:y=1380:w=8:h=440:color=0xE8002D@1.0:t=fill:enable='between(t,0,4.6)'[vline]`);

  // 6. Titolo riga 1
  F.push(
    `[vline]drawtext=fontfile='${fontFile}':text='${t1}':` +
    `fontsize=52:fontcolor=white:x=80:y=1420:` +
    `shadowcolor=black@0.8:shadowx=2:shadowy=2:enable='between(t,0,4.6)'[vt1]`
  );

  // 6b. Titolo riga 2
  if (t2) {
    F.push(
      `[vt1]drawtext=fontfile='${fontFile}':text='${t2}':` +
      `fontsize=52:fontcolor=white:x=80:y=1480:` +
      `shadowcolor=black@0.8:shadowx=2:shadowy=2:enable='between(t,0,4.6)'[vt2]`
    );
  } else {
    F.push(`[vt1]copy[vt2]`);
  }

  // 6c. Descrizione
  if (safeDesc) {
    F.push(
      `[vt2]drawtext=fontfile='${fontFile}':text='${safeDesc}':` +
      `fontsize=32:fontcolor=0xC8A45A:x=80:y=1550:` +
      `shadowcolor=black@0.6:shadowx=1:shadowy=1:enable='between(t,0,4.6)'[vt3]`
    );
  } else {
    F.push(`[vt2]copy[vt3]`);
  }

  // 6d. Brand in basso
  F.push(
    `[vt3]drawtext=fontfile='${fontFile}':text='formulapaddock.it':` +
    `fontsize=28:fontcolor=0xE8002D@0.9:x=80:y=1620:enable='between(t,0,4.6)'[vmain_content]`
  );

  // 7. END / OUTRO (da t=4.6 a t=6.0): sfondo scuro + Logo centrato + call to action
  let finalV = 'vmain_content';
  if (hasLogo) {
    const lW = 460;
    const lX = Math.round((W - lW) / 2);
    const lY = Math.round((H - lW) / 2) - 120;

    // Sfondo oscurato elegante per outro
    F.push(`[vmain_content]drawbox=x=0:y=0:w=${W}:h=${H}:color=black@0.88:t=fill:enable='between(t,4.6,6.0)'[voutro_bg]`);

    // Logo animato in entrata/uscita al centro
    F.push(`[${logoIdx}:v]scale=${lW}:-1,fade=t=in:st=4.6:d=0.4,fade=t=out:st=5.8:d=0.2[logo_outro]`);
    F.push(`[voutro_bg][logo_outro]overlay=x=${lX}:y=${lY}:enable='between(t,4.6,6.0)'[voutro_logo]`);

    // Testo brand grande centrato sotto il logo
    F.push(
      `[voutro_logo]drawtext=fontfile='${fontFile}':text='FORMULAPADDOCK.IT':` +
      `fontsize=44:fontcolor=white:x=(w-text_w)/2:y=1120:` +
      `shadowcolor=black@0.8:shadowx=2:shadowy=2:enable='between(t,4.7,6.0)'[voutro_text1]`
    );

    // Tagline oro centrata
    F.push(
      `[voutro_text1]drawtext=fontfile='${fontFile}':text='SEGUICI PER TUTTE LE NOVITA':` +
      `fontsize=28:fontcolor=0xC8A45A:x=(w-text_w)/2:y=1185:` +
      `shadowcolor=black@0.6:shadowx=1:shadowy=1:enable='between(t,4.8,6.0)'[vfinal]`
    );
    finalV = 'vfinal';
  }

  args.push('-filter_complex', F.join(';'));
  args.push('-map', '[' + finalV + ']');

  if (hasAudio) {
    args.push('-map', audioIdx + ':a');
    args.push('-af', 'atrim=0:' + DURATION + ',afade=t=out:st=' + (DURATION-1) + ':d=1,asetpts=PTS-STARTPTS');
    args.push('-shortest');
  }

  args.push('-c:v', 'libx264', '-preset', 'fast', '-crf', '20', '-pix_fmt', 'yuv420p', '-r', '25', '-t', String(DURATION));
  if (hasAudio) args.push('-c:a', 'aac', '-b:a', '192k');
  else args.push('-an');
  args.push('-movflags', '+faststart');
  args.push(outputPath);

  return args;
}

// ─── POST /generate ───
app.post('/generate', async (req, res) => {
  const { url } = req.body;
  if (!url || !url.startsWith('http')) {
    return res.status(400).json({ error: 'URL non valido. Deve iniziare con http/https.' });
  }

  const sessionId = Date.now().toString();
  const sessionTemp = path.join(TEMP_DIR, sessionId);
  fs.mkdirSync(sessionTemp, { recursive: true });

  const log = (msg) => {
    console.log('[' + sessionId + '] ' + msg);
  };

  try {
    log('Estrazione articolo e media da FormulaPaddock...');
    const mediaData = await getFormulaPaddockMedia(url);
    log('Titolo: ' + mediaData.title);
    log('Categoria WP: ' + mediaData.categoryName);
    log('Immagini selezionate: ' + mediaData.images.length);

    const downloadedImages = [];
    for (let i = 0; i < mediaData.images.length; i++) {
      const imgUrl = mediaData.images[i];
      const ext = (imgUrl.split('.').pop().split('?')[0] || '').toLowerCase();
      const validExts = ['jpg', 'jpeg', 'png', 'webp'];
      const imgExt = validExts.includes(ext) ? ext : 'jpg';
      const imgPath = path.join(sessionTemp, 'img_' + i + '.' + imgExt);
      try {
        if (fs.existsSync(imgUrl)) {
          // File locale da Google Drive
          fs.copyFileSync(imgUrl, imgPath);
        } else {
          // URL web
          await downloadFile(imgUrl, imgPath);
        }
        const stat = fs.statSync(imgPath);
        if (stat.size > 5000) {
          downloadedImages.push(imgPath);
          log('OK img ' + (i+1) + ' (' + Math.round(stat.size/1024) + 'KB)');
        }
      } catch (e) {
        log('Skip img ' + i + ': ' + e.message);
      }
      if (downloadedImages.length >= 3) break;
    }

    if (downloadedImages.length === 0) {
      throw new Error('Nessuna immagine valida trovata per questo articolo. Prova con un altro link.');
    }

    log('Download logo...');
    const logoPath = await ensureLogo();
    const musicPath = getRandomMusic();
    if (musicPath) log('Musica: ' + path.basename(musicPath));
    else log('Nessuna musica in music/');

    const outputFilename = 'reel_' + sessionId + '.mp4';
    const outputPath = path.join(OUTPUT_DIR, outputFilename);

    const ffmpegArgs = buildFFmpegCommand(downloadedImages, logoPath, musicPath, outputPath, mediaData.title, mediaData.description);

    log('Avvio FFmpeg...');

    await new Promise((resolve, reject) => {
      execFile(FFMPEG_BIN, ffmpegArgs, { timeout: 120000 }, (error, stdout, stderr) => {
        if (error) {
          log('FFmpeg stderr: ' + stderr.substring(0, 500));
          reject(new Error('FFmpeg error: ' + error.message));
        } else {
          resolve();
        }
      });
    });

    if (!fs.existsSync(outputPath) || fs.statSync(outputPath).size < 1000) {
      throw new Error('FFmpeg non ha prodotto un file valido.');
    }

    log('Reel generato: ' + outputFilename);

    setTimeout(() => {
      try { fs.rmSync(sessionTemp, { recursive: true, force: true }); } catch {}
    }, 5 * 60 * 1000);

    res.json({
      success: true,
      filename: outputFilename,
      title: mediaData.title,
      description: mediaData.description,
      category: mediaData.categoryName,
      imagesUsed: downloadedImages.length,
      hasAudio: !!musicPath
    });

  } catch (err) {
    log('ERRORE: ' + err.message);
    try { fs.rmSync(sessionTemp, { recursive: true, force: true }); } catch {}
    res.status(500).json({ error: err.message });
  }
});

// ─── POST /api/render-reel (Compatibilità Cloud API per PHP Aruba) ───
app.post('/api/render-reel', async (req, res) => {
  const payload = req.body || {};
  const targetUrl = payload.article_url || payload.url;
  const rawText = payload.text || payload.title || 'Formula Paddock F1 News';

  const sessionId = Date.now().toString() + '_' + Math.random().toString(36).substring(2, 6);
  const sessionTemp = path.join(TEMP_DIR, sessionId);
  fs.mkdirSync(sessionTemp, { recursive: true });

  try {
    let mediaData;
    if (targetUrl && targetUrl.startsWith('http')) {
      mediaData = await getFormulaPaddockMedia(targetUrl);
    } else {
      mediaData = {
        title: rawText,
        description: payload.description || 'Notizie e aggiornamenti Formula 1',
        categoryName: payload.category || 'Formula 1',
        images: payload.image_url ? [payload.image_url] : []
      };
    }

    const downloadedImages = [];
    for (let i = 0; i < mediaData.images.length; i++) {
      const imgUrl = mediaData.images[i];
      const imgPath = path.join(sessionTemp, 'img_' + i + '.jpg');
      try {
        if (fs.existsSync(imgUrl)) {
          fs.copyFileSync(imgUrl, imgPath);
        } else if (imgUrl.startsWith('http')) {
          await downloadFile(imgUrl, imgPath);
        }
        if (fs.existsSync(imgPath) && fs.statSync(imgPath).size > 3000) {
          downloadedImages.push(imgPath);
        }
      } catch (e) {}
      if (downloadedImages.length >= 3) break;
    }

    if (downloadedImages.length === 0) {
      downloadedImages.push(await ensureLogo());
    }

    const logoPath = await ensureLogo();
    const musicPath = getRandomMusic();
    const outputFilename = 'reel_' + sessionId + '.mp4';
    const outputPath = path.join(OUTPUT_DIR, outputFilename);

    const ffmpegArgs = buildFFmpegCommand(
      downloadedImages,
      logoPath,
      musicPath,
      outputPath,
      mediaData.title,
      mediaData.description
    );

    await new Promise((resolve, reject) => {
      execFile(FFMPEG_BIN, ffmpegArgs, { timeout: 120000 }, (error, stdout, stderr) => {
        if (error) reject(new Error('FFmpeg error: ' + error.message));
        else resolve();
      });
    });

    if (!fs.existsSync(outputPath) || fs.statSync(outputPath).size < 1000) {
      throw new Error('FFmpeg generation failed.');
    }

    setTimeout(() => {
      try { fs.rmSync(sessionTemp, { recursive: true, force: true }); } catch {}
    }, 5 * 60 * 1000);

    // Se la richiesta si aspetta JSON
    if (req.headers.accept && req.headers.accept.includes('application/json')) {
      res.json({
        success: true,
        filename: outputFilename,
        title: mediaData.title,
        description: mediaData.description,
        category: mediaData.categoryName
      });
    } else {
      // Invia direttamente il file MP4 binario a PHP
      res.setHeader('Content-Type', 'video/mp4');
      res.setHeader('Content-Disposition', 'inline; filename="' + outputFilename + '"');
      fs.createReadStream(outputPath).pipe(res);
    }

  } catch (err) {
    try { fs.rmSync(sessionTemp, { recursive: true, force: true }); } catch {}
    res.status(500).json({ error: err.message });
  }
});

// ─── GET /download/:filename ───
app.get('/download/:filename', (req, res) => {
  const filename = path.basename(req.params.filename);
  const filePath = path.join(OUTPUT_DIR, filename);
  if (!fs.existsSync(filePath)) return res.status(404).json({ error: 'File non trovato' });
  res.download(filePath, filename);
});

// ─── GET /video/:filename ── stream con range support ───
app.get('/video/:filename', (req, res) => {
  const filename = path.basename(req.params.filename);
  const filePath = path.join(OUTPUT_DIR, filename);
  if (!fs.existsSync(filePath)) return res.status(404).json({ error: 'File non trovato' });

  const stat = fs.statSync(filePath);
  const fileSize = stat.size;
  const range = req.headers.range;

  if (range) {
    const parts = range.replace(/bytes=/, '').split('-');
    const start = parseInt(parts[0], 10);
    const end = parts[1] ? parseInt(parts[1], 10) : fileSize - 1;
    const chunkSize = end - start + 1;
    res.writeHead(206, {
      'Content-Range': 'bytes ' + start + '-' + end + '/' + fileSize,
      'Accept-Ranges': 'bytes',
      'Content-Length': chunkSize,
      'Content-Type': 'video/mp4'
    });
    fs.createReadStream(filePath, { start, end }).pipe(res);
  } else {
    res.writeHead(200, { 'Content-Length': fileSize, 'Content-Type': 'video/mp4' });
    fs.createReadStream(filePath).pipe(res);
  }
});

// ─── Start ───
app.listen(PORT, () => {
  console.log('');
  console.log('╔══════════════════════════════════════════╗');
  console.log('║  🏎  F1 REEL GENERATOR - FormulaPaddock  ║');
  console.log('╚══════════════════════════════════════════╝');
  console.log('');
  console.log('  Server: http://localhost:' + PORT);
  console.log('  Musica: ' + MUSIC_DIR);
  console.log('  Output: ' + OUTPUT_DIR);
  console.log('');
});
