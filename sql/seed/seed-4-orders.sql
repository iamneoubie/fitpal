-- =====================================================
-- FitPal Seed Data — 4 of 4: ORDERS
-- Version 1.3
--
-- ALIGNED WITH: fitpal_food_delivery schema v2.5.0
--
-- RUN ORDER (strict):
--   1. seed-1-core.sql      (structure & essential values)
--   2. seed-2-content.sql   (descriptions & copy)
--   3. seed-3-paths.sql     (profile pictures, permits, documents)
--   4. seed-4-orders.sql    ← this file
--
-- RESPONSIBILITY
-- --------------
-- 30 finished orders spread across the three seeded
-- restaurants and across recent calendar time, so the
-- restaurant dashboard's Revenue, Trending, Days, and
-- Categories panels all have data to display.
--
-- "Finished" means order_status = 'delivered'. Every
-- revenue reader in restaurant/backend/database/
-- restaurant-queries.php filters on
--     order_status = 'delivered'
-- and the project's business rule is explicit:
--
--     NO GROSS REVENUE UNTIL THE ORDER IS
--     FINISHED/DELIVERED.
--
-- Cancelled, refunded, and failed orders contribute
-- zero to every revenue aggregate, everywhere in the
-- system. Only delivered orders are seeded here.
--
-- -----------------------------------------------------
-- v1.3 — BRANCH RESOLUTION FIXED IN SECTION 3b
-- -----------------------------------------------------
-- The v1.2 pairing resolved each order's branch by
-- joining seed4_slot.branch_pick to a separate
-- subquery that enumerated the three branches:
--
--     JOIN (
--         SELECT CASE branch_pick
--                    WHEN 0 THEN @seed_branch1_id
--                    WHEN 1 THEN @seed_branch2_id
--                    WHEN 2 THEN @seed_branch3_id
--                END AS branch_id
--         FROM (SELECT 0 UNION ALL SELECT 1 UNION ALL SELECT 2) picks
--     ) AS slot_branch ON slot_branch.branch_id IS NOT NULL
--
-- That join was WRONG. Its ON clause was a constant
-- predicate (`IS NOT NULL`), so it acted as a cross
-- join of every row against all three branches. The
-- result was a cartesian product and, because the
-- target table has PRIMARY KEY (order_id), only the
-- first surviving row per order was kept — the one
-- from the first branch in the enumeration, Green
-- Bowl Cafe.
--
-- Every queue_item row was therefore written with
-- Green Bowl's branch id. Keto Kitchen and Asian
-- Fusion Fit ended up with zero queue_item rows. Any
-- owner who was not Green Bowl's owner saw an empty
-- dashboard.
--
-- v1.3 removes the broken join. The branch is now a
-- scalar CASE in the SELECT list, and the join to
-- `picked` compares against the same CASE so the
-- product and the branch come from the same row of
-- seed4_slot:
--
--     CASE s.branch_pick
--         WHEN 0 THEN @seed_branch1_id
--         WHEN 1 THEN @seed_branch2_id
--         WHEN 2 THEN @seed_branch3_id
--     END
--
-- No cross join, no cartesian product, no PRIMARY KEY
-- collision. Each order gets exactly one branch and
-- one product, and the branch is the one seed4_slot
-- names for that order_rank.
--
-- -----------------------------------------------------
-- v1.2 — EXPLICIT ORDER → BRANCH → PRODUCT PAIRING
-- -----------------------------------------------------
-- The pairing is driven by seed4_slot, an explicit
-- 30-row VALUES list. Each row names:
--
--     order_rank    1..30
--     branch_pick   0 = Green Bowl Cafe
--                   1 = Keto Kitchen
--                   2 = Asian Fusion Fit
--     product_rank  1..10, the Nth product of that
--                   branch ordered by product_id ASC
--
-- A reviewer reads the VALUES list and sees exactly
-- which branch and which product rank every order
-- gets. No window function is trusted to agree with
-- another.
--
-- The pairing is 10 orders per branch. Every branch's
-- ten products each appear exactly once.
--
-- -----------------------------------------------------
-- v1.1 — QUEUE_ITEM INSERT NO LONGER READS `product`
-- -----------------------------------------------------
-- The queue_item table has two triggers that touch
-- `product`:
--
--   before_queue_item_insert   reads  product (FOR UPDATE)
--   after_queue_item_insert    writes product.stock
--
-- MySQL refuses to let a statement that fires those
-- triggers also READ the table they touch. The v1.0
-- seed's queue_item INSERT ... SELECT joined `product`
-- in the outer statement, and MySQL aborted with:
--
--   Can't update table 'product' in stored function/
--   trigger because it is already used by statement
--   which invoked this stored function/trigger
--
-- v1.1 and later split the work in two:
--
--   Section 3b builds a temporary table that pairs every
--   seeded order with its product_id, unit_price, and
--   base_price_snapshot. This step reads `product` and
--   does NOT touch `queue_item`, so no trigger fires.
--
--   Section 4 inserts the queue_item rows from that
--   temporary table. The outer statement reads only the
--   temporary table and the target table, never
--   `product`, so the triggers are free to run.
--
-- This matches what createOrderFromQueue() does in
-- production: read the product metadata first, then
-- insert the queue_item row with scalar values already
-- in hand.
--
-- -----------------------------------------------------
-- DYNAMIC DATES
-- -----------------------------------------------------
-- Every order's delivered_at is computed from NOW() at
-- the moment this file runs:
--
--     delivered_at = DATE_SUB(NOW(),
--                     INTERVAL <n> DAY)
--                  - INTERVAL <m> HOUR
--
-- and its order_date is set to
--
--     delivered_at - INTERVAL 45 MINUTE
--
-- The 30 orders are spaced so the dashboard's three
-- period presets all return non-empty data:
--
--   last  7 days     10 orders   (the  '7d'  preset)
--   days  7 .. 29    10 orders   (the '30d'  preset
--                                 minus the '7d' part)
--   months 1 .. 11   10 orders   (the '12m'  preset
--                                 minus the '30d' part)
--
-- Every restaurant receives at least three orders in
-- each dashboard bucket, so every restaurant's owner
-- sees non-empty Trending, Days, and Categories panels
-- in every period. The branch rotation in seed4_slot
-- distributes each bucket as 4/3/3, 3/3/4, and 3/4/3
-- respectively. No restaurant is empty in any period.
--
-- Run this file today, next month, or a year from now,
-- and every offset lands in the same dashboard bucket
-- relative to that day. No date is hard-coded.
--
-- -----------------------------------------------------
-- WHAT THE SEED WRITES PER ORDER
-- -----------------------------------------------------
-- For each of the 30 delivered orders:
--
--   orders              1 row
--   queue_item          1 row
--   transaction         customer payment   (1 row)
--   transaction         rider deposit      (1 row)
--   transaction         restaurant deposit (1 row)
--
-- Plus one non-order-scoped row:
--
--   transaction         customer funding
--                       deposit            (0 or 1 row)
--
-- The funding deposit is what lets the Wallet orders'
-- completed `payment` rows pass the Insufficient
-- balance guard in before_transaction_insert. It is
-- written once and is NOT removed by a re-run.
--
-- -----------------------------------------------------
-- LEDGER SHAPE
-- -----------------------------------------------------
-- Every balance change is driven by the transaction
-- triggers:
--
--   after_transaction_insert
--       deposit  / refund  → balance = balance + amount
--       payment  / withdraw → balance = balance - amount
--
-- The seed therefore writes ledger rows, never
-- balances. A COD or Online order's customer payment
-- row is written with status = 'pending', which the
-- trigger does not apply. A Wallet order's customer
-- payment row is written with status = 'completed',
-- which the trigger applies immediately.
--
-- The rider and restaurant deposit rows on every
-- delivered order are written with status = 'completed'
-- and credit the two accounts.
--
-- -----------------------------------------------------
-- SIDE EFFECT ON product.stock
-- -----------------------------------------------------
-- The after_queue_item_insert trigger decrements
-- product.stock by queue_quantity on every inserted
-- queue_item row. Each seeded order inserts exactly
-- one queue_item row with queue_quantity = 1, so every
-- product that receives a seeded order has its stock
-- decremented by 1.
--
-- This is correct behaviour, not a side effect to
-- suppress: the seeded orders did consume one unit of
-- each product.
--
-- The seed's cleanup in Section 1 does NOT restore
-- stock. A re-run will decrement stock again. The
-- seeded stock levels (60 to 200 per product, from
-- seed-1) leave generous headroom for the three or so
-- runs a normal development cycle sees before a fresh
-- seed-1 rebuild.
--
-- -----------------------------------------------------
-- IDEMPOTENCY
-- -----------------------------------------------------
-- The seed begins by deleting every order previously
-- placed by the seeded customer, together with its
-- queue_item rows and its order-scoped transaction
-- rows. A re-run therefore replaces the 30 orders and
-- their ledger rows, and never doubles them.
--
-- The customer's funding deposit is NOT order-scoped,
-- so the delete does not remove it. Its insert is
-- guarded by a NOT EXISTS check on its description
-- string. A re-run leaves exactly one such row.
--
-- product.stock is not restored on cleanup — see the
-- note above.
--
-- -----------------------------------------------------
-- WHAT THE SEED DELIBERATELY DOES NOT WRITE
-- -----------------------------------------------------
--   rider_collection rows.
--     The v2.5.0 model treats these as the physical
--     cash-custody record for COD orders, settled on
--     delivery. Seeding them would require another
--     ten rows per COD order and would not change any
--     dashboard reading.
--
--   rider_liability_amount.
--     Cleared to NULL on successful delivery. The
--     column default is already NULL.
--
--   feedback, rating, notification, message rows.
--     Out of scope for this seed.
--
-- -----------------------------------------------------
-- PREREQUISITE
-- -----------------------------------------------------
--   seed-1-core.sql, seed-2-content.sql, seed-3-paths.sql
--   must all have run. This file depends on:
--     * the seeded customer (user@example.com)
--     * the three seeded restaurants and their branches
--     * the first ten products of each restaurant
--     * the two verified riders (rider1@fitpal.com,
--       rider2@fitpal.com)
--     * the customer's financial account
--     * the branch financial accounts
--     * the rider financial accounts
--
--   The third seeded rider (rider3@fitpal.com) is
--   verification_status = 'pending' and is deliberately
--   NOT assigned any order. A pending rider has never
--   been cleared to work.
--
--   There must be at least ten products per restaurant.
--   seed-1-core.sql inserts exactly ten, so this file's
--   product-picking expression is bounded to those ten.
-- =====================================================

