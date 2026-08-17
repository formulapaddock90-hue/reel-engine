<?php
/**
 * process.php — Formula Paddock Social Suite Automation Workflow Engine
 * Integrates: Live Timing Telemetry, Dynamic 1080x1080 & 1080x1350 Infographics,
 * Google Drive Automation, 5-Level Dynamic Hashtags, Chrome Extension Queue,
 * Multi-Platform Reels Auto-Publishing (BACKGROUND), and Real-Time Publishing Monitor Widget.
 */

ini_set('max_execution_time', 300);
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/includes/url_extractor.php';
require_once __DIR__ . '/includes/ai_generator.php';
require_once __DIR__ . '/includes/image_generator.php';
require_once __DIR__ . '/includes/google_service.php';
require_once __DIR__ . '/includes/hashtag_service.php';
require_once __DIR__ . '/includes/live_telemetry_service.php';
require_once __DIR__ . '/includes/live_infographic_generator.php';
require_once __DIR__ . '/includes/queue_manager.php';
require_once __DIR__ . '/includes/reel_publisher.php';
require_once __DIR__ . '/includes/reel_job_manager.php';

$config = require __DIR__ . '/config.php';
$cloudUrl = 'reel.php';

function renderError(string $message): void
{
    http_response_code(500);
    echo '<!DOCTYPE html><html lang="it"><head><meta charset="UTF-8"><title>Errore</title>';
    echo '<style>body{font-family:sans-serif;background:#0b0f19;color:#fff;padding:40px;}
    .box{background:#151926;border:1px solid #e10600;padding:24px;border-radius:12px;max-width:700px;margin:0 auto;}
    h2{color:#e10600;margin-top:0;} a{color:#ffd100;text-decoration:none;font-weight:bold;}</style></head><body>';
    echo '<div class="box"><h2>⚠️ Si è verificato un errore</h2><pre style="white-space:pre-wrap;background:rgba(0,0,0,0.3);padding:14px;border-radius:8px;">'
        . htmlspecialchars($message) . '</pre>';
    echo '<p><a href="index.php">&larr; Torna al Generatore Social</a></p></div></body></html>';
    exit;
}

function detectReelMode(string $articleUrl, bool $isLive): string
{
    if ($isLive) return 'live';
    $slug = strtolower((string)(parse_url($articleUrl, PHP_URL_PATH) ?: ''));
    if (strpos($slug, 'gran_premi') !== false) return 'live';
    if (strpos($slug, 'evergreen') !== false) return 'analysis';
    return 'news';
}

function buildReelStoryPoints(string $text, string $title, string $mode): array
{
    $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($text)));
    preg_match_all('/[^.!?]+[.!?]+/u', $plain, $matches);
    $sentences = array_values(array_filter(array_map('trim', $matches[0] ?? []), fn($s) => mb_strlen($s) >= 35));
    while (count($sentences) < 3) $sentences[] = $title;
    $labels = $mode === 'analysis'
        ? ['CONTESTO', 'ANALISI', 'CONCLUSIONE']
        : ($mode === 'live' ? ['RISULTATO', 'DISTACCHI', 'STRATEGIA'] : ['IL FATTO', 'PERCHÉ CONTA', 'COSA SEGUE']);
    return array_map(fn($label, $i) => ['label' => $label, 'text' => $sentences[$i]], $labels, array_keys($labels));
}

