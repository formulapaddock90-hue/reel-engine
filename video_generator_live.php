<?php
/**
 * Generatore Video Reel Verticale 9:16 (1080x1920) FormulaPaddock
 * Versione 2.0: Multi-immagine, Ken Burns centrato, Intro Badge, Outro con Logo, Musica F1 & Opera Lirica
 * Supporta sia FFmpeg locale (se exec() è abilitato) sia Cloud Engine API (per hosting condivisi come Aruba).
 */

declare(strict_types=1);

const FP_GOOGLE_DRIVE_UPLOADS = 'G:\\Il mio Drive\\uploads';
const FP_F1_SYNONYMS = [
    'abu dhabi'   => ['abu dhabi', 'yas marina'],
    'australia'   => ['australia', 'melbourne', 'albert park'],
    'austria'     => ['austria', 'spielberg', 'red bull ring'],
    'brasile'     => ['brasile', 'brazil', 'interlagos', 'sao paolo', 'san paolo'],
    'canada'      => ['canada', 'montreal', 'gilles villeneuve'],
    'cina'        => ['cina', 'china', 'shanghai'],
    'giappone'    => ['giappone', 'japan', 'suzuka'],
    'jeddah'      => ['jeddah', 'arabia', 'saudi'],
    'las vegas'   => ['las vegas', 'vegas'],
    'mclaren'     => ['mclaren', 'norris', 'piastri', 'papaya', 'woking'],
    'messico'     => ['messico', 'mexico', 'hermanos rodriguez'],
    'miami'       => ['miami'],
    'monaco'      => ['monaco', 'monte carlo', 'montecarlo'],
    'pirelli'     => ['pirelli', 'gomme', 'tyres', 'pneumatici'],
    'qatar'       => ['qatar', 'losail', 'lusail'],
    'silverstone' => ['silverstone', 'gran bretagna', 'british', 'inghilterra', 'enstone'],
    'spa'         => ['spa', 'francorchamps', 'belgio', 'belgian'],
    'spagna'      => ['spagna', 'spain', 'barcellona', 'barcelona', 'catalunya'],
    'ungheria'    => ['ungheria', 'hungary', 'hungaroring', 'budapest'],
    'usa'         => ['usa', 'austin', 'cota', 'texas', 'stati uniti'],
    'test'        => ['test', 'sakhir', 'pre-season']
];

function findDriveGpFolder(array $searchTerms): ?string {
    $baseUploads = is_dir(__DIR__ . '/../../uploads') ? __DIR__ . '/../../uploads' : (is_dir(FP_GOOGLE_DRIVE_UPLOADS) ? FP_GOOGLE_DRIVE_UPLOADS : null);
    if (!$baseUploads || !is_dir($baseUploads)) return null;

    $folders = array_filter(scandir($baseUploads), fn($f) => $f !== '.' && $f !== '..' && is_dir($baseUploads . DIRECTORY_SEPARATOR . $f));
    $text = mb_strtolower(implode(' ', $searchTerms));
    $bestFolder = null;
    $maxScore = 0;

    foreach ($folders as $folder) {
        $fLower = mb_strtolower($folder);
        $score = 0;

        $tokens = preg_split('/[\s\-_]+/', $fLower);
        foreach ($tokens as $t) {
            if (in_array($t, ['test', '2025', '2026', '25', '26'], true)) continue;
            if (mb_strlen($t) > 3 && strpos($text, $t) !== false) {
                $score += 10;
            }
        }

        foreach (FP_F1_SYNONYMS as $key => $syns) {
            if (strpos($fLower, $key) !== false) {
                foreach ($syns as $s) {
                    if (strpos($text, $s) !== false) $score += 15;
                }
            }
        }

        if ($score > $maxScore) {
            $maxScore = $score;
            $bestFolder = $baseUploads . DIRECTORY_SEPARATOR . $folder;
        }
    }

    return $bestFolder;
}

