<?php
/**
 * reel.php - Generatore Reel F1 Verticali 9:16 FormulaPaddock
 * Versione 9.0: Layout Side-by-Side ("Affianca i Box") + Database WP Diretto ($wpdb) + AI Copywriting F1
 */

$targetUrl = trim($_GET['url'] ?? ($_POST['url'] ?? ''));

$wpConfigPath = '/web/htdocs/www.formulapaddock.it/home/wp-config.php';
if (file_exists($wpConfigPath)) {
    @require_once $wpConfigPath;
}

$seoConfig = file_exists(__DIR__ . '/../config.php') ? require __DIR__ . '/../config.php' : (file_exists(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : []);

if (!empty($targetUrl)) {
    header('Content-Type: application/json; charset=utf-8');
    $path = trim((string)parse_url($targetUrl, PHP_URL_PATH), '/');
    $segments = array_values(array_filter(explode('/', $path)));
    $slug = end($segments) ?: '';

    $title = 'Formula Paddock News';
    $description = 'Ultime notizie e aggiornamenti Formula 1';
    $mainImage = '';
    $categoryName = 'Formula 1';
    $categoryImages = [];

    // 1. Lettura diretta dal Database WordPress con $wpdb
    global $wpdb;
    if ($wpdb && !empty($slug)) {
        $post = $wpdb->get_row($wpdb->prepare("SELECT ID, post_title, post_excerpt, post_content FROM {$wpdb->posts} WHERE post_name = %s AND post_status = 'publish' LIMIT 1", $slug));

        if ($post) {
            $title = html_entity_decode((string)$post->post_title, ENT_QUOTES, 'UTF-8');
            $rawText = !empty($post->post_excerpt) ? $post->post_excerpt : strip_tags((string)$post->post_content);
            $description = mb_substr(preg_replace('/\s+/', ' ', trim($rawText)), 0, 150);

            // Immagine in evidenza WebP full-res
            $thumbId = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_thumbnail_id'", (int)$post->ID));
            if ($thumbId) {
                $imgUrl = $wpdb->get_var($wpdb->prepare("SELECT guid FROM {$wpdb->posts} WHERE ID = %d", (int)$thumbId));
                if ($imgUrl) $mainImage = $imgUrl;
            }

            // Categoria post
            $catName = $wpdb->get_var($wpdb->prepare("
                SELECT t.name FROM {$wpdb->terms} t
                INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
                INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
                WHERE tr.object_id = %d AND tt.taxonomy = 'category' LIMIT 1
            ", (int)$post->ID));
            if ($catName) $categoryName = $catName;
        }
    }

    // 2. Cerca foto correlate in /seo/uploads/
    $uploadsDir = __DIR__ . '/../uploads';
    if (is_dir($uploadsDir)) {
        $folders = array_filter(scandir($uploadsDir), fn($f) => $f !== '.' && $f !== '..' && is_dir($uploadsDir . '/' . $f));
        $text = mb_strtolower($title . ' ' . $description . ' ' . $slug);
        
        foreach ($folders as $folder) {
            $fLower = mb_strtolower($folder);
            $tokens = preg_split('/[\s\-_]+/', $fLower);
            foreach ($tokens as $t) {
                if (mb_strlen($t) > 3 && !in_array($t, ['2025', '2026', 'test'], true) && strpos($text, $t) !== false) {
                    $gpFiles = scandir($uploadsDir . '/' . $folder);
                    foreach ($gpFiles as $gf) {
                        if (preg_match('/\.(jpe?g|png|webp)$/i', $gf)) {
                            $categoryImages[] = '/seo/uploads/' . rawurlencode($folder) . '/' . rawurlencode($gf);
                        }
                    }
                    break 2;
                }
            }
        }
    }

    if (empty($mainImage)) {
        $mainImage = 'fonts/logo_fp.webp';
    }

    shuffle($categoryImages);
    $extraImages = array_slice($categoryImages, 0, 2);

    // 3. AI Copywriting con Gemini per 3 Frasi distinte (1 per ogni immagine)
    $geminiKey = $seoConfig['gemini_api_key'] ?? '';
    $boom1 = '🔴 ' . mb_strtoupper($categoryName !== 'Formula 1' ? $categoryName : 'CADILLAC F1') . ' — BREAKING NEWS';
    $boom2 = mb_strtoupper($title);
    $boom3 = 'SCOPRI TUTTI I RETROSCENA SU FORMULAPADDOCK.IT';

    if (!empty($geminiKey) && $title !== 'Formula Paddock News') {
        $prompt = "Sei un copywriter esperto di Formula 1 per Reel virali TikTok e Instagram.
Dato questo articolo di Formula 1:
TITOLO: \"{$title}\"
ESTRATTO: \"{$description}\"

Genera ESATTAMENTE un JSON con 3 frasi distinte, una per ciascuna delle 3 immagini che si susseguono nel video:
- \"boom1\": Testo per la 1ª IMMAGINE (Gancio con emoji, max 4-6 parole, es. \"🔴 CLAMOROSO CADILLAC F1: ARRIVA LA SVOLTA!\")
- \"boom2\": Testo per la 2ª IMMAGINE (Notizia chiave esplosiva, max 5-7 parole, es. \"MARCIN BUDKOWSKI È IL NUOVO TEAM PRINCIPAL!\")
- \"boom3\": Testo per la 3ª IMMAGINE (Dettaglio o Call-to-Action, max 5-7 parole, es. \"LEGGI TUTTI I RETROSCENA SU FORMULAPADDOCK.IT\")

Rispondi SOLO con il JSON senza markdown o altro testo.";

        try {
            $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=" . urlencode($geminiKey);
            $aiPayload = json_encode([
                "contents" => [["parts" => [["text" => $prompt]]]]
            ]);

            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $aiPayload);
            curl_setopt($ch, CURLOPT_TIMEOUT, 6);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $aiRes = curl_exec($ch);
            curl_close($ch);

            if ($aiRes) {
                $aiData = json_decode($aiRes, true);
                $aiText = $aiData['candidates'][0]['content']['parts'][0]['text'] ?? '';
                $clean = trim(preg_replace('/```json|```/', '', $aiText));
                $parsed = json_decode($clean, true);
                if (!empty($parsed['boom1'])) $boom1 = mb_strtoupper(trim($parsed['boom1']));
                if (!empty($parsed['boom2'])) $boom2 = mb_strtoupper(trim($parsed['boom2']));
                if (!empty($parsed['boom3'])) $boom3 = mb_strtoupper(trim($parsed['boom3']));
            }
        } catch (Throwable $e) {}
    }

    $musicFiles = [];
    $musicDir = __DIR__ . '/music';
    if (is_dir($musicDir)) {
        foreach (scandir($musicDir) as $mf) {
            if (preg_match('/\.(mp3|wav|ogg)$/i', $mf)) {
                $musicFiles[] = 'music/' . rawurlencode($mf);
            }
        }
    }

    $chosenAudio = !empty($musicFiles) ? $musicFiles[array_rand($musicFiles)] : '';

    $finalImages = array_values(array_filter([$mainImage, $extraImages[0] ?? null, $extraImages[1] ?? null]));
    while (count($finalImages) < 3) {
        $finalImages[] = $finalImages[0] ?? 'fonts/logo_fp.webp';
    }

    echo json_encode([
        'success' => true,
        'slug' => $slug,
        'title' => $title,
        'description' => $description,
        'category' => $categoryName,
        'boom1' => $boom1,
        'boom2' => $boom2,
        'boom3' => $boom3,
        'images' => $finalImages,
        'audio' => $chosenAudio,
        'logo' => 'fonts/logo_fp.webp'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$initialUrl = trim((string)($_GET['init_url'] ?? ''));
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Generatore Reel F1 9:16 — FormulaPaddock (Layout Affiancato)</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@800;900&family=Inter:wght@600;700;800;900&display=swap" rel="stylesheet">
  <style>
    :root {
      --bg: #070709;
      --card-bg: #111116;
      --card-border: #1e1e28;
      --red: #E8002D;
      --red-hover: #ff1a40;
      --gold: #FFD700;
      --text: #F3F4F6;
      --text-muted: #8E929E;
      --radius: 16px;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      background-color: var(--bg);
      color: var(--text);
      font-family: 'Inter', sans-serif;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      padding: 24px 16px;
    }
    .header { text-align: center; margin-bottom: 20px; width: 100%; max-width: 1080px; }
    .badge {
      display: inline-flex; align-items: center; gap: 6px;
      background: rgba(232,0,45,0.12); border: 1px solid rgba(232,0,45,0.3);
      color: var(--red); font-size: 11px; font-weight: 800;
      letter-spacing: 2px; text-transform: uppercase;
      padding: 5px 14px; border-radius: 100px; margin-bottom: 10px;
    }
    .badge::before { content: ''; width: 6px; height: 6px; background: var(--red); border-radius: 50%; }
    h1 {
      font-family: 'Outfit', sans-serif; font-size: clamp(24px, 3.5vw, 34px);
      font-weight: 900; letter-spacing: -1px; line-height: 1.15; margin-bottom: 6px;
      background: linear-gradient(135deg, #FFF 40%, var(--gold) 100%);
      -webkit-background-clip: text; -webkit-text-fill-color: transparent;
    }
    p.sub { font-size: 13px; color: var(--text-muted); line-height: 1.4; }

    /* LAYOUT PRINCIPALE AFFIANCATO */
    .main-workspace {
      width: 100%;
      max-width: 1120px;
      display: grid;
      grid-template-columns: 1fr 340px;
      gap: 24px;
      align-items: start;
    }
    @media (max-width: 900px) {
      .main-workspace {
        grid-template-columns: 1fr;
      }
    }

    .card {
      background: var(--card-bg);
      border: 1px solid var(--card-border);
      border-radius: var(--radius);
      padding: 24px;
      box-shadow: 0 20px 40px rgba(0,0,0,0.6);
    }
    .input-group { margin-bottom: 18px; }
    label { display: block; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; color: var(--text-muted); margin-bottom: 8px; }
    input[type="url"], input[type="text"] {
      width: 100%; background: #09090D; border: 1px solid #232330;
      border-radius: 10px; padding: 13px 16px; color: #fff;
      font-size: 13px; outline: none; transition: all 0.2s;
    }
    input:focus { border-color: var(--red); box-shadow: 0 0 0 3px rgba(232,0,45,0.2); }
    
    /* BOX AFFIANCATI (3 COLONNE) */
    .boxes-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 12px;
      margin-bottom: 18px;
    }
    @media (max-width: 650px) {
      .boxes-grid {
        grid-template-columns: 1fr;
      }
    }

    .box-col {
      background: rgba(255,255,255,0.02);
      border: 1px solid #1a1a24;
      border-radius: 12px;
      padding: 14px;
      display: flex;
      flex-direction: column;
      gap: 6px;
    }
    .box-label {
      font-size: 11px;
      font-weight: 900;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      display: flex;
      align-items: center;
      gap: 5px;
    }
    .box-label.red { color: #ff4d6d; }
    .box-label.dark { color: #e5e7eb; }
    .box-label.yellow { color: var(--gold); }

    button.btn-gen {
      width: 100%; background: var(--red); color: #fff; border: none;
      border-radius: 10px; padding: 16px; font-family: 'Outfit', sans-serif;
      font-size: 16px; font-weight: 900; letter-spacing: 1px;
      text-transform: uppercase; cursor: pointer; display: flex;
      align-items: center; justify-content: center; gap: 8px;
      transition: background 0.2s, transform 0.1s;
    }
    button.btn-gen:hover { background: var(--red-hover); transform: translateY(-1px); }
    button.btn-gen:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
    
    #progressSection { display: none; margin-top: 18px; }
    .progress-bar-bg { width: 100%; height: 6px; background: #222; border-radius: 10px; overflow: hidden; margin-bottom: 8px; }
    .progress-bar-fill { height: 100%; width: 0%; background: linear-gradient(90deg, var(--red), var(--gold)); transition: width 0.2s; }
    .progress-status { font-size: 12px; color: var(--text-muted); text-align: center; }

    /* PANNELLO ANTEPRIMA VIDEO AFFIANCATO A DESTRA */
    .preview-card {
      background: var(--card-bg);
      border: 1px solid var(--card-border);
      border-radius: var(--radius);
      padding: 20px;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 14px;
      position: sticky;
      top: 20px;
    }
    .preview-header { width: 100%; display: flex; justify-content: space-between; align-items: center; }
    .preview-title { font-size: 13px; font-weight: 800; color: #fff; text-transform: uppercase; letter-spacing: 0.5px; }
    .video-container {
      width: 100%;
      max-width: 280px;
      aspect-ratio: 9/16;
      background: #000;
      border-radius: 12px;
      overflow: hidden;
      border: 1px solid #282836;
      box-shadow: 0 10px 30px rgba(0,0,0,0.8);
      position: relative;
    }
    .video-container video { width: 100%; height: 100%; object-fit: cover; }
    .video-placeholder {
      position: absolute; inset: 0; display: flex; flex-direction: column;
      align-items: center; justify-content: center; color: var(--text-muted);
      gap: 10px; font-size: 12px; text-align: center; padding: 20px;
    }
    .video-placeholder span.icon { font-size: 32px; opacity: 0.5; }

    .btn-download {
      width: 100%; display: flex; align-items: center; justify-content: center;
      gap: 8px; background: #10B981; color: #fff; text-decoration: none;
      padding: 13px; border-radius: 10px; font-weight: 800; font-size: 13px;
      text-align: center; transition: background 0.2s;
    }
    .btn-download:hover { background: #059669; }
    .badge-cat { display: inline-block; background: #1C1C24; color: var(--gold); font-size: 11px; font-weight: 800; padding: 3px 8px; border-radius: 6px; }
    #errorBox { display: none; background: rgba(232,0,45,0.15); border: 1px solid var(--red); color: #ff6b6b; padding: 12px; border-radius: 10px; font-size: 13px; margin-top: 14px; }
    canvas#renderCanvas { display: none; }
  </style>
</head>
<body>

  <div class="header">
    <div class="badge">🎬 REEL ENGINE 9:16 BREAKING NEWS</div>
    <h1>Generatore Reel F1</h1>
    <p class="sub">Layout con 3 box affiancati, rendering client-side 100% nativo (1080x1920 MP4) con Ken Burns, banner e musica.</p>
  </div>

  <div class="main-workspace">
    <!-- COLONNA SINISTRA: CONTROLLI & 3 BOX AFFIANCATI -->
    <div class="card">
      <div class="input-group">
        <label>1. Link Articolo FormulaPaddock</label>
        <div style="display:flex; gap:8px;">
          <input type="url" id="urlInput" value="<?= htmlspecialchars($initialUrl) ?>" placeholder="https://www.formulapaddock.it/marcin-budkowski-e-il-nuovo-team-principal-del-team-cadillac-f1/" required>
          <button type="button" style="background:#222; border:1px solid #333; color:#fff; padding:0 16px; border-radius:10px; font-weight:700; cursor:pointer; white-space:nowrap;" onclick="fetchAndPreview()">
            ⚡ Carica AI
          </button>
        </div>
      </div>

      <!-- 3 BOX AFFIANCATI (1 PER OGNI FOTO) -->
      <label>2. 3 Testi nel Video (1 Scritta per ciascuna delle 3 Foto)</label>
      <div class="boxes-grid">
        <div class="box-col">
          <div class="box-label red">🟥 Foto 1: Gancio Iniziale</div>
          <input type="text" id="b1Input" value="🔴 CADILLAC F1: SVOLTA STORICA">
        </div>
        <div class="box-col">
          <div class="box-label dark">⬛ Foto 2: Notizia Principale</div>
          <input type="text" id="b2Input" value="MARCIN BUDKOWSKI AL COMANDO">
        </div>
        <div class="box-col">
          <div class="box-label yellow">🟨 Foto 3: Dettaglio / Call-To-Action</div>
          <input type="text" id="b3Input" value="TUTTI I RETROSCENA SU FORMULAPADDOCK.IT">
        </div>
      </div>

      <button id="genBtn" class="btn-gen" onclick="startClientRender()">
        <span>⚡</span> Genera Video Reel 9:16
      </button>

      <div id="progressSection">
        <div class="progress-bar-bg">
          <div class="progress-bar-fill" id="progressFill"></div>
        </div>
        <div class="progress-status" id="progressText">Inizializzazione in corso...</div>
      </div>

      <div id="errorBox"></div>
    </div>

    <!-- COLONNA DESTRA: ANTEPRIMA VIDEO REEL AFFIANCATA -->
    <div class="preview-card">
      <div class="preview-header">
        <div class="preview-title">📺 Anteprima Reel 9:16</div>
        <span class="badge-cat" id="resCat">F1 News</span>
      </div>

      <div class="video-container">
        <div class="video-placeholder" id="placeholder">
          <span class="icon">🎥</span>
          <div>Il video generato apparirà qui pronto per il download.</div>
        </div>
        <video id="player" style="display:none;" controls playsinline loop></video>
      </div>

      <a id="dlBtn" class="btn-download" href="#" style="display:none;" download="reel_f1.mp4">
        <span>⬇</span> Scarica Video MP4
      </a>
    </div>
  </div>

  <canvas id="renderCanvas" width="1080" height="1920"></canvas>

  <script>
    let cachedMetaData = null;

    async function loadImage(src) {
      return new Promise((resolve) => {
        const img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = () => resolve(img);
        img.onerror = () => {
          const fallback = new Image();
          fallback.onload = () => resolve(fallback);
          fallback.src = 'fonts/logo_fp.webp';
        };
        img.src = src;
      });
    }

    function drawWrappedBanner(ctx, startX, startY, text, fontSize, fontFamily, bgColor, textColor, maxW = 960, paddingX = 26, paddingY = 16, radius = 8) {
      if (!text || text.trim() === '') return 0;
      
      ctx.save();
      ctx.font = `900 ${fontSize}px ${fontFamily}`;
      
      const words = text.trim().split(/\s+/);
      const lines = [];
      let currentLine = '';

      for (const word of words) {
        const testLine = currentLine ? currentLine + ' ' + word : word;
        const testWidth = ctx.measureText(testLine).width;
        if (testWidth > (maxW - paddingX * 2) && currentLine) {
          lines.push(currentLine);
          currentLine = word;
        } else {
          currentLine = testLine;
        }
      }
      if (currentLine) lines.push(currentLine);

      const lineHeight = fontSize * 1.22;
      let totalH = 0;

      for (let i = 0; i < lines.length; i++) {
        const line = lines[i];
        const textMetrics = ctx.measureText(line);
        const lineW = Math.min(maxW, textMetrics.width + paddingX * 2);
        const lineH = lineHeight + paddingY;
        const curY = startY + totalH;

        ctx.shadowColor = 'rgba(0, 0, 0, 0.95)';
        ctx.shadowBlur = 18;
        ctx.shadowOffsetX = 4;
        ctx.shadowOffsetY = 6;

        ctx.fillStyle = bgColor;
        ctx.beginPath();
        ctx.roundRect(startX, curY, lineW, lineH, radius);
        ctx.fill();

        ctx.shadowColor = 'transparent';
        ctx.fillStyle = textColor;
        ctx.textBaseline = 'middle';
        ctx.fillText(line, startX + paddingX, curY + lineH / 2 + 1);

        totalH += lineH + 12;
      }

      ctx.restore();
      return totalH;
    }

    async function fetchAndPreview() {
      const url = document.getElementById('urlInput').value.trim();
      const errBox = document.getElementById('errorBox');
      if (!url.startsWith('http')) {
        errBox.textContent = 'Inserisci un link valido che inizia con https://';
        errBox.style.display = 'block';
        return;
      }
      errBox.style.display = 'none';

      try {
        const metaRes = await fetch(`reel.php?url=${encodeURIComponent(url)}`);
        const metaData = await metaRes.json();
        if (!metaData.success) throw new Error(metaData.error || 'Impossibile leggere l\'articolo.');

        cachedMetaData = metaData;
        document.getElementById('b1Input').value = metaData.boom1 || '🔴 CADILLAC F1';
        document.getElementById('b2Input').value = metaData.boom2 || metaData.title;
        document.getElementById('b3Input').value = metaData.boom3 || 'SCOPRI TUTTI I DETTAGLI SU FORMULAPADDOCK';
        if (metaData.category) document.getElementById('resCat').textContent = metaData.category;
      } catch (err) {
        errBox.textContent = 'Errore: ' + err.message;
        errBox.style.display = 'block';
      }
    }

    async function startClientRender() {
      const url = document.getElementById('urlInput').value.trim();
      const errBox = document.getElementById('errorBox');
      const genBtn = document.getElementById('genBtn');
      const progSec = document.getElementById('progressSection');
      const progFill = document.getElementById('progressFill');
      const progText = document.getElementById('progressText');

      errBox.style.display = 'none';

      if (!url.startsWith('http')) {
        errBox.textContent = 'Inserisci un link valido che inizia con https://';
        errBox.style.display = 'block';
        return;
      }

      genBtn.disabled = true;
      progSec.style.display = 'block';
      progFill.style.width = '15%';
      progText.textContent = 'Estrazione articolo e copy AI...';

      try {
        await document.fonts.ready;

        let metaData = cachedMetaData;
        if (!metaData || metaData.articleUrl !== url) {
          const metaRes = await fetch(`reel.php?url=${encodeURIComponent(url)}`);
          metaData = await metaRes.json();
          if (!metaData.success) throw new Error(metaData.error || 'Impossibile leggere l\'articolo.');
          cachedMetaData = metaData;
          document.getElementById('b1Input').value = metaData.boom1;
          document.getElementById('b2Input').value = metaData.boom2;
          document.getElementById('b3Input').value = metaData.boom3;
        }

        const banner1 = document.getElementById('b1Input').value.trim().toUpperCase();
        const banner2 = document.getElementById('b2Input').value.trim().toUpperCase();
        const banner3 = document.getElementById('b3Input').value.trim().toUpperCase();

        progFill.style.width = '35%';
        progText.textContent = 'Caricamento immagini e musica...';

        const loadedImgs = [];
        for (const imgSrc of metaData.images) {
          try {
            const img = await loadImage(imgSrc);
            loadedImgs.push(img);
          } catch(e) {}
        }
        if (loadedImgs.length === 0) throw new Error('Nessuna immagine disponibile per questo articolo.');
        while (loadedImgs.length < 3) loadedImgs.push(loadedImgs[0]);

        const logoImg = await loadImage(metaData.logo || 'fonts/logo_fp.webp');

        const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        let audioBuffer = null;
        if (metaData.audio) {
          try {
            const audioResp = await fetch(metaData.audio);
            const audioArrayBuf = await audioResp.arrayBuffer();
            audioBuffer = await audioCtx.decodeAudioData(audioArrayBuf);
          } catch (e) {}
        }

        progFill.style.width = '55%';
        progText.textContent = 'Rendering fotogrammi con 3 Box Breaking News...';

        const canvas = document.getElementById('renderCanvas');
        const ctx = canvas.getContext('2d');
        const W = 1080;
        const H = 1920;
        const FPS = 30;
        const DURATION = 6.8;
        const totalFrames = Math.round(DURATION * FPS);

        const canvasStream = canvas.captureStream(FPS);

        let combinedStream = canvasStream;
        let audioSource = null;
        if (audioBuffer) {
          const audioDest = audioCtx.createMediaStreamDestination();
          const gainNode = audioCtx.createGain();
          gainNode.gain.setValueAtTime(1.0, audioCtx.currentTime);
          gainNode.gain.setValueAtTime(1.0, audioCtx.currentTime + 5.8);
          gainNode.gain.linearRampToValueAtTime(0.01, audioCtx.currentTime + 6.8);

          audioSource = audioCtx.createBufferSource();
          audioSource.buffer = audioBuffer;
          audioSource.connect(gainNode);
          gainNode.connect(audioDest);
          audioSource.start(0);

          combinedStream = new MediaStream([
            ...canvasStream.getVideoTracks(),
            ...audioDest.stream.getAudioTracks()
          ]);
        }

        let mimeType = 'video/mp4; codecs="avc1.42E01E, mp4a.40.2"';
        if (!MediaRecorder.isTypeSupported(mimeType)) mimeType = 'video/mp4';
        if (!MediaRecorder.isTypeSupported(mimeType)) mimeType = 'video/webm; codecs=vp9,opus';
        if (!MediaRecorder.isTypeSupported(mimeType)) mimeType = 'video/webm';

        const recorder = new MediaRecorder(combinedStream, {
          mimeType: mimeType,
          videoBitsPerSecond: 8000000
        });

        const recordedChunks = [];
        recorder.ondataavailable = e => { if (e.data.size > 0) recordedChunks.push(e.data); };

        const renderPromise = new Promise((resolve) => {
          recorder.onstop = () => {
            const blob = new Blob(recordedChunks, { type: mimeType });
            resolve(blob);
          };
        });

        recorder.start();

        for (let frame = 0; frame < totalFrames; frame++) {
          const t = frame / FPS;

          let currentImg = loadedImgs[0];
          let imgProgress = t / 1.9;
          let sceneIndex = 1; // 1, 2, o 3

          if (t >= 1.9 && t < 3.8) {
            currentImg = loadedImgs[1];
            imgProgress = (t - 1.9) / 1.9;
            sceneIndex = 2;
          } else if (t >= 3.8 && t < 5.4) {
            currentImg = loadedImgs[2];
            imgProgress = (t - 3.8) / 1.6;
            sceneIndex = 3;
          } else if (t >= 5.4) {
            currentImg = loadedImgs[2];
            imgProgress = (t - 5.4) / 1.4;
            sceneIndex = 4;
          }

          ctx.save();
          ctx.fillStyle = '#000';
          ctx.fillRect(0, 0, W, H);

          const nw = currentImg.naturalWidth || currentImg.width || 1920;
          const nh = currentImg.naturalHeight || currentImg.height || 1080;
          const imgRatio = nw / nh;
          const targetRatio = W / H;

          let baseW, baseH;
          if (imgRatio > targetRatio) {
            baseH = H;
            baseW = H * imgRatio;
          } else {
            baseW = W;
            baseH = W / imgRatio;
          }

          const scale = 1.08 + Math.sin(imgProgress * Math.PI) * 0.05;
          const panX = Math.sin(imgProgress * Math.PI) * 28;
          const panY = Math.cos(imgProgress * Math.PI) * 14;

          const drawW = baseW * scale;
          const drawH = baseH * scale;
          const drawX = (W - drawW) / 2 + panX;
          const drawY = (H - drawH) / 2 + panY;

          let alpha = 1.0;
          if (t < 0.25) alpha = t / 0.25;
          else if (t >= 1.9 && t < 2.1) alpha = 0.6 + (t - 1.9) / 0.2 * 0.4;
          else if (t >= 3.8 && t < 4.0) alpha = 0.6 + (t - 3.8) / 0.2 * 0.4;
          ctx.globalAlpha = alpha;

          ctx.drawImage(currentImg, drawX, drawY, drawW, drawH);
          ctx.restore();

          // ─── RENDERING DINAMICO: 1 TESTO DIVERSO PER OGNI FOTO (0.0s - 5.4s) ───
          if (sceneIndex <= 3) {
            // Header Top TV Badge
            ctx.save();
            ctx.fillStyle = '#E8002D';
            ctx.beginPath();
            ctx.roundRect(60, 70, 360, 56, 10);
            ctx.fill();

            ctx.fillStyle = '#FFFFFF';
            ctx.font = '900 24px "Inter", sans-serif';
            ctx.fillText('FORMULA PADDOCK', 85, 107);
            ctx.restore();

            // Sfumatura nera inferiore per leggibilità
            ctx.save();
            const grad = ctx.createLinearGradient(0, 1000, 0, H);
            grad.addColorStop(0, 'rgba(0,0,0,0)');
            grad.addColorStop(0.3, 'rgba(0,0,0,0.85)');
            grad.addColorStop(1, 'rgba(0,0,0,0.98)');
            ctx.fillStyle = grad;
            ctx.fillRect(0, 1000, W, 920);

            const curY = 1200;

            if (sceneIndex === 1) {
              // 🟥 SCENA 1 / FOTO 1: TESTO 1 (GANCIO ROSSO CORSA)
              drawWrappedBanner(
                ctx,
                60,
                curY,
                banner1,
                52,
                '"Outfit", sans-serif',
                '#E8002D',
                '#FFFFFF',
                960,
                30,
                22,
                10
              );
            } else if (sceneIndex === 2) {
              // ⬛ SCENA 2 / FOTO 2: TESTO 2 (NOTIZIA CHIAVE NERA CARBONIO GIGANTE)
              drawWrappedBanner(
                ctx,
                60,
                curY,
                banner2,
                54,
                '"Outfit", sans-serif',
                'rgba(12, 14, 20, 0.96)',
                '#FFFFFF',
                960,
                32,
                24,
                12
              );
            } else if (sceneIndex === 3) {
              // 🟨 SCENA 3 / FOTO 3: TESTO 3 (DETTAGLIO GIALLO RACING)
              drawWrappedBanner(
                ctx,
                60,
                curY,
                banner3,
                48,
                '"Inter", sans-serif',
                '#FFD700',
                '#000000',
                960,
                30,
                22,
                10
              );
            }

            ctx.fillStyle = '#FFFFFF';
            ctx.font = '800 26px "Inter", sans-serif';
            ctx.fillText('🔗 FORMULAPADDOCK.IT', 70, 1780);
            ctx.restore();
          }

          // ─── OUTRO CON LOGO CENTRATO (5.4s - 6.8s) ───
          if (t >= 5.4) {
            ctx.save();
            const outroAlpha = Math.min(1.0, (t - 5.4) / 0.3);
            ctx.fillStyle = `rgba(0, 0, 0, ${0.92 * outroAlpha})`;
            ctx.fillRect(0, 0, W, H);

            const logoSize = 460;
            ctx.globalAlpha = outroAlpha;
            ctx.drawImage(logoImg, (W - logoSize) / 2, (H - logoSize) / 2 - 140, logoSize, logoSize);

            ctx.textAlign = 'center';
            ctx.shadowColor = 'rgba(0,0,0,0.9)';
            ctx.shadowBlur = 14;
            ctx.shadowOffsetX = 3;
            ctx.shadowOffsetY = 3;

            ctx.fillStyle = '#FFFFFF';
            ctx.font = '900 52px "Outfit", sans-serif';
            ctx.fillText('FORMULAPADDOCK.IT', W / 2, 1150);

            ctx.fillStyle = '#FFD700';
            ctx.font = '800 34px "Inter", sans-serif';
            ctx.fillText('SEGUICI PER TUTTE LE NOVITA', W / 2, 1225);
            ctx.restore();
          }

          const percent = 55 + Math.round((frame / totalFrames) * 40);
          progFill.style.width = percent + '%';

          await new Promise(r => setTimeout(r, 1000 / FPS));
        }

        progFill.style.width = '98%';
        progText.textContent = 'Finalizzazione video...';
        recorder.stop();
        if (audioSource) try { audioSource.stop(); } catch(e) {}

        const videoBlob = await renderPromise;
        const videoUrl = URL.createObjectURL(videoBlob);

        progFill.style.width = '100%';
        progText.textContent = '✅ Video generato con successo!';

        setTimeout(() => {
          progSec.style.display = 'none';
          document.getElementById('resCat').textContent = metaData.category || 'Formula 1';

          const placeholder = document.getElementById('placeholder');
          if (placeholder) placeholder.style.display = 'none';

          const player = document.getElementById('player');
          player.style.display = 'block';
          player.src = videoUrl;
          player.load();
          player.play().catch(() => {});

          const dlBtn = document.getElementById('dlBtn');
          dlBtn.href = videoUrl;
          dlBtn.download = `reel_${Date.now()}.mp4`;
          dlBtn.style.display = 'flex';

          genBtn.disabled = false;
        }, 400);

      } catch (err) {
        progSec.style.display = 'none';
        genBtn.disabled = false;
        errBox.textContent = 'Errore: ' + err.message;
        errBox.style.display = 'block';
      }
    }

    // Auto-carica ed esegue il rendering in automatico se passato param url
    window.addEventListener('DOMContentLoaded', async () => {
      const u = document.getElementById('urlInput').value.trim();
      if (u.startsWith('http')) {
        await fetchAndPreview();
        // Avvio automatico immediato del rendering Reel
        setTimeout(() => {
          startClientRender();
        }, 400);
      }
    });
  </script>
</body>
</html>
