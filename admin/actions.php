<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../database/queries.php';

function save_medicine_image(?array $file): string
{
    if (!$file || !isset($file['error'])) throw new RuntimeException('Please choose a medicine image.');
    if ($file['error'] === UPLOAD_ERR_NO_FILE) throw new RuntimeException('Please choose a medicine image.');
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) throw new RuntimeException('The image is too large (max 2 MB).');
    if ($file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('The image could not be uploaded.');
    if ($file['size'] > 2 * 1024 * 1024) throw new RuntimeException('The image is too large (max 2 MB).');
    $info = @getimagesize($file['tmp_name']);
    $map = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if (!$info || !isset($map[$info[2]])) throw new RuntimeException('Only JPG, PNG, WEBP or GIF images are allowed.');
    $dir = __DIR__ . '/../uploads/medicines';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) throw new RuntimeException('The upload folder could not be created.');
    $name = 'med-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $map[$info[2]];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) throw new RuntimeException('The image could not be saved.');
    return 'uploads/medicines/' . $name;
}
function delete_medicine_image_file(?string $path): void
{
    $path = trim((string)$path);
    if ($path === '' || strpos($path, 'uploads/medicines/') !== 0 || strpos($path, '..') !== false) return;
    $abs = __DIR__ . '/../' . $path;
    if (is_file($abs)) @unlink($abs);
}
function resolve_medicine_image_input(?string $imageUrl, ?array $file): string
{
    $imageUrl = trim((string)$imageUrl);
    $hasUpload = $file && isset($file['error']) && $file['error'] !== UPLOAD_ERR_NO_FILE;
    if ($imageUrl !== '' && $hasUpload) throw new RuntimeException('Choose either a file OR an image URL.');
    if ($imageUrl !== '') {
        if (!preg_match('#^https?://#i', $imageUrl) && strpos($imageUrl, 'data:') !== 0) throw new RuntimeException('Image URL must start with http:// or https://');
        if (strlen($imageUrl) > 500) throw new RuntimeException('Image URL too long.');
        return $imageUrl;
    }
    if ($hasUpload) return save_medicine_image($file);
    throw new RuntimeException('Please upload a medicine image or paste an image URL.');
}
function parse_conflict_ids(?string $json): array
{
    $json = trim((string)$json);
    if ($json === '') return [];
    $arr = json_decode($json, true);
    if (!is_array($arr)) return [];
    return array_values(array_unique(array_filter(array_map('intval', $arr), fn($x) => $x > 0)));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}
$action = $_POST['action'] ?? '';
$adminId = (int)($_SESSION['user_id'] ?? 0);
if ($adminId <= 0) {
    $_SESSION['flash_error'] = 'Session expired. Please log in again.';
    header('Location: index.php');
    exit;
}