USE fitpal_food_delivery;

START TRANSACTION;

-- =====================================================
-- 0. RESOLVE THE SEEDED KEYS
-- =====================================================

SET
    @seed_customer_id = (
        SELECT customer_id
        FROM customer
        WHERE
            email = 'user@example.com'
        LIMIT 1
    );

SET
    @seed_customer_address = (
        SELECT CONCAT(
                COALESCE(block, ''), ', ', COALESCE(barangay, ''), ', ', COALESCE(city, ''), ', ', COALESCE(province, ''), ' ', COALESCE(postal_code, '')
            )
        FROM customer_address
        WHERE
            customer_id = @seed_customer_id
            AND is_default = 1
        LIMIT 1
    );

SET
    @seed_customer_financial_id = (
        SELECT financial_account_id
        FROM customer_profile
        WHERE
            customer_id = @seed_customer_id
        LIMIT 1
    );

SET
    @seed_branch1_id = (
        SELECT rb.restaurant_branch_id
        FROM
            restaurant_branch rb
            JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
        WHERE
            r.business_name = 'Green Bowl Cafe'
        ORDER BY rb.restaurant_branch_id ASC
        LIMIT 1
    );

SET
    @seed_branch2_id = (
        SELECT rb.restaurant_branch_id
        FROM
            restaurant_branch rb
            JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
        WHERE
            r.business_name = 'Keto Kitchen'
        ORDER BY rb.restaurant_branch_id ASC
        LIMIT 1
    );

