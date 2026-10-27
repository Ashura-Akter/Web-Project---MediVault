<?php
/*
 * MediVault OCR helper — accuracy-tuned matcher.
 *
 * Fixes over the previous version:
 *   1. Per-line matching restored (no more whole-text substring collisions).
 *   2. Longest-name-first ordering — "Napa Extra" is matched before "Napa",
 *      so a line containing "Napa Extra" does not also produce "Napa".
 *   3. Word-boundary matching on the normalized text — no more partial
 *      substring hits inside other medicine names.
 *   4. Noise-word filter restored — doctor / hospital / test / complaint
 *      lines never match a medicine.
 *   5. One medicine per line — no duplicates from the same OCR row.
 *   6. Fuzzy pass only runs on lines with a medicine indicator
 *      (mg / ml / tab / cap / syrup …) and threshold raised to 82.
 *   7. Text normalization still fixes common OCR misreads (0↔o, 1↔l, 5↔s,
 *      rn↔m, vv↔w, ii↔n) so handwriting reads do not silently drop out.
 */

function ocr_config(): array
{
    static $cfg = null;
    if ($cfg === null)
        $cfg = require __DIR__ . '/ocr_config.php';
    return $cfg;
}

/* =====================================================================
 *  SECTION 1 — OCR call (with engine override)
 * ===================================================================== */

function ocr_extract_text_from_file(string $tmpFilePath, string $originalName, string $engineOverride = ''): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'PHP cURL extension is not enabled.'];
    }
    $cfg = ocr_config();
    $apiKey = trim((string) ($cfg['ocr_space_api_key'] ?? ''));
    if ($apiKey === '')
        return ['ok' => false, 'error' => 'OCR API key is missing.'];

    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) ?: 'jpg';

    /* Optional image pre-processing (grayscale + contrast + upscale). */
    $sendPath = $tmpFilePath;
    $cleanup = null;
    if (strtolower($ext) !== 'pdf' && !empty($cfg['preprocess_image'])) {
        $pre = ocr_preprocess_image($tmpFilePath);
        if ($pre) {
            $sendPath = $pre;
            $cleanup = $pre;
        }
    }

    $mime = mime_content_type($sendPath) ?: 'application/octet-stream';
    $engine = $engineOverride !== '' ? $engineOverride : (string) ($cfg['ocr_engine'] ?? '2');

    $post = [
        'apikey' => $apiKey,
        'file' => new CURLFile($sendPath, $mime, 'prescription.' . $ext),
        'language' => 'eng',
        'isOverlayRequired' => 'false',
        'OCREngine' => $engine,
        'scale' => 'true',
        'isTable' => 'false',
        'detectOrientation' => 'true',
    ];

    $ch = curl_init('https://api.ocr.space/parse/image');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_USERAGENT => 'MediVault/1.3',
    ]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    if ($cleanup && is_file($cleanup))
        @unlink($cleanup);

    if ($response === false)
        return ['ok' => false, 'error' => 'Network error: ' . $curlErr];
    if ($httpCode !== 200)
        return ['ok' => false, 'error' => 'OCR service returned HTTP ' . $httpCode . '.'];

    $data = json_decode($response, true);
    if (!is_array($data))
        return ['ok' => false, 'error' => 'Invalid OCR response.'];
    if (!empty($data['IsErroredOnProcessing'])) {
        $err = $data['ErrorMessage'] ?? 'OCR failed.';
        if (is_array($err))
            $err = implode('; ', $err);
        return ['ok' => false, 'error' => (string) $err];
    }
    if (empty($data['ParsedResults']) || !is_array($data['ParsedResults'])) {
        return ['ok' => false, 'error' => 'No text read.'];
    }

    $text = '';
    foreach ($data['ParsedResults'] as $r) {
        if (!empty($r['ParsedText']))
            $text .= $r['ParsedText'] . "\n";
    }
    $text = trim($text);
    if ($text === '')
        return ['ok' => false, 'error' => 'No text found in the image.'];
    return ['ok' => true, 'text' => $text];
}