function getImagesFromFolder(?string $folder): array {
    if (!$folder || !is_dir($folder)) return [];
    $files = scandir($folder);
    $imgs = [];
    foreach ($files as $f) {
        if (preg_match('/\.(jpe?g|png|webp)$/i', $f)) {
            $imgs[] = $folder . DIRECTORY_SEPARATOR . $f;
        }
    }
    return $imgs;
}

function getFolderRandomMp3(): ?string {
    $candidates = [
        __DIR__ . '/../music',
        'F:\\reel\\music',
        'G:\\Il mio Drive\\seo\\social\\music',
        __DIR__ . '/../../music'
    ];

    $allMp3s = [];
    foreach ($candidates as $dir) {
        if (is_dir($dir)) {
            foreach (scandir($dir) as $f) {
                if (preg_match('/\.(mp3|m4a|wav|aac|ogg|flac)$/i', $f)) {
                    $allMp3s[] = $dir . DIRECTORY_SEPARATOR . $f;
                }
            }
        }
    }

    if (!empty($allMp3s)) {
        return $allMp3s[array_rand($allMp3s)];
    }
    return null;
}

function prepareDrawtextText(string $text): string {
    $text = trim($text);
    if ($text === '') return '';
    $text = str_replace(["\r\n", "\r", "\n"], ' ', $text);
    $text = str_replace('\\', '\\\\', $text);
    $text = str_replace("'", "'\\''", $text);
    $text = str_replace(':', '\\:', $text);
    $text = str_replace(',', '\\,', $text);
    $text = str_replace('%', '\\%', $text);
    return $text;
}

/**
 * Funzione principale per la generazione di Reel Video
 */
function generateReelVideo(
    string $imagePath,
    string $outputPath,
    string $overlayText,
    array $config = [],
    int $durationSeconds = 6,
    string $articleUrl = '',
    array $reelData = []
): string {
    $outDir = dirname($outputPath);
    if (!is_dir($outDir)) {
        mkdir($outDir, 0777, true);
    }

    // 1. Se exec() è disponibile sul server (es. VPS / server dedicato / Windows locale)
    if (function_exists('exec')) {
        $ffmpegCandidatePaths = [
            $config['ffmpeg_path'] ?? null,
            'C:\\Users\\formu\\AppData\\Local\\Microsoft\\WinGet\\Packages\\Gyan.FFmpeg_Microsoft.Winget.Source_8wekyb3d8bbwe\\ffmpeg-9.0-full_build\\bin\\ffmpeg.exe',
            __DIR__ . '/../bin/ffmpeg.exe',
            'ffmpeg',
            '/usr/bin/ffmpeg',
            '/usr/local/bin/ffmpeg'
        ];

        $ffmpeg = null;
        foreach ($ffmpegCandidatePaths as $cand) {
            if (!empty($cand)) {
                $checkCmd = escapeshellarg($cand) . ' -version 2>&1';
                @exec($checkCmd, $checkOut, $checkCode);
                if ($checkCode === 0) {
                    $ffmpeg = $cand;
                    break;
                }
            }
        }

        if ($ffmpeg) {
            return generateLocalFfmpegReel($ffmpeg, $imagePath, $outputPath, $overlayText, $config, $durationSeconds, $articleUrl, $reelData);
        }
    }

    // 2. Se exec() è disabilitato da Aruba (Hosting Condiviso), usa il Cloud Engine Rendering API
    $cloudUrl = $config['reel_cloud_url'] ?? 'https://reel-engine-dcnr.onrender.com';
    return generateCloudEngineReel($cloudUrl, $imagePath, $outputPath, $overlayText, $config, $articleUrl, $reelData);
}

