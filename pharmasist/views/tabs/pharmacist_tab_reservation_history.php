<?php
/**
 * MediVault - Pharmacist module
 * File    : views/tabs/pharmacist_tab_reservation_history.php
 * Title   : Tab: Reservation History (?tab=res_history)
 *
 * FRONTEND (HTML) + BACKEND (PHP + SQL)
 * Finished / closed normal reservations with feedback and reason.
 * SQL inside this file.
 */
defined('PHARMACIST_ROOT') || exit('Direct access not allowed.');
?>
        <?php
        // SQL: normal reservations (prescription_id IS NULL) that are finished or closed
        // (ACCEPTED, READY, COLLECTED, NOT_COLLECTED, CANCELLED, REJECTED, EXPIRED).
        // GROUP_CONCAT builds the medicine and quantity lists in the same order; LEFT JOIN reservation_feedback
        // adds the patient's rating / feedback when there is one. Newest first.
        $history_q = "SELECT
                        r.reservation_id,
                        r.reservation_number,
                        r.requested_at,
                        r.status,
                        r.pickup_deadline,
                        r.collected_at,
                        r.cancellation_reason,
                        r.cancellation_note,
                        u.full_name AS patient_name,
                        GROUP_CONCAT(
                            m.medicine_name
                            ORDER BY m.medicine_name SEPARATOR ', '
                        ) AS medicines,
                        GROUP_CONCAT(ri.quantity ORDER BY m.medicine_name SEPARATOR ', ') AS quantities,
                        rf.rating,
                        rf.feedback_text
                      FROM reservations r
                      JOIN users u ON u.user_id = r.patient_id
                      JOIN reservation_items ri ON ri.reservation_id = r.reservation_id
                      JOIN medicines m ON m.medicine_id = ri.medicine_id
                      LEFT JOIN reservation_feedback rf ON rf.reservation_id = r.reservation_id
                      WHERE r.pharmacy_id = ?
                        AND r.prescription_id IS NULL
                        AND r.status IN ('ACCEPTED','READY','COLLECTED','NOT_COLLECTED','CANCELLED','REJECTED','EXPIRED') {$res_search_sql}
                      GROUP BY r.reservation_id, rf.rating, rf.feedback_text
                      ORDER BY r.requested_at DESC";
        // Bind: pharmacy id + optional search values.
        $history_stmt = $conn->prepare($history_q);
        $history_stmt->bind_param('i' . $res_search_types, $pharmacyId, ...$res_search_params);
        $history_stmt->execute();
        $history_result = $history_stmt->get_result();
        ?>

        <!-- History table (one row per reservation). -->
        <?php if ($history_result && $history_result->num_rows > 0): ?>
            <div class="table-container">
                <table class="history-table">
                    <thead>
                        <tr>
                            <th>Order Date</th>
                            <th>Reservation Id</th>
                            <th>Patient Name</th>
                            <th>Medicine Name</th>
                            <th style="text-align:center;">Quantity</th>
                            <th>Status</th>
                            <th>Pickup</th>
                            <th>Feedback / Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $history_row = 0; ?>
                        <?php while ($history = $history_result->fetch_assoc()): ?>
                            <?php
                            // Row colour: red for cancelled / rejected / expired / not collected, green otherwise.
                            $statusClass = in_array($history['status'], ['CANCELLED','REJECTED','EXPIRED','NOT_COLLECTED'], true)
                                ? 'status-cancelled'
                                : 'status-accepted';
                            // Feedback column: patient feedback, else cancellation reason (+ note), else the star rating.
                            $feedback = $history['feedback_text'] ?? '';
                            if ($feedback === '' && $history['cancellation_reason']) {
                                $feedback = str_replace('_', ' ', $history['cancellation_reason']);
                                if ($history['cancellation_note']) {
                                    $feedback .= ': ' . $history['cancellation_note'];
                                }
                            }
                            if ($feedback === '' && $history['rating'] !== null) {
                                $feedback = 'Rating: ' . (int)$history['rating'] . '/5';
                            }
                            ?>
                            <tr class="<?= ($history_row++ % 2 === 0) ? 'row-light' : 'row-dark' ?>">
                                <td style="font-weight:700;"> <?= date('d/m/Y', strtotime($history['requested_at'])) ?> </td>
                                <td style="font-weight:700;"><?= htmlspecialchars($history['reservation_number']) ?></td>
                                <td style="font-weight:700;"><?= htmlspecialchars($history['patient_name']) ?></td>
                                <td><div class="med-list-item"><?= htmlspecialchars($history['medicines']) ?></div></td>
                                <td style="text-align:center;font-weight:700;"><?= htmlspecialchars($history['quantities']) ?></td>
                                <td class="<?= $statusClass ?>"><?= htmlspecialchars($history['status']) ?></td>
                                <td style="font-weight:700;">
                                    <?= $history['collected_at'] ? date('d/m/Y h:i A', strtotime($history['collected_at'])) : ($history['pickup_deadline'] ? date('d/m/Y h:i A', strtotime($history['pickup_deadline'])) : '-') ?>
                                </td>
                                <td style="font-weight:600;max-width:240px;"><?= htmlspecialchars($feedback !== '' ? $feedback : '-') ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <!-- Empty state. -->
            <div class="pending-card" style="text-align:center;color:#64748b;font-weight:600;">
                No reservation history found.
            </div>
        <?php endif; ?>
