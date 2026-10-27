<?php
/*
 * MediVault database connection.
 */
$host = 'localhost';
$user = 'root';
$password = '';
$database = 'medivault';

$conn = new mysqli($host, $user, $password, $database);
if ($conn->connect_error) {
    die('Database connection failed: ' . htmlspecialchars($conn->connect_error));
}
$conn->set_charset('utf8mb4');

/* =====================================================================
 * Auto-OCR worker trigger — no terminal, no bat file, no cron required.
 *
 * Fires on EVERY page load of the whole project (patient, pharmacist,
 * admin, login — any page that includes connection.php).
 *
 * - Rate-limited to once every 4 minutes via a lock file.
 * - Runs AFTER the user's page is fully rendered using a fire-and-forget
 *   background HTTP request, so the user never sees any delay.
 * - Does NOT require any user to be logged in.
 * ===================================================================== */
register_shutdown_function(function () {
    static $fired = false;
    if ($fired) return;
    $fired = true;

    try {
        $lockFile = __DIR__ . '/.ocr_worker_lock';
        $lastRun  = is_file($lockFile) ? (int)file_get_contents($lockFile) : 0;
        if (time() - $lastRun < 120) return;   /* 2 minutes */

        /* Reserve the slot immediately to prevent double firing. */
        @file_put_contents($lockFile, time());

        /* Build the worker URL from the current request. */
        $proto  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

        /* project root = three levels up from /database/connection.php */
        $projectRoot = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
        $docRoot     = rtrim(str_replace('\\', '/', (string)($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
        $urlPath     = '/pharmasist/auto_ocr_worker.php?auto=1';

        if ($docRoot && strpos($projectRoot, $docRoot) === 0) {
            $urlPath = substr($projectRoot, strlen($docRoot)) . '/pharmasist/auto_ocr_worker.php?auto=1';
        }
        $url = $proto . '://' . $host . $urlPath;

        /* --- Fire-and-forget HTTP call with a 1-second timeout --- */
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 1,
                CURLOPT_CONNECTTIMEOUT => 1,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_NOSIGNAL       => 1,
                CURLOPT_FRESH_CONNECT  => 1,
            ]);
            @curl_exec($ch);
            curl_close($ch);
        } elseif (function_exists('fsockopen')) {
            /* Fallback: raw socket with 1-second timeout. */
            $parts = parse_url($url);
            $port  = ($parts['scheme'] ?? 'http') === 'https' ? 443 : 80;
            $fp = @fsockopen($parts['host'], $port, $errno, $errstr, 1);
            if ($fp) {
                $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
                $req  = "GET {$path} HTTP/1.1\r\nHost: {$parts['host']}\r\nConnection: close\r\n\r\n";
                @fwrite($fp, $req);
                @fclose($fp);
            }
        }
    } catch (Throwable $e) {
        /* never block the user's page */
    }
});