try {
    if ($action === 'add_medicine') {
        $name = trim($_POST['medicine_name'] ?? '');
        $strength = trim($_POST['strength'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $restricted = ((int)($_POST['is_restricted'] ?? 0)) === 1 ? 1 : 0;
        $hasConflict = strtolower(trim((string)($_POST['has_conflict'] ?? 'no'))) === 'yes';
        if ($name === '' || $strength === '') throw new RuntimeException('Please complete all medicine fields.');

        $standardForms = ['TABLET', 'CAPSULE', 'SYRUP', 'CREAM', 'OINTMENT', 'LOZENGE', 'INHALER', 'DROPS', 'INJECTION', 'SOLUTION'];
        $dosageChoice = trim($_POST['dosage_form'] ?? '');
        if ($dosageChoice === '__OTHER__') {
            $dosage = trim(preg_replace('/\s+/', ' ', (string)($_POST['dosage_form_other'] ?? '')));
            if ($dosage === '') throw new RuntimeException('Please write the medicine type in the Others box.');
            if (mb_strlen($dosage) > 60) throw new RuntimeException('Medicine type must be 60 characters or less.');
            $dosage = mb_convert_case($dosage, MB_CASE_TITLE, 'UTF-8');
        } else {
            $dosage = strtoupper($dosageChoice);
            if (!in_array($dosage, $standardForms, true)) throw new RuntimeException('Please select a valid medicine type.');
        }

        $companyChoice = trim($_POST['company_name'] ?? '');
        if ($companyChoice === '__OTHER__') {
            $company = trim(preg_replace('/\s+/', ' ', (string)($_POST['company_name_other'] ?? '')));
            if ($company === '') throw new RuntimeException('Please write the company name.');
        } else {
            $company = $companyChoice;
        }
        if ($company === '') throw new RuntimeException('Please select the company name.');
        if (mb_strlen($company) > 150) throw new RuntimeException('Company name must be 150 characters or less.');
        $cs = $conn->prepare("SELECT company_name FROM medicines WHERE LOWER(company_name)=LOWER(?) LIMIT 1");
        $cs->bind_param('s', $company);
        $cs->execute();
        $ce = $cs->get_result()->fetch_assoc();
        if ($ce) $company = $ce['company_name'];

        $categoryChoice = trim($_POST['category_id'] ?? '');
        if ($categoryChoice === '__OTHER__') {
            $catName = trim(preg_replace('/\s+/', ' ', (string)($_POST['category_other'] ?? '')));
            if ($catName === '') throw new RuntimeException('Please write the category name.');
            if (mb_strlen($catName) > 80) throw new RuntimeException('Category name must be 80 characters or less.');
            if (strcasecmp($catName, 'Restricted') === 0) throw new RuntimeException('"Restricted" is a reserved category.');
            $cq = $conn->prepare("SELECT category_id FROM categories WHERE LOWER(category_name)=LOWER(?) LIMIT 1");
            $cq->bind_param('s', $catName);
            $cq->execute();
            $crow = $cq->get_result()->fetch_assoc();
            if ($crow) {
                $category = (int)$crow['category_id'];
            } else {
                $desc = 'Added by admin';
                $ci = $conn->prepare("INSERT INTO categories (category_name,description) VALUES (?,?)");
                $ci->bind_param('ss', $catName, $desc);
                $ci->execute();
                $category = (int)$conn->insert_id;
            }
        } else {
            $category = (int)$categoryChoice;
        }
        if ($category <= 0) throw new RuntimeException('Please select a category.');

        $image = resolve_medicine_image_input($_POST['medicine_image_url'] ?? '', $_FILES['medicine_image'] ?? null);
        $requiresRx = $restricted === 1 ? 1 : 0;

        // Explicit values for all 14 columns — fixes the insert mismatch.
        $generic   = null;
        $unitPrice = null;
        $reorder   = 30;
        $isActive  = 1;

        try {
            $sql = "INSERT INTO medicines
                        (category_id, medicine_name, generic_name, strength, dosage_form,
                         company_name, description, image_path, unit_price,
                         is_restricted, requires_prescription, reorder_level, is_active,
                         created_by_user_id)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

            $s = $conn->prepare($sql);
            if (!$s) throw new RuntimeException('Prepare failed: ' . $conn->error);

            // 14 types: i s s s s s s s d i i i i i
            $types = 'isssssssdiiiii';
            $s->bind_param(
                $types,
                $category, $name, $generic, $strength, $dosage,
                $company, $description, $image, $unitPrice,
                $restricted, $requiresRx, $reorder, $isActive,
                $adminId
            );

            if (!$s->execute()) {
                throw new RuntimeException('Execute failed: ' . $s->error);
            }
            $newId = (int)$conn->insert_id;
            if ($newId <= 0) {
                throw new RuntimeException('Insert returned no ID.');
            }
        } catch (mysqli_sql_exception $e) {
            delete_medicine_image_file($image);
            if ((int)$e->getCode() === 1062) {
                throw new RuntimeException('This medicine already exists with the same name, strength, type and company.');
            }
            if ((int)$e->getCode() === 1452) {
                throw new RuntimeException('Invalid reference (category or user). Please check and try again.');
            }
            throw new RuntimeException('Database error ' . $e->getCode() . ': ' . $e->getMessage());
        }

        /* Conflicts only if Yes was selected. */
        if ($hasConflict) {
            $conflictIds = parse_conflict_ids($_POST['conflicts_json'] ?? '');
            if ($conflictIds) set_medicine_conflicts($conn, $newId, $conflictIds);
        }

        $_SESSION['flash'] = 'Medicine added successfully.';
    } elseif ($action === 'update_medicine_image') {
        $id = (int)($_POST['medicine_id'] ?? 0);
        if ($id <= 0) throw new RuntimeException('Invalid medicine selected.');
        $s = $conn->prepare("SELECT medicine_name,image_path FROM medicines WHERE medicine_id=? LIMIT 1");
        $s->bind_param('i', $id);
        $s->execute();
        $med = $s->get_result()->fetch_assoc();
        if (!$med) throw new RuntimeException('Medicine not found.');
        $image = resolve_medicine_image_input($_POST['medicine_image_url'] ?? '', $_FILES['medicine_image'] ?? null);
        $u = $conn->prepare("UPDATE medicines SET image_path=? WHERE medicine_id=?");
        $u->bind_param('si', $image, $id);
        $u->execute();
        if (strpos((string)$med['image_path'], 'http') !== 0) delete_medicine_image_file($med['image_path']);
        $_SESSION['flash'] = 'Image updated.';
    } elseif ($action === 'update_medicine_conflicts') {
        $id = (int)($_POST['medicine_id'] ?? 0);
        if ($id <= 0) throw new RuntimeException('Invalid medicine.');
        $s = $conn->prepare("SELECT medicine_name FROM medicines WHERE medicine_id=? LIMIT 1");
        $s->bind_param('i', $id);
        $s->execute();
        $med = $s->get_result()->fetch_assoc();
        if (!$med) throw new RuntimeException('Medicine not found.');
        $conflictIds = parse_conflict_ids($_POST['conflicts_json'] ?? '');
        set_medicine_conflicts($conn, $id, $conflictIds);
        $_SESSION['flash'] = 'Conflicts updated for "' . $med['medicine_name'] . '" (' . count($conflictIds) . ')';
    } elseif ($action === 'hide_medicine') {
        $id = (int)($_POST['medicine_id'] ?? 0);
        if ($id <= 0) throw new RuntimeException('Invalid medicine.');
        $s = $conn->prepare("SELECT medicine_name,is_active FROM medicines WHERE medicine_id=? LIMIT 1");
        $s->bind_param('i', $id);
        $s->execute();
        $med = $s->get_result()->fetch_assoc();
        if (!$med) throw new RuntimeException('Medicine not found.');
        $u = $conn->prepare("UPDATE medicines SET is_active=FALSE WHERE medicine_id=?");
        $u->bind_param('i', $id);
        $u->execute();
        $_SESSION['flash'] = '"' . $med['medicine_name'] . '" hidden.';
    } elseif ($action === 'show_medicine') {
        $id = (int)($_POST['medicine_id'] ?? 0);
        if ($id <= 0) throw new RuntimeException('Invalid medicine.');
        $s = $conn->prepare("SELECT medicine_name FROM medicines WHERE medicine_id=? LIMIT 1");
        $s->bind_param('i', $id);
        $s->execute();
        $med = $s->get_result()->fetch_assoc();
        if (!$med) throw new RuntimeException('Medicine not found.');
        $u = $conn->prepare("UPDATE medicines SET is_active=TRUE WHERE medicine_id=?");
        $u->bind_param('i', $id);
        $u->execute();
        $_SESSION['flash'] = '"' . $med['medicine_name'] . '" shown.';
    } elseif ($action === 'delete_medicine') {
        $id = (int)($_POST['medicine_id'] ?? 0);
        if ($id <= 0) throw new RuntimeException('Invalid medicine.');
        $s = $conn->prepare("SELECT medicine_name,image_path FROM medicines WHERE medicine_id=? LIMIT 1");
        $s->bind_param('i', $id);
        $s->execute();
        $med = $s->get_result()->fetch_assoc();
        if (!$med) throw new RuntimeException('Medicine not found.');
        $blockedBy = [];
        foreach (['order_items' => 'orders', 'reservation_items' => 'reservations', 'prescription_items' => 'prescriptions'] as $tbl => $label) {
            $c = $conn->prepare("SELECT COUNT(*) FROM `$tbl` WHERE medicine_id=?");
            $c->bind_param('i', $id);
            $c->execute();
            if ((int)$c->get_result()->fetch_column() > 0) $blockedBy[] = $label;
        }
        if ($blockedBy) throw new RuntimeException('"' . $med['medicine_name'] . '" cannot be permanently deleted. Hide instead.');
        $conn->begin_transaction();
        try {
            $d = $conn->prepare("DELETE FROM medicine_conflicts WHERE medicine_id=? OR conflicting_medicine_id=?");
            $d->bind_param('ii', $id, $id);
            $d->execute();
            foreach (['cart_items', 'inventory_batches', 'refill_reminders'] as $tbl) {
                $d = $conn->prepare("DELETE FROM `$tbl` WHERE medicine_id=?");
                $d->bind_param('i', $id);
                $d->execute();
            }
            $d = $conn->prepare("DELETE FROM medicines WHERE medicine_id=?");
            $d->bind_param('i', $id);
            $d->execute();
            if ($d->affected_rows !== 1) throw new RuntimeException('Could not delete.');
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
        if (strpos((string)$med['image_path'], 'http') !== 0) delete_medicine_image_file($med['image_path']);
        $_SESSION['flash'] = 'Medicine "' . $med['medicine_name'] . '" deleted.';
    } elseif ($action === 'reply_support') {
        /* Reply to a support request. */
        $nid = (int)($_POST['notification_id'] ?? 0);
        $reply = trim((string)($_POST['reply_message'] ?? ''));
        if ($nid <= 0) throw new RuntimeException('Invalid support request.');
        if ($reply === '') throw new RuntimeException('Please write a reply.');
        if (mb_strlen($reply) > 600) throw new RuntimeException('Reply must be 600 characters or less.');
        $res = save_support_reply($conn, $nid, $adminId, $reply);
        if (empty($res['ok'])) throw new RuntimeException($res['error'] ?? 'Could not save reply.');
        $_SESSION['flash'] = 'Reply sent to the user.';
    } elseif ($action === 'approve_pharmacist' || $action === 'reject_pharmacist') {
        $pid = (int)($_POST['pharmacist_profile_id'] ?? 0);
        $status = $action === 'approve_pharmacist' ? 'APPROVED' : 'REJECTED';
        $note = $status === 'APPROVED' ? 'Approved by admin.' : 'Rejected by admin.';
        $s = $conn->prepare("UPDATE pharmacist_profiles SET approval_status=?, approved_by_admin_id=?, reviewed_at=NOW(), admin_note=? WHERE pharmacist_profile_id=?");
        $s->bind_param('sisi', $status, $adminId, $note, $pid);
        $s->execute();
        if ($status === 'APPROVED') ensure_pharmacy_for_profile($conn, $pid);
        $_SESSION['flash'] = 'Pharmacist ' . strtolower($status) . '.';
    } elseif ($action === 'deactivate_user') {
        $id = (int)($_POST['user_id'] ?? 0);
        $s = $conn->prepare("SELECT role,email,is_active FROM users WHERE user_id=? LIMIT 1");
        $s->bind_param('i', $id);
        $s->execute();
        $u = $s->get_result()->fetch_assoc();
        if (!$u) throw new RuntimeException('User not found.');
        if ($id === $adminId || strtoupper($u['role']) === 'ADMIN') throw new RuntimeException('Admins cannot be deactivated here.');
        $s = $conn->prepare("UPDATE users SET is_active=FALSE WHERE user_id=?");
        $s->bind_param('i', $id);
        $s->execute();
        $_SESSION['flash'] = 'User deactivated.';
    } elseif ($action === 'activate_user') {
        $id = (int)($_POST['user_id'] ?? 0);
        $s = $conn->prepare("SELECT role,email FROM users WHERE user_id=? LIMIT 1");
        $s->bind_param('i', $id);
        $s->execute();
        $u = $s->get_result()->fetch_assoc();
        if (!$u) throw new RuntimeException('User not found.');
        $s = $conn->prepare("UPDATE users SET is_active=TRUE WHERE user_id=?");
        $s->bind_param('i', $id);
        $s->execute();
        $_SESSION['flash'] = 'User activated.';
    } elseif ($action === 'delete_user') {
        $id = (int)($_POST['user_id'] ?? 0);
        $s = $conn->prepare("SELECT role,email FROM users WHERE user_id=? LIMIT 1");
        $s->bind_param('i', $id);
        $s->execute();
        $u = $s->get_result()->fetch_assoc();
        if (!$u) throw new RuntimeException('User not found.');
        if ($id === $adminId || strtoupper($u['role']) === 'ADMIN') throw new RuntimeException('Admins cannot be deleted here.');
        try {
            $s = $conn->prepare("DELETE FROM users WHERE user_id=?");
            $s->bind_param('i', $id);
            $s->execute();
            $_SESSION['flash'] = 'User removed.';
        } catch (mysqli_sql_exception $e) {
            if ((int)$e->getCode() === 1451) throw new RuntimeException('This user has existing records. Deactivate instead.');
            throw $e;
        }
    } elseif ($action === 'mark_support_read') {
        $id = (int)($_POST['notification_id'] ?? 0);
        $s = $conn->prepare("UPDATE notifications SET is_read=TRUE, read_at=NOW() WHERE notification_id=? AND user_id=?");
        $s->bind_param('ii', $id, $adminId);
        $s->execute();
        $_SESSION['flash'] = 'Marked as read.';
    } else throw new RuntimeException('Unknown action.');
} catch (Throwable $e) {
    $_SESSION['flash_error'] = $e->getMessage();
}
$tab = $_POST['tab'] ?? 'all-medicine';
$q = $_POST['q'] ?? '';
$url = 'index.php?tab=' . urlencode($tab);
if ($q !== '') $url .= '&q=' . urlencode($q);
header('Location: ' . $url);
exit;