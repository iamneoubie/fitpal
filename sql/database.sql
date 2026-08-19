-- =====================================================
-- DATABASE: fitpal_food_delivery
-- Dietary Meal Ordering and Restaurant Nutrition Analytics System
-- ACID Compliant with Proper Constraints
-- =====================================================

DROP DATABASE IF EXISTS fitpal_food_delivery;

CREATE DATABASE IF NOT EXISTS fitpal_food_delivery;

USE fitpal_food_delivery;

-- =====================================================
-- 1. FINANCIAL_ACCOUNT (no dependencies)
-- =====================================================
CREATE TABLE IF NOT EXISTS financial_account (
    financial_account_id INT AUTO_INCREMENT PRIMARY KEY,
    balance DECIMAL(10, 2) NOT NULL DEFAULT 0.00 CHECK (balance >= 0),
    account_type VARCHAR(20) NOT NULL CHECK (
        account_type IN (
            'customer',
            'rider',
            'restaurant'
        )
    ),
    date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_account_type (account_type)
) COMMENT = 'Financial accounts for all users';

-- =====================================================
-- 2. CUSTOMER_ADDRESS (no dependencies)
-- =====================================================
CREATE TABLE IF NOT EXISTS customer_address (
    customer_address_id INT AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(20) NULL CHECK (
        label IN ('Home', 'Office', 'Other')
    ),
    block VARCHAR(120) NULL,
    barangay VARCHAR(100) NULL,
    city VARCHAR(100) NOT NULL,
    province VARCHAR(100) NULL,
    region VARCHAR(100) NULL,
    postal_code VARCHAR(10) NULL,
    country VARCHAR(100) DEFAULT 'Philippines',
    INDEX idx_city (city)
) COMMENT = 'Customer address storage';

-- =====================================================
-- 3. DELIVERY_RIDER_ADDRESS (no dependencies)
-- =====================================================
CREATE TABLE IF NOT EXISTS delivery_rider_address (
    delivery_rider_address_id INT AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(20) NULL CHECK (
        label IN ('Home', 'Base', 'Other')
    ),
    block VARCHAR(120) NULL,
    barangay VARCHAR(100) NULL,
    city VARCHAR(100) NOT NULL,
    province VARCHAR(100) NULL,
    region VARCHAR(100) NULL,
    postal_code VARCHAR(10) NULL,
    country VARCHAR(100) DEFAULT 'Philippines',
    INDEX idx_city (city)
) COMMENT = 'Delivery rider address storage';

-- =====================================================
-- 4. CUSTOMER (depends on customer_address)
-- =====================================================
CREATE TABLE IF NOT EXISTS customer (
    customer_id INT AUTO_INCREMENT PRIMARY KEY,
    customer_address_id INT NULL,
    first_name VARCHAR(50) NOT NULL,
    middle_name VARCHAR(50) NULL,
    last_name VARCHAR(50) NOT NULL,
    birthdate DATE NULL,
    gender VARCHAR(10) NULL CHECK (
        gender IN ('Male', 'Female', 'Other')
    ),
    email VARCHAR(100) NOT NULL UNIQUE,
    contact_number VARCHAR(15) NULL,
    username VARCHAR(30) NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_active TINYINT(1) DEFAULT 1,
    FOREIGN KEY (customer_address_id) REFERENCES customer_address (customer_address_id) ON DELETE SET NULL,
    INDEX idx_email (email),
    INDEX idx_username (username),
    INDEX idx_contact_number (contact_number)
) COMMENT = 'Customer account information';

-- =====================================================
-- 5. DELIVERY_RIDER (depends on delivery_rider_address)
-- =====================================================
CREATE TABLE IF NOT EXISTS delivery_rider (
    delivery_rider_id INT AUTO_INCREMENT PRIMARY KEY,
    delivery_rider_address_id INT NULL,
    first_name VARCHAR(50) NOT NULL,
    middle_name VARCHAR(50) NULL,
    last_name VARCHAR(50) NOT NULL,
    birthdate DATE NULL,
    gender VARCHAR(10) NULL CHECK (
        gender IN ('Male', 'Female', 'Other')
    ),
    email VARCHAR(100) NOT NULL UNIQUE,
    contact_number VARCHAR(15) NOT NULL UNIQUE,
    username VARCHAR(30) NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_active TINYINT(1) DEFAULT 1,
    FOREIGN KEY (delivery_rider_address_id) REFERENCES delivery_rider_address (delivery_rider_address_id) ON DELETE SET NULL,
    INDEX idx_email (email),
    INDEX idx_contact_number (contact_number),
    INDEX idx_username (username)
) COMMENT = 'Delivery rider account information';