function generateLocalFfmpegReel(
    string $ffmpeg,
    string $imagePath,
    string $outputPath,
    string $overlayText,
    array $config,
    int $durationSeconds = 6,
    string $articleUrl = '',
    array $reelData = []
): string {
    $title = $reelData['title'] ?? $overlayText;
    $desc = $reelData['description'] ?? 'Aggiornamenti e notizie Formula 1';
    $searchTerms = [$title, $desc, $articleUrl];

    $matchedFolder = findDriveGpFolder($searchTerms);
    $driveImgs = getImagesFromFolder($matchedFolder);

    $extraImgs = [];
    if (count($driveImgs) >= 2) {
        shuffle($driveImgs);
        $extraImgs = array_slice($driveImgs, 0, 2);
    }

    $imageInputs = array_values(array_filter([$imagePath, $extraImgs[0] ?? null, $extraImgs[1] ?? null]));
    if (count($imageInputs) === 1) {
        $imageInputs = [$imagePath, $imagePath, $imagePath];
    } elseif (count($imageInputs) === 2) {
        $imageInputs[] = $imageInputs[0];
    }

    // Logo e Font
    $logoCandidatePaths = [
        __DIR__ . '/../fonts/logo_fp.webp',
        'F:\\reel\\temp\\logo_fp.webp',
        __DIR__ . '/../assets/logo.png'
    ];
    $logoPath = null;
    foreach ($logoCandidatePaths as $lp) {
        if (file_exists($lp)) { $logoPath = $lp; break; }
    }

    $fontFile = __DIR__ . '/../fonts/arialbd.ttf';
    if (!file_exists($fontFile)) {
        $fontFile = 'F:/reel/arialbd.ttf';
    }
    $escapedFont = str_replace('\\', '/', $fontFile);
    $fontPart = file_exists($fontFile) ? ":fontfile='" . str_replace(':', '\\:', $escapedFont) . "'" : '';

    $musicPath = getFolderRandomMp3();

    // Testi
    $cleanTitle = mb_substr(trim($title), 0, 80);
    $words = explode(' ', $cleanTitle);
    $title1 = '';
    $title2 = '';
    foreach ($words as $w) {
        if (mb_strlen($title1 . ' ' . $w) <= 30 && empty($title2)) {
            $title1 = trim($title1 . ' ' . $w);
        } else {
            $title2 = trim($title2 . ' ' . $w);
        }
    }
    $escTitle1 = prepareDrawtextText($title1);
    $escTitle2 = prepareDrawtextText(mb_substr($title2, 0, 36));
    $escDesc = prepareDrawtextText(mb_substr(trim($desc), 0, 75));

    $dur = 6;
    $sliceDur = 2.333;

    // Costruzione comando FFmpeg 9:16 Ken Burns
    $cmdParts = [escapeshellarg($ffmpeg), '-y'];
    for ($i = 0; $i < 3; $i++) {
        $cmdParts[] = '-loop 1';
        $cmdParts[] = '-t ' . $sliceDur;
        $cmdParts[] = '-i ' . escapeshellarg($imageInputs[$i]);
    }

    $hasLogo = !empty($logoPath) && file_exists($logoPath);
    if ($hasLogo) {
        $cmdParts[] = '-loop 1 -t ' . $dur . ' -i ' . escapeshellarg($logoPath);
    }

    $hasAudio = !empty($musicPath) && file_exists($musicPath);
    if ($hasAudio) {
        $cmdParts[] = '-i ' . escapeshellarg($musicPath);
    }

    // Filtergraph
    $filterSteps = [];
    $filterSteps[] = "[0:v]scale=1210:2150:force_original_aspect_ratio=increase,crop=1080:1920:(in_w-out_w)/2+((n/58-0.5)*40):(in_h-out_h)/2+((n/58-0.5)*40),fps=25,setsar=1[kb0]";
    $filterSteps[] = "[1:v]scale=1210:2150:force_original_aspect_ratio=increase,crop=1080:1920:(in_w-out_w)/2-((n/58-0.5)*40):(in_h-out_h)/2-((n/58-0.5)*40),fps=25,setsar=1[kb1]";
    $filterSteps[] = "[2:v]scale=1210:2150:force_original_aspect_ratio=increase,crop=1080:1920:(in_w-out_w)/2+((n/58-0.5)*40):(in_h-out_h)/2+((n/58-0.5)*40),fps=25,setsar=1[kb2]";
    $filterSteps[] = "[kb0][kb1]xfade=transition=fade:duration=0.5:offset=1.833[xf0]";
    $filterSteps[] = "[xf0][kb2]xfade=transition=fade:duration=0.5:offset=3.667[xf1]";
    $filterSteps[] = "[xf1]trim=0:{$dur},setpts=PTS-STARTPTS,fade=t=in:st=0:d=0.4[vmerged]";

    // Intro Badge
    $filterSteps[] = "[vmerged]drawbox=x=60:y=70:w=370:h=56:color=0xE8002D@0.95:t=fill:enable='between(t,0.2,4.6)'[vbadge_bg]";
    $filterSteps[] = "[vbadge_bg]drawtext={$fontPart}:text='FORMULA PADDOCK':fontsize=24:fontcolor=white:x=85:y=86:shadowcolor=black@0.6:shadowx=1:shadowy=1:enable='between(t,0.2,4.6)'[vbadge]";

    // Main Card Bottom
    $filterSteps[] = "[vbadge]drawbox=x=0:y=1360:w=1080:h=560:color=black@0.72:t=fill:enable='between(t,0,4.6)'[vbg]";
    $filterSteps[] = "[vbg]drawbox=x=50:y=1380:w=8:h=440:color=0xE8002D@1.0:t=fill:enable='between(t,0,4.6)'[vline]";
    $filterSteps[] = "[vline]drawtext={$fontPart}:text='{$escTitle1}':fontsize=52:fontcolor=white:x=80:y=1420:shadowcolor=black@0.8:shadowx=2:shadowy=2:enable='between(t,0,4.6)'[vt1]";

    $prev = 'vt1';
    if (!empty($escTitle2)) {
        $filterSteps[] = "[{$prev}]drawtext={$fontPart}:text='{$escTitle2}':fontsize=48:fontcolor=white:x=80:y=1485:shadowcolor=black@0.8:shadowx=2:shadowy=2:enable='between(t,0,4.6)'[vt2]";
        $prev = 'vt2';
        $descY = 1555;
    } else {
        $descY = 1500;
    }

    if (!empty($escDesc)) {
        $filterSteps[] = "[{$prev}]drawtext={$fontPart}:text='{$escDesc}':fontsize=32:fontcolor=0xC8A45A:x=80:y={$descY}:shadowcolor=black@0.6:shadowx=1:shadowy=1:enable='between(t,0,4.6)'[vdesc]";
        $prev = 'vdesc';
    }

    $filterSteps[] = "[{$prev}]drawtext={$fontPart}:text='formulapaddock.it':fontsize=28:fontcolor=0xE8002D@0.9:x=80:y=1640:enable='between(t,0,4.6)'[vlink]";
    $prev = 'vlink';

    // Outro
    $filterSteps[] = "[{$prev}]drawbox=x=0:y=0:w=1080:h=1920:color=black@0.88:t=fill:enable='between(t,4.6,{$dur})'[voutro_bg]";
    $prev = 'voutro_bg';

    if ($hasLogo) {
        $logoIdx = 3;
        $filterSteps[] = "[{$logoIdx}:v]scale=460:-1,fade=t=in:st=4.6:d=0.4,fade=t=out:st=5.8:d=0.2[logo_outro]";
        $filterSteps[] = "[{$prev}][logo_outro]overlay=x=(1080-460)/2:y=(1920-460)/2-120:enable='between(t,4.6,{$dur})'[vwithlogo]";
        $prev = 'vwithlogo';
    }

    $filterSteps[] = "[{$prev}]drawtext={$fontPart}:text='FORMULAPADDOCK.IT':fontsize=44:fontcolor=white:x=(w-text_w)/2:y=1120:shadowcolor=black@0.8:shadowx=2:shadowy=2:enable='between(t,4.7,{$dur})'[voutro_t1]";
    $filterSteps[] = "[voutro_t1]drawtext={$fontPart}:text='SEGUICI PER TUTTE LE NOVITA':fontsize=28:fontcolor=0xC8A45A:x=(w-text_w)/2:y=1185:shadowcolor=black@0.6:shadowx=1:shadowy=1:enable='between(t,4.8,{$dur})'[vfinal]";

    $filterComplex = implode(';', $filterSteps);

    $cmdParts[] = '-filter_complex ' . escapeshellarg($filterComplex);
    $cmdParts[] = '-map "[vfinal]"';

    if ($hasAudio) {
        $audioIdx = $hasLogo ? 4 : 3;
        $cmdParts[] = '-map "' . $audioIdx . ':a"';
        $cmdParts[] = '-af ' . escapeshellarg("atrim=0:{$dur},afade=t=out:st=5:d=1,asetpts=PTS-STARTPTS");
        $cmdParts[] = '-c:a aac -b:a 192k';
    } else {
        $cmdParts[] = '-an';
    }

    $cmdParts[] = '-c:v libx264 -preset fast -crf 20 -pix_fmt yuv420p -r 25 -t ' . $dur . ' -movflags +faststart';
    $cmdParts[] = escapeshellarg($outputPath);

    $finalCmd = implode(' ', $cmdParts);
    @exec($finalCmd . ' 2>&1', $out, $code);

    if ($code !== 0 || !file_exists($outputPath) || filesize($outputPath) < 5000) {
        throw new RuntimeException("FFmpeg rendering fallito: " . implode("\n", array_slice($out ?? [], -10)));
    }

    return $outputPath;
}