SET
    @seed_branch3_id = (
        SELECT rb.restaurant_branch_id
        FROM
            restaurant_branch rb
            JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
        WHERE
            r.business_name = 'Asian Fusion Fit'
        ORDER BY rb.restaurant_branch_id ASC
        LIMIT 1
    );

SET
    @seed_branch1_financial_id = (
        SELECT financial_account_id
        FROM restaurant_branch
        WHERE
            restaurant_branch_id = @seed_branch1_id
        LIMIT 1
    );

SET
    @seed_branch2_financial_id = (
        SELECT financial_account_id
        FROM restaurant_branch
        WHERE
            restaurant_branch_id = @seed_branch2_id
        LIMIT 1
    );

SET
    @seed_branch3_financial_id = (
        SELECT financial_account_id
        FROM restaurant_branch
        WHERE
            restaurant_branch_id = @seed_branch3_id
        LIMIT 1
    );

SET
    @seed_rider1_id = (
        SELECT delivery_rider_id
        FROM delivery_rider
        WHERE
            email = 'rider1@fitpal.com'
        LIMIT 1
    );

SET
    @seed_rider2_id = (
        SELECT delivery_rider_id
        FROM delivery_rider
        WHERE
            email = 'rider2@fitpal.com'
        LIMIT 1
    );

SET
    @seed_rider1_financial_id = (
        SELECT financial_account_id
        FROM delivery_rider_profile
        WHERE
            delivery_rider_id = @seed_rider1_id
        LIMIT 1
    );

SET
    @seed_rider2_financial_id = (
        SELECT financial_account_id
        FROM delivery_rider_profile
        WHERE
            delivery_rider_id = @seed_rider2_id
        LIMIT 1
    );

-- =====================================================
-- 1. CLEAN UP A PRIOR RUN
-- =====================================================

DELETE t
FROM transaction t
    JOIN orders o ON t.order_id = o.order_id
WHERE
    o.customer_id = @seed_customer_id;

DELETE qi
FROM queue_item qi
    JOIN orders o ON qi.order_id = o.order_id
WHERE
    o.customer_id = @seed_customer_id;

DELETE FROM orders WHERE customer_id = @seed_customer_id;

-- =====================================================
-- 2. FUND THE CUSTOMER'S ACCOUNT
-- =====================================================

INSERT INTO
    transaction (
        financial_account_id,
        order_id,
        amount,
        transaction_type,
        status,
        description,
        transaction_date
    )
SELECT
    @seed_customer_financial_id,
    NULL,
    10000.00,
    'deposit',
    'completed',
    'Seed-4 initial funding',
    NOW()
WHERE
    NOT EXISTS (
        SELECT 1
        FROM transaction
        WHERE
            financial_account_id = @seed_customer_financial_id
            AND description = 'Seed-4 initial funding'
    );

-- =====================================================
-- 3. INSERT THE 30 DELIVERED ORDERS
-- =====================================================
-- Each row carries:
--   customer_id             the seeded customer
--   delivery_rider_id       rider1 or rider2, rotating
--   destination_address     the customer's default address
--   order_status            'delivered'
--   payment_method          COD / Online / Wallet, rotating
--   cancelled_by            NULL      (biconditional)
--   rider_liability_amount  NULL      (cleared on delivery)
--   order_date              delivered_at - 45 min
--   delivered_at            NOW() - <days> - <hours>
--
-- The ten orders in each dashboard bucket are marked by
-- a comment block so a reviewer can see which period
-- each row lands in.

INSERT INTO
    orders (
        customer_id,
        delivery_rider_id,
        destination_address,
        order_status,
        payment_method,
        cancelled_by,
        rider_liability_amount,
        order_date,
        delivered_at
    )
