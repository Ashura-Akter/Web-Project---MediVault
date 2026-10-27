<?php
/**
 * MediVault - Pharmacist module
 * File    : views/tabs/pharmacist_tab_approved.php
 * Title   : Tab: Approved (?tab=approved)
 *
 * FRONTEND (HTML) + BACKEND (PHP + SQL)
 * History of approved restricted-medicine (prescription) requests.
 * Regular reservation approvals live in Reservation History, not here.
 * SQL inside this file.
 */
defined('PHARMACIST_ROOT') || exit('Direct access not allowed.');
?>
        <?php
        // Approved history shows ONLY requests approved from the Pending tab,
        // i.e. restricted-medicine (prescription) requests where prescription_id IS NOT NULL.
        // Regular reservation approvals (prescription_id IS NULL) appear in Reservation History.
        // SQL: requests with status APPROVED / READY / ACCEPTED / COLLECTED of this pharmacy
        //      AND a prescription attached.
        // Ordered by the time they were accepted, newest first. {$res_search_sql} = optional search filter.
        $approved_q = "SELECT
                         r.reservation_id,
                         r.reservation_number,
                         r.status,
                         r.requested_at,
                         r.prescription_id,
                         r.accepted_at,
                         r.pickup_deadline,
                         r.reserved_for_hours,
                         u.full_name AS patient_name,
                         u.email AS patient_email,
                         pr.pharmacist_note,
                         GROUP_CONCAT(
                             m.medicine_name
                             ORDER BY m.medicine_name SEPARATOR ', '
                         ) AS medicines
                       FROM reservations r
                       JOIN users u ON u.user_id = r.patient_id
                       LEFT JOIN prescriptions pr ON pr.prescription_id = r.prescription_id
                       JOIN reservation_items ri ON ri.reservation_id = r.reservation_id
                       JOIN medicines m ON m.medicine_id = ri.medicine_id
                       WHERE r.pharmacy_id = ?
                         AND r.prescription_id IS NOT NULL
                         AND r.status IN ('APPROVED','READY','ACCEPTED','COLLECTED') {$res_search_sql}
                       GROUP BY r.reservation_id, pr.pharmacist_note
                       ORDER BY COALESCE(r.accepted_at, r.requested_at) DESC";
        // Bind: pharmacy id + optional search values.
        $approved_stmt = $conn->prepare($approved_q);
        $approved_stmt->bind_param('i' . $res_search_types, $pharmacyId, ...$res_search_params);
        $approved_stmt->execute();
        $approved_result = $approved_stmt->get_result();
        ?>

        <!-- History table (one row per approved restricted-medicine request). -->
        <?php if ($approved_result && $approved_result->num_rows > 0): ?>
            <div class="table-container">
                <table class="history-table">
                    <thead>
                        <tr>
                            <th>Order Date</th>
                            <th>Order Id</th>
                            <th>Patient</th>
                            <th>Email</th>
                            <th>Type</th>
                            <th>Medicine</th>
                            <th style="text-align:center;">Reserved</th>
                            <th>Status</th>
                            <th>Note</th>
                            <th>Approved At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $approved_row = 0; ?>
                        <?php while ($approved = $approved_result->fetch_assoc()): ?>
                            <tr class="<?= ($approved_row++ % 2 === 0) ? 'row-light' : 'row-dark' ?>">
                                <td style="font-weight:700;">
                                    <?= date('d/m/Y h:i A', strtotime($approved['requested_at'])) ?>
                                </td>
                                <td style="font-weight:700;">
                                    <?= htmlspecialchars($approved['reservation_number']) ?>
                                </td>
                                <td style="font-weight:700;">
                                    <?= htmlspecialchars($approved['patient_name']) ?>
                                </td>
                                <td style="font-weight:600; word-break:break-all;">
                                    <?= htmlspecialchars($approved['patient_email']) ?>
                                </td>
                                <?php /* Type: 'Restricted Request' when a prescription is attached, otherwise 'Reservation'. */ ?>
                                <td style="font-weight:700;">
                                    <?= !empty($approved['prescription_id']) ? 'Restricted Request' : 'Reservation' ?>
                                </td>
                                <td>
                                    <div class="med-list-item">
                                        <?= htmlspecialchars($approved['medicines']) ?>
                                    </div>
                                </td>
                                <td style="text-align:center; font-weight:700;">
                                    <?= !empty($approved['prescription_id']) ? '-' : ((int)$approved['reserved_for_hours'] . ' Hours') ?>
                                </td>
                                <td class="status-accepted">
                                    <?= htmlspecialchars($approved['status']) ?>
                                </td>
                                <td style="font-weight:600;max-width:260px;">
                                    <?= htmlspecialchars($approved['pharmacist_note'] ?? '-') ?>
                                </td>
                                <td style="font-weight:600;">
                                    <?= $approved['accepted_at'] ? date('d/m/Y h:i A', strtotime($approved['accepted_at'])) : '-' ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <!-- Empty state. -->
            <div class="pending-card" style="text-align:center; color:#64748b; font-weight:600;">
                No approved restricted-medicine requests yet. When you press <strong>Approve</strong> in the Pending tab, the request will appear here.
            </div>
        <?php endif; ?>