function generateCloudEngineReel(
    string $cloudUrl,
    string $imagePath,
    string $outputPath,
    string $overlayText,
    array $config = [],
    string $articleUrl = '',
    array $reelData = []
): string {
    $renderApiUrl = rtrim($cloudUrl, '/') . '/api/render-reel';

    $webImageUrl = '';
    if (!empty($_SERVER['HTTP_HOST']) && file_exists($imagePath)) {
        $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $webImageUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . '/seo/social/output/' . basename($imagePath);
    } elseif (filter_var($imagePath, FILTER_VALIDATE_URL)) {
        $webImageUrl = $imagePath;
    }

    $payload = json_encode([
        'text' => $overlayText,
        'title' => $reelData['title'] ?? $overlayText,
        'description' => $reelData['description'] ?? '',
        'category' => $reelData['category'] ?? 'Formula 1',
        'image_url' => $webImageUrl,
        'article_url' => $articleUrl,
        'end_card' => [
            'logo_url' => 'https://www.formulapaddock.it/wp-content/uploads/2026/05/preview.webp',
            'title' => 'FORMULAPADDOCK.IT',
            'subtitle' => 'SEGUICI PER TUTTE LE NOVITA'
        ]
    ]);

    $maxAttempts = 6;
    $lastHttpCode = 0;
    $lastErr = '';

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $ch = curl_init($renderApiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json']
        ]);

        $videoBytes = curl_exec($ch);
        $lastHttpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $lastErr = (string)curl_error($ch);
        curl_close($ch);

        if ($lastHttpCode === 200 && $videoBytes && strlen($videoBytes) > 5000) {
            file_put_contents($outputPath, $videoBytes);
            return $outputPath;
        }

        // Se il server è in fase di spin-up/cold start (HTTP 502, 503, 504 o timeout), aspetta 6s e riprova
        if ($attempt < $maxAttempts) {
            sleep(6);
        }
    }

    throw new RuntimeException("Rendering Cloud (HTTP {$lastHttpCode}): " . ($lastErr ?: "Il server di rendering ha impiegato più tempo del previsto per avviarsi."));
}