function ocr_preprocess_image(string $path): ?string
{
    if (!function_exists('imagecreatefromjpeg'))
        return null;
    $info = @getimagesize($path);
    if (!$info)
        return null;
    switch ($info['mime']) {
        case 'image/jpeg':
            $img = @imagecreatefromjpeg($path);
            break;
        case 'image/png':
            $img = @imagecreatefrompng($path);
            break;
        case 'image/webp':
            $img = @imagecreatefromwebp($path);
            break;
        case 'image/bmp':
            $img = @imagecreatefrombmp($path);
            break;
        case 'image/gif':
            $img = @imagecreatefromgif($path);
            break;
        default:
            return null;
    }
    if (!$img)
        return null;

    imagefilter($img, IMG_FILTER_GRAYSCALE);
    imagefilter($img, IMG_FILTER_CONTRAST, -30);

    $w = imagesx($img);
    $h = imagesy($img);
    if ($w > 0 && $w < 1600) {
        $newW = 1600;
        $newH = (int) round($h * ($newW / $w));
        $scaled = @imagescale($img, $newW, $newH);
        if ($scaled) {
            imagedestroy($img);
            $img = $scaled;
        }
    }
    $out = sys_get_temp_dir() . '/mv_ocr_pre_' . bin2hex(random_bytes(6)) . '.jpg';
    imagejpeg($img, $out, 92);
    imagedestroy($img);
    return is_file($out) ? $out : null;
}

/* =====================================================================
 *  SECTION 2 — Text normalization + helpers
 * ===================================================================== */

/* Fix common OCR mistakes; keep only a-z, 0-9, '+', spaces. */
function ocr_normalize_text(string $text): string
{
    $text = mb_strtolower(trim($text));
    $text = str_replace(['rn', 'vv', 'ii'], ['m', 'w', 'n'], $text);
    $text = strtr($text, [
        '0' => 'o',
        '1' => 'l',
        '5' => 's',
        '8' => 'b',
        '3' => 'e',
        '6' => 'g',
        '9' => 'g',
        '4' => 'a',
    ]);
    $text = preg_replace('/[^a-z0-9\+\s]/u', ' ', $text);
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim($text);
}

/* Raw line cleaner for display purposes. */
function ocr_clean_line(string $line): string
{
    $clean = preg_replace('/[^\p{L}\p{N}\s\+\-\.\/\%\(\)\&]/u', ' ', $line);
    return trim(preg_replace('/\s+/u', ' ', $clean));
}

function ocr_tokenize(string $text): array
{
    $parts = preg_split('/[\s\+\-\.\/]+/u', $text);
    $out = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '' && mb_strlen($p) >= 3)
            $out[] = $p;
    }
    return $out;
}

function ocr_edit_ratio(string $a, string $b): float
{
    $a = (string) $a;
    $b = (string) $b;
    if ($a === '' || $b === '')
        return 0.0;
    if ($a === $b)
        return 100.0;
    $dist = levenshtein($a, $b);
    $maxLen = max(mb_strlen($a), mb_strlen($b));
    return $maxLen > 0 ? (1 - $dist / $maxLen) * 100.0 : 0.0;
}

/* Words that indicate the line is NOT a medicine. */
function ocr_noise_words(): array
{
    return [
        'doctor',
        'hospital',
        'clinic',
        'chamber',
        'patient',
        'consultant',
        'dr',
        'square',
        'popular',
        'labaid',
        'ibnsina',
        'united',
        'evercare',
        'medinova',
        'fever',
        'cough',
        'cold',
        'pain',
        'headache',
        'vomiting',
        'nausea',
        'weakness',
        'fatigue',
        'appetite',
        'thirst',
        'urination',
        'discomfort',
        'complaint',
        'complaints',
        'symptom',
        'symptoms',
        'history',
        'diagnosis',
        'bp',
        'pulse',
        'temp',
        'temperature',
        'weight',
        'height',
        'bmi',
        'spo2',
        'pressure',
        'mmhg',
        'minute',
        'test',
        'tests',
        'report',
        'cbc',
        'esr',
        'widal',
        'dengue',
        'ns1',
        'urine',
        'ecg',
        'echo',
        'echocardiography',
        'lipid',
        'creatinine',
        'hba1c',
        'fbs',
        'habf',
        'xray',
        'ultrasound',
        'radiology',
        'pathology',
        'lab',
        'advice',
        'follow',
        'followup',
        'discount',
        'offer',
        'valid',
        'contact',
        'email',
        'website',
        'emergency',
        'hotline',
        'signature',
        'date',
        'age',
        'sex',
        'male',
        'female',
        'years',
        'yrs',
        'reg',
        'registration',
        'bmdc',
        'serial',
        'time',
        'days',
        'week',
        'month',
        'after',
        'before',
        'next',
        'visit',
        'take',
        'advised',
        'investigations',
        'examination',
    ];
}

