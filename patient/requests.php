<?php
session_start();
require_once __DIR__ . '/../database/queries.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login/login.php');
    exit;
}
$userId = (int)$_SESSION['user_id'];

$readReq = $conn->prepare("UPDATE notifications SET is_read=1,read_at=NOW() WHERE user_id=? AND is_read=0 AND (related_reservation_id IS NOT NULL OR related_prescription_id IS NOT NULL)");
$readReq->bind_param('i', $userId);
$readReq->execute();

$stmt = $conn->prepare("SELECT
    r.reservation_id, r.reservation_number, r.status, r.requested_at, r.accepted_at, r.pickup_deadline,
    r.reserved_for_hours, r.cancellation_reason, r.cancellation_note, r.prescription_id, p.pharmacy_name,
    (SELECT rsh.changed_at FROM reservation_status_history rsh
       WHERE rsh.reservation_id=r.reservation_id
         AND rsh.new_status IN ('CANCELLED','REJECTED','EXPIRED','NOT_COLLECTED')
       ORDER BY rsh.changed_at DESC LIMIT 1) AS cancelled_at,
    GROUP_CONCAT(m.medicine_name ORDER BY m.medicine_name SEPARATOR '|||') AS medicines,
    GROUP_CONCAT(ri.quantity ORDER BY m.medicine_name SEPARATOR '|||') AS quantities
    FROM reservations r
    JOIN pharmacies p ON p.pharmacy_id=r.pharmacy_id
    JOIN reservation_items ri ON ri.reservation_id=r.reservation_id
    JOIN medicines m ON m.medicine_id=ri.medicine_id
    WHERE r.patient_id=?
      AND r.status IN ('PENDING','AWAITING_RX','APPROVED','READY','COLLECTED','ACCEPTED','CANCELLED','REJECTED','EXPIRED','NOT_COLLECTED')
    GROUP BY r.reservation_id,p.pharmacy_name
    ORDER BY r.requested_at DESC,r.reservation_id DESC");
$stmt->bind_param('i', $userId);
$stmt->execute();
$requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

function request_status_label(array $r): string
{
    $restricted = !empty($r['prescription_id']);
    return match ($r['status']) {
        'APPROVED' => $restricted ? 'Approved' : 'Reserved',
        'ACCEPTED', 'READY', 'COLLECTED' => 'Reserved',
        'REJECTED' => 'Rejected',
        'CANCELLED', 'EXPIRED', 'NOT_COLLECTED' => 'Cancelled',
        'AWAITING_RX' => 'Pending',
        default => 'Pending',
    };
}
function request_is_negative(array $r): bool
{
    return in_array($r['status'], ['REJECTED', 'CANCELLED', 'EXPIRED', 'NOT_COLLECTED'], true);
}
function request_is_accepted(array $r): bool
{
    return in_array($r['status'], ['APPROVED', 'ACCEPTED', 'READY', 'COLLECTED'], true);
}
?>
<?php require_once __DIR__ . '/../common/header.php'; ?>
<style>
    .request-page .history-title {
        font-size: 28px;
        font-weight: 400;
        margin: 5px 0 12px
    }

    .request-page .table-wrap {
        overflow: auto
    }

    .request-page .history-table {
        width: 100%;
        border-collapse: collapse;
        background: #fff;
        font-size: 14px
    }

    .request-page .history-table th,
    .request-page .history-table td {
        padding: 10px 9px;
        border-bottom: 1px solid #eee;
        text-align: left;
        vertical-align: middle
    }

    .request-page .history-table td[rowspan] {
        vertical-align: top
    }

    .request-page .history-table th {
        font-size: 14px;
        font-weight: 600;
        background: #fff;
        border-bottom: 1px solid #ddd
    }

    .request-page .history-table tbody tr.row-bg-white td {
        background: #ffffff
    }

    .request-page .history-table tbody tr.row-bg-gray td {
        background: #f1f1f1
    }

    .request-page .history-table td {
        font-size: 14px;
        color: #222;
        line-height: 1.4
    }

    .request-page .reservation-status {
        font-weight: 700;
        color: #078f86
    }

    .request-page .reservation-status.rejected {
        color: #dc2626
    }

    .request-page .reservation-status.pending {
        color: #b7791f
    }

    .request-page .more-link {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        margin-top: 5px;
        border: 0;
        background: transparent;
        color: #078f86;
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
        padding: 0;
        text-decoration: none
    }

    .request-page .more-link:hover {
        color: #056f68
    }

    .request-page .more-link svg {
        width: 13px;
        height: 13px
    }

    .request-page .cell-med {
        font-weight: 600
    }

    .request-page .cell-qty {
        text-align: center;
        font-weight: 700;
        color: #0f766e
    }

    @media(max-width:900px) {
        .request-page .history-table {
            min-width: 850px
        }
    }
</style>
<div class="request-page">
    <div class="panel" style="background:transparent;padding:0;margin-bottom:4px">
        <h2 class="history-title">Request History</h2>
    </div>
    <?php if (!$requests): ?>
        <div class="empty">No reserved or restricted medicine requests yet.</div>
    <?php else: ?>
        <div class="panel" style="padding:0;background:transparent">
            <div class="table-wrap">
                <table class="history-table">
                    <thead>
                        <tr>
                            <th>Request ID</th>
                            <th>Request Date</th>
                            <th>Medicine Name</th>
                            <th>Quantity</th>
                            <th>Pharmacy</th>
                            <th>Status</th>
                            <th>Type</th>
                            <th>View More</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $groupIndex = 0; ?>
                        <?php foreach ($requests as $request):
                            $label = request_status_label($request);
                            $negative = request_is_negative($request);
                            $pending = in_array($request['status'], ['PENDING', 'AWAITING_RX'], true);
                            $statusClass = $negative ? 'rejected' : ($pending ? 'pending' : '');
                            $restricted = !empty($request['prescription_id']);
                            $medicines = array_values(array_filter(explode('|||', (string)$request['medicines']), fn($v) => trim($v) !== ''));
                            $quantities = explode('|||', (string)$request['quantities']);
                            if (empty($medicines)) {
                                $medicines = ['—'];
                                $quantities = [''];
                            }
                            $count = count($medicines);
                            $rowBg = ($groupIndex % 2 === 0) ? 'row-bg-white' : 'row-bg-gray';
                        ?>
                            <?php foreach ($medicines as $idx => $medicine): ?>
                                <tr class="<?= $rowBg ?>">
                                    <?php if ($idx === 0): ?>
                                        <td rowspan="<?= $count ?>" style="font-weight:700;"><?= htmlspecialchars($request['reservation_number']) ?></td>
                                        <td rowspan="<?= $count ?>"><?= date('d/m/Y', strtotime($request['requested_at'])) ?></td>
                                    <?php endif; ?>
                                    <td class="cell-med"><?= htmlspecialchars(trim($medicine)) ?></td>
                                    <td class="cell-qty"><?= htmlspecialchars($quantities[$idx] ?? '-') ?></td>
                                    <?php if ($idx === 0): ?>
                                        <td rowspan="<?= $count ?>"><?= htmlspecialchars($request['pharmacy_name']) ?></td>
                                        <td rowspan="<?= $count ?>"><span class="reservation-status <?= $statusClass ?>"><?= htmlspecialchars($label) ?></span></td>
                                        <td rowspan="<?= $count ?>"><?= $restricted ? 'Restricted Medicine' : 'Reservation' ?></td>
                                        <td rowspan="<?= $count ?>"><a class="more-link" href="requests.php?reservation_popup=<?= (int)$request['reservation_id'] ?>">View more <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <path d="M6 9l6 6 6-6" />
                                                </svg></a></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            <?php $groupIndex++; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php
$popupRequest = null;
if (isset($_GET['reservation_popup'])) {
    $rid = (int)$_GET['reservation_popup'];
    foreach ($requests as $request) {
        if ((int)$request['reservation_id'] === $rid) {
            $popupRequest = $request;
            break;
        }
    }
}
?>
<?php if ($popupRequest):
    $r = $popupRequest;
    $restricted = !empty($r['prescription_id']);
    $accepted = request_is_accepted($r);
    $negative = request_is_negative($r);
    $pending = in_array($r['status'], ['PENDING', 'AWAITING_RX'], true);
    $rejected = $r['status'] === 'REJECTED';
    $reason = $r['cancellation_reason'] ? str_replace('_', ' ', $r['cancellation_reason']) : '';
    if ($r['cancellation_note']) $reason .= ($reason !== '' ? ': ' : '') . $r['cancellation_note'];
    if ($restricted && $r['status'] === 'APPROVED') {
        $modalClass = 'accepted';
        $icon = '✓';
        $title = 'Restricted Medicine Approved';
    } elseif ($accepted) {
        $modalClass = 'accepted';
        $icon = '✓';
        $title = 'Reservation Accepted!';
    } elseif ($negative) {
        $modalClass = 'cancelled';
        $icon = '!';
        $title = $rejected ? ($restricted ? 'Restricted Medicine Rejected' : 'Reservation Rejected') : 'Reservation Cancelled';
    } else {
        $modalClass = 'pending';
        $icon = '…';
        $title = $restricted ? 'Restricted Medicine Request' : 'Reservation Request';
    }
?>
    <div class="modal-overlay" id="requestDetailModal">
        <div class="modal viewmore-modal <?= $modalClass ?>" role="dialog" aria-modal="true">
            <h2><span class="view-icon"><?= $icon ?></span><?= htmlspecialchars($title) ?></h2>
            <div class="res-line"><span class="res-label">Pharmacy:</span> <?= htmlspecialchars($r['pharmacy_name']) ?></div>
            <?php $popupMedicines = array_values(array_filter(explode('|||', (string)$r['medicines']), fn($v) => trim($v) !== ''));
            $popupQuantities = explode('|||', (string)$r['quantities']); ?>
            <?php foreach ($popupMedicines as $pi => $pm): ?>
                <div class="res-line"><span class="res-label">Medicine:</span> <?= htmlspecialchars(trim($pm)) ?></div>
                <div class="res-line"><span class="res-label">Quantity:</span> <?= htmlspecialchars($popupQuantities[$pi] ?? '-') ?></div>
            <?php endforeach; ?>
            <?php if ($restricted && $r['status'] === 'APPROVED'): ?>
                <div class="res-line"><span class="res-label">Approved at:</span> <?= $r['accepted_at'] ? date('d/m/Y h:i A', strtotime($r['accepted_at'])) : '-' ?></div>
                <div class="res-message">You can now purchase this restricted medicine. The medicine has already been added to your cart.</div>
            <?php elseif ($accepted && !$restricted): ?>
                <div class="res-line"><span class="res-label">Accepted at:</span> <?= $r['accepted_at'] ? date('d/m/Y h:i A', strtotime($r['accepted_at'])) : '-' ?></div>
                <div class="res-line"><span class="res-label">Reserved for:</span> <?= (int)$r['reserved_for_hours'] ?> hours</div>
                <div class="res-line"><span class="res-label">Pickup deadline:</span> <?= $r['pickup_deadline'] ? date('d/m/Y h:i A', strtotime($r['pickup_deadline'])) : '-' ?></div>
                <div class="res-message">Your reservation has been accepted by the pharmacy. Medicines are ready for pickup.<br><br>Please collect them within <?= (int)$r['reserved_for_hours'] ?> hours.</div>
            <?php elseif ($negative): ?>
                <div class="res-warning"><span class="res-label">Reason:</span> <?= htmlspecialchars($reason ?: 'No reason provided.') ?></div>
                <div class="res-line"><span class="res-label"><?= $rejected ? 'Rejected at:' : 'Cancelled at:' ?></span> <?= $r['cancelled_at'] ? date('d/m/Y h:i A', strtotime($r['cancelled_at'])) : '-' ?></div>
            <?php else: ?>
                <div class="res-line"><span class="res-label">Requested at:</span> <?= date('d/m/Y h:i A', strtotime($r['requested_at'])) ?></div>
                <div class="res-message"><?= $restricted ? 'Your restricted medicine request is waiting for pharmacist verification (usually 10–20 minutes).' : 'Your reservation request is waiting for pharmacist approval.' ?></div>
            <?php endif; ?>
            <button class="close-x" type="button" id="closeRequestModal" aria-label="Close">&times;</button>
        </div>
    </div>
    <script>
        (function() {
            const modal = document.getElementById('requestDetailModal');
            if (!modal) return;
            document.body.appendChild(modal);
            const close = document.getElementById('closeRequestModal');

            function closeNow() {
                window.location.href = 'requests.php';
            }
            if (close) close.addEventListener('click', closeNow);
            modal.addEventListener('click', function(e) {
                if (e.target === modal) closeNow();
            });
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') closeNow();
            });
        })();
    </script>
