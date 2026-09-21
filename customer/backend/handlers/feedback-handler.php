/**
* Get order items summary for display
*
* @param PDO $db
* @param int $orderId
* @return array<int, array<string, mixed>>
    */
    function getOrderItemsSummary(PDO $db, int $orderId): array
    {
    $stmt = $db->prepare(
    "SELECT
    qi.queue_item_id,
    qi.queue_quantity AS quantity,
    qi.unit_price,
    qi.final_price,
    qi.is_customized,
    p.product_id,
    p.name AS product_name,
    COALESCE(di.images, '') AS product_image
    FROM queue_item qi
    JOIN product p ON qi.product_id = p.product_id
    LEFT JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
    WHERE qi.order_id = :order_id
    ORDER BY qi.queue_item_id"
    );
    $stmt->execute([':order_id' => $orderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
    * Get orders for a customer with computed totals
    *
    * @param PDO $db
    * @param int $customerId
    * @param string|null $statusFilter
    * @return array<int, array<string, mixed>>
        */
        function getCustomerOrdersWithTotals(PDO $db, int $customerId, ?string $statusFilter = null): array
        {
        $sql = "SELECT
        o.order_id,
        o.order_status,
        o.payment_method,
        o.destination_address,
        o.order_date,
        o.delivered_at,
        o.cancelled_by,
        COUNT(DISTINCT qi.queue_item_id) AS item_count,
        GROUP_CONCAT(DISTINCT r.business_name SEPARATOR ', ') AS restaurant_names
        FROM orders o
        LEFT JOIN queue_item qi ON o.order_id = qi.order_id
        LEFT JOIN restaurant_branch rb ON qi.branch_id = rb.restaurant_branch_id
        LEFT JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
        WHERE o.customer_id = :customer_id";

        $params = [':customer_id' => $customerId];

        if ($statusFilter !== null && $statusFilter !== 'all') {
        $sql .= " AND o.order_status = :status";
        $params[':status'] = $statusFilter;
        }

        $sql .= " GROUP BY o.order_id ORDER BY o.order_date DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Enrich with totals
        foreach ($orders as &$order) {
        $totals = getOrderTotals($db, (int)$order['order_id']);
        $order['subtotal'] = $totals ? $totals['subtotal'] : 0.0;
        $order['delivery_fee'] = $totals ? $totals['delivery_fee'] : 0.0;
        $order['service_fee'] = $totals ? $totals['service_fee'] : 0.0;
        $order['vat'] = $totals ? $totals['vat'] : 0.0;
        $order['total_amount'] = $totals ? $totals['total'] : 0.0;
        }
        unset($order);

        return $orders;
        }

        /**
        * Check if a customer can review a specific product in an order
        *
        * @param PDO $db
        * @param int $orderId
        * @param int $productId
        * @param int $customerId
        * @return bool
        */
        function canReviewProduct(PDO $db, int $orderId, int $productId, int $customerId): bool
        {
        // Check order belongs to customer and is delivered
        $stmt = $db->prepare(
        "SELECT 1 FROM orders
        WHERE order_id = :order_id
        AND customer_id = :customer_id
        AND order_status = 'delivered'"
        );
        $stmt->execute([
        ':order_id' => $orderId,
        ':customer_id' => $customerId
        ]);

        if (!$stmt->fetch()) {
        return false;
        }

        // Check product is in order
        $stmt = $db->prepare(
        "SELECT 1 FROM queue_item
        WHERE order_id = :order_id AND product_id = :product_id"
        );
        $stmt->execute([
        ':order_id' => $orderId,
        ':product_id' => $productId
        ]);

        if (!$stmt->fetch()) {
        return false;
        }

        // Check no existing review
        $stmt = $db->prepare(
        "SELECT 1 FROM feedback
        WHERE order_id = :order_id AND product_id = :product_id"
        );
        $stmt->execute([
        ':order_id' => $orderId,
        ':product_id' => $productId
        ]);

        return !$stmt->fetch();
        }