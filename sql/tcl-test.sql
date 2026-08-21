-- =====================================================
-- VIEWS for Common Queries
-- =====================================================

-- View: Customer Order History with Product Details
CREATE OR REPLACE VIEW customer_order_details AS
SELECT
    o.order_id,
    o.customer_id,
    c.first_name AS customer_first_name,
    c.last_name AS customer_last_name,
    o.delivery_rider_id,
    dr.first_name AS rider_first_name,
    dr.last_name AS rider_last_name,
    o.destination_address,
    o.order_status,
    o.payment_method,
    o.subtotal,
    o.delivery_charge,
    o.total_amount,
    o.special_instructions,
    o.order_date,
    o.delivered_at,
    qi.queue_item_id,
    qi.queue_quantity,
    qi.unit_price,
    qi.total_price AS item_total,
    p.name AS product_name,
    p.product_id,
    rb.branch_name,
    r.business_name AS restaurant_name,
    si.instruction_text AS specific_instructions
FROM
    orders o
    JOIN customer c ON o.customer_id = c.customer_id
    LEFT JOIN delivery_rider dr ON o.delivery_rider_id = dr.delivery_rider_id
    LEFT JOIN queue_item qi ON o.order_id = qi.order_id
    LEFT JOIN product p ON qi.product_id = p.product_id
    LEFT JOIN restaurant_branch rb ON qi.branch_id = rb.restaurant_branch_id
    LEFT JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
    LEFT JOIN specific_instruction si ON qi.specific_instruction_id = si.specific_instruction_id;

-- View: Kitchen Queue with Allergen Information
CREATE OR REPLACE VIEW kitchen_queue_view AS
SELECT
    qi.queue_item_id,
    qi.order_id,
    o.order_date,
    qi.branch_id,
    rb.branch_name,
    qi.product_id,
    p.name AS product_name,
    qi.queue_quantity,
    qi.unit_price,
    qi.total_price,
    si.instruction_text AS custom_instructions,
    di.allergens,
    di.dietary_tags,
    o.special_instructions AS order_notes,
    o.order_status
FROM
    queue_item qi
    JOIN orders o ON qi.order_id = o.order_id
    JOIN product p ON qi.product_id = p.product_id
    JOIN restaurant_branch rb ON qi.branch_id = rb.restaurant_branch_id
    LEFT JOIN specific_instruction si ON qi.specific_instruction_id = si.specific_instruction_id
    LEFT JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
WHERE
    o.order_status IN ('pending', 'preparing')
ORDER BY o.order_date ASC;

-- View: Restaurant Performance Dashboard
CREATE OR REPLACE VIEW restaurant_performance AS
SELECT
    r.restaurant_id,
    r.business_name,
    rb.restaurant_branch_id,
    rb.branch_name,
    COUNT(DISTINCT o.order_id) AS total_orders,
    COALESCE(SUM(o.total_amount), 0) AS total_revenue,
    COALESCE(AVG(o.total_amount), 0) AS average_order_value,
    COUNT(DISTINCT o.customer_id) AS unique_customers,
    COALESCE(AVG(f.rating), 0) AS average_rating,
    COUNT(f.feedback_id) AS total_reviews
FROM
    restaurant r
    JOIN restaurant_branch rb ON r.restaurant_id = rb.restaurant_id
    LEFT JOIN queue_item qi ON rb.restaurant_branch_id = qi.branch_id
    LEFT JOIN orders o ON qi.order_id = o.order_id
    AND o.order_status = 'delivered'
    LEFT JOIN feedback f ON o.order_id = f.order_id
GROUP BY
    r.restaurant_id,
    rb.restaurant_branch_id;

-- View: Customer Dietary Analysis
CREATE OR REPLACE VIEW customer_dietary_analysis AS
SELECT
    c.customer_id,
    c.email,
    cp.dietary_preferences,
    cp.allergies,
    cp.fitness_goal,
    COUNT(DISTINCT o.order_id) AS total_orders,
    COALESCE(AVG(f.rating), 0) AS average_rating,
    GROUP_CONCAT(DISTINCT di.dietary_tags) AS ordered_dietary_tags
FROM
    customer c
    JOIN customer_profile cp ON c.customer_id = cp.customer_id
    LEFT JOIN orders o ON c.customer_id = o.customer_id
    AND o.order_status = 'delivered'
    LEFT JOIN queue_item qi ON o.order_id = qi.order_id
    LEFT JOIN product p ON qi.product_id = p.product_id
    LEFT JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
    LEFT JOIN feedback f ON o.order_id = f.order_id
GROUP BY
    c.customer_id;