/* Tokens that indicate a line is describing a medicine. */
function ocr_medicine_indicators(): array
{
    return [
        'mg',
        'ml',
        'mcg',
        'gm',
        'iu',
        'tab',
        'tabs',
        'tablet',
        'tablets',
        'cap',
        'caps',
        'capsule',
        'capsules',
        'syrup',
        'syr',
        'susp',
        'suspension',
        'drops',
        'inj',
        'injection',
        'cream',
        'ointment',
        'gel',
        'lotion',
        'spray',
        'inhaler',
        'lozenge',
        'powder',
        'pcs',
        'piece',
        'pieces',
        'pc'
    ];
}

function ocr_line_has_noise(string $lineLower): bool
{
    foreach (ocr_noise_words() as $w) {
        if (preg_match('/\b' . preg_quote($w, '/') . '\b/u', $lineLower))
            return true;
    }
    return false;
}

function ocr_line_has_indicator(string $lineLower): bool
{
    foreach (ocr_medicine_indicators() as $w) {
        if (preg_match('/\b' . preg_quote($w, '/') . '\b/u', $lineLower))
            return true;
    }
    return false;
}

/* =====================================================================
 *  SECTION 3 — Catalogue
 * ===================================================================== */

function ocr_load_catalogue(mysqli $conn): array
{
    $res = $conn->query(
        "SELECT m.medicine_id, m.medicine_name, m.generic_name, m.strength,
                m.dosage_form, m.company_name, m.image_path,
                m.is_restricted, m.requires_prescription,
                c.category_name
         FROM medicines m
         JOIN categories c ON c.category_id = m.category_id
         WHERE m.is_active = TRUE"
    );
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    foreach ($rows as &$r) {
        $r['_name_norm'] = ocr_normalize_text((string) $r['medicine_name']);
        $r['_generic_norm'] = ocr_normalize_text((string) ($r['generic_name'] ?? ''));
    }
    return $rows;
}

/* =====================================================================
 *  SECTION 4 — Matcher
 * ===================================================================== */

