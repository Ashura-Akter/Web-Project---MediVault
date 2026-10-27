<?php
/**
 * MediVault - Pharmacist module
 * File    : views/tabs/pharmacist_tab_order_history.php
 * Title   : Tab: Order History (?tab=order_history)
 *
 * FRONTEND (HTML) + BACKEND (PHP + SQL)
 * Online orders of the pharmacy with sales summary cards.
 * SQL inside this file.
 */
defined('PHARMACIST_ROOT') || exit('Direct access not allowed.');
?>
        <?php
        // SQL (summary cards): number of sold order lines, total quantity and total sales amount of this pharmacy.
        // CANCELLED orders are not counted.
        $ohStmt=$conn->prepare("SELECT COUNT(DISTINCT oi.order_item_id) AS sold_lines, COALESCE(SUM(oi.quantity),0) AS sold_qty, COALESCE(SUM(o.total_amount),0) AS sales_amount FROM orders o JOIN order_items oi ON oi.order_id=o.order_id WHERE o.pharmacy_id=? AND o.order_status<>'CANCELLED'");
        $ohStmt->bind_param('i',$pharmacyId); $ohStmt->execute(); $sales=$ohStmt->get_result()->fetch_assoc();
        // SQL (table): every order of this pharmacy with patient, medicines (GROUP_CONCAT) and payment.
        // LEFT JOIN payments so unpaid orders still appear. Newest order first.
        $ohStmt=$conn->prepare("SELECT o.order_id,o.order_number,o.placed_at,o.order_status,o.subtotal,o.delivery_fee,o.total_amount,u.full_name AS patient_name,u.email, pay.payment_method,pay.payment_status,GROUP_CONCAT(m.medicine_name ORDER BY m.medicine_name SEPARATOR ', ') medicines FROM orders o JOIN users u ON u.user_id=o.patient_id JOIN order_items oi ON oi.order_id=o.order_id JOIN medicines m ON m.medicine_id=oi.medicine_id LEFT JOIN payments pay ON pay.order_id=o.order_id WHERE o.pharmacy_id=? GROUP BY o.order_id,pay.payment_method,pay.payment_status ORDER BY o.placed_at DESC");
        $ohStmt->bind_param('i',$pharmacyId); $ohStmt->execute(); $ohRows=$ohStmt->get_result();
        ?>
        <!-- Two summary cards: total medicine sold and total order amount. -->
        <div class="order-summary-grid">
            <div class="kpi-card order-summary-card"><div class="kpi-label">Total Medicine Sold</div><div class="kpi-value"><?= (int)$sales['sold_qty'] ?></div></div>
            <div class="kpi-card order-summary-card"><div class="kpi-label">Total Order Amount</div><div class="kpi-value"><?= number_format((float)$sales['sales_amount'],2) ?> tk.</div></div>
        </div>
        <!-- Order table. -->
        <div class="table-container"><table class="history-table"><thead><tr><th>Order Date</th><th>Order ID</th><th>Patient</th><th>Email</th><th>Medicine</th><th>Total</th><th>Order Status</th><th>Payment</th></tr></thead><tbody>
        <?php if($ohRows->num_rows): while($oh=$ohRows->fetch_assoc()): ?>
        <tr><td><?=date('d/m/Y h:i A',strtotime($oh['placed_at']))?></td><td><?=htmlspecialchars($oh['order_number'])?></td><td><?=htmlspecialchars($oh['patient_name'])?></td><td><?=htmlspecialchars($oh['email'])?></td><td><?=htmlspecialchars($oh['medicines'])?></td><td><?=number_format((float)$oh['total_amount'],2)?> tk.</td><td><?=htmlspecialchars($oh['order_status'])?></td><td><?=htmlspecialchars($oh['payment_method']??'-')?> / <?=htmlspecialchars($oh['payment_status']??'-')?></td></tr>
        <?php endwhile; else: ?><tr><td colspan="8" style="text-align:center">No patient orders yet.</td></tr><?php endif; ?></tbody></table></div>