-- View: Financial Account Balance Summary
CREATE OR REPLACE VIEW financial_account_summary AS
SELECT
    fa.financial_account_id,
    fa.account_type,
    fa.balance,
    COUNT(t.transaction_id) AS transaction_count,
    COALESCE(
        SUM(
            CASE
                WHEN t.transaction_type = 'deposit'
                AND t.status = 'completed' THEN t.amount
                ELSE 0
            END
        ),
        0
    ) AS total_deposits,
    COALESCE(
        SUM(
            CASE
                WHEN t.transaction_type = 'payment'
                AND t.status = 'completed' THEN t.amount
                ELSE 0
            END
        ),
        0
    ) AS total_payments,
    COALESCE(
        SUM(
            CASE
                WHEN t.transaction_type = 'refund'
                AND t.status = 'completed' THEN t.amount
                ELSE 0
            END
        ),
        0
    ) AS total_refunds,
    MAX(t.transaction_date) AS last_transaction_date
FROM
    financial_account fa
    LEFT JOIN transaction t ON fa.financial_account_id = t.financial_account_id
GROUP BY
    fa.financial_account_id;

-- =====================================================
-- TCL TEST SCRIPT (Transaction Control Language)
-- =====================================================

-- Drop test table if exists
DROP TABLE IF EXISTS tcl_test_orders;

-- Create test table for TCL operations
CREATE TABLE tcl_test_orders (
    test_id INT AUTO_INCREMENT PRIMARY KEY,
    customer_name VARCHAR(50) NOT NULL,
    product_name VARCHAR(50) NOT NULL,
    quantity INT NOT NULL CHECK (quantity > 0),
    price DECIMAL(10, 2) NOT NULL CHECK (price >= 0),
    total_amount DECIMAL(10, 2) GENERATED ALWAYS AS (quantity * price) STORED,
    order_status VARCHAR(20) DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) COMMENT = 'Test table for TCL operations';

-- Insert test data
INSERT INTO
    tcl_test_orders (
        customer_name,
        product_name,
        quantity,
        price
    )
VALUES (
        'John Doe',
        'Burger',
        2,
        150.00
    ),
    (
        'Jane Smith',
        'Pizza',
        1,
        250.00
    ),
    (
        'Bob Johnson',
        'Pasta',
        3,
        120.00
    ),
    (
        'Alice Brown',
        'Salad',
        1,
        80.00
    ),
    (
        'Charlie Wilson',
        'Sandwich',
        2,
        95.00
    );

-- =====================================================
-- TCL COMMANDS TEST
-- =====================================================

-- 1. START TRANSACTION and COMMIT example
START TRANSACTION;

INSERT INTO
    tcl_test_orders (
        customer_name,
        product_name,
        quantity,
        price
    )
VALUES (
        'David Lee',
        'Sushi',
        2,
        180.00
    );

UPDATE tcl_test_orders
SET
    order_status = 'confirmed'
WHERE
    customer_name = 'John Doe';

COMMIT;

SELECT 'COMMIT test completed' AS Status;

-- 2. START TRANSACTION and ROLLBACK example
START TRANSACTION;

INSERT INTO
    tcl_test_orders (
        customer_name,
        product_name,
        quantity,
        price
    )
VALUES (
        'Emma Davis',
        'Steak',
        1,
        350.00
    );

UPDATE tcl_test_orders
SET
    price = price * 1.10
WHERE
    product_name = 'Pizza';

-- Oops, rollback this transaction
ROLLBACK;

SELECT 'ROLLBACK test completed' AS Status;

-- 3. SAVEPOINT example
START TRANSACTION;

INSERT INTO
    tcl_test_orders (
        customer_name,
        product_name,
        quantity,
        price
    )
VALUES (
        'Michael Chen',
        'Noodles',
        2,
        130.00
    );

SAVEPOINT after_first_insert;

INSERT INTO
    tcl_test_orders (
        customer_name,
        product_name,
        quantity,
        price
    )
VALUES (
        'Sarah Park',
        'Rice Bowl',
        1,
        110.00
    );

SAVEPOINT after_second_insert;

UPDATE tcl_test_orders
SET
    quantity = quantity + 1
WHERE
    customer_name = 'Alice Brown';

SAVEPOINT after_update;

-- Rollback to specific savepoint
ROLLBACK TO SAVEPOINT after_second_insert;

COMMIT;

SELECT 'SAVEPOINT test completed' AS Status;

-- 4. Show final data after TCL operations
SELECT * FROM tcl_test_orders ORDER BY test_id;

-- 5. Clean up
DROP TABLE IF EXISTS tcl_test_orders;

-- =====================================================
-- END OF TCL TEST SCRIPT
-- =====================================================

-- =====================================================
-- END OF DATABASE SCHEMA
-- =====================================================