VALUES
    -- ---- last 7 days  (dashboard preset '7d') ----
    (
        @seed_customer_id,
        @seed_rider1_id,
        @seed_customer_address,
        'delivered',
        'COD',
        NULL,
        NULL,
        DATE_SUB(NOW(), INTERVAL 0 DAY) - INTERVAL 2 HOUR - INTERVAL 45 MINUTE,
        DATE_SUB(NOW(), INTERVAL 0 DAY) - INTERVAL 2 HOUR
    ),
    (
        @seed_customer_id,
        @seed_rider2_id,
        @seed_customer_address,
        'delivered',
        'Online',
        NULL,
        NULL,
        DATE_SUB(NOW(), INTERVAL 0 DAY) - INTERVAL 7 HOUR - INTERVAL 45 MINUTE,
        DATE_SUB(NOW(), INTERVAL 0 DAY) - INTERVAL 7 HOUR
    ),
    (
        @seed_customer_id,
        @seed_rider1_id,
        @seed_customer_address,
        'delivered',
        'Wallet',
        NULL,
        NULL,
        DATE_SUB(NOW(), INTERVAL 1 DAY) - INTERVAL 3 HOUR - INTERVAL 45 MINUTE,
        DATE_SUB(NOW(), INTERVAL 1 DAY) - INTERVAL 3 HOUR
    ),
    (
        @seed_customer_id,
        @seed_rider2_id,
        @seed_customer_address,
        'delivered',
        'COD',
        NULL,
        NULL,
        DATE_SUB(NOW(), INTERVAL 1 DAY) - INTERVAL 11 HOUR - INTERVAL 45 MINUTE,
        DATE_SUB(NOW(), INTERVAL 1 DAY) - INTERVAL 11 HOUR
    ),
    (
        @seed_customer_id,
        @seed_rider1_id,
        @seed_customer_address,
        'delivered',
        'Online',
        NULL,
        NULL,
        DATE_SUB(NOW(), INTERVAL 2 DAY) - INTERVAL 5 HOUR - INTERVAL 45 MINUTE,
        DATE_SUB(NOW(), INTERVAL 2 DAY) - INTERVAL 5 HOUR
    ),
    (
        @seed_customer_id,
        @seed_rider2_id,
        @seed_customer_address,
        'delivered',
        'Wallet',
        NULL,
        NULL,
        DATE_SUB(NOW(), INTERVAL 3 DAY) - INTERVAL 1 HOUR - INTERVAL 45 MINUTE,
        DATE_SUB(NOW(), INTERVAL 3 DAY) - INTERVAL 1 HOUR
    ),
    (
        @seed_customer_id,
        @seed_rider1_id,
        @seed_customer_address,
        'delivered',
        'COD',
        NULL,
        NULL,
        DATE_SUB(NOW(), INTERVAL 3 DAY) - INTERVAL 9 HOUR - INTERVAL 45 MINUTE,
        DATE_SUB(NOW(), INTERVAL 3 DAY) - INTERVAL 9 HOUR
    ),
    (
        @seed_customer_id,
        @seed_rider2_id,
        @seed_customer_address,
        'delivered',
        'Online',
        NULL,
        NULL,
        DATE_SUB(NOW(), INTERVAL 4 DAY) - INTERVAL 4 HOUR - INTERVAL 45 MINUTE,
        DATE_SUB(NOW(), INTERVAL 4 DAY) - INTERVAL 4 HOUR
    ),
    (
        @seed_customer_id,
        @seed_rider1_id,
        @seed_customer_address,
        'delivered',
        'Wallet',
        NULL,
        NULL,
        DATE_SUB(NOW(), INTERVAL 5 DAY) - INTERVAL 6 HOUR - INTERVAL 45 MINUTE,
        DATE_SUB(NOW(), INTERVAL 5 DAY) - INTERVAL 6 HOUR
    ),
    (
        @seed_customer_id,
        @seed_rider2_id,
        @seed_customer_address,
        'delivered',
        'COD',
        NULL,
        NULL,
        DATE_SUB(NOW(), INTERVAL 6 DAY) - INTERVAL 8 HOUR - INTERVAL 45 MINUTE,
        DATE_SUB(NOW(), INTERVAL 6 DAY) - INTERVAL 8 HOUR
    ),
    -- ---- days 7 .. 29  (dashboard preset '30d' minus '7d') ----
    (
        @seed_customer_id,
        @seed_rider1_id,
        @seed_customer_address,
        'delivered',
        'Online',
        NULL,
        NULL,
        DATE_SUB(NOW(), INTERVAL 7 DAY) - INTERVAL 2 HOUR - INTERVAL 45 MINUTE,
        DATE_SUB(NOW(), INTERVAL 7 DAY) - INTERVAL 2 HOUR
    ),
    (
        @seed_customer_id,
        @seed_rider2_id,
        @seed_customer_address,
        'delivered',
        'Wallet',
        NULL,
        NULL,
        DATE_SUB(NOW(), INTERVAL 9 DAY) - INTERVAL 5 HOUR - INTERVAL 45 MINUTE,
        DATE_SUB(NOW(), INTERVAL 9 DAY) - INTERVAL 5 HOUR
    ),
    (
        @seed_customer_id,
        @seed_rider1_id,
        @seed_customer_address,
        'delivered',
        'COD',
        NULL,
        NULL,
        DATE_SUB(NOW(), INTERVAL 11 DAY) - INTERVAL 3 HOUR - INTERVAL 45 MINUTE,
        DATE_SUB(NOW(), INTERVAL 11 DAY) - INTERVAL 3 HOUR
    ),
    (
        @seed_customer_id,
        @seed_rider2_id,
        @seed_customer_address,
        'delivered',
        'Online',
        NULL,
        NULL,
        DATE_SUB(NOW(), INTERVAL 14 DAY) - INTERVAL 7 HOUR - INTERVAL 45 MINUTE,
        DATE_SUB(NOW(), INTERVAL 14 DAY) - INTERVAL 7 HOUR
    ),
    (
        @seed_customer_id,
        @seed_rider1_id,
        @seed_customer_address,
        'delivered',
        'Wallet',
        NULL,
        NULL,
        DATE_SUB(NOW(), INTERVAL 16 DAY) - INTERVAL 10 HOUR - INTERVAL 45 MINUTE,
        DATE_SUB(NOW(), INTERVAL 16 DAY) - INTERVAL 10 HOUR
    ),
    (
        @seed_customer_id,
        @seed_rider2_id,
        @seed_customer_address,
        'delivered',
        'COD',
        NULL,
        NULL,
        DATE_SUB(NOW(), INTERVAL 18 DAY) - INTERVAL 1 HOUR - INTERVAL 45 MINUTE,
        DATE_SUB(NOW(), INTERVAL 18 DAY) - INTERVAL 1 HOUR
    ),
    (
        @seed_customer_id,
        @seed_rider1_id,
        @seed_customer_address,
        'delivered',
        'Online',
        NULL,
        NULL,
        DATE_SUB(NOW(), INTERVAL 21 DAY) - INTERVAL 6 HOUR - INTERVAL 45 MINUTE,
        DATE_SUB(NOW(), INTERVAL 21 DAY) - INTERVAL 6 HOUR
    ),
    (
        @seed_customer_id,
        @seed_rider2_id,
        @seed_customer_address,
        'delivered',
        'Wallet',
        NULL,
        NULL,
        DATE_SUB(NOW(), INTERVAL 24 DAY) - INTERVAL 9 HOUR - INTERVAL 45 MINUTE,
        DATE_SUB(NOW(), INTERVAL 24 DAY) - INTERVAL 9 HOUR
    ),
    (
        @seed_customer_id,
        @seed_rider1_id,
        @seed_customer_address,
        'delivered',
        'COD',
        NULL,
        NULL,
        DATE_SUB(NOW(), INTERVAL 27 DAY) - INTERVAL 4 HOUR - INTERVAL 45 MINUTE,
        DATE_SUB(NOW(), INTERVAL 27 DAY) - INTERVAL 4 HOUR
    ),
    (
        @seed_customer_id,
        @seed_rider2_id,
        @seed_customer_address,
        'delivered',
        'Online',
        NULL,
        NULL,
        DATE_SUB(NOW(), INTERVAL 29 DAY) - INTERVAL 12 HOUR - INTERVAL 45 MINUTE,
        DATE_SUB(NOW(), INTERVAL 29 DAY) - INTERVAL 12 HOUR
    ),
    -- ---- months 1 .. 11  (dashboard preset '12m' minus '30d') ----
    (
        @seed_customer_id,
        @seed_rider1_id,
        @seed_customer_address,
        'delivered',
        'Wallet',
        NULL,
        NULL,
        DATE_SUB(NOW(), INTERVAL 35 DAY) - INTERVAL 3 HOUR - INTERVAL 45 MINUTE,
        DATE_SUB(NOW(), INTERVAL 35 DAY) - INTERVAL 3 HOUR
    ),
    (
        @seed_customer_id,
        @seed_rider2_id,
        @seed_customer_address,
        'delivered',
        'COD',
        NULL,
        NULL,
        DATE_SUB(NOW(), INTERVAL 48 DAY) - INTERVAL 7 HOUR - INTERVAL 45 MINUTE,
        DATE_SUB(NOW(), INTERVAL 48 DAY) - INTERVAL 7 HOUR
    ),
    (
        @seed_customer_id,
        @seed_rider1_id,
        @seed_customer_address,
        'delivered',
        'Online',
        NULL,
        NULL,
        DATE_SUB(NOW(), INTERVAL 62 DAY) - INTERVAL 5 HOUR - INTERVAL 45 MINUTE,
        DATE_SUB(NOW(), INTERVAL 62 DAY) - INTERVAL 5 HOUR
    ),
    (
        @seed_customer_id,
        @seed_rider2_id,
        @seed_customer_address,
        'delivered',
        'Wallet',
        NULL,
        NULL,
        DATE_SUB(NOW(), INTERVAL 88 DAY) - INTERVAL 2 HOUR - INTERVAL 45 MINUTE,
        DATE_SUB(NOW(), INTERVAL 88 DAY) - INTERVAL 2 HOUR
    ),
    (
        @seed_customer_id,
        @seed_rider1_id,
        @seed_customer_address,
        'delivered',
        'COD',
        NULL,
        NULL,
        DATE_SUB(NOW(), INTERVAL 112 DAY) - INTERVAL 11 HOUR - INTERVAL 45 MINUTE,
        DATE_SUB(NOW(), INTERVAL 112 DAY) - INTERVAL 11 HOUR
    ),
    (
        @seed_customer_id,
        @seed_rider2_id,
        @seed_customer_address,
        'delivered',
        'Online',
        NULL,
        NULL,
        DATE_SUB(NOW(), INTERVAL 145 DAY) - INTERVAL 6 HOUR - INTERVAL 45 MINUTE,
        DATE_SUB(NOW(), INTERVAL 145 DAY) - INTERVAL 6 HOUR
    ),
    (
        @seed_customer_id,
        @seed_rider1_id,
        @seed_customer_address,
        'delivered',
        'Wallet',
        NULL,
        NULL,
        DATE_SUB(NOW(), INTERVAL 178 DAY) - INTERVAL 1 HOUR - INTERVAL 45 MINUTE,
        DATE_SUB(NOW(), INTERVAL 178 DAY) - INTERVAL 1 HOUR
    ),
    (
        @seed_customer_id,
        @seed_rider2_id,
        @seed_customer_address,
        'delivered',
        'COD',
        NULL,
        NULL,
        DATE_SUB(NOW(), INTERVAL 215 DAY) - INTERVAL 8 HOUR - INTERVAL 45 MINUTE,
        DATE_SUB(NOW(), INTERVAL 215 DAY) - INTERVAL 8 HOUR
    ),
    (
        @seed_customer_id,
        @seed_rider1_id,
        @seed_customer_address,
        'delivered',
        'Online',
        NULL,
        NULL,
        DATE_SUB(NOW(), INTERVAL 262 DAY) - INTERVAL 4 HOUR - INTERVAL 45 MINUTE,
        DATE_SUB(NOW(), INTERVAL 262 DAY) - INTERVAL 4 HOUR
    ),
    (
        @seed_customer_id,
        @seed_rider2_id,
        @seed_customer_address,
        'delivered',
        'Wallet',
        NULL,
        NULL,
        DATE_SUB(NOW(), INTERVAL 310 DAY) - INTERVAL 10 HOUR - INTERVAL 45 MINUTE,
        DATE_SUB(NOW(), INTERVAL 310 DAY) - INTERVAL 10 HOUR
    );

