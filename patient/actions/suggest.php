<?php
session_start();

require_once __DIR__ . '/../../database/queries.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode([]);
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));

if ($q === '') {
    echo json_encode([]);
    exit;
}

$like = '%' . $q . '%';
$prefix = $q . '%';

$suggestions = []; // lowercased label => ['label' => original, 'pref' => 0/1]

function mv_add_suggestions(array &$suggestions, mysqli_result $result): void {
    while ($row = $result->fetch_assoc()) {
        $label = $row['label'];
        $key = mb_strtolower($label);
        $pref = (int)$row['pref'];
        if (!isset($suggestions[$key]) || $pref > $suggestions[$key]['pref']) {
            $suggestions[$key] = ['label' => $label, 'pref' => $pref];
        }
    }
}

// 1) Medicine names (matched by name, generic name, or company)
$st = $conn->prepare(
    "SELECT DISTINCT medicine_name AS label, (medicine_name LIKE ?) AS pref
     FROM medicines
     WHERE is_active = TRUE
       AND (medicine_name LIKE ? OR generic_name LIKE ? OR company_name LIKE ?)
     ORDER BY pref DESC, medicine_name
     LIMIT 6"
);
$st->bind_param('ssss', $prefix, $like, $like, $like);
$st->execute();
mv_add_suggestions($suggestions, $st->get_result());

// 2) Categories (e.g. typing "pain" surfaces "Pain Relief")
$st = $conn->prepare(
    "SELECT DISTINCT category_name AS label, (category_name LIKE ?) AS pref
     FROM categories
     WHERE category_name LIKE ?
     ORDER BY pref DESC, category_name
     LIMIT 4"
);
$st->bind_param('ss', $prefix, $like);
$st->execute();
mv_add_suggestions($suggestions, $st->get_result());

// 3) Symptoms (e.g. typing "pain" also surfaces the "Pain" symptom itself)
$st = $conn->prepare(
    "SELECT DISTINCT symptom_name AS label, (symptom_name LIKE ?) AS pref
     FROM symptoms
     WHERE symptom_name LIKE ?
     ORDER BY pref DESC, symptom_name
     LIMIT 4"
);
$st->bind_param('ss', $prefix, $like);
$st->execute();
mv_add_suggestions($suggestions, $st->get_result());

// Prefix matches first, then alphabetical, capped to 8 total suggestions.
$suggestions = array_values($suggestions);
usort($suggestions, function ($a, $b) {
    if ($a['pref'] !== $b['pref']) {
        return $b['pref'] <=> $a['pref'];
    }
    return strcasecmp($a['label'], $b['label']);
});

$labels = array_map(function ($item) { return $item['label']; }, $suggestions);
$labels = array_slice($labels, 0, 8);

echo json_encode($labels);