function ocr_match_medicines(mysqli $conn, string $rawText): array
{
    $cfg = ocr_config();
    $fuzzyThreshold = (float) ($cfg['fuzzy_threshold'] ?? 82);

    $catalogue = ocr_load_catalogue($conn);
    if (!$catalogue)
        return ['matched' => [], 'unmatched' => [], 'raw_lines' => []];

    /* Longest name first — "Napa Extra" wins over "Napa" on the same line. */
    usort($catalogue, fn($a, $b) => mb_strlen((string) $b['medicine_name']) - mb_strlen((string) $a['medicine_name']));

    /* Build a per-line list from the raw OCR text. */
    $lines = [];
    foreach (preg_split('/[\r\n]+/', $rawText) as $line) {
        $clean = ocr_clean_line($line);
        if ($clean === '' || mb_strlen($clean) < 4)
            continue;
        if (preg_match('/^[\d\s\+\-\.\/\%\(\)\&]+$/', $clean))
            continue;
        $lines[] = $clean;
    }

    $usedIds = [];  // medicine_id => true
    $usedLines = [];  // line index => true
    $matched = [];
    $unmatched = [];

    /* -------- PASS 1: exact normalized full-name, word-boundary -------- */
    foreach ($lines as $i => $line) {
        if (isset($usedLines[$i]))
            continue;
        $lineNorm = ocr_normalize_text($line);

        /* Skip pure noise lines with no medicine indicator. */
        if (ocr_line_has_noise($lineNorm) && !ocr_line_has_indicator($lineNorm)) {
            $usedLines[$i] = true;
            continue;
        }

        foreach ($catalogue as $m) {
            if (isset($usedIds[$m['medicine_id']]))
                continue;
            $norm = $m['_name_norm'];
            if ($norm === '' || mb_strlen($norm) < 4)
                continue;

            if (preg_match('/\b' . preg_quote($norm, '/') . '\b/u', $lineNorm)) {
                $usedIds[$m['medicine_id']] = true;
                $usedLines[$i] = true;
                $m['_source_line'] = $line;
                $m['_score'] = 100.0;
                $m['_match_type'] = 'exact';
                $matched[] = $m;
                break; // one medicine per line
            }
        }
    }

    /* -------- PASS 2: exact normalized generic-name, word-boundary -------- */
    foreach ($lines as $i => $line) {
        if (isset($usedLines[$i]))
            continue;
        $lineNorm = ocr_normalize_text($line);
        if (ocr_line_has_noise($lineNorm) && !ocr_line_has_indicator($lineNorm)) {
            $usedLines[$i] = true;
            continue;
        }

        foreach ($catalogue as $m) {
            if (isset($usedIds[$m['medicine_id']]))
                continue;
            $g = $m['_generic_norm'];
            if ($g === '' || mb_strlen($g) < 6)
                continue;

            if (preg_match('/\b' . preg_quote($g, '/') . '\b/u', $lineNorm)) {
                $usedIds[$m['medicine_id']] = true;
                $usedLines[$i] = true;
                $m['_source_line'] = $line;
                $m['_score'] = 92.0;
                $m['_match_type'] = 'generic';
                $matched[] = $m;
                break;
            }
        }
    }

    /* -------- PASS 3: strict fuzzy — only on medicine-indicator lines -------- */
    foreach ($lines as $i => $line) {
        if (isset($usedLines[$i]))
            continue;
        $lineNorm = ocr_normalize_text($line);
        if (!ocr_line_has_indicator($lineNorm))
            continue;
        if (ocr_line_has_noise($lineNorm))
            continue;

        $tokens = ocr_tokenize($lineNorm);
        if (!$tokens)
            continue;
        $candidates = array_slice($tokens, 0, 4);

        $best = null;
        $bestScore = 0.0;
        foreach ($catalogue as $m) {
            if (isset($usedIds[$m['medicine_id']]))
                continue;
            $nameNorm = $m['_name_norm'];
            if (mb_strlen($nameNorm) < 5)
                continue;

            /* Compare the medicine name against 1..4-word prefixes of the line. */
            for ($n = 1; $n <= min(4, count($candidates)); $n++) {
                $phrase = implode(' ', array_slice($candidates, 0, $n));
                if (mb_strlen($phrase) < 5)
                    continue;
                /* Length guard: skip phrases that differ by more than 3 chars. */
                if (abs(mb_strlen($phrase) - mb_strlen($nameNorm)) > 3)
                    continue;
                $sim = ocr_edit_ratio($phrase, $nameNorm);
                if ($sim > $bestScore && $sim >= $fuzzyThreshold) {
                    $bestScore = $sim;
                    $best = $m;
                }
            }
        }
        if ($best) {
            $usedIds[$best['medicine_id']] = true;
            $usedLines[$i] = true;
            $best['_source_line'] = $line;
            $best['_score'] = round($bestScore, 1);
            $best['_match_type'] = 'fuzzy';
            $matched[] = $best;
        } else {
            $unmatched[] = $line;
        }
    }

    /* Any line still untouched goes into unmatched (never matched any medicine). */
    foreach ($lines as $i => $line) {
        if (isset($usedLines[$i]))
            continue;
        if (in_array($line, $unmatched, true))
            continue;
        $unmatched[] = $line;
    }

    return [
        'matched' => $matched,
        'unmatched' => $unmatched,
        'raw_lines' => $lines,
    ];
}

/* =====================================================================
 *  SECTION 5 — Availability
 * ===================================================================== */