-- =====================================================
-- 3b. RESOLVE EACH ORDER'S PRODUCT, PRICE, AND SNAPSHOT
-- =====================================================
-- MySQL refuses to let a statement that fires the
-- queue_item triggers also read `product`. The triggers
-- are:
--
--   before_queue_item_insert   reads  product (FOR UPDATE)
--   after_queue_item_insert    writes product.stock
--
-- The queue_item INSERT in Section 4 must not read
-- `product` in its outer statement. This step resolves
-- everything Section 4 needs — product_id, unit_price,
-- base_price_snapshot — into a temporary table while
-- nothing is being inserted into queue_item.
--
-- The pairing is driven by seed4_slot, an explicit
-- 30-row VALUES list. Each row names the branch the
-- order belongs to and the rank of the product within
-- that branch.
--
-- The branch is resolved ONCE, by a scalar CASE in the
-- SELECT list, and the SAME CASE is used in the join
-- condition that matches `picked`. There is no
-- separate join that enumerates the three branches, so
-- there is no cartesian product and no chance that
-- two orders with the same product_rank collapse onto
-- the same branch.
--
-- The branch rotation (0,1,2,0,1,2,...) distributes
-- the three dashboard buckets as:
--
--   last 7 days  (ranks 1..10):   0,1,2,0,1,2,0,1,2,0  → 4/3/3
--   days 7..29   (ranks 11..20):  1,2,0,1,2,0,1,2,0,1  → 3/3/4
--   months 1..11 (ranks 21..30):  2,0,1,2,0,1,2,0,1,2  → 3/4/3
--
-- Every restaurant therefore appears in every bucket,
-- so every owner sees non-empty Trending, Days, and
-- Categories panels regardless of which period is
-- selected.

