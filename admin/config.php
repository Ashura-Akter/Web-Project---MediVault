<?php
require_once __DIR__ . '/../database/connection.php';
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function require_admin(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (!isset($_SESSION['user_id']) || strtoupper($_SESSION['role'] ?? '') !== 'ADMIN') {
        header('Location: ../login/login.php'); exit;
    }
}
function admin_id(mysqli $conn): ?int {
    $r=$conn->query("SELECT user_id FROM users WHERE role='ADMIN' AND is_active=TRUE ORDER BY (email='admin.medivult@gmail.com') DESC,user_id LIMIT 1");
    $x=$r?$r->fetch_assoc():null; return $x?(int)$x['user_id']:null;
}
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
require_admin();
