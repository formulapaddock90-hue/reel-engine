<?php
/**
 * Generazione testi (Facebook, Twitter, LinkedIn, categoria, copy infografica, script reel)
 * tramite API AI: Google Gemini oppure Anthropic (Claude), in base a 'ai_provider' in config.php.
 */

/**
 * Instrada la chiamata al provider AI configurato ('gemini' o 'anthropic').
 *
 * @throws Exception
 */
function callAI(string $systemPrompt, string $userPrompt, array $config): string
{
    $provider = $config['ai_provider'] ?? 'gemini';

    if ($provider === 'gemini') {
        try {
            return callGemini($systemPrompt, $userPrompt, $config);
        } catch (Exception $e) {
            if (!empty($config['anthropic_api_key'])) {
                try {
                    return callClaude($systemPrompt, $userPrompt, $config);
                } catch (Exception $eClaude) {}
            }
            throw $e;
        }
    }

    try {
        return callClaude($systemPrompt, $userPrompt, $config);
    } catch (Exception $e) {
        if (!empty($config['gemini_api_key'])) {
            return callGemini($systemPrompt, $userPrompt, $config);
        }
        throw $e;
    }
}

/**
 * Esegue una chiamata all'API Google Gemini provando in ordine il modello
 * principale e tutti i modelli di fallback disponibili.
 *
 * @throws Exception se tutti i modelli falliscono
 */
function callGemini(string $systemPrompt, string $userPrompt, array $config): string
{
    $primary = $config['gemini_model'] ?? 'gemini-2.5-flash';
    $configuredFallbacks = $config['gemini_fallback_models'] ?? ($config['fallback_models'] ?? []);
    
    // Lista modelli ufficiali Google Gemini
    $defaultModels = [
        'gemini-2.5-flash',
        'gemini-2.0-flash',
        'gemini-1.5-flash',
        'gemini-2.0-flash-lite',
        'gemini-1.5-pro'
    ];

    $models = array_values(array_unique(array_filter(array_merge([$primary], $configuredFallbacks, $defaultModels))));

    $errors = [];
    foreach ($models as $model) {
        try {
            return callGeminiModel($model, $systemPrompt, $userPrompt, $config);
        } catch (Exception $e) {
            $errors[] = "[$model] " . $e->getMessage();
        }
    }

    throw new Exception(
        "Tutti i modelli Gemini hanno fallito:\n" . implode("\n", $errors)
    );
}

/**
 * Esegue una singola chiamata a un modello Gemini specifico.
 *
 * @throws Exception
 */
function callGeminiModel(string $model, string $systemPrompt, string $userPrompt, array $config): string
{
    $url = rtrim($config['gemini_api_url'], '/') . '/' . $model
        . ':generateContent?key=' . urlencode($config['gemini_api_key']);

    $payload = [
        'system_instruction' => [
            'parts' => [['text' => $systemPrompt]],
        ],
        'contents' => [
            ['role' => 'user', 'parts' => [['text' => $userPrompt]]],
        ],
        'generationConfig' => [
            'maxOutputTokens' => 4000,
        ],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);

    $response = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $err) {
        throw new Exception("Errore chiamata Gemini API: $err");
    }

    $data = json_decode($response, true);

    if ($httpCode >= 400) {
        $msg = $data['error']['message'] ?? $response;
        throw new Exception("Errore Gemini API ($httpCode): $msg");
    }

    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if ($text === null) {
        throw new Exception('Risposta inattesa da Gemini API: ' . $response);
    }

    return $text;
}

/**
 * Esegue una chiamata all'API Anthropic e restituisce il testo della risposta.
 *
 * @param string $systemPrompt
 * @param string $userPrompt
 * @param array  $config
 * @return string
 * @throws Exception
 */