CREATE TEMPORARY TABLE seed4_slot (
    order_rank INT NOT NULL,
    branch_pick INT NOT NULL,
    product_rank INT NOT NULL,
    PRIMARY KEY (order_rank)
) ENGINE = InnoDB;

INSERT INTO
    seed4_slot (
        order_rank,
        branch_pick,
        product_rank
    )
VALUES
    -- last 7 days: 4 / 3 / 3
    (1, 0, 1),
    (2, 1, 1),
    (3, 2, 1),
    (4, 0, 2),
    (5, 1, 2),
    (6, 2, 2),
    (7, 0, 3),
    (8, 1, 3),
    (9, 2, 3),
    (10, 0, 4),
    -- days 7..29: 3 / 3 / 4
    (11, 1, 4),
    (12, 2, 4),
    (13, 0, 5),
    (14, 1, 5),
    (15, 2, 5),
    (16, 0, 6),
    (17, 1, 6),
    (18, 2, 6),
    (19, 0, 7),
    (20, 1, 7),
    -- months 1..11: 3 / 4 / 3
    (21, 2, 7),
    (22, 0, 8),
    (23, 1, 8),
    (24, 2, 8),
    (25, 0, 9),
    (26, 1, 9),
    (27, 2, 9),
    (28, 0, 10),
    (29, 1, 10),
    (30, 2, 10);

-- Pair every seeded order with its branch and product
-- via seed4_slot. The orders are ranked by order_id so
-- the pairing is deterministic. The branch is resolved
-- by a scalar CASE, and the same CASE appears in the
-- join condition that matches `picked`, so the branch
-- and the product come from the SAME row of
-- seed4_slot. The queue_item table is untouched, so no
-- trigger fires here.

CREATE TEMPORARY TABLE seed4_order_product_map (
    order_id INT NOT NULL,
    branch_id INT NOT NULL,
    product_id INT NOT NULL,
    unit_price DECIMAL(8, 2) NOT NULL,
    base_snapshot DECIMAL(8, 2) NOT NULL,
    PRIMARY KEY (order_id)
) ENGINE = InnoDB;

INSERT INTO
    seed4_order_product_map (
        order_id,
        branch_id,
        product_id,
        unit_price,
        base_snapshot
    )
SELECT
    ranked.order_id,
    CASE s.branch_pick
        WHEN 0 THEN @seed_branch1_id
        WHEN 1 THEN @seed_branch2_id
        WHEN 2 THEN @seed_branch3_id
    END AS branch_id,
    picked.product_id,
    picked.price,
    COALESCE(
        NULLIF(picked.base_price, 0),
        picked.price
    )
FROM (
        SELECT o.order_id, ROW_NUMBER() OVER (
                ORDER BY o.order_id
            ) AS order_rank
        FROM orders o
        WHERE
            o.customer_id = @seed_customer_id
            AND o.order_status = 'delivered'
    ) AS ranked
    JOIN seed4_slot s ON s.order_rank = ranked.order_rank
    JOIN (
        SELECT
            p.product_id,
            p.restaurant_branch_id AS branch_id,
            p.price,
            p.base_price,
            ROW_NUMBER() OVER (
                PARTITION BY
                    p.restaurant_branch_id
                ORDER BY p.product_id ASC
            ) AS product_rank
        FROM product p
        WHERE
            p.is_active = 1
    ) AS picked ON picked.branch_id = CASE s.branch_pick
        WHEN 0 THEN @seed_branch1_id
        WHEN 1 THEN @seed_branch2_id
        WHEN 2 THEN @seed_branch3_id
    END
    AND picked.product_rank = s.product_rank;

