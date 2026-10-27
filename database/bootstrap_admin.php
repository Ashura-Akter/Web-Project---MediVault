<?php
require_once __DIR__ . '/connection.php';

function ensure_default_admin(mysqli $conn): void
{
    $defaultEmail = 'admin.medivult@gmail.com';
    $defaultPassword = '12344321';
    $defaultName = 'MediVault Admin';
    $defaultPhone = '01700000000';

    $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $defaultEmail);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) {
        // The default admin may update their password/profile later; do not overwrite it.
        return;
    }

    // On the supplied legacy seed, these are the old demo-admin accounts. Replace them
    // once with the requested default admin. After that, newly-added admins are preserved.
    $legacy = $conn->query("SELECT user_id FROM users WHERE role='ADMIN' AND is_active=TRUE AND LOWER(email) IN ('admin@gmail.com','natashaa@gmail.com')");
    if (!$legacy || $legacy->num_rows === 0) {
        return;
    }

    $conn->begin_transaction();
    try {
        $conn->query("UPDATE users SET is_active=FALSE WHERE role='ADMIN' AND is_active=TRUE AND LOWER(email) IN ('admin@gmail.com','natashaa@gmail.com')");
        $hash = password_hash($defaultPassword, PASSWORD_DEFAULT);
        $ins = $conn->prepare(
            "INSERT INTO users (full_name, email, phone, password_hash, role, is_active, created_at)
             VALUES (?, ?, ?, ?, 'ADMIN', TRUE, NOW())"
        );
        $ins->bind_param('ssss', $defaultName, $defaultEmail, $defaultPhone, $hash);
        $ins->execute();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}