function ocr_attach_availability(mysqli $conn, array $matched): array
{
    if (!$matched)
        return [];
    $stmt = $conn->prepare(
        "SELECT p.pharmacy_id, p.pharmacy_name,
                SUM(ib.quantity_on_hand - ib.reserved_quantity) AS available,
                MIN(ib.unit_price) AS price
         FROM inventory_batches ib
         JOIN pharmacies p ON p.pharmacy_id = ib.pharmacy_id
         WHERE ib.medicine_id = ?
           AND ib.expiry_date > CURDATE()
           AND (ib.quantity_on_hand - ib.reserved_quantity) > 0
           AND p.is_active = TRUE
         GROUP BY p.pharmacy_id, p.pharmacy_name
         ORDER BY price ASC
         LIMIT 1"
    );
    foreach ($matched as &$m) {
        $stmt->bind_param('i', $m['medicine_id']);
        $stmt->execute();
        $branch = $stmt->get_result()->fetch_assoc();
        if ($branch) {
            $m['pharmacy_id'] = (int) $branch['pharmacy_id'];
            $m['pharmacy_name'] = (string) $branch['pharmacy_name'];
            $m['unit_price'] = (float) $branch['price'];
            $m['available_stock'] = (int) $branch['available'];
            $m['has_stock'] = $m['available_stock'] > 0;
        } else {
            $m['pharmacy_id'] = 0;
            $m['pharmacy_name'] = 'Not in stock';
            $m['unit_price'] = 0.0;
            $m['available_stock'] = 0;
            $m['has_stock'] = false;
        }
    }
    unset($m);
    return $matched;
}

/* =====================================================================
 *  SECTION 6 — Full pipeline with engine fallback
 * ===================================================================== */

function ocr_run_full_pipeline(mysqli $conn, string $tmpFilePath, string $originalName): array
{
    $cfg = ocr_config();
    $primaryEngine = (string) ($cfg['ocr_engine'] ?? '2');

    $extract = ocr_extract_text_from_file($tmpFilePath, $originalName, $primaryEngine);
    if (!$extract['ok'])
        return $extract;

    $matchResult = ocr_match_medicines($conn, $extract['text']);

    /* If engine 2 found nothing, retry with engine 1 (better for handwriting). */
    if (empty($matchResult['matched']) && $primaryEngine !== '1') {
        $retry = ocr_extract_text_from_file($tmpFilePath, $originalName, '1');
        if ($retry['ok']) {
            $retryMatch = ocr_match_medicines($conn, $retry['text']);
            if (!empty($retryMatch['matched'])) {
                $extract = $retry;
                $matchResult = $retryMatch;
            }
        }
    }

    $matched = ocr_attach_availability($conn, $matchResult['matched']);

    $inStock = [];
    $noStock = [];
    foreach ($matched as $m) {
        $row = [
            'medicine_id' => (int) $m['medicine_id'],
            'medicine_name' => (string) $m['medicine_name'],
            'generic_name' => (string) ($m['generic_name'] ?? ''),
            'strength' => (string) ($m['strength'] ?? ''),
            'dosage_form' => (string) ($m['dosage_form'] ?? ''),
            'company_name' => (string) ($m['company_name'] ?? ''),
            'category_name' => (string) ($m['category_name'] ?? ''),
            'image_path' => (string) ($m['image_path'] ?? ''),
            'is_restricted' => (int) $m['is_restricted'] === 1,
            'requires_rx' => (int) $m['requires_prescription'] === 1,
            'pharmacy_id' => (int) $m['pharmacy_id'],
            'pharmacy_name' => (string) $m['pharmacy_name'],
            'unit_price' => (float) $m['unit_price'],
            'available_stock' => (int) $m['available_stock'],
            'has_stock' => (bool) $m['has_stock'],
            'source_line' => (string) ($m['_source_line'] ?? ''),
            'match_type' => (string) ($m['_match_type'] ?? ''),
            'score' => (float) ($m['_score'] ?? 0),
        ];
        if ($row['has_stock'])
            $inStock[] = $row;
        else
            $noStock[] = $row;
    }

    return [
        'ok' => true,
        'raw_text' => $extract['text'],
        'matched' => $inStock,
        'no_stock' => $noStock,
        'unmatched' => array_values(array_slice($matchResult['unmatched'], 0, 40)),
        'stats' => [
            'total_lines' => count($matchResult['raw_lines']),
            'matched' => count($inStock) + count($noStock),
            'unmatched' => count($matchResult['unmatched']),
        ],
    ];
}