DROP TEMPORARY TABLE seed4_slot;

-- =====================================================
-- 4. INSERT QUEUE_ITEM ROWS — 1 PER ORDER
-- =====================================================
-- The outer statement reads only:
--
--   * the temporary table just built
--   * nothing from `product`
--
-- It does NOT read `product`. The two queue_item
-- triggers are therefore free to read and write
-- `product` without hitting MySQL's restriction.
--
-- Each seeded order inserts exactly one queue_item
-- row with queue_quantity = 1, so the
-- after_queue_item_insert trigger decrements each
-- selected product's stock by 1. That is correct: the
-- seeded order did consume one unit.

INSERT INTO
    queue_item (
        order_id,
        branch_id,
        product_id,
        queue_quantity,
        unit_price,
        is_customized,
        base_price_snapshot,
        final_price
    )
SELECT m.order_id, m.branch_id, m.product_id, 1, m.unit_price, 0, m.base_snapshot, m.unit_price
FROM seed4_order_product_map AS m;

DROP TEMPORARY TABLE seed4_order_product_map;

-- =====================================================
-- 5. INSERT CUSTOMER PAYMENT ROWS — 1 PER ORDER
-- =====================================================

INSERT INTO
    transaction (
        financial_account_id,
        order_id,
        amount,
        transaction_type,
        status,
        description,
        transaction_date
    )
SELECT
    @seed_customer_financial_id,
    o.order_id,
    ROUND(q.subtotal * 1.12 + 55.00, 2),
    'payment',
    CASE o.payment_method
        WHEN 'Wallet' THEN 'completed'
        ELSE 'pending'
    END,
    CASE o.payment_method
        WHEN 'Wallet' THEN CONCAT(
            'Wallet payment for order #',
            o.order_id
        )
        WHEN 'Online' THEN CONCAT(
            'Online payment for order #',
            o.order_id
        )
        ELSE CONCAT(
            'Cash on delivery for order #',
            o.order_id
        )
    END,
    o.order_date
FROM orders o
    JOIN (
        SELECT qi.order_id, SUM(
                qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)
            ) AS subtotal
        FROM queue_item qi
        GROUP BY
            qi.order_id
    ) AS q ON q.order_id = o.order_id
WHERE
    o.customer_id = @seed_customer_id
    AND o.order_status = 'delivered';

-- =====================================================
-- 6. INSERT RIDER AND RESTAURANT DEPOSIT ROWS
-- =====================================================

INSERT INTO
    transaction (
        financial_account_id,
        order_id,
        amount,
        transaction_type,
        status,
        description,
        transaction_date
    )
SELECT rb.financial_account_id, o.order_id, q.subtotal, 'deposit', 'completed', CONCAT(
        'Food revenue for order #', o.order_id
    ), o.delivered_at
FROM
    orders o
    JOIN queue_item qi ON qi.order_id = o.order_id
    JOIN restaurant_branch rb ON rb.restaurant_branch_id = qi.branch_id
    JOIN (
        SELECT qi2.order_id, SUM(
                qi2.queue_quantity * COALESCE(
                    qi2.final_price, qi2.unit_price
                )
            ) AS subtotal
        FROM queue_item qi2
        GROUP BY
            qi2.order_id
    ) AS q ON q.order_id = o.order_id
WHERE
    o.customer_id = @seed_customer_id
    AND o.order_status = 'delivered'
GROUP BY
    o.order_id,
    rb.financial_account_id
UNION ALL
SELECT drp.financial_account_id, o.order_id, 50.00, 'deposit', 'completed', CONCAT(
        'Delivery fee for order #', o.order_id
    ), o.delivered_at
FROM
    orders o
    JOIN delivery_rider_profile drp ON drp.delivery_rider_id = o.delivery_rider_id
WHERE
    o.customer_id = @seed_customer_id
    AND o.order_status = 'delivered';

COMMIT;

-- =====================================================
-- VERIFICATION
-- =====================================================
SELECT '=== seed-4-orders.sql loaded (v1.3, 30 delivered orders, fixed pairing) ===' AS status;

-- V1. Order count by restaurant. Expect 10 / 10 / 10.
SELECT r.business_name, COUNT(DISTINCT o.order_id) AS order_count
FROM
    orders o
    JOIN queue_item qi ON qi.order_id = o.order_id
    JOIN restaurant_branch rb ON rb.restaurant_branch_id = qi.branch_id
    JOIN restaurant r ON r.restaurant_id = rb.restaurant_id
WHERE
    o.customer_id = @seed_customer_id
    AND o.order_status = 'delivered'
GROUP BY
    r.business_name
ORDER BY r.business_name;

-- V2. Order count by payment method. Expect 10 / 10 / 10.
SELECT o.payment_method, COUNT(*) AS order_count
FROM orders o
WHERE
    o.customer_id = @seed_customer_id
    AND o.order_status = 'delivered'
GROUP BY
    o.payment_method
ORDER BY o.payment_method;

