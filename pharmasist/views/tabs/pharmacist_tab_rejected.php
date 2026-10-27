<?php
/**
 * MediVault - Pharmacist module
 * File    : views/tabs/pharmacist_tab_rejected.php
 * Title   : Tab: Rejected (?tab=rejected)
 *
 * FRONTEND (HTML) + BACKEND (PHP + SQL)
 * History of rejected requests with the reason.
 * SQL inside this file.
 */
defined('PHARMACIST_ROOT') || exit('Direct access not allowed.');
?>
        <?php
        // Every request the pharmacist rejected, with the reason that was given.
        // SQL: requests with status REJECTED of this pharmacy.
        // The reason is read from reservations.cancellation_note or prescriptions.pharmacist_note;
        // reviewed_at (when it was rejected) comes from the prescriptions table.
        $rejected_q = "SELECT
                         r.reservation_id,
                         r.reservation_number,
                         r.status,
                         r.requested_at,
                         r.prescription_id,
                         r.cancellation_note,
                         u.full_name AS patient_name,
                         u.email AS patient_email,
                         pr.pharmacist_note,
                         pr.reviewed_at,
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
                         AND r.status = 'REJECTED' {$res_search_sql}
                       GROUP BY r.reservation_id, pr.pharmacist_note, pr.reviewed_at
                       ORDER BY COALESCE(pr.reviewed_at, r.requested_at) DESC";
        // Bind: pharmacy id + optional search values.
        $rejected_stmt = $conn->prepare($rejected_q);
        $rejected_stmt->bind_param('i' . $res_search_types, $pharmacyId, ...$res_search_params);
        $rejected_stmt->execute();
        $rejected_result = $rejected_stmt->get_result();
        ?>

        <!-- History table (one row per rejected request). -->
        <?php if ($rejected_result && $rejected_result->num_rows > 0): ?>
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
                            <th>Status</th>
                            <th>Rejection Reason</th>
                            <th>Rejected At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $rejected_row = 0; ?>
                        <?php while ($rej = $rejected_result->fetch_assoc()): ?>
                            <?php /* Reason shown = cancellation note, otherwise pharmacist note, otherwise '-'. */ ?>
                            <?php $rej_reason = $rej['cancellation_note'] ?: ($rej['pharmacist_note'] ?: '-'); ?>
                            <tr class="<?= ($rejected_row++ % 2 === 0) ? 'row-light' : 'row-dark' ?>">
                                <td style="font-weight:700;">
                                    <?= date('d/m/Y', strtotime($rej['requested_at'])) ?>
                                </td>
                                <td style="font-weight:700;">
                                    <?= htmlspecialchars($rej['reservation_number']) ?>
                                </td>
                                <td style="font-weight:700;">
                                    <?= htmlspecialchars($rej['patient_name']) ?>
                                </td>
                                <td style="font-weight:600; word-break:break-all;">
                                    <?= htmlspecialchars($rej['patient_email']) ?>
                                </td>
                                <td style="font-weight:700;">
                                    <?= !empty($rej['prescription_id']) ? 'Restricted Request' : 'Reservation' ?>
                                </td>
                                <td>
                                    <div class="med-list-item">
                                        <?= htmlspecialchars($rej['medicines']) ?>
                                    </div>
                                </td>
                                <td class="status-rejected">
                                    <?= htmlspecialchars($rej['status']) ?>
                                </td>
                                <td style="font-weight:600;max-width:280px;">
                                    <?= htmlspecialchars($rej_reason) ?>
                                </td>
                                <td style="font-weight:600;">
                                    <?= $rej['reviewed_at'] ? date('d/m/Y h:i A', strtotime($rej['reviewed_at'])) : '-' ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <!-- Empty state. -->
            <div class="pending-card" style="text-align:center; color:#64748b; font-weight:600;">
                No rejected requests yet. When you press <strong>Reject</strong> in the Pending tab, the request will appear here.
            </div>
        <?php endif; ?>