function downloadArticleImageForReel(string $articleUrl, array $config): ?string
{
    if (!filter_var($articleUrl, FILTER_VALIDATE_URL)) return null;
    $context = stream_context_create(['http' => ['timeout' => 12, 'user_agent' => 'FormulaPaddock ReelBot/1.0']]);
    $html = @file_get_contents($articleUrl, false, $context);
    if (!$html || !preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)/i', $html, $m)) return null;
    $imageUrl = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
    $bytes = @file_get_contents($imageUrl, false, $context);
    if (!$bytes || strlen($bytes) < 1024) return null;
    $dir = rtrim($config['output_images_dir'] ?? (__DIR__ . '/output/images'), '/\\');
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $ext = strtolower(pathinfo((string)parse_url($imageUrl, PHP_URL_PATH), PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) $ext = 'jpg';
    $path = $dir . '/article_reel_' . date('Ymd_His') . '_' . substr(md5($imageUrl), 0, 8) . '.' . $ext;
    return @file_put_contents($path, $bytes) ? $path : null;
}

try {
    // 1. Parsing Input & Session Mode
    $isLive = !empty($_POST['is_live']) || !empty($_GET['is_live']) || (isset($_POST['is_live']) && $_POST['is_live'] === '1');
    $rawInput = trim((string)($_GET['url'] ?? ($_POST['input_text'] ?? '')));
    $articleUrlInput = trim((string)($_POST['article_url'] ?? ($_GET['article_url'] ?? '')));
    $autoPublish = (($_GET['auto_pubblish'] ?? $_POST['auto_pubblish'] ?? $_GET['auto_publish'] ?? $_POST['auto_publish'] ?? '') !== 'off');

    // Channels selection
    $rawChannels = $_POST['channels'] ?? $_GET['channels'] ?? null;
    if ($rawChannels === null) {
        $selectedChannels = ['tiktok', 'twitter', 'instagram', 'facebook_reels', 'facebook_page', 'fb_groups', 'threads'];
    } elseif (is_array($rawChannels)) {
        $selectedChannels = array_map('strtolower', array_map('trim', $rawChannels));
    } else {
        $selectedChannels = [strtolower(trim((string)$rawChannels))];
    }
    // Twitter/X viene gestito automaticamente da Buffer anche nelle vecchie
    // versioni del form che non espongono ancora il relativo checkbox.
    if (!in_array('twitter', $selectedChannels, true) && !in_array('x', $selectedChannels, true)) {
        $selectedChannels[] = 'twitter';
    }

    if ($rawInput === '' && !$isLive) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' && empty($_GET['url'])) {
            header('Location: index.php');
            exit;
        }
        throw new Exception('Nessun testo, URL o modalità "Sessione Live" selezionata.');
    }

    $sourceUrl = '';
    $title = '';
    $sourceText = '';
    $telemetryData = null;
    $liveImages = null;
    $dynamicHashtags = [];
    $fbImageDrive = null;
    $igImageDrive = null;
    $images = ['fb_image' => '', 'ig_image' => ''];
    $content = [];
    $driveErrors = [];
    $queueResult = null;
    $reelJobId = null;
    $reelJobData = null;
    $bgReelJobId = null;   // Background Reel Creation job ID
    $facebookPageResults = [];
    $facebookPageErrors = [];
    $twitterBufferResult = null;
    $twitterBufferError = null;

    // =========================================================================
    // BRANCH A: SESSIONE LIVE WORKFLOW
    // =========================================================================
    if ($isLive) {
        // Step 1: Fetch Live Telemetry (Top 3 + Ferrari Focus)
        $telemetryData = fetchLiveTelemetryData();
        
        $gpName = $telemetryData['session']['gp_name'] ?? 'Gran Premio F1';
        $sessionName = $telemetryData['session']['session_name'] ?? 'Live Timing';
        $totalLaps = $telemetryData['session']['total_laps'] ?? 53;
        
        $p1 = $telemetryData['podium'][0]['driver'] ?? 'P1 Leader';
        $p1Team = $telemetryData['podium'][0]['team'] ?? 'F1';
        $p2 = $telemetryData['podium'][1]['driver'] ?? 'P2 Driver';
        $p2Team = $telemetryData['podium'][1]['team'] ?? 'F1';
        $p3 = $telemetryData['podium'][2]['driver'] ?? 'P3 Driver';
        $p3Team = $telemetryData['podium'][2]['team'] ?? 'F1';

        $lecPos = $telemetryData['ferrari_focus']['leclerc']['position'] ?? 'N/A';
        $lecGap = $telemetryData['ferrari_focus']['leclerc']['gap'] ?? 'N/A';
        $lecTyre = $telemetryData['ferrari_focus']['leclerc']['current_tyre'] ?? 'M';
        
        $hamPos = $telemetryData['ferrari_focus']['hamilton']['position'] ?? 'N/A';
        $hamGap = $telemetryData['ferrari_focus']['hamilton']['gap'] ?? 'N/A';
        $hamTyre = $telemetryData['ferrari_focus']['hamilton']['current_tyre'] ?? 'M';

        $fastestDriver = $telemetryData['fastest_lap']['driver'] ?? 'N/A';
        $fastestLap = $telemetryData['fastest_lap']['lap_time'] ?? 'N/A';

        $title = "🔴 F1 LIVE TIMING — {$gpName} ({$sessionName})";

        // Step 2: Build Live Commentary Text
        if ($rawInput !== '' && !isValidUrl($rawInput)) {
            $sourceText = $rawInput;
        } else {
            $sourceText = "🏁 F1 LIVE TIMING — {$gpName} ({$sessionName})\n\n"
                . "🏆 Top 3 Provvisoria:\n"
                . "1️⃣ {$p1} ({$p1Team})\n"
                . "2️⃣ {$p2} ({$p2Team})\n"
                . "3️⃣ {$p3} ({$p3Team})\n\n"
                . "🔴 FOCUS SCUDERIA FERRARI:\n"
                . "• Charles Leclerc #16: P{$lecPos} ({$lecGap} | Gomme: {$lecTyre})\n"
                . "• Lewis Hamilton #44: P{$hamPos} ({$hamGap} | Gomme: {$hamTyre})\n\n"
                . "⚡ Giro veloce provvisorio: {$fastestDriver} ({$fastestLap})\n"
                . "📊 Segui tutti i distacchi e i tempi in tempo reale su FormulaPaddock.it!";
        }

        if (isValidUrl($articleUrlInput)) {
            $sourceUrl = $articleUrlInput;
        } elseif (isValidUrl($rawInput)) {
            $sourceUrl = $rawInput;
        } else {
            $sourceUrl = 'https://www.formulapaddock.it/live.html';
        }

        // Step 3: 5-Level Dynamic Hashtags Generation
        $hashtagContext = [
            'title'        => $title,
            'text'         => $sourceText,
            'is_live'      => true,
            'session_type' => $sessionName,
            'gp_name'      => $gpName,
            'drivers'      => ['Leclerc', 'Hamilton', $p1, $p2, $p3],
        ];

        $dynamicHashtags = generateDynamicHashtags($hashtagContext, 'default');
        $tagString = $dynamicHashtags['tag_string'] ?? '#F1 #FormulaPaddock #LiveTiming #Ferrari #Leclerc #Hamilton';

        // Prepare per-platform content
        $content = [
            'facebook' => $sourceText . "\n\n" . $tagString . "\n\n🔗 " . $sourceUrl,
            'twitter' => mb_substr("🔴 LIVE TIMING {$gpName}: P1 {$p1} | Ferrari LEC P{$lecPos} HAM P{$hamPos}! " . $tagString, 0, 270) . " " . $sourceUrl,
            'linkedin' => $sourceText . "\n\n" . $tagString . "\n\nAnalisi live su: " . $sourceUrl,
            'instagram' => $sourceText . "\n\n.\n.\n" . generateDynamicHashtags($hashtagContext, 'instagram')['tag_string'],
            'threads' => $sourceText . "\n\n" . $tagString,
            'categoria' => 'LiveTiming',
            'infografica_titolo' => "F1 LIVE {$gpName}",
            'infografica_sottotitolo' => "Top 3 + Focus Ferrari ({$sessionName})",
            'twitter_modificato' => false,
        ];

        // Step 4: Render Dynamic Live Infographics (1080x1080 Square & 1080x1350 Portrait)
        $liveImages = generateLiveInfographic($telemetryData, 'both', [
            'title_override' => $title,
            'output_dir'     => $config['output_images_dir'] ?? (__DIR__ . '/output/images'),
        ]);

        $images['fb_image'] = $liveImages['square']['file_path'] ?? ($liveImages['square']['path'] ?? '');
        $images['ig_image'] = $liveImages['portrait']['file_path'] ?? ($liveImages['portrait']['path'] ?? '');

        // Step 5: Save Live Infographics to Google Drive (Folder ID: 1zDqtrdpLBxC7q_2kB42tZ9f9_eyABz5K)
        try {
            $driveSaved = saveLiveInfographicToDrive($liveImages, $config);
            $fbImageDrive = $driveSaved['uploads']['square'] ?? ($driveSaved['square'] ?? null);
            $igImageDrive = $driveSaved['uploads']['portrait'] ?? ($driveSaved['portrait'] ?? null);
        } catch (Throwable $e) {
            $driveErrors[] = 'Upload Live Infographics Drive: ' . $e->getMessage();
        }

    } else {
        // =========================================================================
        // BRANCH B: STANDARD ARTICLE / TEXT WORKFLOW
        // =========================================================================
        if (isValidUrl($rawInput)) {
            $extracted = extractTextFromUrl($rawInput);
            $sourceUrl = $extracted['source_url'];
            $title = $extracted['title'];
            $sourceText = $extracted['text'];
        } else {
            $sourceText = $rawInput;
            if (isValidUrl($articleUrlInput)) {
                $sourceUrl = resolveInputUrl($articleUrlInput);
            } elseif (preg_match('/https?:\/\/[^\s<"\']+/', $rawInput, $urlMatch)) {
                $sourceUrl = $urlMatch[0];
            } else {
                $sourceUrl = 'https://www.formulapaddock.it';
            }
            $firstSentence = preg_split('/(?<=[.!?])\s+/u', $rawInput)[0] ?? $rawInput;
            $title = mb_substr($firstSentence, 0, 80);
        }

        // Generate dynamic hashtags from article content
        $hashtagContext = [
            'title'   => $title,
            'text'    => $sourceText,
            'is_live' => false,
        ];
        $dynamicHashtags = generateDynamicHashtags($hashtagContext, 'default');

        // AI text generation
        $content = generateSocialContent($sourceText, $title, $config);

        // Render standard infographics
        $slug = 'post_' . date('Ymd_His') . '_' . substr(md5($title . microtime()), 0, 6);
        $articleImageForSocial = downloadArticleImageForReel($sourceUrl, $config);
        if ($articleImageForSocial && is_file($articleImageForSocial)) {
            $content['_article_image_url'] = $articleImageForSocial;
        }
        $images = generateAllInfographics($content, $slug, $config);

        // Upload standard infographics to Google Drive
        try {
            if (!empty($images['fb_image']) && file_exists($images['fb_image'])) {
                $fbImageDrive = uploadFileToDrive($images['fb_image'], 'image/jpeg', $config);
            }
        } catch (Throwable $e) {
            $driveErrors[] = 'Upload infografica Facebook: ' . $e->getMessage();
        }

        try {
            if (!empty($images['ig_image']) && file_exists($images['ig_image'])) {
                $igImageDrive = uploadFileToDrive($images['ig_image'], 'image/jpeg', $config);
            }
        } catch (Throwable $e) {
            $driveErrors[] = 'Upload infografica Instagram: ' . $e->getMessage();
        }
    }

    // =========================================================================
    // STEP 6: LOGGING SU GOOGLE SHEETS
    // =========================================================================
    $sheetError = null;
    try {
        appendRowToSheet([
            'data'               => date('Y-m-d H:i:s'),
            'facebook'           => $content['facebook'] ?? '',
            'twitter'            => $content['twitter'] ?? '',
            'linkedin'           => $content['linkedin'] ?? '',
            'instagram'          => $igImageDrive['view_link'] ?? ($content['infografica_titolo'] ?? 'Infografica F1'),
            'categoria'          => $content['categoria'] ?? 'F1',
            'img_evidenza'       => $fbImageDrive['view_link'] ?? '',
            'twitter_modificato' => $content['twitter_modificato'] ?? false,
            'link'               => $sourceUrl !== '' ? $sourceUrl : 'https://www.formulapaddock.it',
        ], $config);
    } catch (Throwable $e) {
        $sheetError = $e->getMessage();
    }

    // =========================================================================
    // STEP 7: AUTO-ENQUEUE PER ESTENSIONE CHROME GRUPPI FACEBOOK
    // =========================================================================
    if ($autoPublish && in_array('fb_groups', $selectedChannels)) {
        try {
            $fbGroupPostData = [
                'title'           => $title,
                'text'            => $content['facebook'] ?? $sourceText,
                'hashtags'        => $dynamicHashtags['tag_string'] ?? '',
                'full_caption'    => ($content['facebook'] ?? $sourceText),
                'article_url'     => $sourceUrl,
                'media_url'       => $images['fb_image'] ?? ($images['ig_image'] ?? ''),
                'media_drive_url' => $fbImageDrive['view_link'] ?? ($fbImageDrive['direct_link'] ?? ''),
                'media_type'      => $isLive ? 'infographic' : 'image',
                'category'        => $isLive ? 'LiveTiming' : ($content['categoria'] ?? 'F1News'),
            ];

            // Hybrid Queue persistence (MySQL or atomic JSON fallback)
            $queueResult = QueueManager::enqueue($fbGroupPostData, $config);
        } catch (Throwable $e) {
            $driveErrors[] = 'Accodamento Estensione Gruppi FB: ' . $e->getMessage();
        }
    }

    // =========================================================================
    // STEP 8: PUBBLICAZIONE DIRETTA SULLA PAGINA FACEBOOK (META GRAPH API)
    // =========================================================================
    // "buffer" resta un alias temporaneo per il vecchio checkbox dell'interfaccia.
    $facebookPageSelected = in_array('facebook_page', $selectedChannels, true)
        || in_array('buffer', $selectedChannels, true);
    if ($autoPublish && $facebookPageSelected) {
        require_once __DIR__ . '/includes/facebook_page_service.php';
        $linkToPublish = $sourceUrl !== '' ? $sourceUrl : null;

        if (!empty($content['facebook'])) {
            try {
                $facebookMessage = (string)$content['facebook'];
                // Pubblica un solo post immagine; il link resta nel primo commento.
                $facebookPhotoMessage = $facebookMessage;
                if ($linkToPublish !== null) {
                    $facebookPhotoMessage = trim(str_replace($linkToPublish, '', $facebookPhotoMessage));
                }
                $facebookImagePath = !empty($images['fb_image']) && is_file($images['fb_image'])
                    ? $images['fb_image']
                    : null;
                if ($facebookImagePath !== null) {
                    $resFbPhoto = publishToFacebookPage($facebookPhotoMessage, $linkToPublish, $config, $facebookImagePath);
                    $resultLabel = 'Infografica pubblicata';
                    if (!empty($resFbPhoto['comment_id'])) $resultLabel .= ' + link nel primo commento';
                    $facebookPageResults[] = 'Facebook Page (Meta API): ' . $resultLabel . ' — ID ' . $resFbPhoto['post_id'];
                    if (!empty($resFbPhoto['comment_error'])) {
                        $facebookPageErrors[] = 'Facebook: infografica pubblicata, ma commento con link non riuscito: ' . $resFbPhoto['comment_error'];
                    }
                } else {
                    $facebookPageErrors[] = 'Facebook: infografica non disponibile, nessun post pubblicato.';
                }
            } catch (Throwable $e) {
                $facebookPageErrors[] = 'Facebook Page API: ' . $e->getMessage();
            }
        }
    }

    // =========================================================================
    // STEP 8B: PUBBLICAZIONE TWITTER/X TRAMITE BUFFER
    // =========================================================================
    if ($autoPublish && (in_array('twitter', $selectedChannels, true) || in_array('x', $selectedChannels, true))) {
        require_once __DIR__ . '/includes/buffer_service.php';
        try {
            $twitterText = trim((string)($content['twitter'] ?? ''));
            if ($twitterText === '') {
                throw new Exception('Testo Twitter/X non generato.');
            }
            $twitterBufferResult = publishToBuffer($twitterText, 'twitter', null, $config);
        } catch (Throwable $e) {
            $twitterBufferError = $e->getMessage();
        }
    }

    // =========================================================================
    // STEP 9: BACKGROUND REEL CREATION & PUBLISHING
    // Usa l'immagine infografica generata (o un video caricato manualmente) per
    // creare il Reel MP4 9:16 in un processo PHP separato (background worker),
    // poi pubblica automaticamente su TikTok, Instagram Reels e Facebook Reels.
    // La pagina ritorna IMMEDIATAMENTE — il polling JS monitorizza lo stato.
    // =========================================================================
    $reelTargetPlatforms = [];
    if ($autoPublish) {
        foreach ($selectedChannels as $ch) {
            if ($ch === 'tiktok')         $reelTargetPlatforms[] = 'tiktok';
            if ($ch === 'instagram')      $reelTargetPlatforms[] = 'instagram';
            if ($ch === 'facebook_reels') $reelTargetPlatforms[] = 'facebook';
        }
    }

    // Determina l'immagine sorgente del reel:
    // 1) Video caricato manualmente (già MP4) → usa dispatchReelPublish
    // 2) Il Reel con le 3 scene dinamiche viene generato e pubblicato in automatico dal Generatore Nativo in pagina
    $uploadedVideoFile = null;
    if (!empty($_FILES['video']['tmp_name']) && file_exists($_FILES['video']['tmp_name'])) {
        $uploadedVideoFile = $_FILES['video']['tmp_name'];
    } elseif (!empty($_POST['video_path']) && file_exists($_POST['video_path'])) {
        $uploadedVideoFile = $_POST['video_path'];
    }

    $reelCaption = $title . "\n\n" . ($dynamicHashtags['tag_string'] ?? '#F1 #FormulaPaddock #Ferrari');

    if (!empty($reelTargetPlatforms) && $uploadedVideoFile) {
        try {
            // Pubblicazione diretta del video manuale se fornito
            $reelJobId   = dispatchReelPublish($uploadedVideoFile, $reelCaption, $reelTargetPlatforms, $config);
            $reelJobData = getReelPublishStatus($reelJobId, $config);
        } catch (Throwable $e) {
            $driveErrors[] = "Reel Dispatcher: " . $e->getMessage();
        }
    }

    // Native Reel Engine Target URL (Client-Side HTML5 Canvas con Box Affiancati)
    $reelTargetUrl = 'reel.php?init_url=' . urlencode($sourceUrl !== '' ? $sourceUrl : 'https://www.formulapaddock.it');

} catch (Throwable $e) {
    renderError($e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Workflow Social — FormulaPaddock</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-main: #0b0f19;
            --bg-card: #151926;
            --bg-card-alt: rgba(255, 255, 255, 0.04);
            --border-color: rgba(255, 255, 255, 0.12);
            --accent-red: #e10600;
            --accent-red-glow: rgba(225, 6, 0, 0.35);
            --accent-gold: #ffd100;
            --accent-green: #00c853;
            --accent-blue: #0091ea;
            --text-main: #ffffff;
            --text-muted: #9ba1b0;
        }

        * { box-sizing: border-box; }
        body {
            font-family: 'Montserrat', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: linear-gradient(145deg, #07090e 0%, #0b0f19 50%, #170d12 100%);
            color: var(--text-main);
            margin: 0;
            padding: 30px 20px;
        }
        .container { max-width: 1100px; margin: 0 auto; }
        h1 { font-size: 26px; font-weight: 900; margin-top: 0; }
        .accent { color: var(--accent-gold); }
        .accent-red { color: var(--accent-red); }

        /* REAL-TIME MONITOR WIDGET */
        .monitor-widget {
            background: #151926;
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 28px;
            box-shadow: 0 16px 48px rgba(0,0,0,0.5);
            position: relative;
            overflow: hidden;
        }
        .monitor-widget::before {
            content: "";
            position: absolute;
            top: 0; left: 0; right: 0; height: 3px;
            background: linear-gradient(90deg, var(--accent-red), var(--accent-gold), var(--accent-green));
        }
        .monitor-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }
        .monitor-title {
            font-size: 17px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .monitor-live-dot {
            width: 10px;
            height: 10px;
            background: var(--accent-green);
            border-radius: 50%;
            display: inline-block;
            box-shadow: 0 0 10px var(--accent-green);
            animation: pulse-green 1.5s infinite;
        }
        @keyframes pulse-green {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(0.7); opacity: 0.4; }
        }

        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(310px, 1fr));
            gap: 16px;
        }
        .status-card {
            background: var(--bg-card-alt);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 16px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 12px;
            transition: all 0.2s;
        }
        .status-card:hover {
            border-color: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }
        .card-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .platform-name {
            font-weight: 800;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .badge {
            font-size: 11px;
            font-weight: 800;
            padding: 4px 10px;
            border-radius: 999px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }
        .badge-success { background: rgba(0, 200, 83, 0.18); color: #00e676; border: 1px solid rgba(0, 200, 83, 0.4); }
        .badge-pending { background: rgba(255, 209, 0, 0.18); color: #ffd100; border: 1px solid rgba(255, 209, 0, 0.4); }
        .badge-processing { background: rgba(0, 145, 234, 0.18); color: #40c4ff; border: 1px solid rgba(0, 145, 234, 0.4); }
        .badge-idle { background: rgba(255, 255, 255, 0.08); color: #9ba1b0; border: 1px solid rgba(255, 255, 255, 0.15); }
        .badge-warn { background: rgba(225, 6, 0, 0.18); color: #ff5252; border: 1px solid rgba(225, 6, 0, 0.4); }

        .card-body-info {
            font-size: 12px;
            color: var(--text-muted);
            line-height: 1.5;
        }
        .card-body-info strong { color: #fff; }
        .card-footer-links {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            padding-top: 8px;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
        }
        .btn-mini {
            font-size: 11px;
            font-weight: 700;
            padding: 5px 10px;
            border-radius: 6px;
            background: rgba(255, 255, 255, 0.08);
            color: #fff;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: background 0.2s;
        }
        .btn-mini:hover { background: rgba(255, 255, 255, 0.16); }
        .btn-mini-drive {
            background: rgba(255, 209, 0, 0.15);
            color: var(--accent-gold);
            border: 1px solid rgba(255, 209, 0, 0.3);
        }
        .btn-mini-drive:hover { background: rgba(255, 209, 0, 0.25); }

        /* BANNER & SECTIONS */
        .cloud-banner {
            background: linear-gradient(90deg, #e10600 0%, #b30000 100%);
            border: 2px solid #ffd100;
            border-radius: 14px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 8px 32px rgba(225,6,0,0.4);
            text-align: center;
        }
        .cloud-banner h2 { margin: 0 0 10px 0; font-size: 22px; color: #fff; font-weight: 900; }
        .cloud-banner p { margin: 0 0 16px 0; font-size: 15px; color: #ffeb3b; }
        
        .btn-launch {
            display: inline-block;
            padding: 14px 28px;
            background: #ffd100;
            color: #111;
            font-weight: 900;
            font-size: 16px;
            border-radius: 8px;
            text-decoration: none;
            box-shadow: 0 4px 16px rgba(255,209,0,0.4);
            transition: transform 0.2s;
        }
        .btn-launch:hover { transform: scale(1.04); }

        .section {
            background: #151926;
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 22px;
            margin-bottom: 20px;
        }
        .section h2 { margin-top: 0; font-size: 16px; font-weight: 800; color: var(--accent-gold); }
        .section pre {
            white-space: pre-wrap;
            font-family: inherit;
            background: rgba(0,0,0,0.35);
            padding: 14px;
            border-radius: 8px;
            font-size: 13px;
            line-height: 1.6;
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
        img.infographic-preview {
            max-width: 100%;
            height: auto;
            border-radius: 8px;
            margin-top: 10px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        /* GRIGLIA BOX SOCIAL AFFIANCATI */
        .social-boxes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }
        .social-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 18px 20px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: transform 0.2s, border-color 0.2s;
        }
        .social-card:hover {
            border-color: rgba(255, 209, 0, 0.4);
            transform: translateY(-2px);
        }
        .social-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }
        .social-card-title {
            font-size: 14px;
            font-weight: 800;
            color: var(--accent-gold);
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0;
        }
        .social-card pre {
            white-space: pre-wrap;
            font-family: inherit;
            background: rgba(0, 0, 0, 0.4);
            padding: 12px 14px;
            border-radius: 8px;
            font-size: 12.5px;
            line-height: 1.55;
            border: 1px solid rgba(255, 255, 255, 0.05);
            margin: 0 0 12px 0;
            max-height: 220px;
            overflow-y: auto;
            flex-grow: 1;
        }
        .btn-copy {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            padding: 5px 12px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
            align-self: flex-end;
        }
        .btn-copy:hover {
            background: var(--accent-gold);
            color: #111;
        }

        .grid-images { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .warn { background: rgba(180,40,40,0.2); border: 1px solid #b33; padding: 12px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; }
        .ok { background: rgba(40,160,80,0.15); border: 1px solid #2a8; padding: 12px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; }
        .back-link { color: var(--accent-gold); text-decoration: none; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; }
        .back-link:hover { text-decoration: underline; }

        .reel-iframe-box {
            width: 100%;
            height: 720px;
            border: 2px solid rgba(225, 6, 0, 0.6);
            border-radius: 12px;
            overflow: hidden;
            background: #000;
            margin-top: 12px;
        }
        .reel-iframe-box iframe { width: 100%; height: 100%; border: none; }

        /* ── BACKGROUND REEL CREATION WIDGET ── */
        .bg-reel-widget {
            background: #0f1520;
            border: 1px solid rgba(225, 6, 0, 0.3);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 28px;
            position: relative;
            overflow: hidden;
        }
        .bg-reel-widget::before {
            content: "";
            position: absolute;
            top: 0; left: 0; right: 0; height: 3px;
            background: linear-gradient(90deg, #e10600, #ffd100);
        }
        .bg-reel-header {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 18px;
        }
        .bg-reel-title { font-size: 16px; font-weight: 800; display: flex; align-items: center; gap: 10px; }
        .bg-reel-badge {
            font-size: 11px; font-weight: 700; padding: 4px 10px;
            border-radius: 20px; letter-spacing: 0.5px;
        }
        .bg-reel-badge.queued    { background: rgba(100,100,100,0.25); color: #9ba1b0; }
        .bg-reel-badge.rendering { background: rgba(0, 145, 234, 0.2); color: #29b6f6; }
        .bg-reel-badge.rendered  { background: rgba(255, 209, 0, 0.2); color: #ffd100; }
        .bg-reel-badge.publishing{ background: rgba(255, 145, 0, 0.2); color: #ff9800; }
        .bg-reel-badge.completed { background: rgba(0, 200, 83, 0.2); color: #00c853; }
        .bg-reel-badge.partial   { background: rgba(255, 152, 0, 0.2); color: #ffa726; }
        .bg-reel-badge.failed    { background: rgba(225, 6, 0, 0.2); color: #e10600; }
        .progress-track {
            width: 100%; height: 8px; background: rgba(255,255,255,0.08);
            border-radius: 4px; overflow: hidden; margin-bottom: 16px;
        }
        .progress-fill {
            height: 100%;
            border-radius: 4px;
            background: linear-gradient(90deg, #e10600, #ffd100);
            transition: width 0.8s ease;
            width: 5%;
        }
        .reel-phases {
            display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap;
        }
        .reel-phase {
            padding: 5px 12px; border-radius: 20px;
            font-size: 11px; font-weight: 700;
            border: 1px solid rgba(255,255,255,0.1);
            color: var(--text-muted);
            background: rgba(255,255,255,0.04);
            transition: all 0.3s;
        }
        .reel-phase.active   { background: rgba(0,145,234,0.2); border-color: #29b6f6; color: #29b6f6; }
        .reel-phase.done     { background: rgba(0,200,83,0.18); border-color: #00c853; color: #00c853; }
        .reel-phase.error    { background: rgba(225,6,0,0.2);   border-color: #e10600; color: #e10600; }
        .reel-platform-row {
            display: flex; gap: 10px; margin-top: 10px; flex-wrap: wrap;
        }
        .reel-plat-pill {
            display: flex; align-items: center; gap: 6px;
            padding: 6px 14px; border-radius: 20px;
            font-size: 12px; font-weight: 700;
            border: 1px solid rgba(255,255,255,0.1);
            background: rgba(255,255,255,0.04);
            color: var(--text-muted);
            transition: all 0.3s;
        }
        .reel-plat-pill.pending    { color: #9ba1b0; }
        .reel-plat-pill.publishing { background: rgba(0,145,234,0.15); border-color: #29b6f6; color: #29b6f6; }
        .reel-plat-pill.published  { background: rgba(0,200,83,0.15); border-color: #00c853; color: #00c853; }
        .reel-plat-pill.failed     { background: rgba(225,6,0,0.15); border-color: #e10600; color: #e10600; }
        .reel-log-box {
            background: rgba(0,0,0,0.4);
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 8px; padding: 10px 14px;
            font-family: monospace; font-size: 11px;
            color: #7a8a9e; max-height: 120px; overflow-y: auto;
            margin-top: 14px; line-height: 1.5;
        }
        .reel-job-id {
            font-size: 10px; color: #5a6272; font-family: monospace;
            margin-top: 8px; word-break: break-all;
        }

        @media (max-width: 768px) {
            .grid-images { grid-template-columns: 1fr; }
            .cards-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="container">
    <h1>✅ Workflow Social FormulaPaddock: <span class="accent"><?= htmlspecialchars($title) ?></span></h1>

    <!-- ===================================================================== -->
    <!-- BACKGROUND REEL CREATION WIDGET                                        -->
    <!-- ===================================================================== -->
    <?php if ($bgReelJobId || $reelJobId): ?>
    <div class="bg-reel-widget" id="bgReelWidget">
        <div class="bg-reel-header">
            <div class="bg-reel-title">
                🎬 <span>Reel 9:16 — Creazione Background</span>
            </div>
            <span class="bg-reel-badge queued" id="bgReelBadge">⏳ QUEUED</span>
        </div>

        <!-- Progress Bar -->
        <div class="progress-track">
            <div class="progress-fill" id="bgReelProgress" style="width:5%"></div>
        </div>

        <!-- Fasi -->
        <div class="reel-phases">
            <span class="reel-phase" id="phaseQueue">📋 In coda</span>
            <span class="reel-phase" id="phaseRender">🎞️ Rendering</span>
            <span class="reel-phase" id="phaseUpload">☁️ Drive</span>
            <span class="reel-phase" id="phasePublish">📤 Pubblica</span>
            <span class="reel-phase" id="phaseDone">✅ Completato</span>
        </div>

        <!-- Piattaforme -->
        <div class="reel-platform-row" id="bgReelPlatforms">
            <?php
            $bgJobPlatforms = [];
            if ($bgReelJobId) {
                $tmpMgr = new ReelJobManager($config);
                $tmpJob = $tmpMgr->loadJob($bgReelJobId);
                $bgJobPlatforms = $tmpJob['platforms'] ?? ['tiktok', 'instagram', 'facebook'];
            }
            $platIcons = ['tiktok' => '🎵', 'instagram' => '📸', 'facebook' => '👥'];
            foreach ($bgJobPlatforms as $p): ?>
                <div class="reel-plat-pill pending" id="bgPlat_<?= htmlspecialchars($p) ?>">
                    <?= $platIcons[$p] ?? '🌐' ?> <?= ucfirst($p) ?>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Log render -->
        <div class="reel-log-box" id="bgReelLog">⏳ Worker avviato — in attesa del rendering...</div>
        <div class="reel-job-id">Job ID: <?= htmlspecialchars($bgReelJobId ?? $reelJobId ?? '') ?></div>
    </div>
    <?php endif; ?>

    <!-- ===================================================================== -->
    <!-- REAL-TIME PUBLISHING MONITOR WIDGET                                   -->
    <!-- ===================================================================== -->
    <div class="monitor-widget" id="publishingMonitor">
        <div class="monitor-header">
            <div class="monitor-title">
                <span class="monitor-live-dot"></span>
                <span>Real-Time Publishing Monitor Widget</span>
            </div>
            <div style="font-size: 12px; color: var(--text-muted);">
                <span>Auto-refresh: <strong style="color: var(--accent-gold);" id="pollStatus">Attivo (3s)</strong></span>
            </div>
        </div>

        <div class="cards-grid">
            <!-- TIKTOK REELS -->
            <div class="status-card" id="cardTikTok">
                <div class="card-top">
                    <span class="platform-name">🎵 TikTok Reels</span>
                    <span class="badge <?= in_array('tiktok', $selectedChannels) ? 'badge-success' : 'badge-idle' ?>" id="badgeTikTok">
                        <?= in_array('tiktok', $selectedChannels) ? '🟢 Abilitato' : '⚪ Escluso' ?>
                    </span>
                </div>
                <div class="card-body-info" id="infoTikTok">
                    <div>API: <strong>Buffer — TikTok automatico</strong></div>
                    <div>Stato: <strong id="stateTikTok"><?= in_array('tiktok', $selectedChannels) ? 'Pronto / In attesa Reel' : 'Non selezionato' ?></strong></div>
                    <div>Timestamp: <strong><?= date('Y-m-d H:i:s') ?></strong></div>
                </div>
                <div class="card-footer-links">
                    <a href="https://drive.google.com/drive/folders/1zDqtrdpLBxC7q_2kB42tZ9f9_eyABz5K" target="_blank" class="btn-mini btn-mini-drive">📁 Drive Creatività</a>
                </div>
            </div>

            <!-- INSTAGRAM REELS -->
            <div class="status-card" id="cardInstagram">
                <div class="card-top">
                    <span class="platform-name">📸 Instagram Reels</span>
                    <span class="badge <?= in_array('instagram', $selectedChannels) ? 'badge-success' : 'badge-idle' ?>" id="badgeInstagram">
                        <?= in_array('instagram', $selectedChannels) ? '🟢 Abilitato' : '⚪ Escluso' ?>
                    </span>
                </div>
                <div class="card-body-info" id="infoInstagram">
                    <div>API: <strong>Graph API Reels Container</strong></div>
                    <div>Stato: <strong id="stateInstagram"><?= in_array('instagram', $selectedChannels) ? 'Pronto / In attesa Reel' : 'Non selezionato' ?></strong></div>
                    <div>Timestamp: <strong><?= date('Y-m-d H:i:s') ?></strong></div>
                </div>
                <div class="card-footer-links">
                    <?php if ($igImageDrive): ?>
                        <a href="<?= htmlspecialchars($igImageDrive['view_link']) ?>" target="_blank" class="btn-mini btn-mini-drive">🖼️ Img Drive</a>
                    <?php endif; ?>
                    <a href="https://drive.google.com/drive/folders/1zDqtrdpLBxC7q_2kB42tZ9f9_eyABz5K" target="_blank" class="btn-mini btn-mini-drive">📁 Drive Creatività</a>
                </div>
            </div>

            <!-- FACEBOOK REELS -->
            <div class="status-card" id="cardFbReels">
                <div class="card-top">
                    <span class="platform-name">👥 Facebook Reels</span>
                    <span class="badge <?= in_array('facebook_reels', $selectedChannels) ? 'badge-success' : 'badge-idle' ?>" id="badgeFbReels">
                        <?= in_array('facebook_reels', $selectedChannels) ? '🟢 Abilitato' : '⚪ Escluso' ?>
                    </span>
                </div>
                <div class="card-body-info" id="infoFbReels">
                    <div>API: <strong>Meta Video Reels API</strong></div>
                    <div>Stato: <strong id="stateFbReels"><?= in_array('facebook_reels', $selectedChannels) ? 'Pronto / In attesa Reel' : 'Non selezionato' ?></strong></div>
                    <div>Timestamp: <strong><?= date('Y-m-d H:i:s') ?></strong></div>
                </div>
                <div class="card-footer-links">
                    <a href="https://drive.google.com/drive/folders/1zDqtrdpLBxC7q_2kB42tZ9f9_eyABz5K" target="_blank" class="btn-mini btn-mini-drive">📁 Drive Creatività</a>
                </div>
            </div>

            <!-- FACEBOOK PAGE DIRETTA (META GRAPH API) -->
            <div class="status-card" id="cardBuffer">
                <div class="card-top">
                    <span class="platform-name">📘 Facebook Page (Meta API)</span>
                    <span class="badge <?= (!empty($facebookPageResults)) ? 'badge-success' : ($facebookPageSelected ? 'badge-processing' : 'badge-idle') ?>" id="badgeBuffer">
                        <?= (!empty($facebookPageResults)) ? '🟢 Pubblicato' : ($facebookPageSelected ? '🟡 Inviato' : '⚪ Escluso') ?>
                    </span>
                </div>
                <div class="card-body-info" id="infoBuffer">
                    <div>Canale: <strong>Pagina Facebook diretta</strong></div>
                    <div>Risultato: <strong><?= !empty($facebookPageResults) ? 'Post normale + infografica separata' : 'In attesa pubblicazione' ?></strong></div>
                    <div>Timestamp: <strong><?= date('Y-m-d H:i:s') ?></strong></div>
                </div>
                <div class="card-footer-links">
                    <?php if ($fbImageDrive): ?>
                        <a href="<?= htmlspecialchars($fbImageDrive['view_link']) ?>" target="_blank" class="btn-mini btn-mini-drive">🖼️ Img Drive</a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- TWITTER / X VIA BUFFER -->
            <div class="status-card" id="cardTwitter">
                <div class="card-top">
                    <span class="platform-name">🐦 Twitter / X</span>
                    <span class="badge <?= $twitterBufferResult ? 'badge-success' : ($twitterBufferError ? 'badge-processing' : 'badge-idle') ?>">
                        <?= $twitterBufferResult ? '🟢 Inviato a Buffer' : ($twitterBufferError ? '🟡 Errore Buffer' : '⚪ Escluso') ?>
                    </span>
                </div>
                <div class="card-body-info">
                    <div>API: <strong>Buffer — Twitter/X</strong></div>
                    <div>Stato: <strong><?= htmlspecialchars($twitterBufferResult['status'] ?? ($twitterBufferError ?: 'Non selezionato')) ?></strong></div>
                    <?php if ($twitterBufferResult): ?>
                        <div>Post ID: <strong><?= htmlspecialchars($twitterBufferResult['id'] ?? '') ?></strong></div>
                    <?php endif; ?>
                    <div>Timestamp: <strong><?= date('Y-m-d H:i:s') ?></strong></div>
                </div>
            </div>

            <!-- CHROME EXTENSION GRUPPI FB -->
            <div class="status-card" id="cardFbGroups">
                <div class="card-top">
                    <span class="platform-name">🧩 Estensione Gruppi FB</span>
                    <span class="badge <?= ($queueResult && ($queueResult['success'] ?? false)) ? 'badge-success' : (in_array('fb_groups', $selectedChannels) ? 'badge-processing' : 'badge-idle') ?>" id="badgeFbGroups">
                        <?= ($queueResult && ($queueResult['success'] ?? false)) ? '🟢 Accodato (REST)' : (in_array('fb_groups', $selectedChannels) ? '🟡 Coda Inizializzata' : '⚪ Escluso') ?>
                    </span>
                </div>
                <div class="card-body-info" id="infoFbGroups">
                    <div>Post ID: <strong id="queuePostId"><?= htmlspecialchars($queueResult['id'] ?? $queueResult['post_id'] ?? 'post_' . date('Ymd_His')) ?></strong></div>
                    <div>Stato Coda: <strong id="queuePostState"><?= htmlspecialchars($queueResult['status'] ?? (in_array('fb_groups', $selectedChannels) ? 'pending' : 'idle')) ?></strong></div>
                    <div>Deduplicazione: <strong><?= (!empty($queueResult['duplicate'])) ? 'Deduplicato' : 'Nuovo Post' ?></strong></div>
                </div>
                <div class="card-footer-links">
                    <a href="api/pending_posts.php?mode=single&token=f1_paddock_ext_sec_99a8b7c6d5e4" target="_blank" class="btn-mini">📡 API Endpoint</a>
                    <?php if ($fbImageDrive): ?>
                        <a href="<?= htmlspecialchars($fbImageDrive['view_link']) ?>" target="_blank" class="btn-mini btn-mini-drive">🖼️ Img Drive</a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- THREADS -->
            <div class="status-card" id="cardThreads">
                <div class="card-top">
                    <span class="platform-name">🧵 Threads</span>
                    <span class="badge <?= in_array('threads', $selectedChannels) ? 'badge-success' : 'badge-idle' ?>" id="badgeThreads">
                        <?= in_array('threads', $selectedChannels) ? '🟢 Testo Pronto' : '⚪ Escluso' ?>
                    </span>
                </div>
                <div class="card-body-info" id="infoThreads">
                    <div>Tipo: <strong>Post + Hashtag</strong></div>
                    <div>Stato: <strong><?= in_array('threads', $selectedChannels) ? 'Pronto per pubblicazione' : 'Escluso' ?></strong></div>
                    <div>Timestamp: <strong><?= date('Y-m-d H:i:s') ?></strong></div>
                </div>
                <div class="card-footer-links">
                    <a href="https://drive.google.com/drive/folders/1zDqtrdpLBxC7q_2kB42tZ9f9_eyABz5K" target="_blank" class="btn-mini btn-mini-drive">📁 Drive Creatività</a>
                </div>
            </div>
        </div>
    </div>

    <?php if ($sheetError): ?>
        <div class="warn">⚠️ Riga NON scritta su Google Sheet: <?= htmlspecialchars($sheetError) ?></div>
    <?php else: ?>
        <div class="ok">✅ Riga e post pubblicati / scritti su Google Sheet.</div>
    <?php endif; ?>

    <?php foreach ($facebookPageResults as $br): ?>
        <div class="ok">🚀 <?= htmlspecialchars($br) ?></div>
    <?php endforeach; ?>

    <?php foreach ($facebookPageErrors as $be): ?>
        <div class="warn">⚠️ <?= htmlspecialchars($be) ?></div>
    <?php endforeach; ?>

    <?php if ($twitterBufferResult): ?>
        <div class="ok">🐦 Twitter/X inviato a Buffer — ID <?= htmlspecialchars($twitterBufferResult['id'] ?? '') ?></div>
    <?php elseif ($twitterBufferError): ?>
        <div class="warn">⚠️ Twitter/X Buffer: <?= htmlspecialchars($twitterBufferError) ?></div>
    <?php endif; ?>

    <?php foreach ($driveErrors as $de): ?>
        <div class="warn">⚠️ <?= htmlspecialchars($de) ?></div>
    <?php endforeach; ?>

    <!-- ===================================================================== -->
    <!-- 1°: SEZIONE BOX TESTI SOCIAL GENERATI AFFIANCATI                       -->
    <!-- ===================================================================== -->
    <div style="margin-top: 24px; margin-bottom: 24px;">
        <h2 style="font-size: 20px; font-weight: 800; color: #fff; display: flex; align-items: center; gap: 10px; margin-bottom: 16px;">
            <span style="color:var(--accent-gold);">📱</span> 1. Testi Social & Hashtag Generati (Affiancati)
        </h2>

        <div class="social-boxes-grid">
            <!-- BOX 1: FACEBOOK -->
            <div class="social-card">
                <div class="social-card-header">
                    <h3 class="social-card-title">📘 Facebook & Gruppi FB</h3>
                    <button type="button" class="btn-copy" onclick="copyBoxText('fbText', this)">📋 Copia</button>
                </div>
                <pre id="fbText"><?= htmlspecialchars($content['facebook'] ?? '') ?></pre>
            </div>

            <!-- BOX 2: TWITTER / X -->
            <div class="social-card">
                <div class="social-card-header">
                    <h3 class="social-card-title">🐦 Twitter / X & Threads</h3>
                    <button type="button" class="btn-copy" onclick="copyBoxText('twText', this)">📋 Copia</button>
                </div>
                <pre id="twText"><?= htmlspecialchars($content['twitter'] ?? '') ?></pre>
            </div>

            <!-- BOX 3: LINKEDIN -->
            <div class="social-card">
                <div class="social-card-header">
                    <h3 class="social-card-title">💼 LinkedIn</h3>
                    <button type="button" class="btn-copy" onclick="copyBoxText('liText', this)">📋 Copia</button>
                </div>
                <pre id="liText"><?= htmlspecialchars($content['linkedin'] ?? '') ?></pre>
            </div>

            <!-- BOX 4: HASHTAGS -->
            <div class="social-card">
                <div class="social-card-header">
                    <h3 class="social-card-title">#️⃣ Hashtags 5-Level Engine</h3>
                    <button type="button" class="btn-copy" onclick="copyBoxText('htText', this)">📋 Copia</button>
                </div>
                <pre id="htText"><?= htmlspecialchars($dynamicHashtags['tag_string'] ?? '') ?></pre>
            </div>

            <!-- BOX 5: ARTICOLO DI ORIGINE -->
            <?php if ($sourceUrl): ?>
            <div class="social-card">
                <div class="social-card-header">
                    <h3 class="social-card-title">🔗 Articolo di Origine</h3>
                    <button type="button" class="btn-copy" onclick="copyBoxText('srcUrlText', this)">📋 Copia Link</button>
                </div>
                <pre id="srcUrlText"><?= htmlspecialchars($sourceUrl) ?></pre>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===================================================================== -->
    <!-- 2°: SEZIONE INFOGRAFICHE DINAMICHE                                     -->
    <!-- ===================================================================== -->
    <div class="section" style="margin-bottom: 24px;">
        <h2 style="font-size: 18px; font-weight: 800; color: var(--accent-gold); margin-bottom: 16px;">
            📊 2. Infografiche <?= $isLive ? 'Live Timing 1080x1080 & 1080x1350' : 'Social Generate' ?>
        </h2>
        <div class="grid-images">
            <div>
                <h3 style="font-size: 14px; color: #fff;">🖼️ Formato Square (1080x1080) - Facebook / Feed</h3>
                <?php if (!empty($images['fb_image']) && file_exists($images['fb_image'])): ?>
                    <img class="infographic-preview" src="output/images/<?= htmlspecialchars(basename($images['fb_image'])) ?>" alt="Infografica Square">
                <?php endif; ?>
                <?php if ($fbImageDrive): ?>
                    <br><a class="btn-mini btn-mini-drive" style="margin-top: 10px;" href="<?= htmlspecialchars($fbImageDrive['view_link']) ?>" target="_blank">🔗 Apri Immagine su Google Drive</a>
                <?php endif; ?>
            </div>
            <div>
                <h3 style="font-size: 14px; color: #fff;">📸 Formato Portrait (1080x1350) - Instagram / Storie</h3>
                <?php if (!empty($images['ig_image']) && file_exists($images['ig_image'])): ?>
                    <img class="infographic-preview" src="output/images/<?= htmlspecialchars(basename($images['ig_image'])) ?>" alt="Infografica Portrait">
                <?php endif; ?>
                <?php if ($igImageDrive): ?>
                    <br><a class="btn-mini btn-mini-drive" style="margin-top: 10px;" href="<?= htmlspecialchars($igImageDrive['view_link']) ?>" target="_blank">🔗 Apri Immagine su Google Drive</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ===================================================================== -->
    <!-- 3°: SEZIONE REEL ENGINE 9:16 NATIVO                                    -->
    <!-- ===================================================================== -->
    <div class="cloud-banner">
        <h2>🎬 3. REEL ENGINE 9:16 NATIVO — PRONTO PER QUESTA NOTIZIA</h2>
        <p>Genera il Reel 1080x1920 con 3 scritte BOOM affiancate (una per ogni foto) e grafica TV Breaking News:</p>
        <a class="btn-launch" href="<?= htmlspecialchars($reelTargetUrl) ?>" target="_blank">🚀 APRI REEL ENGINE A SCHERMO INTERO &rarr;</a>
    </div>

    <div class="section" style="border: 2px solid var(--accent-red); background: #0c0e14; margin-bottom: 24px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <h2 style="margin:0; font-size:18px; color:#fff; display:flex; align-items:center; gap:8px;">
                <span style="color:var(--accent-red);">🎬</span> Generatore Reel F1 9:16 (Nativo & Box Affiancati)
            </h2>
            <a href="<?= htmlspecialchars($reelTargetUrl) ?>" target="_blank" style="font-size:12px; color:var(--accent-gold); text-decoration:none; font-weight:800; border:1px solid rgba(255,209,0,0.3); padding:5px 12px; border-radius:6px; background:rgba(255,209,0,0.08);">
                ↗ Apri Scheda Intera
            </a>
        </div>
        <p style="font-size:13px; color:var(--text-muted); margin-bottom:14px;">
            I 3 box di testo sono affiancati con l'articolo già caricato! Clicca su <strong>⚡ Genera Video Reel 9:16</strong> per renderizzare e scaricare direttamente l'MP4.
        </p>

        <div class="reel-iframe-box" style="height: 640px; border-color: rgba(225, 6, 0, 0.4);">
            <iframe src="<?= htmlspecialchars($reelTargetUrl) ?>" title="Reel Engine 9:16 FormulaPaddock" allow="autoplay; microphone; camera; display-capture"></iframe>
        </div>
    </div>

    <p style="margin-top: 30px;">
        <a class="back-link" href="index.php">&larr; Torna al Generatore Social FormulaPaddock</a>
    </p>
</div>

<!-- AJAX REAL-TIME POLLING SCRIPT -->
<script>
    const reelJobId = <?= json_encode($reelJobId) ?>;
    const queuePostId = <?= json_encode($queueResult['id'] ?? $queueResult['post_id'] ?? null) ?>;
    const extToken = 'f1_paddock_ext_sec_99a8b7c6d5e4';

    function pollStatus() {
        // 1. Polling legacy Reel Publishing Status
        if (reelJobId) {
            fetch('api/publish_reel_status.php?job_id=' + encodeURIComponent(reelJobId))
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.results) {
                        if (data.results.tiktok)   updatePlatformStatus('TikTok',   data.results.tiktok);
                        if (data.results.instagram) updatePlatformStatus('Instagram', data.results.instagram);
                        if (data.results.facebook)  updatePlatformStatus('FbReels',  data.results.facebook);
                    }
                })
                .catch(err => console.log('Reel poll error:', err));
        }

        // 2. Polling Chrome Extension Queue Status
        fetch('api/pending_posts.php?mode=single&token=' + encodeURIComponent(extToken))
            .then(res => res.json())
            .then(data => {
                if (data.success && data.post) {
                    const post = data.post;
                    const badge = document.getElementById('badgeFbGroups');
                    const stateElem = document.getElementById('queuePostState');
                    if (!badge || !stateElem) return;
                    if (post.status === 'processing') {
                        badge.className = 'badge badge-processing';
                        badge.textContent = '🔵 In Elaborazione (Ext)';
                        stateElem.textContent = 'processing (claimed by Chrome)';
                    } else if (post.status === 'published') {
                        badge.className = 'badge badge-success';
                        badge.textContent = '🟢 Pubblicato nei Gruppi';
                        stateElem.textContent = 'published';
                    }
                }
            })
            .catch(err => console.log('Queue poll error:', err));
    }

    function updatePlatformStatus(prefix, result) {
        const badge = document.getElementById('badge' + prefix);
        const state = document.getElementById('state' + prefix);
        if (!badge || !state) return;

        if (result.status === 'published') {
            badge.className = 'badge badge-success';
            badge.textContent = '🟢 Pubblicato';
            state.textContent = 'Pubblicato (ID: ' + (result.publish_id || 'OK') + ')';
        } else if (result.status === 'publishing' || result.status === 'processing') {
            badge.className = 'badge badge-processing';
            badge.textContent = '🔵 In Caricamento...';
            state.textContent = 'Upload in corso';
        } else if (result.status === 'failed') {
            badge.className = 'badge badge-warn';
            badge.textContent = '🔴 Errore';
            state.textContent = result.message || result.error || 'Errore di pubblicazione';
        }
    }

    // ── Background Reel Job Polling ──────────────────────────────────────────
    const bgReelJobId    = <?= json_encode($bgReelJobId) ?>;
    let   bgReelComplete = false;

    const BG_STATUS_LABELS = {
        queued: '⏳ QUEUED', rendering: '🎞️ RENDERING...', rendered: '☁️ DRIVE UPLOAD',
        publishing: '📤 PUBLISHING...', completed: '✅ COMPLETATO',
        partial: '🟡 PARZIALE', failed: '❌ ERRORE',
    };

    function activateBgPhase(status) {
        const doneMap = {
            queued:    ['phaseQueue'],
            rendering: ['phaseQueue'],
            rendered:  ['phaseQueue','phaseRender'],
            publishing:['phaseQueue','phaseRender','phaseUpload'],
            completed: ['phaseQueue','phaseRender','phaseUpload','phasePublish','phaseDone'],
            partial:   ['phaseQueue','phaseRender','phaseUpload','phasePublish','phaseDone'],
        };
        const allPhases = ['phaseQueue','phaseRender','phaseUpload','phasePublish','phaseDone'];
        const done = doneMap[status] || [];
        allPhases.forEach(id => {
            const el = document.getElementById(id);
            if (!el) return;
            el.className = 'reel-phase' + (done.includes(id) ? ' done' : '');
        });
        if (status === 'rendering')  { const el = document.getElementById('phaseRender');  if (el) el.className = 'reel-phase active'; }
        if (status === 'rendered')   { const el = document.getElementById('phaseUpload');  if (el) el.className = 'reel-phase active'; }
        if (status === 'publishing') { const el = document.getElementById('phasePublish'); if (el) el.className = 'reel-phase active'; }
        if (status === 'failed')     { const el = document.getElementById('phaseRender');  if (el) el.className = 'reel-phase error'; }
    }

    function pollBgReelJob() {
        if (!bgReelJobId || bgReelComplete) return;
        fetch('api/reel_job_status.php?job_id=' + encodeURIComponent(bgReelJobId))
            .then(r => r.json())
            .then(data => {
                if (!data.success) return;
                const status   = data.status   || 'queued';
                const progress = data.progress || 5;
                const results  = data.publish_results || {};
                const logLines = data.render_log || [];

                // Progress bar
                const fill = document.getElementById('bgReelProgress');
                if (fill) fill.style.width = Math.max(5, progress) + '%';

                // Badge
                const badge = document.getElementById('bgReelBadge');
                if (badge) {
                    badge.textContent = BG_STATUS_LABELS[status] || status.toUpperCase();
                    badge.className   = 'bg-reel-badge ' + (status in BG_STATUS_LABELS ? status : 'queued');
                }

                activateBgPhase(status);

                // Platform pills + monitor cards
                const platMap = {tiktok: 'TikTok', instagram: 'Instagram', facebook: 'FbReels'};
                const icons   = {tiktok:'🎵', instagram:'📸', facebook:'👥'};
                Object.keys(results).forEach(p => {
                    const st   = results[p].status || 'pending';
                    const pill = document.getElementById('bgPlat_' + p);
                    if (pill) {
                        pill.className   = 'reel-plat-pill ' + st;
                        pill.textContent = (icons[p]||'🌐') + ' ' + (st==='published'?p+' ✓':st==='failed'?p+' ✗':p);
                    }
                    if (platMap[p]) updatePlatformStatus(platMap[p], results[p]);
                });

                // Log box
                const logBox = document.getElementById('bgReelLog');
                if (logBox && logLines.length) {
                    logBox.textContent = logLines.join('\n');
                    logBox.scrollTop   = logBox.scrollHeight;
                }

                // Stop polling when terminal
                if (['completed','partial','failed'].includes(status)) {
                    bgReelComplete = true;
                    const pollEl = document.getElementById('pollStatus');
                    if (pollEl) pollEl.textContent = status === 'completed'
                        ? 'Completato ✅ — polling fermato'
                        : 'Terminato (' + status + ') — polling fermato';
                }
            })
            .catch(err => console.warn('[BgReel poll]', err));
    }

    // Main polling loop (legacy + bg reel + queue)
    function pollAll() {
        pollStatus();
        pollBgReelJob();
    }

    // Clipboard copy helper per box social affiancati
    function copyBoxText(id, btn) {
        const el = document.getElementById(id);
        if (!el) return;
        const text = el.innerText || el.textContent;
        navigator.clipboard.writeText(text).then(() => {
            const orig = btn.innerHTML;
            btn.innerHTML = '✅ Copiato!';
            btn.style.background = 'var(--accent-green)';
            btn.style.color = '#fff';
            setTimeout(() => {
                btn.innerHTML = orig;
                btn.style.background = '';
                btn.style.color = '';
            }, 1800);
        });
    }

    setInterval(pollAll, 3000);
    pollAll(); // immediate first call
</script>
</body>
</html>
