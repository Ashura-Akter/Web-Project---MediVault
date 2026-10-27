<?php
session_start();
require_once __DIR__ . '/../../database/queries.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || strtoupper($_SESSION['role'] ?? '') !== 'PATIENT') {
    echo json_encode(['ok' => false, 'error' => 'Not authorized.']);
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) {
    echo json_encode(['ok' => true, 'results' => []]);
    exit;
}

$like = '%' . $q . '%';

$stmt = $conn->prepare(
    "SELECT m.medicine_id, m.medicine_name, m.generic_name, m.strength,
            m.dosage_form, m.company_name, m.image_path,
            m.is_restricted, m.requires_prescription,
            c.category_name,
            p.pharmacy_id, p.pharmacy_name,
            SUM(ib.quantity_on_hand - ib.reserved_quantity) AS available,
            MIN(ib.unit_price) AS price
     FROM medicines m
     JOIN categories c ON c.category_id = m.category_id
     JOIN inventory_batches ib ON ib.medicine_id = m.medicine_id
         AND ib.expiry_date > CURDATE()
         AND (ib.quantity_on_hand - ib.reserved_quantity) > 0
     JOIN pharmacies p ON p.pharmacy_id = ib.pharmacy_id AND p.is_active = TRUE
     WHERE m.is_active = TRUE
       AND (m.medicine_name LIKE ? OR m.generic_name LIKE ? OR m.company_name LIKE ?)
     GROUP BY m.medicine_id, p.pharmacy_id
     ORDER BY (m.medicine_name LIKE ?) DESC, m.medicine_name ASC, price ASC
     LIMIT 12"
);
$prefix = $q . '%';
$stmt->bind_param('ssss', $like, $like, $like, $prefix);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$results = [];
foreach ($rows as $r) {
    $results[] = [
        'medicine_id'   => (int)$r['medicine_id'],
        'medicine_name' => (string)$r['medicine_name'],
        'generic_name'  => (string)($r['generic_name'] ?? ''),
        'strength'      => (string)($r['strength'] ?? ''),
        'dosage_form'   => (string)($r['dosage_form'] ?? ''),
        'company_name'  => (string)($r['company_name'] ?? ''),
        'category_name' => (string)($r['category_name'] ?? ''),
        'image_path'    => (string)($r['image_path'] ?? ''),
        'is_restricted' => (int)$r['is_restricted'] === 1,
        'pharmacy_id'   => (int)$r['pharmacy_id'],
        'pharmacy_name' => (string)$r['pharmacy_name'],
        'unit_price'    => (float)$r['price'],
        'available_stock' => (int)$r['available'],
    ];
}

echo json_encode(['ok' => true, 'results' => $results]);
exit;
