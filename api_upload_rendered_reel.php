<?php
/**
 * upload_rendered_reel.php — Riceve il video MP4 renderizzato dal Client (HTML5 Canvas)
 * con le 3 scritte BOOM e lo inoltra a TikTok, Instagram Reels e Facebook Reels.
 */

declare(strict_types=1);
ini_set('max_execution_time', '300');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../includes/reel_publisher.php';
require_once __DIR__ . '/../includes/google_service.php';
$config = file_exists(__DIR__ . '/../config.php') ? require __DIR__ . '/../config.php' : [];

try {
    // 1. Verifica ricezione file video
    $videoBytes = null;
    $ext = 'mp4';

    if (!empty($_FILES['video']['tmp_name']) && is_uploaded_file($_FILES['video']['tmp_name'])) {
        $videoBytes = file_get_contents($_FILES['video']['tmp_name']);
    } elseif (!empty($_POST['video_base64'])) {
        $raw = preg_replace('/^data:video\/[a-zA-Z0-9]+;base64,/', '', $_POST['video_base64']);
        $videoBytes = base64_decode($raw);
    } else {
        $rawInput = file_get_contents('php://input');
        if (!empty($rawInput) && strlen($rawInput) > 1000) {
            $videoBytes = $rawInput;
        }
    }

    if (empty($videoBytes) || strlen($videoBytes) < 5000) {
        throw new Exception("File video non ricevuto o dimensione non valida (" . strlen((string)$videoBytes) . " bytes).");
    }

    // 2. Salva il file video in output/reels/
    $outDir = rtrim($config['output_reels_dir'] ?? (__DIR__ . '/../output/reels'), '/\\');
    if (!is_dir($outDir)) {
        @mkdir($outDir, 0777, true);
    }

    $filename = 'reel_boom_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(3)), 0, 6) . '.' . $ext;
    $filePath = $outDir . DIRECTORY_SEPARATOR . $filename;
    
    if (!file_put_contents($filePath, $videoBytes)) {
        throw new Exception("Impossibile salvare il video su disco.");
    }

    // 3. Caption e Piattaforme
    $title = trim((string)($_POST['title'] ?? ($_GET['title'] ?? 'Formula Paddock Reel')));
    $caption = trim((string)($_POST['caption'] ?? ($_GET['caption'] ?? '')));
    if (empty($caption)) {
        $caption = $title . "\n\n#F1 #Formula1 #FormulaPaddock #Motorsport";
    }

    $rawPlats = $_POST['platforms'] ?? ['tiktok', 'instagram', 'facebook'];
    if (is_string($rawPlats)) {
        $platforms = json_decode($rawPlats, true) ?: explode(',', $rawPlats);
    } else {
        $platforms = (array)$rawPlats;
    }

    // 4. Carica su Google Drive se configurato
    $driveResult = null;
    try {
        $driveResult = uploadFileToDrive($filePath, 'video/mp4', $config);
    } catch (Throwable $e) {}

    // 5. Dispatch pubblicazione su TikTok, IG Reels e FB Reels
    $jobId = dispatchReelPublish($filePath, $caption, $platforms, $config);
    $statusData = getReelPublishStatus($jobId, $config);

    echo json_encode([
        'success' => true,
        'job_id' => $jobId,
        'video_file' => $filename,
        'video_url' => 'https://www.formulapaddock.it/seo/social/output/reels/' . rawurlencode($filename),
        'drive_url' => $driveResult['view_link'] ?? null,
        'status' => $statusData['status'] ?? 'processing',
        'results' => $statusData['results'] ?? []
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