-- =====================================================
-- 6. ADMINISTRATOR (no dependencies)
-- =====================================================
CREATE TABLE IF NOT EXISTS administrator (
    administrator_id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(50) NOT NULL,
    middle_name VARCHAR(50) NULL,
    last_name VARCHAR(50) NOT NULL,
    birthdate DATE NULL,
    gender VARCHAR(10) NULL CHECK (
        gender IN ('Male', 'Female', 'Other')
    ),
    email VARCHAR(100) NOT NULL UNIQUE,
    contact_number VARCHAR(15) NULL,
    username VARCHAR(30) NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_active TINYINT(1) DEFAULT 1,
    INDEX idx_email (email),
    INDEX idx_username (username)
) COMMENT = 'Administrator account information';

-- =====================================================
-- 7. CUSTOMER_PROFILE (depends on customer and financial_account)
-- =====================================================
CREATE TABLE IF NOT EXISTS customer_profile (
    customer_profile_id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL UNIQUE,
    financial_account_id INT NOT NULL UNIQUE,
    profile_picture VARCHAR(255) NULL,
    dietary_preferences TEXT NULL,
    allergies TEXT NULL,
    fitness_goal VARCHAR(30) NULL CHECK (
        fitness_goal IN (
            'weight_loss',
            'muscle_gain',
            'maintenance'
        )
    ),
    height_cm DECIMAL(5, 2) NULL CHECK (
        height_cm IS NULL
        OR height_cm > 0
    ),
    weight_kg DECIMAL(5, 2) NULL CHECK (
        weight_kg IS NULL
        OR weight_kg > 0
    ),
    FOREIGN KEY (customer_id) REFERENCES customer (customer_id) ON DELETE CASCADE,
    FOREIGN KEY (financial_account_id) REFERENCES financial_account (financial_account_id) ON DELETE CASCADE,
    INDEX idx_customer_id (customer_id),
    INDEX idx_financial_account_id (financial_account_id)
) COMMENT = 'Customer profile with dietary and health information';

-- =====================================================
-- 8. DELIVERY_RIDER_PROFILE (depends on delivery_rider, financial_account, delivery_rider_address)
-- =====================================================
CREATE TABLE IF NOT EXISTS delivery_rider_profile (
    delivery_rider_profile_id INT AUTO_INCREMENT PRIMARY KEY,
    delivery_rider_id INT NOT NULL UNIQUE,
    financial_account_id INT NOT NULL UNIQUE,
    profile_picture VARCHAR(255) NULL,
    address_id INT NULL,
    vehicle_type VARCHAR(20) NULL CHECK (
        vehicle_type IN (
            'motorcycle',
            'car',
            'bicycle'
        )
    ),
    vehicle_plate VARCHAR(10) NULL,
    is_verified TINYINT(1) DEFAULT 0,
    average_rating DECIMAL(2, 1) DEFAULT 0.0 CHECK (
        average_rating BETWEEN 0 AND 5
    ),
    total_deliveries INT DEFAULT 0 CHECK (total_deliveries >= 0),
    is_available TINYINT(1) DEFAULT 1,
    FOREIGN KEY (delivery_rider_id) REFERENCES delivery_rider (delivery_rider_id) ON DELETE CASCADE,
    FOREIGN KEY (financial_account_id) REFERENCES financial_account (financial_account_id) ON DELETE CASCADE,
    FOREIGN KEY (address_id) REFERENCES delivery_rider_address (delivery_rider_address_id) ON DELETE SET NULL,
    INDEX idx_delivery_rider_id (delivery_rider_id),
    INDEX idx_financial_account_id (financial_account_id),
    INDEX idx_is_available (is_available)
) COMMENT = 'Delivery rider profile with verification and performance data';

