-- =====================================================
-- FitPal test script — force an order to 'failed'
-- =====================================================
-- Run against: fitpal_food_delivery
--
-- What this script does:
--   1. Verifies the order exists and shows its current state.
--   2. Transitions the order to 'delivering' if it is not already,
--      so the sweep's status filter will match it.
--   3. Backdates orders.updated_at by two hours so
--      sweepFailedDeliveries() picks the order up on the next
--      request that reaches the shared order-transaction handler.
--   4. Voids any 'collected' rider_collection row for the order,
--      matching what sweepFailedDeliveries() would do.
--
-- The script is transaction-wrapped. Run the whole block; if
-- anything looks wrong in the SELECT before the COMMIT, roll back.
--
-- Replace 3 with the order_id you want to fail.
-- =====================================================

USE fitpal_food_delivery;

-- -----------------------------------------------------
-- Set the target order id once.
-- -----------------------------------------------------
SET @target_order_id := 4;

-- -----------------------------------------------------
-- Show the order's current state so you can see what
-- will change.
-- -----------------------------------------------------
SELECT o.order_id, o.order_status, o.payment_method, o.delivery_rider_id, o.cancelled_by, o.delivered_at, o.order_date, o.updated_at
FROM orders o
WHERE
    o.order_id = @target_order_id;

-- -----------------------------------------------------
-- Show any rider_collection row the order currently has.
-- COD orders in flight will have one; Wallet and Online
-- orders will not.
-- -----------------------------------------------------
SELECT rc.rider_collection_id, rc.delivery_rider_id, rc.order_id, rc.amount, rc.status, rc.collected_at, rc.settled_at
FROM rider_collection rc
WHERE
    rc.order_id = @target_order_id;

-- -----------------------------------------------------
-- Perform the transition.
--
-- Wrap the writes so the whole block can be rolled back if
-- the pre-flight SELECT above shows something unexpected.
-- -----------------------------------------------------
START TRANSACTION;

-- 1. Move the order to 'delivering' if it is not already
--    there. The sweep only looks at 'delivering' orders.
UPDATE orders
SET
    order_status = 'delivering',
    cancelled_by = NULL,
    delivered_at = NULL,
    updated_at = NOW()
WHERE
    order_id = @target_order_id
    AND order_status IN (
        'pending',
        'preparing',
        'rider_pending',
        'picking_up'
    );

-- 2. Backdate updated_at by two hours so the sweep's
--    `updated_at <= NOW() - INTERVAL 3600 SECOND` filter
--    matches on the next request.
UPDATE orders
SET
    updated_at = NOW() - INTERVAL 2 HOUR
WHERE
    order_id = @target_order_id
    AND order_status = 'delivering';

-- 3. Void any rider_collection row the order currently has.
--    This mirrors what sweepFailedDeliveries() writes:
--    the rider returned the cash to the customer, so the
--    custody record is closed.
UPDATE rider_collection
SET
    status = 'void',
    settled_at = NULL,
    notes = 'Test script: order forced to failed',
    updated_at = NOW()
WHERE
    order_id = @target_order_id
    AND status = 'collected';

-- -----------------------------------------------------
-- Confirm the state before committing.
-- -----------------------------------------------------
SELECT
    o.order_id,
    o.order_status,
    o.payment_method,
    o.delivery_rider_id,
    o.cancelled_by,
    o.delivered_at,
    o.updated_at,
    TIMESTAMPDIFF(SECOND, o.updated_at, NOW()) AS seconds_since_update
FROM orders o
WHERE
    o.order_id = @target_order_id;

SELECT rc.rider_collection_id, rc.order_id, rc.amount, rc.status, rc.settled_at, rc.notes
FROM rider_collection rc
WHERE
    rc.order_id = @target_order_id;

-- -----------------------------------------------------
-- Commit when the two SELECTs above look correct.
-- Use ROLLBACK instead if anything is wrong.
-- -----------------------------------------------------
COMMIT;
-- ROLLBACK;