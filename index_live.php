<?php
require_once __DIR__ . '/includes/sitemap_helper.php';
$sitemapLinks = getSitemapUrls();

$autoPublish = true;
if (($_GET['auto_pubblish'] ?? $_GET['auto_publish'] ?? '') === 'off') {
    $autoPublish = false;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generatore Contenuti Social F1 — FormulaPaddock</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-main: #0b0f19;
            --bg-card: #151926;
            --bg-card-glass: rgba(21, 25, 38, 0.85);
            --border-color: rgba(255, 255, 255, 0.12);
            --accent-red: #e10600;
            --accent-red-glow: rgba(225, 6, 0, 0.35);
            --accent-gold: #ffd100;
            --text-main: #ffffff;
            --text-muted: #9ba1b0;
            --card-radius: 16px;
        }

        * { box-sizing: border-box; }
        body {
            font-family: 'Montserrat', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: linear-gradient(145deg, #07090e 0%, #0b0f19 50%, #170d12 100%);
            color: var(--text-main);
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px 20px;
        }

        .main-container {
            width: 100%;
            max-width: 820px;
        }

        .card {
            background: var(--bg-card-glass);
            backdrop-filter: blur(16px);
            border: 1px solid var(--border-color);
            border-radius: var(--card-radius);
            padding: 36px;
            box-shadow: 0 24px 64px rgba(0, 0, 0, 0.6), 0 0 0 1px rgba(255, 255, 255, 0.05);
            position: relative;
            overflow: hidden;
        }

        .card::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--accent-red) 0%, var(--accent-gold) 50%, var(--accent-red) 100%);
        }

        .brand-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        .brand-badge {
            font-size: 13px;
            font-weight: 800;
            letter-spacing: 1.5px;
            color: var(--accent-gold);
            background: rgba(255, 209, 0, 0.12);
            border: 1px solid rgba(255, 209, 0, 0.3);
            padding: 6px 14px;
            border-radius: 999px;
            text-transform: uppercase;
        }

        .cloud-link-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(90deg, rgba(225, 6, 0, 0.2) 0%, rgba(225, 6, 0, 0.08) 100%);
            border: 1px solid var(--accent-red);
            padding: 12px 18px;
            border-radius: 10px;
            margin-bottom: 24px;
            font-size: 13px;
        }
        .cloud-link-bar a {
            color: var(--accent-gold);
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: transform 0.2s;
        }
        .cloud-link-bar a:hover { transform: translateX(3px); }

        h1 {
            margin: 0 0 10px 0;
            font-size: 28px;
            font-weight: 900;
            letter-spacing: -0.5px;
        }
        .accent { color: var(--accent-red); }
        .accent-gold { color: var(--accent-gold); }

        p.desc {
            color: var(--text-muted);
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 24px;
        }

        /* LIVE TIMING TOGGLE CARD */
        .live-toggle-card {
            background: linear-gradient(135deg, rgba(225, 6, 0, 0.15) 0%, rgba(15, 20, 30, 0.9) 100%);
            border: 1px solid rgba(225, 6, 0, 0.4);
            border-radius: 12px;
            padding: 18px 20px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            transition: all 0.25s ease;
            box-shadow: 0 4px 20px rgba(225, 6, 0, 0.1);
        }
        .live-toggle-card:hover {
            border-color: var(--accent-red);
            box-shadow: 0 6px 24px var(--accent-red-glow);
        }
        .live-info {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .live-badge-pulse {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--accent-red);
            color: #fff;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 1px;
            padding: 6px 12px;
            border-radius: 6px;
            box-shadow: 0 0 12px var(--accent-red);
        }
        .live-dot {
            width: 8px;
            height: 8px;
            background: #fff;
            border-radius: 50%;
            display: inline-block;
            animation: pulse-dot 1.2s infinite ease-in-out;
        }
        @keyframes pulse-dot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.3; transform: scale(0.7); }
        }
        .live-text-title {
            font-size: 15px;
            font-weight: 700;
            color: #fff;
            margin-bottom: 2px;
        }
        .live-text-sub {
            font-size: 12px;
            color: #ccc;
        }

        /* TOGGLE SWITCH */
        .switch {
            position: relative;
            display: inline-block;
            width: 52px;
            height: 28px;
            flex-shrink: 0;
        }
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: rgba(255, 255, 255, 0.2);
            transition: .3s;
            border-radius: 34px;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 20px;
            width: 20px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .3s;
            border-radius: 50%;
        }
        input:checked + .slider {
            background-color: var(--accent-red);
            border-color: var(--accent-red);
            box-shadow: 0 0 12px var(--accent-red-glow);
        }
        input:checked + .slider:before {
            transform: translateX(24px);
        }

        /* TARGET CHANNELS SELECTOR */
        .channels-section {
            background: rgba(0, 0, 0, 0.25);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
        }
        .channels-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
        }
        .channels-title {
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--accent-gold);
        }
        .channels-quick-actions {
            display: flex;
            gap: 10px;
        }
        .btn-link-action {
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 12px;
            cursor: pointer;
            padding: 0;
            text-decoration: underline;
        }
        .btn-link-action:hover { color: #fff; }

        .channels-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 12px;
        }
        .channel-chip {
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.1);
            padding: 10px 14px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            user-select: none;
        }
        .channel-chip:hover {
            background: rgba(255, 255, 255, 0.08);
            border-color: rgba(255, 255, 255, 0.2);
        }
        .channel-chip input[type="checkbox"] {
            accent-color: var(--accent-red);
            width: 16px;
            height: 16px;
            cursor: pointer;
        }
        .channel-chip-label {
            font-size: 13px;
            font-weight: 600;
        }
        .channel-chip-sub {
            font-size: 11px;
            color: var(--text-muted);
            display: block;
        }

        /* FORM INPUTS */
        label.input-label {
            display: block;
            margin: 20px 0 8px;
            font-weight: 700;
            font-size: 14px;
            letter-spacing: 0.3px;
        }
        textarea, input[type="text"] {
            width: 100%;
            padding: 14px 16px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: rgba(7, 10, 17, 0.6);
            color: #fff;
            font-family: inherit;
            font-size: 14px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        textarea { min-height: 130px; resize: vertical; line-height: 1.5; }
        textarea:focus, input[type="text"]:focus {
            outline: none;
            border-color: var(--accent-gold);
            box-shadow: 0 0 0 2px rgba(255, 209, 0, 0.2);
        }
        .hint {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 6px;
            line-height: 1.4;
        }

        /* SUBMIT BUTTON */
        .btn-submit {
            margin-top: 28px;
            width: 100%;
            padding: 16px;
            border: none;
            border-radius: 10px;
            background: linear-gradient(90deg, #e10600 0%, #b30000 100%);
            color: #ffffff;
            font-family: inherit;
            font-size: 16px;
            font-weight: 800;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            cursor: pointer;
            box-shadow: 0 8px 24px var(--accent-red-glow);
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 32px rgba(225, 6, 0, 0.5);
            background: linear-gradient(90deg, #ff0700 0%, #cc0000 100%);
        }
        .btn-submit:active { transform: translateY(0); }

        .loading {
            display: none;
            text-align: center;
            margin-top: 20px;
            font-size: 14px;
            color: var(--accent-gold);
            font-weight: 600;
        }

        @media (max-width: 600px) {
            .card { padding: 24px 18px; }
            .channels-grid { grid-template-columns: 1fr; }
            .live-info { flex-direction: column; align-items: flex-start; gap: 8px; }
        }
    </style>
</head>
<body>
    <div class="main-container">
        <div class="card">
            <div class="brand-header">
                <span class="brand-badge">Formula Paddock Social Suite 2026</span>
                <span style="font-size: 12px; color: var(--text-muted);">v5.0 Multi-Platform</span>
            </div>

            <div class="cloud-link-bar">
                <span>🎬 Reel Engine 9:16 FormulaPaddock Nativo</span>
                <a href="reel.php" target="_blank">Apri Reel Engine &rarr;</a>
                <a href="reel_jobs.php" style="margin-left:10px;background:rgba(255,209,0,0.15);color:#ffd100;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:700;text-decoration:none;">📊 Job Reel Monitor</a>
            </div>

            <h1>🏎️ Generatore Contenuti <span class="accent">F1</span></h1>
            <p class="desc">
                Inserisci un <strong>testo</strong>, un <strong>URL dell'articolo</strong> o attiva la <strong>Sessione Live</strong>. Verranno generati automaticamente:
                post Facebook, Twitter/X, LinkedIn, Threads, infografiche dinamiche 1080x1080 & 1080x1350 (salvate su Drive <strong>creatività</strong>) e accodamento automatico per l'estensione Chrome Gruppi Facebook e Reel multi-piattaforma.
            </p>

            <form action="process.php" method="post" id="genForm">
                
                <!-- LIVE TIMING TOGGLE -->
                <div class="live-toggle-card" onclick="toggleLiveCheckbox(event)">
                    <div class="live-info">
                        <span class="live-badge-pulse"><span class="live-dot"></span> LIVE TIMING</span>
                        <div>
                            <div class="live-text-title">Sessione Live F1 (Top 3 + Focus Ferrari)</div>
                            <div class="live-text-sub">Estrae telemetria reale (Leclerc #16 & Hamilton #44), genera infografiche Live 1080x1080 e 1080x1350</div>
                        </div>
                    </div>
                    <label class="switch" onclick="event.stopPropagation();">
                        <input type="checkbox" id="is_live" name="is_live" value="1" <?php echo (!empty($_GET['is_live']) ? 'checked' : ''); ?>>
                        <span class="slider"></span>
                    </label>
                </div>

                <!-- TARGET CHANNEL SELECTOR -->
                <div class="channels-section">
                    <div class="channels-header">
                        <span class="channels-title">🎯 Canali Social di Destinazione</span>
                        <div class="channels-quick-actions">
                            <button type="button" class="btn-link-action" onclick="selectAllChannels(true)">Tutti</button>
                            <span>•</span>
                            <button type="button" class="btn-link-action" onclick="selectAllChannels(false)">Nessuno</button>
                        </div>
                    </div>

                    <!-- AUTO-PUBLISH SWITCH -->
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; padding-bottom: 12px; border-bottom: 1px dashed rgba(255, 255, 255, 0.1);">
                        <span style="font-size: 13px; font-weight: 700; color: var(--accent-gold);">⚡ Pubblicazione Automatica (Master Switch):</span>
                        <label class="switch">
                            <input type="checkbox" id="auto_publish_toggle" onchange="toggleAutoPublishFromSwitch()" <?php echo $autoPublish ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </label>
                    </div>

                    <div class="channels-grid">
                        <label class="channel-chip">
                            <input type="checkbox" name="channels[]" value="tiktok" onclick="syncToggleSwitch()" <?php echo $autoPublish ? 'checked' : ''; ?>>
                            <div>
                                <span class="channel-chip-label">🎵 TikTok Reels</span>
                                <span class="channel-chip-sub">Direct Content Posting v2</span>
                            </div>
                        </label>
                        <label class="channel-chip">
                            <input type="checkbox" name="channels[]" value="instagram" onclick="syncToggleSwitch()" <?php echo $autoPublish ? 'checked' : ''; ?>>
                            <div>
                                <span class="channel-chip-label">📸 Instagram Reels</span>
                                <span class="channel-chip-sub">Graph API Media Container</span>
                            </div>
                        </label>
                        <label class="channel-chip">
                            <input type="checkbox" name="channels[]" value="facebook_reels" onclick="syncToggleSwitch()" <?php echo $autoPublish ? 'checked' : ''; ?>>
                            <div>
                                <span class="channel-chip-label">👥 Facebook Reels</span>
                                <span class="channel-chip-sub">Meta Video Reels API</span>
                            </div>
                        </label>
                        <label class="channel-chip">
                            <input type="checkbox" name="channels[]" value="buffer" onclick="syncToggleSwitch()" <?php echo $autoPublish ? 'checked' : ''; ?>>
                            <div>
                                <span class="channel-chip-label">🌐 Buffer (FB Page / X)</span>
                                <span class="channel-chip-sub">Post & Infografiche</span>
                            </div>
                        </label>
                        <label class="channel-chip">
                            <input type="checkbox" name="channels[]" value="fb_groups" onclick="syncToggleSwitch()" <?php echo $autoPublish ? 'checked' : ''; ?>>
                            <div>
                                <span class="channel-chip-label">🧩 Estensione Gruppi FB</span>
                                <span class="channel-chip-sub">Coda REST Chrome Ext</span>
                            </div>
                        </label>
                        <label class="channel-chip">
                            <input type="checkbox" name="channels[]" value="threads" onclick="syncToggleSwitch()" <?php echo $autoPublish ? 'checked' : ''; ?>>
                            <div>
                                <span class="channel-chip-label">🧵 Threads</span>
                                <span class="channel-chip-sub">Meta Threads API</span>
                            </div>
                        </label>
                    </div>
                </div>

                <input type="hidden" id="auto_pubblish_input" name="auto_pubblish" value="<?php echo $autoPublish ? 'on' : 'off'; ?>">

                <!-- SITEMAP SEARCH AUTOCOMPLETE -->
                <div style="margin-top: 24px; position: relative;">
                    <label class="input-label" for="sitemap_search">🔎 Cerca Articolo da Sitemap (Predizione URL)</label>
                    <input type="text" id="sitemap_search" placeholder="Inizia a digitare il titolo dell'articolo o parte dell'URL..." autocomplete="off">
                    <div id="autocomplete_dropdown" style="display:none; position:absolute; left:0; right:0; max-height:250px; overflow-y:auto; background:var(--bg-card); border:1px solid var(--accent-gold); border-top:none; border-radius:0 0 10px 10px; z-index:9999; box-shadow: 0 10px 30px rgba(0,0,0,0.5);"></div>
                </div>

                <!-- CONTENT INPUTS -->
                <label class="input-label" for="input_text">Testo oppure URL articolo</label>
                <textarea id="input_text" name="input_text" placeholder="Incolla qui il testo della notizia, oppure un link tipo https://www.formulapaddock.it/articolo... (Opzionale se 'Sessione Live' è attiva)"><?php echo htmlspecialchars($_GET['url'] ?? ''); ?></textarea>
                <p class="hint">Se inserisci un URL, il testo verra' estratto automaticamente dalla pagina. In modalità 'Sessione Live', se lasciato vuoto verrà utilizzata la telemetria live.</p>

                <label class="input-label" for="article_url">Link diretto all'articolo</label>
                <input type="url" id="article_url" name="article_url" placeholder="es. https://www.formulapaddock.it/titolo-articolo..." value="<?php echo htmlspecialchars($_GET['article_url'] ?? ''); ?>" />
                <p class="hint">Verrà aggiunto alla caption e alla CTA finale del reel, senza pagine intermedie.</p>

                <button type="submit" class="btn-submit" id="submitBtn">
                    <span>🚀 Genera Contenuti & Avvia Workflow</span>
                </button>
                <div class="loading" id="loadingMsg">⏳ Elaborazione in corso (Telemetria, Infografiche, Hashtag & Pubblicazione)...</div>
            </form>
        </div>
    </div>

    <script>
        function toggleLiveCheckbox(e) {
            const cb = document.getElementById('is_live');
            cb.checked = !cb.checked;
            updateLiveState();
        }

        function updateLiveState() {
            const cb = document.getElementById('is_live');
            const card = document.querySelector('.live-toggle-card');
            const textarea = document.getElementById('input_text');
            if (cb.checked) {
                card.style.borderColor = 'var(--accent-red)';
                card.style.background = 'linear-gradient(135deg, rgba(225, 6, 0, 0.25) 0%, rgba(20, 25, 40, 0.95) 100%)';
                textarea.placeholder = "🔴 Modalità LIVE attiva: puoi lasciare vuoto per usare la telemetria live automatica o inserire note di gara.";
            } else {
                card.style.borderColor = 'rgba(225, 6, 0, 0.4)';
                card.style.background = 'linear-gradient(135deg, rgba(225, 6, 0, 0.15) 0%, rgba(15, 20, 30, 0.9) 100%)';
                textarea.placeholder = "Incolla qui il testo della notizia, oppure un link tipo https://www.formulapaddock.it/articolo...";
            }
        }

        function selectAllChannels(status) {
            const checkboxes = document.querySelectorAll('input[name="channels[]"]');
            checkboxes.forEach(cb => cb.checked = status);
            syncToggleSwitch();
        }

        function toggleAutoPublishFromSwitch() {
            const toggle = document.getElementById('auto_publish_toggle');
            const checkboxes = document.querySelectorAll('.channels-grid input[type="checkbox"]');
            const hiddenInput = document.getElementById('auto_pubblish_input');
            
            checkboxes.forEach(cb => {
                cb.checked = toggle.checked;
            });
            
            if (hiddenInput) {
                hiddenInput.value = toggle.checked ? 'on' : 'off';
            }
        }

        function syncToggleSwitch() {
            const toggle = document.getElementById('auto_publish_toggle');
            const checkboxes = document.querySelectorAll('.channels-grid input[type="checkbox"]');
            const hiddenInput = document.getElementById('auto_pubblish_input');
            
            let anyChecked = Array.from(checkboxes).some(cb => cb.checked);
            toggle.checked = anyChecked;
            if (hiddenInput) {
                hiddenInput.value = anyChecked ? 'on' : 'off';
            }
        }

        document.getElementById('is_live').addEventListener('change', updateLiveState);

        document.getElementById('genForm').addEventListener('submit', function (e) {
            const cb = document.getElementById('is_live');
            const textVal = document.getElementById('input_text').value.trim();
            if (!cb.checked && textVal === '') {
                alert('Inserisci un testo, un link o attiva la modalità "Sessione Live".');
                e.preventDefault();
                return;
            }
            document.getElementById('loadingMsg').style.display = 'block';
            document.getElementById('submitBtn').style.opacity = '0.7';
            document.getElementById('submitBtn').style.pointerEvents = 'none';
        });

        // Initialize state
        updateLiveState();

        // --- Sitemap Autocomplete / Prediction ---
        const sitemapLinks = <?php echo json_encode($sitemapLinks ?? []); ?>;
        const searchInput = document.getElementById('sitemap_search');
        const dropdown = document.getElementById('autocomplete_dropdown');
        const inputText = document.getElementById('input_text');
        const articleUrlInput = document.getElementById('article_url');

        searchInput.addEventListener('input', function() {
            const query = this.value.toLowerCase().trim();
            dropdown.innerHTML = '';
            
            if (query.length < 2) {
                dropdown.style.display = 'none';
                return;
            }

            const matches = sitemapLinks.filter(item => 
                item.title.toLowerCase().includes(query) || 
                item.url.toLowerCase().includes(query)
            ).slice(0, 15);

            if (matches.length === 0) {
                dropdown.style.display = 'none';
                return;
            }

            matches.forEach(item => {
                const div = document.createElement('div');
                div.style.padding = '10px 16px';
                div.style.cursor = 'pointer';
                div.style.borderBottom = '1px solid rgba(255,255,255,0.05)';
                div.style.transition = 'background 0.2s';
                div.innerHTML = `<div style="font-weight:700; font-size:13px; color:#fff;">${item.title}</div><div style="font-size:11px; color:var(--text-muted);">${item.url}</div>`;
                
                div.addEventListener('mouseenter', () => {
                    div.style.backgroundColor = 'rgba(255,209,0,0.15)';
                });
                div.addEventListener('mouseleave', () => {
                    div.style.backgroundColor = 'transparent';
                });
                
                div.addEventListener('click', () => {
                    searchInput.value = item.title;
                    inputText.value = item.url;
                    articleUrlInput.value = item.url;
                    dropdown.style.display = 'none';
                });
                dropdown.appendChild(div);
            });

            dropdown.style.display = 'block';
        });

        document.addEventListener('click', function(e) {
            if (e.target !== searchInput && e.target !== dropdown) {
                dropdown.style.display = 'none';
            }
        });

        <?php if (!empty($_GET['url'])): ?>
        document.getElementById('genForm').submit();
        <?php endif; ?>
    </script>
</body>
</html>