<?php endif; ?>

<?php if (isset($_GET['pending_approval']) && !empty($_SESSION['ocr_pending_approval'])):
    $pendingInfo = $_SESSION['ocr_pending_approval'];
    unset($_SESSION['ocr_pending_approval']);
?>
    <div class="modal-overlay" id="pendingApprovalModal" style="z-index:100001;">
        <div class="modal viewmore-modal accepted" role="dialog" aria-modal="true">
            <h2><span class="view-icon">⏳</span>Restricted Order Pending Approval</h2>
            <div class="res-line"><span class="res-label">Status:</span> Waiting for pharmacist verification</div>
            <div class="res-line"><span class="res-label">Estimated wait:</span> <?= (int)$pendingInfo['wait_min'] ?> – <?= (int)$pendingInfo['wait_max'] ?> minutes</div>
            <div class="res-line"><span class="res-label">Reason:</span> Restricted medicine requires a licensed pharmacist's prescription verification</div>
            <div class="res-line"><span class="res-label">Requests sent:</span> <?= count($pendingInfo['reservations']) ?> pharmacy request(s)</div>
            <?php if (!empty($pendingInfo['normal_items'])): ?>
                <div class="res-line"><span class="res-label">Normal medicines:</span> <?= (int)$pendingInfo['normal_items'] ?> item(s) added to your cart</div>
            <?php endif; ?>
            <div class="res-message">
                Your prescription has been sent to the pharmacy. Our system automatically verifies it and you will receive a notification the moment it is approved or rejected.<br><br>
                Once approved, the restricted medicines will be added to your cart automatically. Click the approval notification to proceed to checkout.
            </div>
            <button class="close-x" type="button" id="pendingApprovalClose" aria-label="Close">&times;</button>
        </div>
    </div>
    <script>
        (function() {
            const modal = document.getElementById('pendingApprovalModal');
            if (!modal) return;
            document.body.appendChild(modal);
            const close = () => {
                window.location.href = 'requests.php';
            };
            document.getElementById('pendingApprovalClose').addEventListener('click', close);
            modal.addEventListener('click', e => {
                if (e.target === modal) close();
            });
            document.addEventListener('keydown', e => {
                if (e.key === 'Escape') close();
            });
        })();
    </script>
<?php endif; ?>

<?php require_once __DIR__ . '/../common/footer.php'; ?>