-- =====================================================
-- 9. ADMINISTRATOR_PROFILE (depends on administrator)
-- =====================================================
CREATE TABLE IF NOT EXISTS administrator_profile (
    administrator_profile_id INT AUTO_INCREMENT PRIMARY KEY,
    administrator_id INT NOT NULL UNIQUE,
    role VARCHAR(20) NOT NULL DEFAULT 'support' CHECK (
        role IN (
            'super_admin',
            'manager',
            'support'
        )
    ),
    permissions JSON NULL,
    is_active TINYINT(1) DEFAULT 1,
    last_login TIMESTAMP NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (administrator_id) REFERENCES administrator (administrator_id) ON DELETE CASCADE,
    INDEX idx_administrator_id (administrator_id),
    INDEX idx_is_active (is_active)
) COMMENT = 'Administrator profile with roles and permissions';

-- =====================================================
-- 10. RESTAURANT (depends on administrator)
-- =====================================================
CREATE TABLE IF NOT EXISTS restaurant (
    restaurant_id INT AUTO_INCREMENT PRIMARY KEY,
    owner_id INT NOT NULL,
    business_name VARCHAR(100) NOT NULL,
    cuisine_type VARCHAR(50) NULL CHECK (
        cuisine_type IN (
            'Filipino',
            'Italian',
            'Japanese',
            'Chinese'
        )
    ),
    dietary_tags TEXT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_id) REFERENCES administrator (administrator_id) ON DELETE CASCADE,
    INDEX idx_owner_id (owner_id),
    INDEX idx_business_name (business_name)
) COMMENT = 'Restaurant information';

-- =====================================================
-- 11. RESTAURANT_BRANCH (depends on restaurant and financial_account)
-- =====================================================
CREATE TABLE IF NOT EXISTS restaurant_branch (
    restaurant_branch_id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id INT NOT NULL,
    financial_account_id INT NOT NULL UNIQUE,
    branch_name VARCHAR(50) NOT NULL,
    branch_code VARCHAR(20) NOT NULL UNIQUE,
    block VARCHAR(120) NULL,
    barangay VARCHAR(100) NULL,
    city VARCHAR(100) NOT NULL,
    province VARCHAR(100) NULL,
    region VARCHAR(100) NULL,
    postal_code VARCHAR(10) NULL,
    country VARCHAR(100) DEFAULT 'Philippines',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (restaurant_id) REFERENCES restaurant (restaurant_id) ON DELETE CASCADE,
    FOREIGN KEY (financial_account_id) REFERENCES financial_account (financial_account_id) ON DELETE CASCADE,
    INDEX idx_restaurant_id (restaurant_id),
    INDEX idx_branch_code (branch_code),
    INDEX idx_financial_account_id (financial_account_id)
) COMMENT = 'Restaurant branches with financial accounts';

-- =====================================================
-- 12. DIETARY_INFORMATION (no dependencies)
-- =====================================================
CREATE TABLE IF NOT EXISTS dietary_information (
    dietary_information_id INT AUTO_INCREMENT PRIMARY KEY,
    images VARCHAR(255) NULL,
    category VARCHAR(30) NULL CHECK (
        category IN (
            'Appetizer',
            'Main',
            'Dessert',
            'Beverage'
        )
    ),
    dietary_tags TEXT NULL,
    allergens TEXT NULL,
    calories INT NULL CHECK (
        calories IS NULL
        OR calories >= 0
    ),
    protein DECIMAL(5, 2) NULL CHECK (
        protein IS NULL
        OR protein >= 0
    ),
    carbs DECIMAL(5, 2) NULL CHECK (
        carbs IS NULL
        OR carbs >= 0
    ),
    fat DECIMAL(5, 2) NULL CHECK (
        fat IS NULL
        OR fat >= 0
    ),
    INDEX idx_category (category)
) COMMENT = 'Nutritional and dietary information for products';

