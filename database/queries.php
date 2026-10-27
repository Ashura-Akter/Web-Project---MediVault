<?php
require_once __DIR__ . '/connection.php';

function ensure_checkout_schema(mysqli $conn): void
{
    static $ready = false;
    if ($ready)
        return;
    $ready = true;

    $has = $conn->query("SHOW COLUMNS FROM cart_items LIKE 'pharmacy_id'");
    if ($has && $has->num_rows === 0) {
        $conn->query("ALTER TABLE cart_items ADD COLUMN pharmacy_id INT UNSIGNED NULL AFTER medicine_id");
    }

    $idx = $conn->query("SHOW INDEX FROM cart_items");
    $hasOldUnique = false;
    $hasNewUnique = false;
    $hasPharmacyIndex = false;
    if ($idx) {
        while ($r = $idx->fetch_assoc()) {
            if (($r['Key_name'] ?? '') === 'uq_cart_medicine')
                $hasOldUnique = true;
            if (($r['Key_name'] ?? '') === 'uq_cart_medicine_branch')
                $hasNewUnique = true;
            if (($r['Key_name'] ?? '') === 'idx_cart_item_pharmacy')
                $hasPharmacyIndex = true;
        }
    }
    $hasCartIndex = false;
    if ($idx) {
        $idx2 = $conn->query("SHOW INDEX FROM cart_items");
        if ($idx2)
            while ($r = $idx2->fetch_assoc())
                if (($r['Key_name'] ?? '') === 'idx_cart_item_cart')
                    $hasCartIndex = true;
    }
    if (!$hasCartIndex)
        $conn->query("ALTER TABLE cart_items ADD KEY idx_cart_item_cart (cart_id)");
    if (!$hasNewUnique)
        $conn->query("ALTER TABLE cart_items ADD UNIQUE KEY uq_cart_medicine_branch (cart_id, medicine_id, pharmacy_id)");
    if ($hasOldUnique)
        $conn->query("ALTER TABLE cart_items DROP INDEX uq_cart_medicine");
    if (!$hasPharmacyIndex)
        $conn->query("ALTER TABLE cart_items ADD KEY idx_cart_item_pharmacy (pharmacy_id)");

    $conn->query("UPDATE cart_items ci
                  JOIN (SELECT medicine_id, unit_price, MIN(pharmacy_id) pharmacy_id
                        FROM inventory_batches
                        WHERE expiry_date > CURRENT_DATE()
                          AND (quantity_on_hand - reserved_quantity) > 0
                        GROUP BY medicine_id, unit_price) x
                    ON x.medicine_id = ci.medicine_id AND x.unit_price = ci.unit_price
                  SET ci.pharmacy_id = x.pharmacy_id
                  WHERE ci.pharmacy_id IS NULL");
    $conn->query("UPDATE cart_items ci
                  JOIN (SELECT medicine_id, MIN(pharmacy_id) pharmacy_id
                        FROM inventory_batches
                        WHERE expiry_date > CURRENT_DATE()
                          AND (quantity_on_hand - reserved_quantity) > 0
                        GROUP BY medicine_id) x
                    ON x.medicine_id = ci.medicine_id
                  SET ci.pharmacy_id = x.pharmacy_id
                  WHERE ci.pharmacy_id IS NULL");

    foreach (
        [
            ['payment_provider', 'VARCHAR(40) NULL'],
            ['payment_account', 'VARCHAR(80) NULL'],
        ] as [$column, $definition]
    ) {
        $c = $conn->query("SHOW COLUMNS FROM payments LIKE '$column'");
        if ($c && $c->num_rows === 0)
            $conn->query("ALTER TABLE payments ADD COLUMN $column $definition AFTER payment_method");
    }
}

function get_user_by_email(mysqli $conn, string $email): ?array
{
    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function get_pharmacies(mysqli $conn): array
{
    $result = $conn->query("SELECT pharmacy_id, pharmacy_name, campus_name, address, phone FROM pharmacies WHERE is_active = TRUE ORDER BY pharmacy_id");
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function get_pharmacy_by_id(mysqli $conn, int $pharmacyId): ?array
{
    $stmt = $conn->prepare("SELECT pharmacy_id, pharmacy_name, campus_name, address, phone, opening_time, closing_time FROM pharmacies WHERE pharmacy_id = ? AND is_active = TRUE LIMIT 1");
    $stmt->bind_param("i", $pharmacyId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function get_pharmacy_by_name(mysqli $conn, string $name): ?array
{
    $stmt = $conn->prepare("SELECT pharmacy_id, pharmacy_name, campus_name, address, phone, opening_time, closing_time FROM pharmacies WHERE pharmacy_name = ? AND is_active = TRUE LIMIT 1");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

function get_categories(mysqli $conn): array
{
    $result = $conn->query("SELECT category_id, category_name FROM categories ORDER BY category_id");
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function get_medicines(mysqli $conn, string $search = '', int $categoryId = 0, bool $restrictedOnly = false, int $branchId = 0, string $sort = 'newest'): array
{
    $sql = "SELECT m.medicine_id, m.medicine_name, m.generic_name, m.strength, m.dosage_form, m.company_name,
                   m.description, m.image_path, m.is_restricted, m.requires_prescription, c.category_name,
                   p.pharmacy_id, p.pharmacy_name,
                   COALESCE(SUM(ib.quantity_on_hand - ib.reserved_quantity),0) AS available_stock,
                   MIN(ib.expiry_date) AS nearest_expiry_date,
                   MIN(ib.unit_price) AS stock_price
            FROM medicines m
            JOIN categories c ON c.category_id = m.category_id
            LEFT JOIN inventory_batches ib ON ib.medicine_id = m.medicine_id
                 AND ib.expiry_date > CURRENT_DATE()
                 AND (ib.quantity_on_hand - ib.reserved_quantity) > 0
            LEFT JOIN pharmacies p ON p.pharmacy_id = ib.pharmacy_id AND p.is_active = TRUE
            LEFT JOIN medicine_symptoms ms ON ms.medicine_id = m.medicine_id
            LEFT JOIN symptoms s ON s.symptom_id = ms.symptom_id
            WHERE m.is_active = TRUE AND (? = 0 OR ib.pharmacy_id = ?)";
    $types = 'ii';
    $params = [$branchId, $branchId];

    if ($search !== '') {
        $sql .= " AND (m.medicine_name LIKE ? OR m.generic_name LIKE ? OR m.company_name LIKE ? OR c.category_name LIKE ? OR s.symptom_name LIKE ?)";
        $like = '%' . $search . '%';
        $types .= 'sssss';
        array_push($params, $like, $like, $like, $like, $like);
    }
    if ($categoryId > 0) {
        $sql .= " AND m.category_id = ?";
        $types .= 'i';
        $params[] = $categoryId;
    }
    if ($restrictedOnly) {
        $sql .= " AND m.is_restricted = TRUE";
    }

    $sortMap = [
        'newest' => 'MAX(ib.created_at) DESC, MAX(ib.last_restocked_at) DESC, m.medicine_id DESC',
        'name' => 'm.medicine_name ASC, m.medicine_id ASC',
        'expiry' => 'MIN(ib.expiry_date) ASC, m.medicine_name ASC',
        'price' => 'MIN(ib.unit_price) ASC, m.medicine_name ASC',
        'quantity' => 'SUM(ib.quantity_on_hand - ib.reserved_quantity) DESC, m.medicine_name ASC',
        'branch' => 'p.pharmacy_name ASC, MAX(ib.created_at) DESC, m.medicine_name ASC'
    ];
    $orderBy = $sortMap[$sort] ?? $sortMap['newest'];

    $sql .= " GROUP BY m.medicine_id, p.pharmacy_id HAVING (? = 0 OR p.pharmacy_id IS NOT NULL) ORDER BY $orderBy";
    $types .= 'i';
    $params[] = $branchId;

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function get_or_create_active_cart(mysqli $conn, int $userId): int
{
    $stmt = $conn->prepare("SELECT cart_id FROM carts WHERE user_id = ? AND status = 'ACTIVE' LIMIT 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row)
        return (int) $row['cart_id'];
    $stmt = $conn->prepare("INSERT INTO carts (user_id, status) VALUES (?, 'ACTIVE')");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    return (int) $conn->insert_id;
}

function add_to_cart(mysqli $conn, int $userId, int $medicineId, int $quantity = 1, int $pharmacyId = 0): bool
{
    ensure_checkout_schema($conn);
    $cartId = get_or_create_active_cart($conn, $userId);

    if ($pharmacyId > 0) {
        $stmt = $conn->prepare("SELECT MIN(ib.unit_price) AS unit_price FROM inventory_batches ib
                                 JOIN medicines m ON m.medicine_id = ib.medicine_id
                                 JOIN pharmacies p ON p.pharmacy_id = ib.pharmacy_id AND p.is_active = TRUE
                                 WHERE ib.medicine_id = ? AND ib.pharmacy_id = ?
                                   AND m.is_active = TRUE AND ib.expiry_date > CURRENT_DATE()
                                   AND (ib.quantity_on_hand - ib.reserved_quantity) > 0");
        $stmt->bind_param("ii", $medicineId, $pharmacyId);
    } else {
        $stmt = $conn->prepare("SELECT MIN(ib.unit_price) AS unit_price, MIN(ib.pharmacy_id) AS pharmacy_id FROM inventory_batches ib
                                 JOIN medicines m ON m.medicine_id = ib.medicine_id
                                 JOIN pharmacies p ON p.pharmacy_id = ib.pharmacy_id AND p.is_active = TRUE
                                 WHERE ib.medicine_id = ? AND m.is_active = TRUE AND ib.expiry_date > CURRENT_DATE()
                                   AND (ib.quantity_on_hand - ib.reserved_quantity) > 0");
        $stmt->bind_param("i", $medicineId);
    }
    $stmt->execute();
    $medicine = $stmt->get_result()->fetch_assoc();
    if (!$medicine || $medicine['unit_price'] === null)
        return false;
    if ($pharmacyId <= 0)
        $pharmacyId = (int) ($medicine['pharmacy_id'] ?? 0);
    if ($pharmacyId <= 0)
        return false;

    $price = (float) $medicine['unit_price'];
    $stmt = $conn->prepare(
        "INSERT INTO cart_items (cart_id, medicine_id, pharmacy_id, quantity, unit_price)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity), unit_price = VALUES(unit_price)"
    );
    $stmt->bind_param("iiiid", $cartId, $medicineId, $pharmacyId, $quantity, $price);
    return $stmt->execute();
}

function toggle_favorite(mysqli $conn, int $userId, int $medicineId, int $pharmacyId): bool
{
    if ($medicineId <= 0 || $pharmacyId <= 0)
        return false;
    $stmt = $conn->prepare("SELECT favorite_id FROM favorite_medicines WHERE user_id = ? AND medicine_id = ? AND pharmacy_id = ? LIMIT 1");
    $stmt->bind_param("iii", $userId, $medicineId, $pharmacyId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    if ($existing) {
        $stmt = $conn->prepare("DELETE FROM favorite_medicines WHERE favorite_id = ?");
        $stmt->bind_param("i", $existing['favorite_id']);
        return $stmt->execute();
    }
    $stmt = $conn->prepare("INSERT INTO favorite_medicines (user_id, medicine_id, pharmacy_id) VALUES (?, ?, ?)");
    $stmt->bind_param("iii", $userId, $medicineId, $pharmacyId);
    return $stmt->execute();
}

function get_favorite_medicine_ids(mysqli $conn, int $userId): array
{
    $stmt = $conn->prepare("SELECT medicine_id, pharmacy_id FROM favorite_medicines WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $ids = [];
    while ($row = $result->fetch_assoc())
        $ids[(int) $row['medicine_id'] . ':' . (int) $row['pharmacy_id']] = true;
    return $ids;
}

function is_favorite_for_branch(array $favoriteIds, int $medicineId, int $pharmacyId): bool
{
    return !empty($favoriteIds[$medicineId . ':' . $pharmacyId]);
}

function get_favorites(mysqli $conn, int $userId): array
{
    $stmt = $conn->prepare(
        "SELECT f.favorite_id, f.medicine_id, f.pharmacy_id, f.saved_at,
                m.medicine_name, m.generic_name, m.strength, m.dosage_form, m.company_name, m.description,
                m.image_path, m.is_restricted, m.requires_prescription, c.category_name, p.pharmacy_name,
                COALESCE(SUM(CASE WHEN ib.expiry_date > CURRENT_DATE() AND (ib.quantity_on_hand - ib.reserved_quantity) > 0 THEN ib.quantity_on_hand - ib.reserved_quantity ELSE 0 END),0) AS available_stock,
                MIN(CASE WHEN ib.expiry_date > CURRENT_DATE() AND (ib.quantity_on_hand - ib.reserved_quantity) > 0 THEN ib.expiry_date ELSE NULL END) AS nearest_expiry_date,
                MIN(CASE WHEN ib.expiry_date > CURRENT_DATE() AND (ib.quantity_on_hand - ib.reserved_quantity) > 0 THEN ib.unit_price ELSE NULL END) AS unit_price
         FROM favorite_medicines f
         JOIN medicines m ON m.medicine_id = f.medicine_id
         JOIN categories c ON c.category_id = m.category_id
         JOIN pharmacies p ON p.pharmacy_id = f.pharmacy_id AND p.is_active = TRUE
         LEFT JOIN inventory_batches ib ON ib.medicine_id = f.medicine_id AND ib.pharmacy_id = f.pharmacy_id
         WHERE f.user_id = ?
         GROUP BY f.favorite_id, f.medicine_id, f.pharmacy_id, f.saved_at, m.medicine_name, m.generic_name, m.strength, m.dosage_form, m.company_name, m.description, m.image_path, m.is_restricted, m.requires_prescription, c.category_name, p.pharmacy_name
         ORDER BY f.saved_at DESC, f.favorite_id DESC"
    );
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function get_cart(mysqli $conn, int $userId): array
{
    ensure_checkout_schema($conn);
    $stmt = $conn->prepare(
        "SELECT ci.cart_item_id, ci.medicine_id, ci.pharmacy_id, ci.quantity, ci.unit_price,
                m.medicine_name, m.strength, m.is_restricted, c.category_name, p.pharmacy_name,
                (ci.quantity * ci.unit_price) AS line_total
         FROM carts ca
         JOIN cart_items ci ON ci.cart_id = ca.cart_id
         JOIN medicines m ON m.medicine_id = ci.medicine_id
         JOIN categories c ON c.category_id = m.category_id
         LEFT JOIN pharmacies p ON p.pharmacy_id = ci.pharmacy_id
         WHERE ca.user_id = ? AND ca.status = 'ACTIVE'
         ORDER BY ci.added_at DESC"
    );
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function get_cart_item_count(mysqli $conn, int $userId): int
{
    ensure_checkout_schema($conn);
    $stmt = $conn->prepare("SELECT COUNT(*) FROM carts c JOIN cart_items ci ON ci.cart_id=c.cart_id WHERE c.user_id=? AND c.status='ACTIVE'");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    return (int) ($stmt->get_result()->fetch_row()[0] ?? 0);
}

function update_cart_item(mysqli $conn, int $userId, int $cartItemId, int $quantity): bool
{
    if ($quantity < 1)
        $quantity = 1;
    $stmt = $conn->prepare("UPDATE cart_items ci JOIN carts c ON c.cart_id = ci.cart_id SET ci.quantity = ? WHERE ci.cart_item_id = ? AND c.user_id = ? AND c.status = 'ACTIVE'");
    $stmt->bind_param("iii", $quantity, $cartItemId, $userId);
    return $stmt->execute();
}

function remove_cart_item(mysqli $conn, int $userId, int $cartItemId): bool
{
    $stmt = $conn->prepare("DELETE ci FROM cart_items ci JOIN carts c ON c.cart_id = ci.cart_id WHERE ci.cart_item_id = ? AND c.user_id = ? AND c.status = 'ACTIVE'");
    $stmt->bind_param("ii", $cartItemId, $userId);
    return $stmt->execute();
}

function get_orders(mysqli $conn, int $userId): array
{
    $stmt = $conn->prepare(
        "SELECT o.order_id, o.order_number, o.pharmacy_id, p.pharmacy_name, o.fulfillment_type, o.order_status,
                o.subtotal, o.delivery_fee, o.total_amount, o.placed_at, pay.payment_method, pay.payment_status
         FROM orders o
         JOIN pharmacies p ON p.pharmacy_id = o.pharmacy_id
         LEFT JOIN payments pay ON pay.order_id = o.order_id
         WHERE o.patient_id = ?
         ORDER BY o.placed_at DESC"
    );
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function get_order_details(mysqli $conn, int $userId, int $orderId): ?array
{
    $stmt = $conn->prepare(
        "SELECT o.*, p.pharmacy_name, p.campus_name, pay.payment_method, pay.payment_status
         FROM orders o JOIN pharmacies p ON p.pharmacy_id = o.pharmacy_id
         LEFT JOIN payments pay ON pay.order_id = o.order_id
         WHERE o.order_id = ? AND o.patient_id = ? LIMIT 1"
    );
    $stmt->bind_param("ii", $orderId, $userId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    if (!$order)
        return null;

    $stmt = $conn->prepare("SELECT oi.*, m.medicine_name, m.strength FROM order_items oi JOIN medicines m ON m.medicine_id = oi.medicine_id WHERE oi.order_id = ? ORDER BY oi.order_item_id");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $order['items'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    return $order;
}

function get_user_notifications(mysqli $conn, int $userId, int $limit = 20, string $role = 'PATIENT'): array
{
    $limit = max(1, min(50, $limit));
    $stmt = $conn->prepare("SELECT notification_id, notification_type, title, message, related_order_id, related_reservation_id, related_prescription_id, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC, notification_id DESC LIMIT $limit");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function get_unread_notification_count(mysqli $conn, int $userId, string $role = 'PATIENT'): int
{
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    return (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
}

function get_pharmacy_staff_user_ids(mysqli $conn, int $pharmacyId): array
{
    if ($pharmacyId <= 0)
        return [];
    $stmt = $conn->prepare("SELECT DISTINCT pp.user_id FROM pharmacist_profiles pp JOIN users u ON u.user_id = pp.user_id WHERE pp.pharmacy_id = ? AND pp.approval_status = 'APPROVED' AND u.role = 'PHARMACIST' AND u.is_active = TRUE ORDER BY pp.pharmacist_profile_id");
    $stmt->bind_param('i', $pharmacyId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    return array_values(array_unique(array_map(static fn($row) => (int) $row['user_id'], $rows)));
}

function get_pharmacy_display_name(mysqli $conn, int $pharmacyId): string
{
    if ($pharmacyId <= 0)
        return 'the pharmacy';
    $stmt = $conn->prepare("SELECT pharmacy_name FROM pharmacies WHERE pharmacy_id = ? LIMIT 1");
    $stmt->bind_param('i', $pharmacyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return !empty($row['pharmacy_name']) ? (string) $row['pharmacy_name'] : 'the pharmacy';
}

function notify_pharmacy_staff(mysqli $conn, int $pharmacyId, string $type, string $title, string $message, ?int $orderId = null, ?int $reservationId = null, ?int $prescriptionId = null): int
{
    $sent = 0;
    foreach (get_pharmacy_staff_user_ids($conn, $pharmacyId) as $staffUserId) {
        if (add_notification($conn, $staffUserId, $type, $title, $message, $orderId, $reservationId, $prescriptionId))
            $sent++;
    }
    return $sent;
}

function notify_admins(mysqli $conn, string $type, string $title, string $message): int
{
    $sent = 0;
    $res = $conn->query("SELECT user_id FROM users WHERE role='ADMIN' AND is_active=TRUE");
    if (!$res)
        return 0;
    while ($row = $res->fetch_assoc())
        if (add_notification($conn, (int) $row['user_id'], $type, $title, $message))
            $sent++;
    return $sent;
}

function sync_admin_stock_notifications(mysqli $conn, int $adminId): void
{
    $build = static function (int $count, array $names, string $tail): string {
        $shown = array_slice($names, 0, 3);
        $msg = $count . ($count === 1 ? ' medicine ' : ' medicines ') . $tail . ': ' . implode(', ', $shown);
        if ($count > count($shown))
            $msg .= ' and ' . ($count - count($shown)) . ' more';
        return $msg . '.';
    };
    $push = static function (string $type, string $title, string $message) use ($conn, $adminId): void {
        $st = $conn->prepare("SELECT message FROM notifications WHERE user_id=? AND notification_type=? ORDER BY notification_id DESC LIMIT 1");
        $st->bind_param('is', $adminId, $type);
        $st->execute();
        $last = $st->get_result()->fetch_assoc();
        if ($last && $last['message'] === $message)
            return;
        add_notification($conn, $adminId, $type, $title, $message);
    };

    $low = $conn->query("SELECT m.medicine_name FROM medicines m LEFT JOIN inventory_batches ib ON ib.medicine_id=m.medicine_id WHERE m.is_active=TRUE GROUP BY m.medicine_id,m.medicine_name,m.reorder_level HAVING COALESCE(SUM(GREATEST(ib.quantity_on_hand-ib.reserved_quantity,0)),0)<=m.reorder_level ORDER BY m.medicine_name");
    if ($low && $low->num_rows > 0) {
        $names = array_column($low->fetch_all(MYSQLI_ASSOC), 'medicine_name');
        $push('LOW_STOCK', 'Low Stock Alert', $build(count($names), $names, 'at or below the reorder level'));
    }
    $exp = $conn->query("SELECT DISTINCT m.medicine_name FROM medicines m JOIN inventory_batches ib ON ib.medicine_id=m.medicine_id WHERE m.is_active=TRUE AND ib.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 90 DAY) ORDER BY m.medicine_name");
    if ($exp && $exp->num_rows > 0) {
        $names = array_column($exp->fetch_all(MYSQLI_ASSOC), 'medicine_name');
        $push('EXPIRY_WARNING', 'Expiry Alert', $build(count($names), $names, 'expiring within 90 days'));
    }
}

function add_notification(mysqli $conn, int $userId, string $type, string $title, string $message, ?int $orderId = null, ?int $reservationId = null, ?int $prescriptionId = null): bool
{
    $allowed = ['PRESCRIPTION_UPLOADED', 'PRESCRIPTION_APPROVED', 'PRESCRIPTION_REJECTED', 'RESERVATION_ACCEPTED', 'RESERVATION_READY', 'RESERVATION_CANCELLED', 'ORDER_UPDATE', 'PAYMENT_UPDATE', 'DRUG_INTERACTION', 'REFILL_REMINDER', 'LOW_STOCK', 'EXPIRY_WARNING', 'PHARMACIST_APPROVAL', 'GENERAL'];
    if (!in_array($type, $allowed, true))
        $type = 'GENERAL';
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, notification_type, title, message, related_order_id, related_reservation_id, related_prescription_id) VALUES (?, ?, ?, ?, NULLIF(?,0), NULLIF(?,0), NULLIF(?,0))");
    $o = $orderId ?? 0;
    $r = $reservationId ?? 0;
    $p = $prescriptionId ?? 0;
    $stmt->bind_param('isssiii', $userId, $type, $title, $message, $o, $r, $p);
    return $stmt->execute();
}

function add_restricted_medicine_to_cart(mysqli $conn, int $patientId, int $pharmacyId, int $medicineId, int $quantity = 1): bool
{
    ensure_checkout_schema($conn);
    $quantity = max(1, $quantity);

    $cartStmt = $conn->prepare("SELECT cart_id FROM carts WHERE user_id = ? AND status = 'ACTIVE' ORDER BY cart_id DESC LIMIT 1");
    $cartStmt->bind_param('i', $patientId);
    $cartStmt->execute();
    $cart = $cartStmt->get_result()->fetch_assoc();
    if ($cart) {
        $cartId = (int) $cart['cart_id'];
    } else {
        $createCart = $conn->prepare("INSERT INTO carts (user_id, status) VALUES (?, 'ACTIVE')");
        $createCart->bind_param('i', $patientId);
        if (!$createCart->execute())
            return false;
        $cartId = (int) $conn->insert_id;
    }

    $priceStmt = $conn->prepare("SELECT COALESCE(MIN(CASE WHEN expiry_date > CURRENT_DATE() AND (quantity_on_hand - reserved_quantity) > 0 THEN unit_price END), MIN(unit_price)) AS unit_price FROM inventory_batches WHERE pharmacy_id = ? AND medicine_id = ?");
    $priceStmt->bind_param('ii', $pharmacyId, $medicineId);
    $priceStmt->execute();
    $priceRow = $priceStmt->get_result()->fetch_assoc();
    $unitPrice = isset($priceRow['unit_price']) ? (float) $priceRow['unit_price'] : 0.0;
    if ($unitPrice <= 0)
        return false;

    $existingStmt = $conn->prepare("SELECT cart_item_id, quantity FROM cart_items WHERE cart_id = ? AND medicine_id = ? AND pharmacy_id = ? LIMIT 1");
    $existingStmt->bind_param('iii', $cartId, $medicineId, $pharmacyId);
    $existingStmt->execute();
    $existing = $existingStmt->get_result()->fetch_assoc();

    if ($existing) {
        $newQty = (int) $existing['quantity'] + $quantity;
        $update = $conn->prepare("UPDATE cart_items SET quantity = ?, unit_price = ? WHERE cart_item_id = ?");
        $itemId = (int) $existing['cart_item_id'];
        $update->bind_param('idi', $newQty, $unitPrice, $itemId);
        return $update->execute();
    }

    $insert = $conn->prepare("INSERT INTO cart_items (cart_id, medicine_id, pharmacy_id, quantity, unit_price) VALUES (?, ?, ?, ?, ?)");
    $insert->bind_param('iiiid', $cartId, $medicineId, $pharmacyId, $quantity, $unitPrice);
    return $insert->execute();
}

function get_patient_prescriptions(mysqli $conn, int $userId): array
{
    $stmt = $conn->prepare("SELECT pr.*, u.full_name AS pharmacist_name FROM prescriptions pr LEFT JOIN users u ON u.user_id = pr.reviewed_by_pharmacist_id WHERE pr.patient_id = ? ORDER BY pr.uploaded_at DESC");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function get_latest_approved_prescription(mysqli $conn, int $userId): ?array
{
    $stmt = $conn->prepare("SELECT * FROM prescriptions WHERE patient_id = ? AND status = 'APPROVED' ORDER BY reviewed_at DESC, prescription_id DESC LIMIT 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

/* FIFO stock deduction from inventory_batches at the given pharmacy. */
function deduct_stock_fifo(mysqli $conn, int $pharmacyId, int $medicineId, int $qty): void
{
    if ($qty <= 0 || $pharmacyId <= 0 || $medicineId <= 0)
        return;
    $stmt = $conn->prepare(
        "SELECT inventory_batch_id, quantity_on_hand, reserved_quantity
         FROM inventory_batches
         WHERE pharmacy_id = ? AND medicine_id = ? AND expiry_date > CURDATE()
           AND (quantity_on_hand - reserved_quantity) > 0
         ORDER BY expiry_date ASC, inventory_batch_id ASC"
    );
    $stmt->bind_param('ii', $pharmacyId, $medicineId);
    $stmt->execute();
    $batches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $remaining = $qty;
    foreach ($batches as $b) {
        if ($remaining <= 0)
            break;
        $avail = (int) $b['quantity_on_hand'] - (int) $b['reserved_quantity'];
        $take = min($avail, $remaining);
        if ($take <= 0)
            continue;
        $upd = $conn->prepare("UPDATE inventory_batches SET quantity_on_hand = quantity_on_hand - ? WHERE inventory_batch_id = ?");
        $bid = (int) $b['inventory_batch_id'];
        $upd->bind_param('ii', $take, $bid);
        $upd->execute();
        $remaining -= $take;
    }
}

/* Generate a guaranteed-unique transaction reference. */
function generate_unique_txn_reference(): string
{
    return 'TXN-MV-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(4)));
}

function create_order_from_cart(
    mysqli $conn,
    int $userId,
    array $customer,
    string $deliveryStatus,
    string $paymentMethod,
    ?string $paymentProvider,
    ?string $paymentAccount,
    ?string $paymentReference,
    ?string $reservationDeadline,
    array $selectedItemIds = []
): array {
    ensure_checkout_schema($conn);
    $items = get_cart($conn, $userId);
    $selectedMap = [];
    foreach ($selectedItemIds as $id) {
        $id = (int) $id;
        if ($id > 0)
            $selectedMap[$id] = true;
    }
    $items = $selectedItemIds ? array_values(array_filter($items, fn($item) => isset($selectedMap[(int) $item['cart_item_id']]))) : $items;
    if (!$items)
        return ['ok' => false, 'message' => 'Please select at least one item.'];

    $deliveryStatus = strtoupper(trim($deliveryStatus));
    $paymentMethod = strtoupper(trim($paymentMethod));
    if (!in_array($deliveryStatus, ['RESERVE', 'HOME_DELIVERY'], true))
        return ['ok' => false, 'message' => 'Please select a valid delivery status.'];
    if (!in_array($paymentMethod, ['CASH', 'ONLINE_PAYMENT'], true))
        return ['ok' => false, 'message' => 'Please select a valid payment method.'];
    foreach (['name', 'mobile', 'email', 'address'] as $required) {
        if (trim((string) ($customer[$required] ?? '')) === '')
            return ['ok' => false, 'message' => 'All billing details are required.'];
    }
    if ($deliveryStatus === 'RESERVE') {
        if (!$reservationDeadline)
            return ['ok' => false, 'message' => 'Please select a reservation date and time.'];
        $deadlineTs = strtotime($reservationDeadline);
        if (!$deadlineTs || $deadlineTs <= time())
            return ['ok' => false, 'message' => 'Reservation date and time must be in the future.'];
    }
    if ($paymentMethod === 'ONLINE_PAYMENT' && trim((string) $paymentProvider) === '')
        return ['ok' => false, 'message' => 'Please select an online payment method.'];
    if ($paymentMethod === 'ONLINE_PAYMENT' && trim((string) $paymentAccount) === '')
        return ['ok' => false, 'message' => 'Please provide the required payment information.'];

    $groups = [];
    foreach ($items as $item) {
        $pid = (int) ($item['pharmacy_id'] ?? 0);
        if ($pid <= 0)
            return ['ok' => false, 'message' => 'A pharmacy could not be determined for ' . $item['medicine_name'] . '.'];
        $groups[$pid][] = $item;
    }

    $conn->begin_transaction();
    try {
        $createdOrders = [];
        $createdReservations = [];
        $grandTotal = 0.0;
        foreach ($groups as $pharmacyId => $groupItems) {
            $subtotal = 0.0;
            $groupRx = null;
            foreach ($groupItems as &$item) {
                $stmt = $conn->prepare("SELECT COALESCE(SUM(quantity_on_hand - reserved_quantity),0) AS available, MIN(unit_price) AS unit_price FROM inventory_batches WHERE pharmacy_id=? AND medicine_id=? AND expiry_date>CURRENT_DATE() AND (quantity_on_hand - reserved_quantity) > 0");
                $mid = (int) $item['medicine_id'];
                $stmt->bind_param('ii', $pharmacyId, $mid);
                $stmt->execute();
                $stock = $stmt->get_result()->fetch_assoc();
                if ((int) $item['quantity'] > (int) $stock['available'] || $stock['unit_price'] === null)
                    throw new Exception($item['medicine_name'] . ' is not available.');
                if ((int) $item['is_restricted'] === 1) {
                    $groupRx = get_latest_approved_prescription($conn, $userId);
                    if (!$groupRx)
                        throw new Exception('An approved prescription is required for restricted medicine.');
                }
                $item['unit_price'] = (float) $stock['unit_price'];
                $item['line_total'] = (int) $item['quantity'] * (float) $stock['unit_price'];
                $subtotal += $item['line_total'];
            }
            unset($item);

            if ($deliveryStatus === 'RESERVE') {
                $deadlineTs = strtotime($reservationDeadline);
                $hours = max(1, (int) ceil(($deadlineTs - time()) / 3600));
                $resNo = 'RES-' . date('YmdHis') . '-' . random_int(100, 999);
                $stmt = $conn->prepare("INSERT INTO reservations (patient_id,pharmacy_id,prescription_id,reservation_number,status,reserved_for_hours,requested_at,pickup_deadline) VALUES (?,?,NULLIF(?,0),?,'PENDING',?,NOW(),?)");
                $deadline = date('Y-m-d H:i:s', $deadlineTs);
                $rxId = $groupRx ? (int) $groupRx['prescription_id'] : 0;
                $stmt->bind_param('iiisis', $userId, $pharmacyId, $rxId, $resNo, $hours, $deadline);
                $stmt->execute();
                $resId = (int) $conn->insert_id;

                foreach ($groupItems as $item) {
                    $stmt = $conn->prepare("INSERT INTO reservation_items (reservation_id,medicine_id,quantity) VALUES (?,?,?)");
                    $mid = (int) $item['medicine_id'];
                    $qty = (int) $item['quantity'];
                    $stmt->bind_param('iii', $resId, $mid, $qty);
                    $stmt->execute();
                }

                /* Reserve stock: bump reserved_quantity FIFO. */
                foreach ($groupItems as $item) {
                    $mid = (int) $item['medicine_id'];
                    $qty = (int) $item['quantity'];
                    $bstmt = $conn->prepare("SELECT inventory_batch_id, (quantity_on_hand - reserved_quantity) AS avail FROM inventory_batches WHERE pharmacy_id=? AND medicine_id=? AND expiry_date>CURDATE() AND (quantity_on_hand - reserved_quantity) > 0 ORDER BY expiry_date ASC LIMIT 20");
                    $bstmt->bind_param('ii', $pharmacyId, $mid);
                    $bstmt->execute();
                    $batches = $bstmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $remaining = $qty;
                    foreach ($batches as $b) {
                        if ($remaining <= 0)
                            break;
                        $take = min((int) $b['avail'], $remaining);
                        if ($take <= 0)
                            continue;
                        $u = $conn->prepare("UPDATE inventory_batches SET reserved_quantity = reserved_quantity + ? WHERE inventory_batch_id = ?");
                        $bid = (int) $b['inventory_batch_id'];
                        $u->bind_param('ii', $take, $bid);
                        $u->execute();
                        $remaining -= $take;
                    }
                }

                $createdReservations[] = ['reservation_id' => $resId, 'reservation_number' => $resNo];
                $phName = get_pharmacy_display_name($conn, $pharmacyId);
                add_notification($conn, $userId, 'RESERVATION_ACCEPTED', 'Reservation Request Submitted', 'Your reservation request has been sent to ' . $phName . '. You will be notified once approved.', null, $resId, null);
                notify_pharmacy_staff($conn, $pharmacyId, 'RESERVATION_ACCEPTED', 'New Reservation Request', 'A patient has placed a reservation request at ' . $phName . '.', null, $resId, null);
                $grandTotal += $subtotal;
            } else {
                $deliveryFee = 80.0;
                $total = $subtotal + $deliveryFee;
                $orderNo = 'ORD-' . date('YmdHis') . '-' . random_int(100, 999);
                $stmt = $conn->prepare("INSERT INTO orders (patient_id,pharmacy_id,prescription_id,order_number,order_type,fulfillment_type,delivery_address,order_status,subtotal,delivery_fee,total_amount) VALUES (?,?,NULLIF(?,0),?,'PURCHASE','HOME_DELIVERY',?,'PROCESSING',?,?,?)");
                $address = trim((string) $customer['address']);
                $rxId = $groupRx ? (int) $groupRx['prescription_id'] : 0;
                $stmt->bind_param('iiissddd', $userId, $pharmacyId, $rxId, $orderNo, $address, $subtotal, $deliveryFee, $total);
                $stmt->execute();
                $orderId = (int) $conn->insert_id;

                foreach ($groupItems as $item) {
                    $stmt = $conn->prepare("INSERT INTO order_items (order_id,medicine_id,quantity,unit_price) VALUES (?,?,?,?)");
                    $mid = (int) $item['medicine_id'];
                    $qty = (int) $item['quantity'];
                    $price = (float) $item['unit_price'];
                    $stmt->bind_param('iiid', $orderId, $mid, $qty, $price);
                    $stmt->execute();
                    deduct_stock_fifo($conn, $pharmacyId, $mid, $qty);
                }

                $provider = $paymentMethod === 'ONLINE_PAYMENT' ? $paymentProvider : null;
                $account = $paymentMethod === 'ONLINE_PAYMENT' ? $paymentAccount : null;

                /* Ensure the transaction reference is unique. */
                $ref = $paymentReference;
                if ($paymentMethod === 'ONLINE_PAYMENT') {
                    $attempts = 0;
                    while ($attempts < 5) {
                        $chk = $conn->prepare("SELECT payment_id FROM payments WHERE transaction_reference = ? LIMIT 1");
                        $chk->bind_param('s', $ref);
                        $chk->execute();
                        if (!$chk->get_result()->fetch_assoc())
                            break;
                        $ref = generate_unique_txn_reference();
                        $attempts++;
                    }
                    if (!$ref)
                        $ref = generate_unique_txn_reference();

                    $stmt = $conn->prepare("INSERT INTO payments (order_id,payment_method,payment_provider,payment_account,payment_status,amount,transaction_reference,paid_at) VALUES (?,'ONLINE_PAYMENT',?,?,'PAID',?,?,NOW())");
                    $stmt->bind_param('issds', $orderId, $provider, $account, $total, $ref);
                } else {
                    $stmt = $conn->prepare("INSERT INTO payments (order_id,payment_method,payment_provider,payment_account,payment_status,amount,transaction_reference,paid_at) VALUES (?,'CASH',NULL,NULL,'UNPAID',?,NULL,NULL)");
                    $stmt->bind_param('id', $orderId, $total);
                }
                $stmt->execute();

                $createdOrders[] = ['order_id' => $orderId, 'order_number' => $orderNo, 'total' => $total];
                add_notification($conn, $userId, 'ORDER_UPDATE', 'Order Placed', 'Your order ' . $orderNo . ' has been placed successfully.', $orderId, null, null);
                $phName = get_pharmacy_display_name($conn, $pharmacyId);
                notify_pharmacy_staff($conn, $pharmacyId, 'ORDER_UPDATE', 'New Order Received', 'A new online patient order has been placed at ' . $phName . '.', $orderId, null, null);
                $grandTotal += $total;
            }
        }

        $ids = array_map(fn($x) => (int) $x['cart_item_id'], $items);
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $types = 'i' . str_repeat('i', count($ids));
            $stmt = $conn->prepare("DELETE ci FROM cart_items ci JOIN carts c ON c.cart_id=ci.cart_id WHERE c.user_id=? AND c.status='ACTIVE' AND ci.cart_item_id IN ($ph)");
            $params = array_merge([$userId], $ids);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
        }
        $conn->commit();
        return ['ok' => true, 'orders' => $createdOrders, 'reservations' => $createdReservations, 'total' => $grandTotal, 'is_reservation' => $deliveryStatus === 'RESERVE'];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}

/* =====================================================================
 * v13 helpers
 * ===================================================================== */
function ensure_medicine_schema(mysqli $conn): void
{
    static $done = false;
    if ($done)
        return;
    $done = true;
    try {
        $c = $conn->query("SHOW COLUMNS FROM medicines LIKE 'image_path'");
        if ($c && $c->num_rows === 0)
            $conn->query("ALTER TABLE medicines ADD COLUMN image_path VARCHAR(255) NULL AFTER description");
        $d = $conn->query("SHOW COLUMNS FROM medicines LIKE 'dosage_form'");
        if ($d && ($row = $d->fetch_assoc()) && stripos((string) $row['Type'], 'enum') === 0)
            $conn->query("ALTER TABLE medicines MODIFY dosage_form VARCHAR(60) NOT NULL DEFAULT 'TABLET'");
    } catch (Throwable $e) {
    }
}
if (isset($conn) && $conn instanceof mysqli)
    ensure_medicine_schema($conn);

function medicine_form_label($form): string
{
    $form = trim((string) $form);
    if ($form === '')
        return '';
    return ucwords(strtolower(str_replace('_', ' ', $form)));
}

function medicine_image_url(?string $path): ?string
{
    $path = trim((string) $path);
    if ($path === '')
        return null;
    if (preg_match('#^(https?:)?//#i', $path) === 1 || strpos($path, 'data:') === 0)
        return $path;
    if (strpos($path, '..') !== false)
        return null;
    $abs = __DIR__ . '/../' . $path;
    if (!is_file($abs))
        return null;
    return '../' . ltrim(str_replace('\\', '/', $path), '/');
}

function medicine_image_html(array $m, string $extraClass = ''): string
{
    $url = medicine_image_url($m['image_path'] ?? null);
    $name = htmlspecialchars((string) ($m['medicine_name'] ?? 'Medicine'), ENT_QUOTES, 'UTF-8');
    $cls = 'mv-med-img' . ($extraClass !== '' ? ' ' . $extraClass : '');
    if ($url)
        return '<div class="' . $cls . '"><img src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" alt="' . $name . '" loading="lazy"></div>';
    return '<div class="' . $cls . ' mv-med-img-empty" aria-hidden="true"><svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg"><g transform="rotate(-40 32 32)"><rect x="6" y="21" width="52" height="22" rx="11" fill="#ffffff" stroke="#078f86" stroke-width="2.5"/><path d="M32 21h15a11 11 0 0 1 0 22H32z" fill="#bfe5e0" stroke="#078f86" stroke-width="2.5" stroke-linejoin="round"/></g></svg></div>';
}

function mark_notifications_read(mysqli $conn, int $userId, array $types = [], array $titles = []): void
{
    if ($userId <= 0 || (!$types && !$titles))
        return;
    $conds = [];
    $bind = 'i';
    $params = [$userId];
    if ($types) {
        $conds[] = 'notification_type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
        $bind .= str_repeat('s', count($types));
        foreach ($types as $t)
            $params[] = (string) $t;
    }
    if ($titles) {
        $conds[] = 'title IN (' . implode(',', array_fill(0, count($titles), '?')) . ')';
        $bind .= str_repeat('s', count($titles));
        foreach ($titles as $t)
            $params[] = (string) $t;
    }
    $sql = "UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0 AND (" . implode(' OR ', $conds) . ")";
    $stmt = $conn->prepare($sql);
    if (!$stmt)
        return;
    $stmt->bind_param($bind, ...$params);
    $stmt->execute();
}

function ensure_pharmacy_for_profile(mysqli $conn, int $profileId): int
{
    if ($profileId <= 0)
        return 0;
    $st = $conn->prepare("SELECT pp.pharmacist_profile_id, pp.pharmacy_id, pp.pharmacy_or_company_name, u.phone FROM pharmacist_profiles pp JOIN users u ON u.user_id = pp.user_id WHERE pp.pharmacist_profile_id = ? LIMIT 1");
    $st->bind_param('i', $profileId);
    $st->execute();
    $profile = $st->get_result()->fetch_assoc();
    if (!$profile)
        return 0;

    $current = (int) ($profile['pharmacy_id'] ?? 0);
    if ($current > 0) {
        $chk = $conn->prepare("SELECT pharmacy_id FROM pharmacies WHERE pharmacy_id = ? LIMIT 1");
        $chk->bind_param('i', $current);
        $chk->execute();
        if ($chk->get_result()->fetch_assoc())
            return $current;
    }

    $name = trim((string) $profile['pharmacy_or_company_name']);
    if ($name === '')
        $name = 'Pharmacy ' . $profileId;
    if (function_exists('mb_substr'))
        $name = mb_substr($name, 0, 150);

    $find = $conn->prepare("SELECT pharmacy_id FROM pharmacies WHERE LOWER(pharmacy_name) = LOWER(?) ORDER BY is_active DESC, pharmacy_id LIMIT 1");
    $find->bind_param('s', $name);
    $find->execute();
    $existing = $find->get_result()->fetch_assoc();

    if ($existing) {
        $pharmacyId = (int) $existing['pharmacy_id'];
        $conn->query("UPDATE pharmacies SET is_active = 1 WHERE pharmacy_id = " . $pharmacyId);
    } else {
        $campus = $name . ' Branch';
        $phone = trim((string) ($profile['phone'] ?? ''));
        if ($phone === '')
            $phone = '01700000000';
        $phone = substr($phone, 0, 20);
        $address = 'Dhaka, Bangladesh';
        $ins = $conn->prepare("INSERT INTO pharmacies (pharmacy_name, campus_name, address, phone, email, opening_time, closing_time, is_active) VALUES (?, ?, ?, ?, NULL, '08:00:00', '21:00:00', 1)");
        $ins->bind_param('ssss', $name, $campus, $address, $phone);
        $ins->execute();
        $pharmacyId = (int) $conn->insert_id;
    }

    $up = $conn->prepare("UPDATE pharmacist_profiles SET pharmacy_id = ? WHERE pharmacist_profile_id = ?");
    $up->bind_param('ii', $pharmacyId, $profileId);
    $up->execute();
    return $pharmacyId;
}

/* =====================================================================
 * Medicine Conflict helpers
 * ===================================================================== */

/* Returns the set of medicine_ids that conflict with ANY of the given IDs.
   Bidirectional — if A-B exists in either direction, both are covered. */
function get_conflicting_medicine_ids(mysqli $conn, array $medicineIds): array
{
    if (!$medicineIds) return [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $medicineIds))));
    if (!$ids) return [];
    $in = implode(',', $ids);

    $sql = "SELECT DISTINCT
                CASE
                    WHEN medicine_id IN ($in) THEN conflicting_medicine_id
                    ELSE medicine_id
                END AS conflict_id
            FROM medicine_conflicts
            WHERE medicine_id IN ($in)
               OR conflicting_medicine_id IN ($in)";
    $res = $conn->query($sql);
    if (!$res) return [];
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $cid = (int)$row['conflict_id'];
        if ($cid > 0 && !in_array($cid, $ids, true)) $out[] = $cid;
    }
    return $out;
}

/* True if this medicine has any conflict entry (either direction). */
function medicine_has_conflicts(mysqli $conn, int $medicineId): bool
{
    if ($medicineId <= 0) return false;
    $stmt = $conn->prepare("SELECT 1 FROM medicine_conflicts WHERE medicine_id = ? OR conflicting_medicine_id = ? LIMIT 1");
    $stmt->bind_param('ii', $medicineId, $medicineId);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_row();
}

/* Returns all conflicts for a given medicine with details (either direction). */
function get_medicine_conflicts(mysqli $conn, int $medicineId): array
{
    if ($medicineId <= 0) return [];
    $stmt = $conn->prepare(
        "SELECT mc.conflict_id, mc.severity, mc.notes,
                m.medicine_id, m.medicine_name, m.strength, m.company_name
         FROM medicine_conflicts mc
         JOIN medicines m
           ON m.medicine_id = CASE
                WHEN mc.medicine_id = ? THEN mc.conflicting_medicine_id
                ELSE mc.medicine_id
              END
         WHERE mc.medicine_id = ? OR mc.conflicting_medicine_id = ?
         ORDER BY m.medicine_name ASC"
    );
    $stmt->bind_param('iii', $medicineId, $medicineId, $medicineId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/* Replace all conflicts for a medicine with the given list of conflicting IDs. */
function set_medicine_conflicts(mysqli $conn, int $medicineId, array $conflictIds): void
{
    if ($medicineId <= 0) return;
    $del = $conn->prepare("DELETE FROM medicine_conflicts WHERE medicine_id = ? OR conflicting_medicine_id = ?");
    $del->bind_param('ii', $medicineId, $medicineId);
    $del->execute();

    $conflictIds = array_values(array_unique(array_filter(array_map('intval', $conflictIds))));
    if (!$conflictIds) return;

    $ins = $conn->prepare("INSERT IGNORE INTO medicine_conflicts (medicine_id, conflicting_medicine_id) VALUES (?, ?)");
    foreach ($conflictIds as $cid) {
        if ($cid <= 0 || $cid === $medicineId) continue;
        $a = min($medicineId, $cid);
        $b = max($medicineId, $cid);
        $ins->bind_param('ii', $a, $b);
        $ins->execute();
    }
}

/* =====================================================================
 * Support Reply helpers
 * ===================================================================== */

/* Save a support reply + send a notification to the original sender. */
/* Extract User ID from a support request message line "User ID: 15". */
function extract_support_sender_id(string $message): int
{
    if (preg_match('/User ID:\s*(\d+)/', $message, $m)) return (int)$m[1];
    return 0;
}

/* Save a support reply. Only ONE reply per original request is allowed.
   The reply notification goes to the SENDER (patient/pharmacist) — never to admin. */
function save_support_reply(mysqli $conn, int $originalNotificationId, int $adminId, string $replyMessage): array
{
    if ($originalNotificationId <= 0 || $adminId <= 0 || trim($replyMessage) === '') {
        return ['ok' => false, 'error' => 'Invalid reply.'];
    }

    /* Original notification must belong to an admin (support inbox). */
    $stmt = $conn->prepare("SELECT user_id, title, message FROM notifications WHERE notification_id = ? LIMIT 1");
    $stmt->bind_param('i', $originalNotificationId);
    $stmt->execute();
    $orig = $stmt->get_result()->fetch_assoc();
    if (!$orig) return ['ok' => false, 'error' => 'Original support request not found.'];

    /* Already replied? */
    $chk = $conn->prepare("SELECT reply_id FROM support_replies WHERE original_notification_id = ? LIMIT 1");
    $chk->bind_param('i', $originalNotificationId);
    $chk->execute();
    if ($chk->get_result()->fetch_row()) {
        return ['ok' => false, 'error' => 'This support request already has a reply.'];
    }

    /* Identify the original sender from the message text. */
    $senderId = extract_support_sender_id((string)$orig['message']);
    if ($senderId <= 0) return ['ok' => false, 'error' => 'Could not identify the sender of this request.'];
    if ($senderId === $adminId) return ['ok' => false, 'error' => 'Sender cannot be an admin.'];

    $conn->begin_transaction();
    try {
        /* Send the reply notification to the SENDER, not to admin. */
        $title = 'Support Reply';
        $insert = $conn->prepare("INSERT INTO notifications (user_id, notification_type, title, message, is_read, created_at) VALUES (?, 'GENERAL', ?, ?, FALSE, NOW())");
        $insert->bind_param('iss', $senderId, $title, $replyMessage);
        $insert->execute();
        $replyNotificationId = (int)$conn->insert_id;

        /* Save the reply record. */
        $ins = $conn->prepare("INSERT INTO support_replies (original_notification_id, reply_notification_id, patient_id, admin_id, reply_message) VALUES (?, ?, ?, ?, ?)");
        $ins->bind_param('iiiss', $originalNotificationId, $replyNotificationId, $senderId, $adminId, $replyMessage);
        $ins->execute();

        /* Mark the original support request as read by admin. */
        $upd = $conn->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE notification_id = ?");
        $upd->bind_param('i', $originalNotificationId);
        $upd->execute();

        $conn->commit();
        return ['ok' => true, 'reply_notification_id' => $replyNotificationId];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['ok' => false, 'error' => 'Could not save the reply.'];
    }
}

/* Helper: clean display name to avoid duplicate strength. */
function medicine_display_name(string $name, ?string $strength): string
{
    $name = trim($name);
    $strength = trim((string)$strength);
    if ($strength === '') return $name;
    $normalizedName = strtolower(preg_replace('/\s+/', '', $name));
    $normalizedStrength = strtolower(preg_replace('/\s+/', '', $strength));
    if ($normalizedStrength !== '' && strpos($normalizedName, $normalizedStrength) !== false) return $name;
    return $name . ' ' . $strength;
}
/* Get a support reply by the reply notification id (for the patient popup). */
function get_support_reply_by_notification(mysqli $conn, int $notificationId, int $forUserId): ?array
{
    if ($notificationId <= 0) return null;
    $stmt = $conn->prepare(
        "SELECT sr.reply_message, sr.created_at,
                u.full_name AS admin_name,
                (SELECT n2.message FROM notifications n2 WHERE n2.notification_id = sr.original_notification_id LIMIT 1) AS original_message
         FROM support_replies sr
         JOIN users u ON u.user_id = sr.admin_id
         WHERE sr.reply_notification_id = ? AND sr.patient_id = ?
         LIMIT 1"
    );
    $stmt->bind_param('ii', $notificationId, $forUserId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}