function callClaude(string $systemPrompt, string $userPrompt, array $config): string
{
    $payload = [
        'model'      => $config['anthropic_model'],
        'max_tokens' => 2000,
        'system'     => $systemPrompt,
        'messages'   => [
            ['role' => 'user', 'content' => $userPrompt],
        ],
    ];

    $ch = curl_init($config['anthropic_api_url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $config['anthropic_api_key'],
            'anthropic-version: 2023-06-01',
        ],
    ]);

    $response = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $err) {
        throw new Exception("Errore chiamata Claude API: $err");
    }

    $data = json_decode($response, true);

    if ($httpCode >= 400) {
        $msg = $data['error']['message'] ?? $response;
        throw new Exception("Errore Claude API ($httpCode): $msg");
    }

    if (!isset($data['content'][0]['text'])) {
        throw new Exception('Risposta inattesa da Claude API: ' . $response);
    }

    return $data['content'][0]['text'];
}

/**
 * Genera tutti i contenuti testuali a partire dal testo sorgente.
 * Restituisce un array associativo pronto per essere usato nelle varie sezioni.
 *
 * @param string $sourceText  Testo originale (o estratto dall'URL)
 * @param string $title       Titolo (se disponibile, es. da URL)
 * @param array  $config
 * @return array
 * @throws Exception
 */
function generateSocialContent(string $sourceText, string $title, array $config): array
{
    $systemPrompt = <<<SYS
Sei un social media manager esperto di Formula 1 che scrive per una pagina Facebook
e un sito di contenuti F1 in lingua italiana. Il tuo compito e' trasformare un testo
(articolo, comunicato, notizia) in contenuti pronti per la pubblicazione sui social.

Rispondi SOLO con un oggetto JSON valido (nessun testo prima o dopo, nessun blocco
markdown ```), con esattamente questa struttura:

{
  "facebook": "Testo per un post Facebook, tono coinvolgente, 2-4 frasi, con eventuali emoji pertinenti e 2-3 hashtag F1 alla fine",
  "twitter": "Testo per un tweet/post X, massimo 280 caratteri, incisivo, con 1-2 hashtag",
  "twitter_modificato": "Una variante alternativa del tweet, diverso taglio o angolazione, massimo 280 caratteri",
  "linkedin": "Testo per un post LinkedIn, tono piu' professionale/analitico, 3-5 frasi, senza eccessive emoji",
  "categoria": "Una singola parola o breve etichetta che classifica l'argomento (es. Gara, Qualifiche, Mercato Piloti, Tecnica, Regolamento, Test, Curiosita')",
  "infografica_titolo": "Titolo breve (massimo 6 parole) da mettere in grande nell'infografica",
  "infografica_sottotitolo": "Sottotitolo breve (massimo 12 parole) da mettere sotto il titolo nell'infografica",
  "reel_script": "Breve testo (max 3 frasi brevi) da mostrare/leggere nel video reel verticale, stile rapido e accattivante"
}
SYS;

    $userPrompt = "TITOLO: " . $title . "\n\nTESTO ORIGINALE:\n" . $sourceText;

    $raw = callAI($systemPrompt, $userPrompt, $config);

    // Rimuove eventuali blocchi markdown ```json ... ``` se presenti
    $raw = trim($raw);
    $raw = preg_replace('/^```json\s*|\s*```$/m', '', $raw);
    $raw = trim($raw);

    $json = json_decode($raw, true);

    if (!is_array($json)) {
        throw new Exception("Impossibile interpretare la risposta JSON di Claude: $raw");
    }

    $defaults = [
        'facebook' => '',
        'twitter' => '',
        'twitter_modificato' => '',
        'linkedin' => '',
        'categoria' => '',
        'infografica_titolo' => $title,
        'infografica_sottotitolo' => '',
        'reel_script' => '',
    ];

    $result = array_merge($defaults, $json);

    // Rispetta il limite di 280 caratteri per Twitter
    foreach (['twitter', 'twitter_modificato'] as $key) {
        if (mb_strlen($result[$key]) > 280) {
            $result[$key] = mb_substr($result[$key], 0, 277) . '...';
        }
    }

    return $result;
}