-- =====================================================
-- 13. PRODUCT (depends on restaurant_branch and dietary_information)
-- =====================================================
CREATE TABLE IF NOT EXISTS product (
    product_id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_branch_id INT NOT NULL,
    dietary_information_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    price DECIMAL(8, 2) NOT NULL CHECK (price >= 0),
    stock INT NOT NULL DEFAULT 0 CHECK (stock >= 0),
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (restaurant_branch_id) REFERENCES restaurant_branch (restaurant_branch_id) ON DELETE CASCADE,
    FOREIGN KEY (dietary_information_id) REFERENCES dietary_information (dietary_information_id) ON DELETE CASCADE,
    INDEX idx_restaurant_branch_id (restaurant_branch_id),
    INDEX idx_is_active (is_active)
) COMMENT = 'Product listings with nutritional information';

-- =====================================================
-- 14. CART (depends on customer and product)
-- =====================================================
CREATE TABLE IF NOT EXISTS cart (
    cart_id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1 CHECK (quantity > 0),
    price DECIMAL(8, 2) NOT NULL CHECK (price >= 0),
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customer (customer_id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES product (product_id) ON DELETE CASCADE,
    INDEX idx_customer_id (customer_id),
    INDEX idx_product_id (product_id),
    UNIQUE KEY unique_cart_item (customer_id, product_id)
) COMMENT = 'Shopping cart items';

-- =====================================================
-- 15. SPECIFIC_INSTRUCTION (no dependencies)
-- =====================================================
CREATE TABLE IF NOT EXISTS specific_instruction (
    specific_instruction_id INT AUTO_INCREMENT PRIMARY KEY,
    instruction_text TEXT NOT NULL
) COMMENT = 'Per-item custom instructions';

-- =====================================================
-- 16. ORDERS (depends on customer and delivery_rider)
-- =====================================================
CREATE TABLE IF NOT EXISTS orders (
    order_id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    delivery_rider_id INT NULL,
    destination_address VARCHAR(250) NOT NULL,
    order_status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (
        order_status IN (
            'pending',
            'preparing',
            'delivering',
            'delivered',
            'cancelled'
        )
    ),
    payment_method VARCHAR(20) NOT NULL DEFAULT 'COD' CHECK (
        payment_method IN ('COD', 'Wallet', 'Online')
    ),
    subtotal DECIMAL(10, 2) NOT NULL CHECK (subtotal >= 0),
    delivery_charge DECIMAL(8, 2) NOT NULL DEFAULT 0.00 CHECK (delivery_charge >= 0),
    total_amount DECIMAL(10, 2) GENERATED ALWAYS AS (subtotal + delivery_charge) STORED,
    special_instructions TEXT NULL,
    cancelled_by VARCHAR(20) NULL CHECK (
        cancelled_by IN (
            'customer',
            'restaurant',
            'rider',
            'admin'
        )
    ),
    order_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    delivered_at TIMESTAMP NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customer (customer_id) ON DELETE CASCADE,
    FOREIGN KEY (delivery_rider_id) REFERENCES delivery_rider (delivery_rider_id) ON DELETE SET NULL,
    INDEX idx_customer_id (customer_id),
    INDEX idx_delivery_rider_id (delivery_rider_id),
    INDEX idx_order_status (order_status),
    INDEX idx_order_date (order_date)
) COMMENT = 'Order transactions';

-- =====================================================
-- 17. QUEUE_ITEM (depends on orders, restaurant_branch, product, specific_instruction)
-- =====================================================
CREATE TABLE IF NOT EXISTS queue_item (
    queue_item_id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    branch_id INT NOT NULL,
    product_id INT NOT NULL,
    queue_quantity INT NOT NULL CHECK (queue_quantity > 0),
    unit_price DECIMAL(8, 2) NOT NULL CHECK (unit_price >= 0),
    total_price DECIMAL(10, 2) GENERATED ALWAYS AS (queue_quantity * unit_price) STORED,
    specific_instruction_id INT NULL,
    FOREIGN KEY (order_id) REFERENCES orders (order_id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES restaurant_branch (restaurant_branch_id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES product (product_id) ON DELETE CASCADE,
    FOREIGN KEY (specific_instruction_id) REFERENCES specific_instruction (specific_instruction_id) ON DELETE SET NULL,
    INDEX idx_order_id (order_id),
    INDEX idx_branch_id (branch_id),
    INDEX idx_product_id (product_id)
) COMMENT = 'Kitchen queue items with per-item instructions';

-- =====================================================
-- 18. TRANSACTION (depends on financial_account and orders)
-- =====================================================
CREATE TABLE IF NOT EXISTS transaction (
    transaction_id INT AUTO_INCREMENT PRIMARY KEY,
    financial_account_id INT NOT NULL,
    order_id INT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    transaction_type VARCHAR(20) NOT NULL CHECK (
        transaction_type IN (
            'deposit',
            'payment',
            'refund',
            'withdrawal'
        )
    ),
    status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (
        status IN (
            'pending',
            'completed',
            'failed'
        )
    ),
    description VARCHAR(255) NULL,
    transaction_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (financial_account_id) REFERENCES financial_account (financial_account_id) ON DELETE CASCADE,
    FOREIGN KEY (order_id) REFERENCES orders (order_id) ON DELETE SET NULL,
    INDEX idx_financial_account_id (financial_account_id),
    INDEX idx_order_id (order_id),
    INDEX idx_transaction_date (transaction_date)
) COMMENT = 'Financial transaction history';

-- =====================================================
-- 19. FEEDBACK (depends on product, customer, orders)
-- =====================================================
CREATE TABLE IF NOT EXISTS feedback (
    feedback_id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    customer_id INT NOT NULL,
    order_id INT NOT NULL UNIQUE,
    rating TINYINT NOT NULL CHECK (rating BETWEEN 1 AND 5),
    comment TEXT NULL,
    date_posted TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES product (product_id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customer (customer_id) ON DELETE CASCADE,
    FOREIGN KEY (order_id) REFERENCES orders (order_id) ON DELETE CASCADE,
    INDEX idx_product_id (product_id),
    INDEX idx_customer_id (customer_id),
    INDEX idx_order_id (order_id)
) COMMENT = 'Product reviews and feedback';

-- =====================================================
-- 20. NOTIFICATION (no foreign keys - polymorphic)
-- =====================================================
CREATE TABLE IF NOT EXISTS notification (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    recipient_type VARCHAR(20) NOT NULL CHECK (
        recipient_type IN (
            'customer',
            'delivery_rider',
            'administrator',
            'restaurant_owner'
        )
    ),
    recipient_id INT NOT NULL,
    title VARCHAR(100) NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    notification_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_recipient (recipient_type, recipient_id),
    INDEX idx_is_read (is_read)
) COMMENT = 'System notifications';

-- =====================================================
-- ADDITIONAL INDEXES for Performance
-- =====================================================
CREATE INDEX idx_orders_customer_status ON orders (customer_id, order_status);

CREATE INDEX idx_orders_rider_status ON orders (
    delivery_rider_id,
    order_status
);

CREATE INDEX idx_orders_status_date ON orders (order_status, order_date);

CREATE INDEX idx_queue_branch_status ON queue_item (branch_id);

CREATE INDEX idx_product_branch_active ON product (
    restaurant_branch_id,
    is_active
);

CREATE INDEX idx_cart_customer_added ON cart (customer_id, added_at);

CREATE INDEX idx_transaction_account_type ON transaction (
    financial_account_id,
    transaction_type
);

CREATE INDEX idx_transaction_account_date ON transaction (
    financial_account_id,
    transaction_date DESC
);

-- =====================================================
-- TRIGGERS for Data Integrity (ACID Compliance)
-- =====================================================

DELIMITER $$

-- Trigger: Prevent negative stock on order creation
CREATE TRIGGER before_queue_item_insert
BEFORE INSERT ON queue_item
FOR EACH ROW
BEGIN
    DECLARE current_stock INT;
    
    SELECT stock INTO current_stock 
    FROM product 
    WHERE product_id = NEW.product_id;
    
    IF NEW.queue_quantity > current_stock THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'Insufficient stock available';
    END IF;
END$$

-- Trigger: Update stock when order is placed
CREATE TRIGGER after_queue_item_insert
AFTER INSERT ON queue_item
FOR EACH ROW
BEGIN
    UPDATE product 
    SET stock = stock - NEW.queue_quantity
    WHERE product_id = NEW.product_id;
    
    IF (SELECT stock FROM product WHERE product_id = NEW.product_id) < 0 THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'Stock would become negative';
    END IF;
END$$

-- Trigger: Restore stock when order is cancelled
CREATE TRIGGER after_order_cancelled
AFTER UPDATE ON orders
FOR EACH ROW
BEGIN
    IF NEW.order_status = 'cancelled' AND OLD.order_status != 'cancelled' THEN
        UPDATE product p
        JOIN queue_item qi ON p.product_id = qi.product_id
        SET p.stock = p.stock + qi.queue_quantity
        WHERE qi.order_id = NEW.order_id;
    END IF;
END$$

-- Trigger: Update delivered_at timestamp
CREATE TRIGGER before_order_delivered
BEFORE UPDATE ON orders
FOR EACH ROW
BEGIN
    IF NEW.order_status = 'delivered' AND OLD.order_status != 'delivered' THEN
        SET NEW.delivered_at = CURRENT_TIMESTAMP;
    END IF;
END$$

-- Trigger: Update rider statistics after delivery
CREATE TRIGGER after_order_delivered
AFTER UPDATE ON orders
FOR EACH ROW
BEGIN
    IF NEW.order_status = 'delivered' AND OLD.order_status != 'delivered' AND NEW.delivery_rider_id IS NOT NULL THEN
        UPDATE delivery_rider_profile 
        SET total_deliveries = total_deliveries + 1
        WHERE delivery_rider_id = NEW.delivery_rider_id;
    END IF;
END$$

-- Trigger: Prevent negative balance in financial account
CREATE TRIGGER before_transaction_insert
BEFORE INSERT ON transaction
FOR EACH ROW
BEGIN
    DECLARE current_balance DECIMAL(10,2);
    
    SELECT balance INTO current_balance 
    FROM financial_account 
    WHERE financial_account_id = NEW.financial_account_id;
    
    IF NEW.transaction_type IN ('payment', 'withdrawal') AND NEW.amount > current_balance THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'Insufficient balance';
    END IF;
END$$

-- Trigger: Update balance after transaction
CREATE TRIGGER after_transaction_insert
AFTER INSERT ON transaction
FOR EACH ROW
BEGIN
    IF NEW.status = 'completed' THEN
        IF NEW.transaction_type IN ('deposit', 'refund') THEN
            UPDATE financial_account 
            SET balance = balance + NEW.amount
            WHERE financial_account_id = NEW.financial_account_id;
        ELSEIF NEW.transaction_type IN ('payment', 'withdrawal') THEN
            UPDATE financial_account 
            SET balance = balance - NEW.amount
            WHERE financial_account_id = NEW.financial_account_id;
        END IF;
    END IF;
END$$

-- Trigger: Prevent duplicate active orders for same rider
CREATE TRIGGER before_order_rider_assign
BEFORE UPDATE ON orders
FOR EACH ROW
BEGIN
    DECLARE active_orders INT;
    
    IF NEW.delivery_rider_id IS NOT NULL AND NEW.order_status IN ('preparing', 'delivering') THEN
        SELECT COUNT(*) INTO active_orders
        FROM orders
        WHERE delivery_rider_id = NEW.delivery_rider_id
        AND order_status IN ('preparing', 'delivering')
        AND order_id != NEW.order_id;
        
        IF active_orders > 2 THEN
            SIGNAL SQLSTATE '45000' 
            SET MESSAGE_TEXT = 'Rider already has too many active orders';
        END IF;
    END IF;
END$$

DELIMITER;

-- =====================================================
-- STORED PROCEDURES for Atomic Operations (TCL)
-- =====================================================

DELIMITER $$

-- Procedure: Create Order with Transaction Control
CREATE PROCEDURE sp_create_order(
    IN p_customer_id INT,
    IN p_destination_address VARCHAR(250),
    IN p_payment_method VARCHAR(20),
    IN p_special_instructions TEXT,
    OUT p_order_id INT
)
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;
    
    START TRANSACTION;
    
    INSERT INTO orders (
        customer_id,
        destination_address,
        payment_method,
        subtotal,
        delivery_charge,
        special_instructions,
        order_status
    )
    SELECT 
        p_customer_id,
        p_destination_address,
        p_payment_method,
        SUM(c.quantity * c.price),
        50.00,
        p_special_instructions,
        'pending'
    FROM cart c
    WHERE c.customer_id = p_customer_id
    GROUP BY c.customer_id;
    
    SET p_order_id = LAST_INSERT_ID();
    
    INSERT INTO queue_item (
        order_id,
        branch_id,
        product_id,
        queue_quantity,
        unit_price
    )
    SELECT 
        p_order_id,
        p.restaurant_branch_id,
        c.product_id,
        c.quantity,
        c.price
    FROM cart c
    JOIN product p ON c.product_id = p.product_id
    WHERE c.customer_id = p_customer_id;
    
    DELETE FROM cart WHERE customer_id = p_customer_id;
    
    COMMIT;
END$$

-- Procedure: Cancel Order with Transaction Control
CREATE PROCEDURE sp_cancel_order(
    IN p_order_id INT,
    IN p_cancelled_by VARCHAR(20),
    OUT p_success BOOLEAN
)
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        SET p_success = FALSE;
        RESIGNAL;
    END;
    
    START TRANSACTION;
    
    UPDATE orders 
    SET order_status = 'cancelled',
        cancelled_by = p_cancelled_by
    WHERE order_id = p_order_id 
    AND order_status IN ('pending', 'preparing');
    
    SET p_success = TRUE;
    COMMIT;
END$$

-- Procedure: Process Refund with Transaction Control
CREATE PROCEDURE sp_process_refund(
    IN p_order_id INT,
    IN p_amount DECIMAL(10,2),
    IN p_description VARCHAR(255),
    OUT p_transaction_id INT
)
BEGIN
    DECLARE v_financial_account_id INT;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;
    
    START TRANSACTION;
    
    SELECT cp.financial_account_id INTO v_financial_account_id
    FROM orders o
    JOIN customer c ON o.customer_id = c.customer_id
    JOIN customer_profile cp ON c.customer_id = cp.customer_id
    WHERE o.order_id = p_order_id;
    
    INSERT INTO transaction (
        financial_account_id,
        order_id,
        amount,
        transaction_type,
        status,
        description
    ) VALUES (
        v_financial_account_id,
        p_order_id,
        p_amount,
        'refund',
        'completed',
        p_description
    );
    
    SET p_transaction_id = LAST_INSERT_ID();
    
    UPDATE orders 
    SET order_status = 'refunded'
    WHERE order_id = p_order_id;
    
    COMMIT;
END$$

-- Procedure: Process Payment with Transaction Control
CREATE PROCEDURE sp_process_payment(
    IN p_order_id INT,
    IN p_amount DECIMAL(10,2),
    IN p_payment_method VARCHAR(20),
    OUT p_transaction_id INT
)
BEGIN
    DECLARE v_financial_account_id INT;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;
    
    START TRANSACTION;
    
    SELECT cp.financial_account_id INTO v_financial_account_id
    FROM orders o
    JOIN customer c ON o.customer_id = c.customer_id
    JOIN customer_profile cp ON c.customer_id = cp.customer_id
    WHERE o.order_id = p_order_id;
    
    IF p_payment_method = 'Wallet' THEN
        INSERT INTO transaction (
            financial_account_id,
            order_id,
            amount,
            transaction_type,
            status,
            description
        ) VALUES (
            v_financial_account_id,
            p_order_id,
            p_amount,
            'payment',
            'completed',
            CONCAT('Payment for order #', p_order_id)
        );
        
        SET p_transaction_id = LAST_INSERT_ID();
    END IF;
    
    COMMIT;
END$$

DELIMITER;

-- =====================================================
-- VIEWS for Common Queries
-- =====================================================

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