SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================
-- DATABASE: fitpal_food_delivery v2.6.2
-- =====================================================
DROP DATABASE IF EXISTS fitpal_food_delivery;

CREATE DATABASE fitpal_food_delivery;

USE fitpal_food_delivery;

-- 1. FINANCIAL_ACCOUNT
CREATE TABLE financial_account (
    financial_account_id INT AUTO_INCREMENT PRIMARY KEY,
    balance DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
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

-- 2. CUSTOMER
CREATE TABLE customer (
    customer_id INT AUTO_INCREMENT PRIMARY KEY,
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
    INDEX idx_username (username),
    INDEX idx_contact_number (contact_number)
) COMMENT = 'Customer account information';

-- 3. CUSTOMER_ADDRESS
CREATE TABLE customer_address (
    customer_address_id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
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
    is_default TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customer (customer_id) ON DELETE CASCADE,
    INDEX idx_customer (customer_id),
    INDEX idx_city (city)
) COMMENT = 'Customer addresses';

-- 4. DELIVERY_RIDER
CREATE TABLE delivery_rider (
    delivery_rider_id INT AUTO_INCREMENT PRIMARY KEY,
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
    INDEX idx_email (email),
    INDEX idx_contact_number (contact_number),
    INDEX idx_username (username)
) COMMENT = 'Delivery rider account information';

-- 5. DELIVERY_RIDER_ADDRESS
CREATE TABLE delivery_rider_address (
    delivery_rider_address_id INT AUTO_INCREMENT PRIMARY KEY,
    delivery_rider_id INT NOT NULL,
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
    is_default TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (delivery_rider_id) REFERENCES delivery_rider (delivery_rider_id) ON DELETE CASCADE,
    INDEX idx_rider (delivery_rider_id),
    INDEX idx_city (city)
) COMMENT = 'Delivery rider addresses';

-- 5b. DELIVERY_RIDER_EMERGENCY_CONTACT
CREATE TABLE delivery_rider_emergency_contact (
    emergency_contact_id INT AUTO_INCREMENT PRIMARY KEY,
    delivery_rider_id INT NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    middle_name VARCHAR(50) NULL,
    last_name VARCHAR(50) NOT NULL,
    contact_number VARCHAR(15) NOT NULL,
    relationship VARCHAR(50) NOT NULL,
    address VARCHAR(250) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (delivery_rider_id) REFERENCES delivery_rider (delivery_rider_id) ON DELETE CASCADE,
    INDEX idx_rider (delivery_rider_id)
) COMMENT = 'Emergency contacts for delivery riders';

-- 6. ADMINISTRATOR
CREATE TABLE administrator (
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

-- 7. CUSTOMER_PROFILE
CREATE TABLE customer_profile (
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

-- 8. DELIVERY_RIDER_PROFILE
CREATE TABLE delivery_rider_profile (
    delivery_rider_profile_id INT AUTO_INCREMENT PRIMARY KEY,
    delivery_rider_id INT NOT NULL UNIQUE,
    financial_account_id INT NOT NULL UNIQUE,
    profile_picture VARCHAR(255) NULL,
    vehicle_type VARCHAR(20) NULL,
    vehicle_plate VARCHAR(10) NULL,
    verification_status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (
        verification_status IN (
            'pending',
            'denied',
            'suspended',
            'verified'
        )
    ),
    verified_by_admin_id INT NULL,
    verified_at TIMESTAMP NULL,
    average_rating DECIMAL(2, 1) DEFAULT 0.0 CHECK (
        average_rating BETWEEN 0 AND 5
    ),
    total_deliveries INT DEFAULT 0 CHECK (total_deliveries >= 0),
    is_available TINYINT(1) DEFAULT 1,
    FOREIGN KEY (delivery_rider_id) REFERENCES delivery_rider (delivery_rider_id) ON DELETE CASCADE,
    FOREIGN KEY (financial_account_id) REFERENCES financial_account (financial_account_id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by_admin_id) REFERENCES administrator (administrator_id) ON DELETE SET NULL,
    INDEX idx_delivery_rider_id (delivery_rider_id),
    INDEX idx_financial_account_id (financial_account_id),
    INDEX idx_verification_status (verification_status),
    INDEX idx_is_available (is_available)
) COMMENT = 'Delivery rider profile with verification and performance data';

-- 8b. DELIVERY_RIDER_DOCUMENT
CREATE TABLE delivery_rider_document (
    document_id INT AUTO_INCREMENT PRIMARY KEY,
    delivery_rider_id INT NOT NULL,
    id_type VARCHAR(30) NOT NULL,
    id_path VARCHAR(255) NOT NULL,
    issue_date DATE NULL,
    expiry_date DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (delivery_rider_id) REFERENCES delivery_rider (delivery_rider_id) ON DELETE CASCADE,
    INDEX idx_rider (delivery_rider_id),
    INDEX idx_id_type (id_type),
    INDEX idx_expiry (expiry_date)
) COMMENT = 'Rider identity document';

-- 9. ADMINISTRATOR_PROFILE
CREATE TABLE administrator_profile (
    administrator_profile_id INT AUTO_INCREMENT PRIMARY KEY,
    administrator_id INT NOT NULL UNIQUE,
    role VARCHAR(20) NOT NULL DEFAULT 'support' CHECK (
        role IN (
            'super_admin',
            'manager',
            'support'
        )
    ),
    profile_picture VARCHAR(255) NULL,
    permissions JSON NULL,
    is_active TINYINT(1) DEFAULT 1,
    last_login TIMESTAMP NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (administrator_id) REFERENCES administrator (administrator_id) ON DELETE CASCADE,
    INDEX idx_administrator_id (administrator_id),
    INDEX idx_is_active (is_active)
) COMMENT = 'Administrator profile';

-- 10. RESTAURANT
CREATE TABLE restaurant (
    restaurant_id INT AUTO_INCREMENT PRIMARY KEY,
    business_name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    cuisine_type VARCHAR(50) NULL,
    dietary_tags TEXT NULL,
    verification_status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (
        verification_status IN (
            'pending',
            'denied',
            'suspended',
            'verified'
        )
    ),
    verified_by_admin_id INT NULL,
    verified_at TIMESTAMP NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (verified_by_admin_id) REFERENCES administrator (administrator_id) ON DELETE SET NULL,
    INDEX idx_business_name (business_name),
    INDEX idx_verification_status (verification_status),
    INDEX idx_is_active (is_active)
) COMMENT = 'Restaurant business entity';

-- 11. RESTAURANT_BRANCH
CREATE TABLE restaurant_branch (
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
) COMMENT = 'Restaurant branches';

-- 12. RESTAURANT_PERMIT
CREATE TABLE restaurant_permit (
    permit_id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id INT NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    display_order INT NOT NULL DEFAULT 0 CHECK (display_order >= 0),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (restaurant_id) REFERENCES restaurant (restaurant_id) ON DELETE CASCADE,
    INDEX idx_restaurant (restaurant_id),
    INDEX idx_restaurant_order (restaurant_id, display_order)
) COMMENT = 'Permit photos';

-- 13. RESTAURANT_ACCOUNT
CREATE TABLE restaurant_account (
    restaurant_account_id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id INT NOT NULL,
    branch_id INT NULL,
    first_name VARCHAR(50) NOT NULL,
    middle_name VARCHAR(50) NULL,
    last_name VARCHAR(50) NOT NULL,
    birthdate DATE NULL,
    gender VARCHAR(10) NULL CHECK (
        gender IN ('Male', 'Female', 'Other')
    ),
    email VARCHAR(100) NOT NULL UNIQUE,
    contact_number VARCHAR(15) NULL UNIQUE,
    username VARCHAR(30) NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'staff' CHECK (
        role IN (
            'owner',
            'partner',
            'manager',
            'staff',
            'cashier',
            'kitchen'
        )
    ),
    is_active TINYINT(1) DEFAULT 1,
    date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (restaurant_id) REFERENCES restaurant (restaurant_id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES restaurant_branch (restaurant_branch_id) ON DELETE SET NULL,
    INDEX idx_restaurant (restaurant_id),
    INDEX idx_branch (branch_id),
    INDEX idx_role (role),
    INDEX idx_email (email)
) COMMENT = 'Restaurant logins';

-- 14. DIETARY_INFORMATION
CREATE TABLE dietary_information (
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
    serving_size VARCHAR(50) NULL,
    serving_unit VARCHAR(20) NULL,
    INDEX idx_category (category)
) COMMENT = 'Nutritional information';

-- 15. PRODUCT
CREATE TABLE product (
    product_id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_branch_id INT NOT NULL,
    dietary_information_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    price DECIMAL(8, 2) NOT NULL CHECK (price >= 0),
    stock INT NOT NULL DEFAULT 0 CHECK (stock >= 0),
    is_customizable TINYINT(1) DEFAULT 0,
    customization_type VARCHAR(20) NULL CHECK (
        customization_type IN (
            'structured',
            'freeform',
            'mixed',
            'none'
        )
    ),
    base_price DECIMAL(8, 2) DEFAULT 0.00 CHECK (base_price >= 0),
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (restaurant_branch_id) REFERENCES restaurant_branch (restaurant_branch_id) ON DELETE CASCADE,
    FOREIGN KEY (dietary_information_id) REFERENCES dietary_information (dietary_information_id) ON DELETE CASCADE,
    INDEX idx_restaurant_branch_id (restaurant_branch_id),
    INDEX idx_is_active (is_active),
    INDEX idx_customizable (is_customizable)
) COMMENT = 'Product listings';

-- 16. INGREDIENT
CREATE TABLE ingredient (
    ingredient_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    unit_price DECIMAL(8, 2) DEFAULT 0.00 CHECK (unit_price >= 0),
    calories INT NULL CHECK (calories >= 0),
    protein DECIMAL(5, 2) NULL CHECK (protein >= 0),
    carbs DECIMAL(5, 2) NULL CHECK (carbs >= 0),
    fat DECIMAL(5, 2) NULL CHECK (fat >= 0),
    dietary_tags JSON NULL,
    allergens JSON NULL,
    stock_quantity INT DEFAULT 0 CHECK (stock_quantity >= 0),
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name),
    INDEX idx_is_active (is_active)
) COMMENT = 'Master list of ingredients';

-- 17. PRODUCT_COMPOSITION
CREATE TABLE product_composition (
    composition_id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    ingredient_id INT NOT NULL,
    is_default TINYINT(1) DEFAULT 0,
    default_quantity INT DEFAULT 0 CHECK (default_quantity >= 0),
    min_quantity INT DEFAULT 0 CHECK (min_quantity >= 0),
    max_quantity INT DEFAULT 1 CHECK (max_quantity >= 0),
    price_modifier DECIMAL(8, 2) DEFAULT 0.00,
    display_order INT DEFAULT 0,
    is_required TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES product (product_id) ON DELETE CASCADE,
    FOREIGN KEY (ingredient_id) REFERENCES ingredient (ingredient_id) ON DELETE CASCADE,
    INDEX idx_product (product_id),
    INDEX idx_ingredient (ingredient_id),
    INDEX idx_default (is_default),
    UNIQUE KEY unique_product_ingredient (product_id, ingredient_id),
    CONSTRAINT chk_composition_bounds CHECK (
        min_quantity <= max_quantity
        AND default_quantity BETWEEN min_quantity AND max_quantity
    )
) COMMENT = 'Product composition';

-- 18. CART
CREATE TABLE cart (
    cart_id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1 CHECK (quantity > 0),
    price DECIMAL(8, 2) NOT NULL CHECK (price >= 0),
    customization_data JSON NULL,
    customization_hash VARCHAR(64) GENERATED ALWAYS AS (
        SHA2(
            COALESCE(
                JSON_EXTRACT(
                    customization_data,
                    '$.customizations'
                ),
                ''
            ),
            256
        )
    ) VIRTUAL,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customer (customer_id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES product (product_id) ON DELETE CASCADE,
    INDEX idx_customer_id (customer_id),
    INDEX idx_product_id (product_id),
    UNIQUE KEY unique_cart_item (
        customer_id,
        product_id,
        customization_hash
    )
) COMMENT = 'Shopping cart';

-- 19. ORDERS
CREATE TABLE orders (
    order_id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    delivery_rider_id INT NULL,
    destination_address VARCHAR(250) NOT NULL,
    order_status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (
        order_status IN (
            'pending',
            'preparing',
            'rider_pending',
            'picking_up',
            'delivering',
            'delivered',
            'failed',
            'cancelled',
            'refunded'
        )
    ),
    payment_method VARCHAR(20) NOT NULL DEFAULT 'COD' CHECK (
        payment_method IN ('COD', 'Wallet', 'Online')
    ),
    cancelled_by VARCHAR(20) NULL CHECK (
        cancelled_by IN (
            'customer',
            'restaurant',
            'rider',
            'admin'
        )
    ),
    rider_liability_amount DECIMAL(10, 2) NULL CHECK (
        rider_liability_amount IS NULL
        OR rider_liability_amount >= 0
    ),
    order_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    delivered_at TIMESTAMP NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customer (customer_id) ON DELETE CASCADE,
    FOREIGN KEY (delivery_rider_id) REFERENCES delivery_rider (delivery_rider_id) ON DELETE SET NULL,
    INDEX idx_customer_id (customer_id),
    INDEX idx_delivery_rider_id (delivery_rider_id),
    INDEX idx_order_status (order_status),
    INDEX idx_order_date (order_date),
    CONSTRAINT chk_cancelled_by_biconditional CHECK (
        (
            order_status IN ('cancelled', 'refunded')
            AND cancelled_by IS NOT NULL
        )
        OR (
            order_status NOT IN('cancelled', 'refunded')
            AND cancelled_by IS NULL
        )
    ),
    CONSTRAINT chk_delivered_at_biconditional CHECK (
        (
            order_status = 'delivered'
            AND delivered_at IS NOT NULL
        )
        OR (
            order_status <> 'delivered'
            AND delivered_at IS NULL
        )
    )
) COMMENT = 'Order transactions';

-- 20. QUEUE_ITEM
CREATE TABLE queue_item (
    queue_item_id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    branch_id INT NOT NULL,
    product_id INT NOT NULL,
    queue_quantity INT NOT NULL CHECK (queue_quantity > 0),
    unit_price DECIMAL(8, 2) NOT NULL CHECK (unit_price >= 0),
    total_price DECIMAL(10, 2) GENERATED ALWAYS AS (queue_quantity * unit_price) STORED,
    is_customized TINYINT(1) DEFAULT 0,
    base_price_snapshot DECIMAL(8, 2) NULL CHECK (base_price_snapshot >= 0),
    final_price DECIMAL(10, 2) NULL CHECK (final_price >= 0),
    custom_instructions TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders (order_id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES restaurant_branch (restaurant_branch_id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES product (product_id) ON DELETE CASCADE,
    INDEX idx_order_id (order_id),
    INDEX idx_branch_id (branch_id),
    INDEX idx_product_id (product_id),
    INDEX idx_customized (is_customized)
) COMMENT = 'Kitchen queue items';

-- 21. CUSTOMIZATION_INSTANCE
CREATE TABLE customization_instance (
    instance_id INT AUTO_INCREMENT PRIMARY KEY,
    queue_item_id INT NOT NULL,
    ingredient_id INT NOT NULL,
    quantity INT NOT NULL CHECK (quantity >= 0),
    price_at_time DECIMAL(8, 2) NOT NULL CHECK (price_at_time >= 0),
    calories_at_time INT NULL CHECK (calories_at_time >= 0),
    is_removed TINYINT(1) DEFAULT 0,
    custom_text TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (queue_item_id) REFERENCES queue_item (queue_item_id) ON DELETE CASCADE,
    FOREIGN KEY (ingredient_id) REFERENCES ingredient (ingredient_id) ON DELETE CASCADE,
    INDEX idx_queue_item (queue_item_id),
    INDEX idx_ingredient (ingredient_id),
    UNIQUE KEY unique_queue_ingredient (queue_item_id, ingredient_id)
) COMMENT = 'Customer customizations';

-- 22. TRANSACTION
CREATE TABLE transaction (
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

-- 22b. RIDER_COLLECTION
CREATE TABLE rider_collection (
    rider_collection_id INT AUTO_INCREMENT PRIMARY KEY,
    delivery_rider_id INT NOT NULL,
    order_id INT NOT NULL,
    amount DECIMAL(10, 2) NOT NULL CHECK (amount >= 0),
    status VARCHAR(20) NOT NULL DEFAULT 'collected' CHECK (
        status IN (
            'collected',
            'settled',
            'void'
        )
    ),
    collected_at TIMESTAMP NULL,
    settled_at TIMESTAMP NULL,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (delivery_rider_id) REFERENCES delivery_rider (delivery_rider_id) ON DELETE RESTRICT,
    FOREIGN KEY (order_id) REFERENCES orders (order_id) ON DELETE CASCADE,
    UNIQUE KEY unique_rider_order (delivery_rider_id, order_id),
    INDEX idx_rider (delivery_rider_id),
    INDEX idx_order (order_id),
    INDEX idx_status (status),
    INDEX idx_collected (collected_at)
) COMMENT = 'Cash a rider physically collected';

-- 23. FEEDBACK
CREATE TABLE feedback (
    feedback_id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    feedback_from_type VARCHAR(30) NOT NULL CHECK (
        feedback_from_type IN (
            'customer',
            'restaurant_account'
        )
    ),
    feedback_from_id INT NOT NULL,
    feedback_content TEXT NULL,
    date_posted TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders (order_id) ON DELETE CASCADE,
    UNIQUE KEY unique_feedback_per_author (
        order_id,
        feedback_from_type,
        feedback_from_id
    ),
    INDEX idx_order (order_id),
    INDEX idx_from (
        feedback_from_type,
        feedback_from_id
    ),
    INDEX idx_posted (date_posted)
) COMMENT = 'Per-order review envelope';

-- 23b. RATING
--
-- subject_key is a STORED generated column in this revision.
-- It was VIRTUAL in v2.6.1 because MySQL 8.0 rejects a STORED
-- generated column whose expression reads a foreign-key source
-- column with an explicit ON DELETE action. The rating.rider_id
-- FK carried ON DELETE RESTRICT in v2.6.1, which triggered that
-- restriction. This revision drops the explicit ON DELETE on
-- rating.rider_id. The default RESTRICT semantics are unchanged
-- — a rider with existing ratings still cannot be deleted — but
-- the STORED generated column is now accepted. STORED is
-- required because UNIQUE KEY unique_rating_per_subject
-- indexes subject_key and MySQL 8.0.0 through 8.0.12 reject a
-- UNIQUE index on a VIRTUAL generated column.
--
-- No application code reads rating.subject_key. It exists only
-- to serve the UNIQUE KEY, so STORED vs VIRTUAL is invisible to
-- getProductReviews(), the four rating triggers, and
-- feedback-handler.php.
CREATE TABLE rating (
    rating_id INT AUTO_INCREMENT PRIMARY KEY,
    feedback_id INT NOT NULL,
    rating_type VARCHAR(20) NOT NULL,
    queue_item_id INT NULL,
    branch_id INT NULL,
    rider_id INT NULL,
    score TINYINT NOT NULL CHECK (score BETWEEN 1 AND 5),
    subject_key VARCHAR(64) GENERATED ALWAYS AS (
        CASE rating_type
            WHEN 'product' THEN CONCAT('product:', queue_item_id)
            WHEN 'restaurant' THEN CONCAT('restaurant:', branch_id)
            WHEN 'rider' THEN CONCAT('rider:', rider_id)
        END
    ) VIRTUAL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (feedback_id) REFERENCES feedback (feedback_id) ON DELETE CASCADE,
    FOREIGN KEY (queue_item_id) REFERENCES queue_item (queue_item_id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES restaurant_branch (restaurant_branch_id) ON DELETE CASCADE,
    FOREIGN KEY (rider_id) REFERENCES delivery_rider (delivery_rider_id),
    UNIQUE KEY unique_rating_per_subject (feedback_id, subject_key),
    INDEX idx_feedback (feedback_id),
    INDEX idx_product_rating (rating_type, queue_item_id),
    INDEX idx_branch_rating (rating_type, branch_id),
    INDEX idx_rider_rating (rating_type, rider_id),
    CONSTRAINT chk_rating_subject_matches_type CHECK (
        (
            rating_type = 'product'
            AND queue_item_id IS NOT NULL
            AND branch_id IS NULL
            AND rider_id IS NULL
        )
        OR (
            rating_type = 'restaurant'
            AND branch_id IS NOT NULL
            AND queue_item_id IS NULL
            AND rider_id IS NULL
        )
        OR (
            rating_type = 'rider'
            AND rider_id IS NOT NULL
            AND queue_item_id IS NULL
            AND branch_id IS NULL
        )
    )
) COMMENT = 'Numeric score per subject';

-- 24. NOTIFICATION
CREATE TABLE notification (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    recipient_type VARCHAR(20) NOT NULL CHECK (
        recipient_type IN (
            'customer',
            'delivery_rider',
            'administrator',
            'restaurant_account'
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

-- 25. MESSAGE
CREATE TABLE message (
    message_id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    sender_type VARCHAR(20) NOT NULL CHECK (
        sender_type IN (
            'customer',
            'restaurant_account',
            'delivery_rider',
            'administrator',
            'system'
        )
    ),
    sender_id INT NOT NULL,
    recipient_type VARCHAR(20) NOT NULL CHECK (
        recipient_type IN (
            'customer',
            'restaurant_account',
            'delivery_rider',
            'administrator',
            'all'
        )
    ),
    recipient_id INT NULL,
    message_type VARCHAR(20) DEFAULT 'text' CHECK (
        message_type IN (
            'text',
            'image',
            'system_notification',
            'instruction'
        )
    ),
    content TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders (order_id) ON DELETE CASCADE,
    INDEX idx_order_id (order_id),
    INDEX idx_sender (sender_type, sender_id),
    INDEX idx_recipient (recipient_type, recipient_id),
    INDEX idx_created_at (created_at),
    INDEX idx_unread (is_read)
) COMMENT = 'All communication between parties';

-- ADDITIONAL INDEXES
CREATE INDEX idx_orders_customer_status ON orders (customer_id, order_status);

CREATE INDEX idx_orders_rider_status ON orders (
    delivery_rider_id,
    order_status
);

CREATE INDEX idx_orders_status_date ON orders (order_status, order_date);

CREATE INDEX idx_orders_rider_liability ON orders (
    delivery_rider_id,
    rider_liability_amount
);

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

CREATE INDEX idx_customization_instance_queue ON customization_instance (queue_item_id, ingredient_id);

-- =====================================================
-- TRIGGERS
-- =====================================================
DELIMITER $$

CREATE TRIGGER before_queue_item_insert
BEFORE INSERT ON queue_item
FOR EACH ROW
BEGIN
    DECLARE current_stock INT;
    DECLARE product_branch INT;

    SELECT stock, restaurant_branch_id INTO current_stock, product_branch
    FROM product WHERE product_id = NEW.product_id FOR UPDATE;

    IF current_stock IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Product not found';
    END IF;

    IF product_branch <> NEW.branch_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Product does not belong to specified branch';
    END IF;

    IF NEW.queue_quantity > current_stock THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Insufficient stock available';
    END IF;
END$$

CREATE TRIGGER after_queue_item_insert
AFTER INSERT ON queue_item
FOR EACH ROW
BEGIN
    UPDATE product SET stock = stock - NEW.queue_quantity WHERE product_id = NEW.product_id;
END$$

CREATE TRIGGER after_order_stock_restore
AFTER UPDATE ON orders
FOR EACH ROW
BEGIN
    IF NEW.order_status IN ('cancelled','refunded')
        AND OLD.order_status IN ('pending','preparing','rider_pending','picking_up','delivering')
        AND OLD.order_status <> NEW.order_status
    THEN
        UPDATE product p JOIN queue_item qi ON p.product_id = qi.product_id
        SET p.stock = p.stock + qi.queue_quantity
        WHERE qi.order_id = NEW.order_id;
    END IF;
END$$

CREATE TRIGGER before_order_delivered
BEFORE UPDATE ON orders
FOR EACH ROW
BEGIN
    IF NEW.order_status = 'delivered' AND OLD.order_status <> 'delivered' THEN
        SET NEW.delivered_at = CURRENT_TIMESTAMP;
    END IF;
END$$

CREATE TRIGGER after_order_delivered
AFTER UPDATE ON orders
FOR EACH ROW
BEGIN
    IF NEW.order_status = 'delivered'
        AND OLD.order_status <> 'delivered'
        AND NEW.delivery_rider_id IS NOT NULL
    THEN
        UPDATE delivery_rider_profile
        SET total_deliveries = total_deliveries + 1
        WHERE delivery_rider_id = NEW.delivery_rider_id;
    END IF;
END$$

CREATE TRIGGER before_order_status_transition
BEFORE UPDATE ON orders
FOR EACH ROW
BEGIN
    IF OLD.order_status IN ('delivered','cancelled','refunded')
        AND NEW.order_status NOT IN ('delivered','cancelled','refunded')
    THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Terminal order status cannot be reversed';
    END IF;

    IF OLD.order_status = 'refunded' AND NEW.order_status <> 'refunded' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Refunded order status is terminal';
    END IF;
END$$

CREATE TRIGGER before_transaction_insert
BEFORE INSERT ON transaction
FOR EACH ROW
BEGIN
    DECLARE current_balance DECIMAL(10,2);
    DECLARE is_rider_liability TINYINT DEFAULT 0;

    SELECT balance INTO current_balance
    FROM financial_account WHERE financial_account_id = NEW.financial_account_id FOR UPDATE;

    IF current_balance IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Financial account not found';
    END IF;

    IF NEW.description IS NOT NULL AND NEW.description LIKE 'Rider liability for order #%' THEN
        SET is_rider_liability = 1;
    END IF;

    IF NEW.status = 'completed'
        AND NEW.transaction_type IN ('payment','withdrawal')
        AND NEW.amount > current_balance
        AND is_rider_liability = 0
    THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Insufficient balance';
    END IF;
END$$

CREATE TRIGGER before_transaction_update
BEFORE UPDATE ON transaction
FOR EACH ROW
BEGIN
    DECLARE current_balance DECIMAL(10,2);
    DECLARE is_rider_liability TINYINT DEFAULT 0;

    IF OLD.financial_account_id <> NEW.financial_account_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot move a transaction between financial accounts';
    END IF;

    SELECT balance INTO current_balance
    FROM financial_account WHERE financial_account_id = NEW.financial_account_id FOR UPDATE;

    IF current_balance IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Financial account not found';
    END IF;

    IF NEW.description IS NOT NULL AND NEW.description LIKE 'Rider liability for order #%' THEN
        SET is_rider_liability = 1;
    END IF;

    IF NEW.status = 'completed'
        AND OLD.status <> 'completed'
        AND NEW.transaction_type IN ('payment','withdrawal')
        AND NEW.amount > current_balance
        AND is_rider_liability = 0
    THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Insufficient balance';
    END IF;
END$$

CREATE TRIGGER after_transaction_insert
AFTER INSERT ON transaction
FOR EACH ROW
BEGIN
    IF NEW.status = 'completed' THEN
        IF NEW.transaction_type IN ('deposit','refund') THEN
            UPDATE financial_account SET balance = balance + NEW.amount WHERE financial_account_id = NEW.financial_account_id;
        ELSEIF NEW.transaction_type IN ('payment','withdrawal') THEN
            UPDATE financial_account SET balance = balance - NEW.amount WHERE financial_account_id = NEW.financial_account_id;
        END IF;
    END IF;
END$$

CREATE TRIGGER after_transaction_update_status
AFTER UPDATE ON transaction
FOR EACH ROW
BEGIN
    IF NEW.status = 'completed' AND OLD.status <> 'completed' THEN
        IF NEW.transaction_type IN ('deposit','refund') THEN
            UPDATE financial_account SET balance = balance + NEW.amount WHERE financial_account_id = NEW.financial_account_id;
        ELSEIF NEW.transaction_type IN ('payment','withdrawal') THEN
            UPDATE financial_account SET balance = balance - NEW.amount WHERE financial_account_id = NEW.financial_account_id;
        END IF;
    END IF;

    IF OLD.status = 'completed' AND NEW.status <> 'completed' THEN
        IF NEW.transaction_type IN ('deposit','refund') THEN
            UPDATE financial_account SET balance = balance - NEW.amount WHERE financial_account_id = NEW.financial_account_id;
        ELSEIF NEW.transaction_type IN ('payment','withdrawal') THEN
            UPDATE financial_account SET balance = balance + NEW.amount WHERE financial_account_id = NEW.financial_account_id;
        END IF;
    END IF;
END$$

CREATE TRIGGER before_order_rider_assign
BEFORE UPDATE ON orders
FOR EACH ROW
BEGIN
    DECLARE active_orders INT;
    DECLARE rider_profile_id INT;

    IF NEW.delivery_rider_id IS NOT NULL
        AND NEW.order_status IN ('rider_pending','picking_up','delivering')
        AND (OLD.delivery_rider_id IS NULL OR OLD.delivery_rider_id <> NEW.delivery_rider_id)
    THEN
        SELECT delivery_rider_profile_id INTO rider_profile_id
        FROM delivery_rider_profile WHERE delivery_rider_id = NEW.delivery_rider_id FOR UPDATE;

        IF rider_profile_id IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Rider profile not found';
        END IF;

        SELECT COUNT(*) INTO active_orders
        FROM orders
        WHERE delivery_rider_id = NEW.delivery_rider_id
            AND order_status IN ('rider_pending','picking_up','delivering')
            AND order_id <> NEW.order_id;

        IF active_orders >= 3 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Rider already has the maximum of 3 active orders';
        END IF;
    END IF;
END$$

CREATE TRIGGER after_customization_instance_insert
AFTER INSERT ON customization_instance
FOR EACH ROW
BEGIN
    DECLARE total_customization_price DECIMAL(10,2);

    SELECT COALESCE(SUM(price_at_time * quantity), 0) INTO total_customization_price
    FROM customization_instance WHERE queue_item_id = NEW.queue_item_id AND is_removed = 0;

    UPDATE queue_item SET final_price = base_price_snapshot + total_customization_price WHERE queue_item_id = NEW.queue_item_id;
END$$

CREATE TRIGGER after_customization_instance_update
AFTER UPDATE ON customization_instance
FOR EACH ROW
BEGIN
    DECLARE total_customization_price DECIMAL(10,2);

    SELECT COALESCE(SUM(price_at_time * quantity), 0) INTO total_customization_price
    FROM customization_instance WHERE queue_item_id = NEW.queue_item_id AND is_removed = 0;

    UPDATE queue_item SET final_price = base_price_snapshot + total_customization_price WHERE queue_item_id = NEW.queue_item_id;
END$$

CREATE TRIGGER after_customization_instance_delete
AFTER DELETE ON customization_instance
FOR EACH ROW
BEGIN
    DECLARE total_customization_price DECIMAL(10,2);

    SELECT COALESCE(SUM(price_at_time * quantity), 0) INTO total_customization_price
    FROM customization_instance WHERE queue_item_id = OLD.queue_item_id AND is_removed = 0;

    UPDATE queue_item SET final_price = base_price_snapshot + total_customization_price WHERE queue_item_id = OLD.queue_item_id;
END$$

CREATE TRIGGER before_customization_instance_insert
BEFORE INSERT ON customization_instance
FOR EACH ROW
BEGIN
    DECLARE max_qty INT;

    SELECT pc.max_quantity INTO max_qty
    FROM product_composition pc
    JOIN queue_item qi ON pc.product_id = qi.product_id
    WHERE qi.queue_item_id = NEW.queue_item_id AND pc.ingredient_id = NEW.ingredient_id;

    IF max_qty IS NOT NULL AND NEW.quantity > max_qty THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Quantity exceeds maximum allowed';
    END IF;
END$$

CREATE TRIGGER before_rating_insert
BEFORE INSERT ON rating
FOR EACH ROW
BEGIN
    DECLARE subject_exists INT DEFAULT 0;

    IF NEW.rating_type = 'product' THEN
        SELECT COUNT(*) INTO subject_exists FROM queue_item WHERE queue_item_id = NEW.queue_item_id;
    ELSEIF NEW.rating_type = 'restaurant' THEN
        SELECT COUNT(*) INTO subject_exists FROM restaurant_branch WHERE restaurant_branch_id = NEW.branch_id;
    ELSEIF NEW.rating_type = 'rider' THEN
        SELECT COUNT(*) INTO subject_exists FROM delivery_rider WHERE delivery_rider_id = NEW.rider_id;
    END IF;

    IF subject_exists = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Rating subject does not exist';
    END IF;
END$$

CREATE TRIGGER before_rating_update
BEFORE UPDATE ON rating
FOR EACH ROW
BEGIN
    DECLARE subject_exists INT DEFAULT 0;

    IF NEW.rating_type = 'product' THEN
        SELECT COUNT(*) INTO subject_exists FROM queue_item WHERE queue_item_id = NEW.queue_item_id;
    ELSEIF NEW.rating_type = 'restaurant' THEN
        SELECT COUNT(*) INTO subject_exists FROM restaurant_branch WHERE restaurant_branch_id = NEW.branch_id;
    ELSEIF NEW.rating_type = 'rider' THEN
        SELECT COUNT(*) INTO subject_exists FROM delivery_rider WHERE delivery_rider_id = NEW.rider_id;
    END IF;

    IF subject_exists = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Rating subject does not exist';
    END IF;
END$$

CREATE TRIGGER after_rating_insert_rider_avg
AFTER INSERT ON rating
FOR EACH ROW
BEGIN
    IF NEW.rating_type = 'rider' AND NEW.rider_id IS NOT NULL THEN
        UPDATE delivery_rider_profile
        SET average_rating = COALESCE(
            (SELECT ROUND(AVG(score), 1) FROM rating WHERE rating_type = 'rider' AND rider_id = NEW.rider_id),
            0.0
        )
        WHERE delivery_rider_id = NEW.rider_id;
    END IF;
END$$

CREATE TRIGGER after_rating_update_rider_avg
AFTER UPDATE ON rating
FOR EACH ROW
BEGIN
    IF NEW.rating_type = 'rider' AND NEW.rider_id IS NOT NULL THEN
        UPDATE delivery_rider_profile
        SET average_rating = COALESCE(
            (SELECT ROUND(AVG(score), 1) FROM rating WHERE rating_type = 'rider' AND rider_id = NEW.rider_id),
            0.0
        )
        WHERE delivery_rider_id = NEW.rider_id;
    END IF;

    IF OLD.rating_type = 'rider' AND OLD.rider_id IS NOT NULL
        AND (NEW.rider_id IS NULL OR NEW.rider_id <> OLD.rider_id OR NEW.rating_type <> 'rider')
    THEN
        UPDATE delivery_rider_profile
        SET average_rating = COALESCE(
            (SELECT ROUND(AVG(score), 1) FROM rating WHERE rating_type = 'rider' AND rider_id = OLD.rider_id),
            0.0
        )
        WHERE delivery_rider_id = OLD.rider_id;
    END IF;
END$$

CREATE TRIGGER after_rating_delete_rider_avg
AFTER DELETE ON rating
FOR EACH ROW
BEGIN
    IF OLD.rating_type = 'rider' AND OLD.rider_id IS NOT NULL THEN
        UPDATE delivery_rider_profile
        SET average_rating = COALESCE(
            (SELECT ROUND(AVG(score), 1) FROM rating WHERE rating_type = 'rider' AND rider_id = OLD.rider_id),
            0.0
        )
        WHERE delivery_rider_id = OLD.rider_id;
    END IF;
END$$

DELIMITER;

-- =====================================================
-- STORED PROCEDURES
-- =====================================================
DELIMITER $$

CREATE PROCEDURE sp_add_customization(
IN  p_queue_item_id INT,
IN  p_ingredient_id INT,
IN  p_quantity INT,
IN  p_price DECIMAL(8,2),
IN  p_calories INT,
OUT p_success BOOLEAN
)
BEGIN
DECLARE EXIT HANDLER FOR SQLEXCEPTION
BEGIN
    ROLLBACK TO SAVEPOINT sp_add_customization;
    SET p_success = FALSE;
    RESIGNAL;
END;

SAVEPOINT sp_add_customization;

INSERT INTO customization_instance (
    queue_item_id, ingredient_id, quantity, price_at_time, calories_at_time
) VALUES (
    p_queue_item_id, p_ingredient_id, p_quantity, p_price, p_calories
);

UPDATE queue_item SET is_customized = 1 WHERE queue_item_id = p_queue_item_id;

SET p_success = TRUE;
RELEASE SAVEPOINT sp_add_customization;
END$$

CREATE PROCEDURE sp_cancel_order(
IN  p_order_id INT,
IN  p_cancelled_by VARCHAR(20),
OUT p_success BOOLEAN
)
BEGIN
DECLARE v_rows INT DEFAULT 0;

DECLARE EXIT HANDLER FOR SQLEXCEPTION
BEGIN
    ROLLBACK TO SAVEPOINT sp_cancel_order;
    SET p_success = FALSE;
    RESIGNAL;
END;

SAVEPOINT sp_cancel_order;

UPDATE orders SET order_status = 'cancelled', cancelled_by = p_cancelled_by
WHERE order_id = p_order_id AND order_status IN ('pending','preparing');

SET v_rows = ROW_COUNT();

IF v_rows = 0 THEN
    SET p_success = FALSE;
    RELEASE SAVEPOINT sp_cancel_order;
ELSE
    SET p_success = TRUE;
    RELEASE SAVEPOINT sp_cancel_order;
END IF;
END$$

CREATE PROCEDURE sp_process_refund(
IN  p_order_id INT,
IN  p_amount DECIMAL(10,2),
IN  p_description VARCHAR(255),
OUT p_transaction_id INT
)
BEGIN
DECLARE v_financial_account_id INT;
DECLARE v_order_status VARCHAR(20);
DECLARE v_existing_refund INT DEFAULT 0;

DECLARE EXIT HANDLER FOR SQLEXCEPTION
BEGIN
    ROLLBACK TO SAVEPOINT sp_process_refund;
    RESIGNAL;
END;

SAVEPOINT sp_process_refund;

SELECT order_status INTO v_order_status FROM orders WHERE order_id = p_order_id FOR UPDATE;

IF v_order_status IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order not found';
END IF;

IF v_order_status = 'refunded' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order already refunded';
END IF;

IF v_order_status NOT IN ('cancelled','delivered') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order must be cancelled or delivered to refund';
END IF;

SELECT COUNT(*) INTO v_existing_refund FROM transaction WHERE order_id = p_order_id AND transaction_type = 'refund' AND status = 'completed';

IF v_existing_refund > 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order already refunded';
END IF;

SELECT cp.financial_account_id INTO v_financial_account_id
FROM orders o
JOIN customer c ON o.customer_id = c.customer_id
JOIN customer_profile cp ON c.customer_id = cp.customer_id
WHERE o.order_id = p_order_id;

INSERT INTO transaction (financial_account_id, order_id, amount, transaction_type, status, description)
VALUES (v_financial_account_id, p_order_id, p_amount, 'refund', 'completed', p_description);

SET p_transaction_id = LAST_INSERT_ID();

UPDATE orders SET order_status = 'refunded' WHERE order_id = p_order_id;

RELEASE SAVEPOINT sp_process_refund;
END$$

CREATE PROCEDURE sp_process_payment(
IN  p_order_id INT,
IN  p_amount DECIMAL(10,2),
IN  p_payment_method VARCHAR(20),
OUT p_transaction_id INT
)
BEGIN
DECLARE v_financial_account_id INT;
DECLARE v_existing_payment INT DEFAULT 0;
DECLARE v_order_exists INT DEFAULT 0;

DECLARE EXIT HANDLER FOR SQLEXCEPTION
BEGIN
    ROLLBACK TO SAVEPOINT sp_process_payment;
    RESIGNAL;
END;

SET p_transaction_id = NULL;

SAVEPOINT sp_process_payment;

SELECT COUNT(*) INTO v_order_exists FROM orders WHERE order_id = p_order_id FOR UPDATE;

IF v_order_exists = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order not found';
END IF;

SELECT COUNT(*) INTO v_existing_payment FROM transaction WHERE order_id = p_order_id AND transaction_type = 'payment' AND status = 'completed';

IF v_existing_payment > 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order already paid';
END IF;

SELECT cp.financial_account_id INTO v_financial_account_id
FROM orders o
JOIN customer c ON o.customer_id = c.customer_id
JOIN customer_profile cp ON c.customer_id = cp.customer_id
WHERE o.order_id = p_order_id;

IF p_payment_method = 'Wallet' THEN
    INSERT INTO transaction (financial_account_id, order_id, amount, transaction_type, status, description)
    VALUES (v_financial_account_id, p_order_id, p_amount, 'payment', 'completed', CONCAT('Payment for order #', p_order_id));
    SET p_transaction_id = LAST_INSERT_ID();
ELSE
    INSERT INTO transaction (financial_account_id, order_id, amount, transaction_type, status, description)
    VALUES (v_financial_account_id, p_order_id, p_amount, 'payment', 'pending', CONCAT('Pending ', p_payment_method, ' payment for order #', p_order_id));
    SET p_transaction_id = LAST_INSERT_ID();
END IF;

RELEASE SAVEPOINT sp_process_payment;
END$$

CREATE PROCEDURE sp_post_feedback(
IN  p_order_id INT,
IN  p_feedback_from_type VARCHAR(30),
IN  p_feedback_from_id INT,
IN  p_feedback_content TEXT,
IN  p_rating_count INT,
OUT p_feedback_id INT
)
BEGIN
DECLARE v_rating_count INT DEFAULT 0;
DECLARE v_has_content TINYINT DEFAULT 0;

DECLARE EXIT HANDLER FOR SQLEXCEPTION
BEGIN
    ROLLBACK TO SAVEPOINT sp_post_feedback;
    RESIGNAL;
END;

SAVEPOINT sp_post_feedback;

IF p_feedback_from_type NOT IN ('customer', 'restaurant_account') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid feedback_from_type';
END IF;

SET v_has_content = (p_feedback_content IS NOT NULL AND CHAR_LENGTH(TRIM(p_feedback_content)) > 0);

IF NOT v_has_content AND p_rating_count = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Feedback must carry content or at least one rating';
END IF;

INSERT INTO feedback (order_id, feedback_from_type, feedback_from_id, feedback_content)
VALUES (p_order_id, p_feedback_from_type, p_feedback_from_id, NULLIF(TRIM(p_feedback_content), ''));

SET p_feedback_id = LAST_INSERT_ID();

RELEASE SAVEPOINT sp_post_feedback;
END$$

CREATE PROCEDURE sp_add_rating(
IN  p_feedback_id INT,
IN  p_rating_type VARCHAR(20),
IN  p_queue_item_id INT,
IN  p_branch_id INT,
IN  p_rider_id INT,
IN  p_score TINYINT,
OUT p_rating_id INT
)
BEGIN
DECLARE EXIT HANDLER FOR SQLEXCEPTION
BEGIN
    ROLLBACK TO SAVEPOINT sp_add_rating;
    RESIGNAL;
END;

SAVEPOINT sp_add_rating;

INSERT INTO rating (feedback_id, rating_type, queue_item_id, branch_id, rider_id, score)
VALUES (p_feedback_id, p_rating_type, p_queue_item_id, p_branch_id, p_rider_id, p_score);

SET p_rating_id = LAST_INSERT_ID();
RELEASE SAVEPOINT sp_add_rating;
END$$

CREATE PROCEDURE sp_get_product_customizations(IN p_product_id INT)
BEGIN
SELECT
    i.ingredient_id, i.name AS ingredient_name, i.unit_price, i.calories, i.dietary_tags, i.allergens,
    pc.is_default, pc.default_quantity, pc.min_quantity, pc.max_quantity, pc.price_modifier, pc.display_order, pc.is_required
FROM ingredient i
JOIN product_composition pc ON i.ingredient_id = pc.ingredient_id
WHERE pc.product_id = p_product_id AND i.is_active = 1
ORDER BY pc.display_order ASC, i.name ASC;
END$$

CREATE PROCEDURE sp_get_order_customizations(IN p_queue_item_id INT)
BEGIN
SELECT
    ci.instance_id, ci.ingredient_id, i.name AS ingredient_name, ci.quantity, ci.price_at_time, ci.calories_at_time, ci.is_removed, ci.custom_text, ci.created_at
FROM customization_instance ci
JOIN ingredient i ON ci.ingredient_id = i.ingredient_id
WHERE ci.queue_item_id = p_queue_item_id
ORDER BY ci.created_at ASC;
END$$

CREATE PROCEDURE sp_get_rider_kyc_summary(IN p_delivery_rider_id INT)
BEGIN
SELECT
    dr.delivery_rider_id, dr.first_name, dr.middle_name, dr.last_name, dr.contact_number, dr.email,
    drp.profile_picture, drp.vehicle_type, drp.vehicle_plate, drp.verification_status, drp.verified_at, drp.average_rating, drp.total_deliveries, drp.is_available,
    drd.id_type, drd.id_path, drd.issue_date AS id_issue_date, drd.expiry_date AS id_expiry_date,
    (SELECT COUNT(*) FROM delivery_rider_emergency_contact ec WHERE ec.delivery_rider_id = dr.delivery_rider_id) AS emergency_contact_count
FROM delivery_rider dr
JOIN delivery_rider_profile drp ON dr.delivery_rider_id = drp.delivery_rider_id
LEFT JOIN delivery_rider_document drd ON dr.delivery_rider_id = drd.delivery_rider_id
WHERE dr.delivery_rider_id = p_delivery_rider_id;
END$$

DELIMITER;

-- =====================================================
-- VIEWS
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
    o.cancelled_by,
    o.rider_liability_amount,
    o.order_date,
    o.delivered_at,
    COALESCE(it.subtotal, 0) AS subtotal,
    COALESCE(it.subtotal, 0) AS total_amount,
    qi.queue_item_id,
    qi.queue_quantity,
    qi.unit_price,
    qi.total_price AS item_total,
    qi.is_customized,
    qi.final_price AS item_final_price,
    p.name AS product_name,
    p.product_id,
    rb.branch_name,
    r.business_name AS restaurant_name,
    qi.custom_instructions
FROM
    orders o
    JOIN customer c ON o.customer_id = c.customer_id
    LEFT JOIN delivery_rider dr ON o.delivery_rider_id = dr.delivery_rider_id
    LEFT JOIN queue_item qi ON o.order_id = qi.order_id
    LEFT JOIN product p ON qi.product_id = p.product_id
    LEFT JOIN restaurant_branch rb ON qi.branch_id = rb.restaurant_branch_id
    LEFT JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
    LEFT JOIN (
        SELECT order_id, SUM(
                queue_quantity * COALESCE(final_price, unit_price)
            ) AS subtotal
        FROM queue_item
        GROUP BY
            order_id
    ) it ON it.order_id = o.order_id;

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
    qi.is_customized,
    qi.final_price,
    qi.custom_instructions,
    di.allergens,
    di.dietary_tags,
    o.order_status,
    GROUP_CONCAT(
        CONCAT(
            i.name,
            ' (x',
            ci.quantity,
            ')'
        )
        ORDER BY ci.created_at SEPARATOR ', '
    ) AS customizations
FROM
    queue_item qi
    JOIN orders o ON qi.order_id = o.order_id
    JOIN product p ON qi.product_id = p.product_id
    JOIN restaurant_branch rb ON qi.branch_id = rb.restaurant_branch_id
    LEFT JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
    LEFT JOIN customization_instance ci ON qi.queue_item_id = ci.queue_item_id
    AND ci.is_removed = 0
    LEFT JOIN ingredient i ON ci.ingredient_id = i.ingredient_id
WHERE
    o.order_status IN (
        'pending',
        'preparing',
        'rider_pending',
        'picking_up',
        'delivering'
    )
GROUP BY
    qi.queue_item_id
ORDER BY o.order_date ASC;

CREATE OR REPLACE VIEW restaurant_performance AS
SELECT
    r.restaurant_id,
    r.business_name,
    r.verification_status,
    rb.restaurant_branch_id,
    rb.branch_name,
    COUNT(DISTINCT o.order_id) AS total_orders,
    COALESCE(SUM(it.subtotal), 0) AS total_revenue,
    COALESCE(AVG(it.subtotal), 0) AS average_order_value,
    COUNT(DISTINCT o.customer_id) AS unique_customers,
    COALESCE(AVG(rt.score), 0) AS average_branch_rating,
    COUNT(rt.rating_id) AS total_branch_reviews,
    COALESCE(
        AVG(
            CASE
                WHEN qi.is_customized = 1 THEN 1
                ELSE 0
            END
        ) * 100,
        0
    ) AS customization_rate
FROM
    restaurant r
    JOIN restaurant_branch rb ON r.restaurant_id = rb.restaurant_id
    LEFT JOIN queue_item qi ON rb.restaurant_branch_id = qi.branch_id
    LEFT JOIN orders o ON qi.order_id = o.order_id
    AND o.order_status = 'delivered'
    LEFT JOIN (
        SELECT order_id, SUM(
                queue_quantity * COALESCE(final_price, unit_price)
            ) AS subtotal
        FROM queue_item
        GROUP BY
            order_id
    ) it ON it.order_id = o.order_id
    LEFT JOIN rating rt ON rt.branch_id = rb.restaurant_branch_id
    AND rt.rating_type = 'restaurant'
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
    COALESCE(AVG(rt.score), 0) AS average_rating_given,
    COUNT(rt.rating_id) AS total_ratings_given,
    GROUP_CONCAT(DISTINCT di.dietary_tags) AS ordered_dietary_tags,
    COALESCE(
        AVG(
            CASE
                WHEN qi.is_customized = 1 THEN 1
                ELSE 0
            END
        ) * 100,
        0
    ) AS customization_frequency
FROM
    customer c
    JOIN customer_profile cp ON c.customer_id = cp.customer_id
    LEFT JOIN orders o ON c.customer_id = o.customer_id
    AND o.order_status = 'delivered'
    LEFT JOIN queue_item qi ON o.order_id = qi.order_id
    LEFT JOIN product p ON qi.product_id = p.product_id
    LEFT JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
    LEFT JOIN feedback f ON f.order_id = o.order_id
    AND f.feedback_from_type = 'customer'
    AND f.feedback_from_id = c.customer_id
    LEFT JOIN rating rt ON rt.feedback_id = f.feedback_id
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

CREATE OR REPLACE VIEW customization_analytics AS
SELECT
    p.product_id,
    p.name AS product_name,
    COUNT(DISTINCT qi.queue_item_id) AS times_customized,
    COUNT(DISTINCT qi.order_id) AS orders_with_customization,
    AVG(ci.quantity) AS avg_quantity_per_ingredient,
    COUNT(DISTINCT ci.ingredient_id) AS unique_ingredients_used,
    SUM(
        ci.price_at_time * ci.quantity
    ) AS total_customization_revenue
FROM
    product p
    JOIN queue_item qi ON p.product_id = qi.product_id
    JOIN customization_instance ci ON qi.queue_item_id = ci.queue_item_id
WHERE
    ci.is_removed = 0
GROUP BY
    p.product_id;

CREATE OR REPLACE VIEW rider_kyc_overview AS
SELECT
    dr.delivery_rider_id,
    CONCAT(
        dr.first_name,
        ' ',
        COALESCE(dr.middle_name, ''),
        ' ',
        dr.last_name
    ) AS rider_name,
    dr.email,
    dr.contact_number,
    drp.verification_status,
    drp.profile_picture,
    drd.id_type,
    drd.id_path,
    drd.issue_date,
    drd.expiry_date,
    CASE
        WHEN drd.id_type IS NULL THEN 'missing'
        WHEN drd.expiry_date IS NULL THEN 'valid'
        WHEN drd.expiry_date < CURDATE() THEN 'expired'
        WHEN drd.expiry_date < DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'expiring_soon'
        ELSE 'valid'
    END AS id_state,
    (
        SELECT COUNT(*)
        FROM
            delivery_rider_emergency_contact ec
        WHERE
            ec.delivery_rider_id = dr.delivery_rider_id
    ) AS emergency_contact_count
FROM
    delivery_rider dr
    LEFT JOIN delivery_rider_profile drp ON dr.delivery_rider_id = drp.delivery_rider_id
    LEFT JOIN delivery_rider_document drd ON dr.delivery_rider_id = drd.delivery_rider_id;

CREATE OR REPLACE VIEW rider_performance AS
SELECT
    dr.delivery_rider_id,
    CONCAT(
        dr.first_name,
        ' ',
        COALESCE(dr.middle_name, ''),
        ' ',
        dr.last_name
    ) AS rider_name,
    COUNT(DISTINCT o.order_id) AS total_orders,
    COALESCE(AVG(rt.score), 0) AS average_rating,
    COUNT(rt.rating_id) AS total_reviews,
    SUM(
        CASE
            WHEN rt.score = 5 THEN 1
            ELSE 0
        END
    ) AS five_star_reviews,
    SUM(
        CASE
            WHEN rt.score = 1 THEN 1
            ELSE 0
        END
    ) AS one_star_reviews
FROM
    delivery_rider dr
    LEFT JOIN orders o ON o.delivery_rider_id = dr.delivery_rider_id
    AND o.order_status = 'delivered'
    LEFT JOIN rating rt ON rt.rider_id = dr.delivery_rider_id
    AND rt.rating_type = 'rider'
GROUP BY
    dr.delivery_rider_id;

SET FOREIGN_KEY_CHECKS = 1;