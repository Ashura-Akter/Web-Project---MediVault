<?php
/*
 * MediVault OCR configuration.
 *
 * Free OCR is provided by OCR.space (https://ocr.space/ocrapi).
 *
 * The API key below is a personal free-tier key. Free tier limits:
 *   - 25,000 requests / month
 *   - 1 MB max file size per request
 *   - 3 requests / second
 */
return [
    /* ---------------- API KEY ---------------- */
    'ocr_space_api_key' => 'K83586182388957',

    /* ---------------- UPLOAD LIMIT ---------------- */
    /* Maximum file size we send to OCR.space (free-tier limit is 1 MB). */
    'max_file_size' => 1 * 1024 * 1024,   // 1 MB

    /* ---------------- OCR ENGINE ---------------- */
    /* OCREngine:
     *   '2' → best for printed / high-contrast text (default, faster)
     *   '1' → better for handwriting (slower, used automatically as a
     *         fallback when engine 2 finds no match)
     * The pipeline retries with engine 1 automatically if engine 2
     * returns zero matched medicines. */
    'ocr_engine' => '2',

    /* ---------------- MATCHING ---------------- */
    /* Fuzzy match threshold (0-100). Only used after exact full-name and
     * generic-name passes have already run, and only on lines that contain
     * a medicine indicator (mg, ml, tab, cap, syrup …).
     *   90+ = very strict (may miss slightly garbled text)
     *   82  = recommended balance (default)
     *   75  = more lenient (higher false-positive risk) */
    'fuzzy_threshold' => 82,

    /* ---------------- IMAGE PRE-PROCESSING ---------------- */
    /* Convert to grayscale, boost contrast, and upscale small images to
     * 1600 px wide. Improves printed-text OCR noticeably. Requires the GD
     * extension (already enabled in standard XAMPP builds). */
    'preprocess_image' => true,
];