-- V2b. THE BUCKET-BY-RESTAURANT MATRIX.
--      Every restaurant appears in every bucket.
SELECT
    r.business_name,
    SUM(
        CASE
            WHEN o.delivered_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1
            ELSE 0
        END
    ) AS orders_last_7_days,
    SUM(
        CASE
            WHEN o.delivered_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1
            ELSE 0
        END
    ) AS orders_last_30_days,
    SUM(
        CASE
            WHEN o.delivered_at >= DATE_SUB(NOW(), INTERVAL 365 DAY) THEN 1
            ELSE 0
        END
    ) AS orders_last_365_days
FROM
    orders o
    JOIN queue_item qi ON qi.order_id = o.order_id
    JOIN restaurant_branch rb ON rb.restaurant_branch_id = qi.branch_id
    JOIN restaurant r ON r.restaurant_id = rb.restaurant_id
WHERE
    o.customer_id = @seed_customer_id
    AND o.order_status = 'delivered'
GROUP BY
    r.business_name
ORDER BY r.business_name;

-- V2c. THE PER-RESTAURANT PRODUCT COUNT IN THE '7d' WINDOW.
SELECT
    r.business_name,
    COUNT(DISTINCT p.product_id) AS distinct_products_in_7d,
    COUNT(DISTINCT o.order_id) AS orders_in_7d
FROM
    orders o
    JOIN queue_item qi ON qi.order_id = o.order_id
    JOIN product p ON qi.product_id = p.product_id
    JOIN restaurant_branch rb ON rb.restaurant_branch_id = qi.branch_id
    JOIN restaurant r ON r.restaurant_id = rb.restaurant_id
WHERE
    o.customer_id = @seed_customer_id
    AND o.order_status = 'delivered'
    AND o.delivered_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY
    r.business_name
ORDER BY r.business_name;

-- V3. Every seeded order carries a non-NULL delivered_at
--      and a NULL cancelled_by.
SELECT o.order_id, o.order_status, o.delivered_at, o.cancelled_by
FROM orders o
WHERE
    o.customer_id = @seed_customer_id
    AND (
        o.delivered_at IS NULL
        OR o.cancelled_by IS NOT NULL
    );

-- V4. Bucket read-out.
SELECT
    SUM(
        CASE
            WHEN o.delivered_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1
            ELSE 0
        END
    ) AS last_7_days,
    SUM(
        CASE
            WHEN o.delivered_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1
            ELSE 0
        END
    ) AS last_30_days,
    SUM(
        CASE
            WHEN o.delivered_at >= DATE_SUB(NOW(), INTERVAL 365 DAY) THEN 1
            ELSE 0
        END
    ) AS last_365_days
FROM orders o
WHERE
    o.customer_id = @seed_customer_id
    AND o.order_status = 'delivered';

-- V5. Revenue summary by restaurant.
SELECT
    r.business_name,
    COUNT(DISTINCT o.order_id) AS delivered_orders,
    ROUND(
        COALESCE(
            SUM(
                qi.queue_quantity * COALESCE(qi.final_price, qi.unit_price)
            ),
            0
        ),
        2
    ) AS gross_revenue
FROM
    orders o
    JOIN queue_item qi ON qi.order_id = o.order_id
    JOIN restaurant_branch rb ON rb.restaurant_branch_id = qi.branch_id
    JOIN restaurant r ON r.restaurant_id = rb.restaurant_id
WHERE
    o.customer_id = @seed_customer_id
    AND o.order_status = 'delivered'
GROUP BY
    r.business_name
ORDER BY r.business_name;

-- V6. Every seeded order has exactly one queue_item row.
SELECT o.order_id, COUNT(qi.queue_item_id) AS queue_item_count
FROM orders o
    LEFT JOIN queue_item qi ON qi.order_id = o.order_id
WHERE
    o.customer_id = @seed_customer_id
    AND o.order_status = 'delivered'
GROUP BY
    o.order_id
HAVING
    queue_item_count <> 1;

-- V6b. The full order → product mapping, ordered by
--      delivered_at DESC.
SELECT
    o.order_id,
    DATE(o.delivered_at) AS delivered_on,
    r.business_name,
    p.product_id,
    p.name AS product_name,
    p.price AS unit_price
FROM
    orders o
    JOIN queue_item qi ON qi.order_id = o.order_id
    JOIN product p ON qi.product_id = p.product_id
    JOIN restaurant_branch rb ON rb.restaurant_branch_id = qi.branch_id
    JOIN restaurant r ON r.restaurant_id = rb.restaurant_id
WHERE
    o.customer_id = @seed_customer_id
    AND o.order_status = 'delivered'
ORDER BY o.delivered_at DESC;

-- V7. Every seeded delivered order produced exactly
--      three order-scoped ledger rows.
SELECT
    o.order_id,
    SUM(
        CASE
            WHEN t.transaction_type = 'payment' THEN 1
            ELSE 0
        END
    ) AS payment_rows,
    SUM(
        CASE
            WHEN t.transaction_type = 'deposit'
            AND t.description LIKE 'Delivery fee for order #%' THEN 1
            ELSE 0
        END
    ) AS rider_deposit_rows,
    SUM(
        CASE
            WHEN t.transaction_type = 'deposit'
            AND t.description LIKE 'Food revenue for order #%' THEN 1
            ELSE 0
        END
    ) AS restaurant_deposit_rows
FROM orders o
    LEFT JOIN transaction t ON t.order_id = o.order_id
WHERE
    o.customer_id = @seed_customer_id
    AND o.order_status = 'delivered'
GROUP BY
    o.order_id
HAVING
    payment_rows <> 1
    OR rider_deposit_rows <> 1
    OR restaurant_deposit_rows <> 1;

-- V8. Financial account balances after the seed.
SELECT fa.financial_account_id, fa.account_type, fa.balance
FROM financial_account fa
ORDER BY fa.financial_account_id;