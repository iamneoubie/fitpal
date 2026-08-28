<?php
/**
 * Rider Database Queries
 *
 * Handles rider-related operations. This file is used by both
 * the customer role (to view rider info) and rider role.
 *
 * @package FitPal
 * @version 1.0
 */

declare(strict_types=1);

/**
 * Get rider details by ID
 *
 * @param PDO $db Database connection
 * @param int $riderId Delivery rider ID
 * @return array|false Rider details or false if not found
 */
function getRiderById(PDO $db, int $riderId): array|false
{
    $stmt = $db->prepare(
        "SELECT 
            dr.delivery_rider_id AS rider_id,
            dr.first_name,
            dr.middle_name,
            dr.last_name,
            dr.email,
            dr.contact_number,
            dr.is_active,
            drp.profile_picture,
            drp.vehicle_type,
            drp.vehicle_plate,
            drp.is_verified,
            drp.average_rating,
            drp.total_deliveries,
            drp.is_available,
            fa.balance
        FROM delivery_rider dr
        LEFT JOIN delivery_rider_profile drp ON dr.delivery_rider_id = drp.delivery_rider_id
        LEFT JOIN financial_account fa ON drp.financial_account_id = fa.financial_account_id
        WHERE dr.delivery_rider_id = :rider_id
        AND dr.is_active = 1"
    );
    $stmt->execute([':rider_id' => $riderId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Get rider assigned to an order
 *
 * @param PDO $db Database connection
 * @param int $orderId Order ID
 * @return array|false Rider details or false if not assigned
 */
function getOrderRider(PDO $db, int $orderId): array|false
{
    $stmt = $db->prepare(
        "SELECT 
            dr.delivery_rider_id AS rider_id,
            dr.first_name,
            dr.last_name,
            dr.contact_number,
            drp.profile_picture,
            drp.vehicle_type,
            drp.vehicle_plate,
            drp.average_rating
        FROM orders o
        JOIN delivery_rider dr ON o.delivery_rider_id = dr.delivery_rider_id
        LEFT JOIN delivery_rider_profile drp ON dr.delivery_rider_id = drp.delivery_rider_id
        WHERE o.order_id = :order_id"
    );
    $stmt->execute([':order_id' => $orderId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Get available riders for assignment
 *
 * @param PDO $db Database connection
 * @param int $limit Maximum number of riders to return
 * @return array List of available riders
 */
function getAvailableRiders(PDO $db, int $limit = 10): array
{
    $stmt = $db->prepare(
        "SELECT 
            dr.delivery_rider_id AS rider_id,
            dr.first_name,
            dr.last_name,
            dr.contact_number,
            drp.vehicle_type,
            drp.average_rating,
            drp.total_deliveries
        FROM delivery_rider dr
        JOIN delivery_rider_profile drp ON dr.delivery_rider_id = drp.delivery_rider_id
        WHERE dr.is_active = 1
        AND drp.is_available = 1
        AND drp.is_verified = 1
        ORDER BY drp.average_rating DESC, drp.total_deliveries ASC
        LIMIT :limit"
    );
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Assign rider to an order
 *
 * @param PDO $db Database connection
 * @param int $orderId Order ID
 * @param int $riderId Rider ID
 * @return bool True on success
 * @throws RuntimeException If rider cannot be assigned
 */
function assignRiderToOrder(PDO $db, int $orderId, int $riderId): bool
{
    $db->beginTransaction();

    try {
        // Check order is in correct state
        $checkStmt = $db->prepare(
            "SELECT order_status FROM orders WHERE order_id = :order_id"
        );
        $checkStmt->execute([':order_id' => $orderId]);
        $order = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            throw new RuntimeException('Order not found.');
        }

        if ($order['order_status'] !== 'preparing') {
            throw new RuntimeException(
                'Order must be in "preparing" state to assign a rider.'
            );
        }

        // Check rider is available
        $riderStmt = $db->prepare(
            "SELECT drp.is_available 
             FROM delivery_rider_profile drp
             JOIN delivery_rider dr ON drp.delivery_rider_id = dr.delivery_rider_id
             WHERE drp.delivery_rider_id = :rider_id
             AND dr.is_active = 1"
        );
        $riderStmt->execute([':rider_id' => $riderId]);
        $rider = $riderStmt->fetch(PDO::FETCH_ASSOC);

        if (!$rider || !$rider['is_available']) {
            throw new RuntimeException('Rider is not available.');
        }

        // Assign rider
        $assignStmt = $db->prepare(
            "UPDATE orders 
             SET delivery_rider_id = :rider_id,
                 order_status = 'delivering'
             WHERE order_id = :order_id"
        );
        $assignStmt->execute([
            ':rider_id' => $riderId,
            ':order_id' => $orderId
        ]);

        // Mark rider as unavailable
        $unavailStmt = $db->prepare(
            "UPDATE delivery_rider_profile 
             SET is_available = 0 
             WHERE delivery_rider_id = :rider_id"
        );
        $unavailStmt->execute([':rider_id' => $riderId]);

        $db->commit();
        return true;

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}