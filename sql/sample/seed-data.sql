-- =====================================================
-- FitPal Seed Data
-- Version 4.3
--
-- CHANGES FROM 4.2
--   Section 4 extended:
--     - 1 manager and 1 staff per restaurant (branch-scoped)
--     - 3 delivery riders with financial accounts and profiles
--   No changes to ingredients, products, or compositions.
--
-- CHANGES FROM 4.1
--   Section 4 revised to match new schema:
--     - restaurant no longer has owner_id
--     - restaurant now has description, verification_status,
--       verified_by_admin_id, verified_at
--     - owners are now restaurant_account rows (role = 'owner')
--     - each restaurant gets a dedicated owner login
--   No changes to ingredients, products, or compositions.
--
-- CHANGES FROM 4.0
--   Product description ingredient lines now use the format
--     - <name>: <calories> kcal, <fat>g fat
--   instead of the previous "x ... x ..." style.
--
-- CHANGES FROM 3.0
--   A. Products renamed to realistic, menu-style names.
--      Generic "Build Your Own" / "Custom" prefixes removed.
--   B. Added 'halal' dietary tag to compatible products.
--   C. Product descriptions now include a full breakdown:
--        Description
--        <narrative>
--
--        Ingredients
--        - <name>: <calories> kcal, <fat>g fat
--      Stored as the product.description column.
--   D. Restaurant concepts tightened:
--        - Green Bowl Cafe  -> plant-forward, vegan-leaning
--        - Keto Kitchen     -> high-fat, low-carb
--        - Asian Fusion Fit -> gluten-free Asian, includes halal
--
-- KEY PRINCIPLE
--   For customizable products, dietary_information.calories
--   is DERIVED from the default composition, not authored.
--   Section 9 recomputes it after all inserts.
--
-- INVARIANTS ENFORCED
--   min_quantity <= default_quantity <= max_quantity
--   exactly one is_default = 1 per choice group
--   every customizable product has at least one default
--   every active product has base_price > 0
--   sum of default price_modifiers = 0
--   is_required = 1 only on group anchors
-- =====================================================

USE fitpal_food_delivery;

START TRANSACTION;

-- =====================================================
-- 1. FINANCIAL ACCOUNTS
-- =====================================================
INSERT IGNORE INTO
    financial_account (balance, account_type)
VALUES (100.00, 'customer');

SET @customer_financial_id = LAST_INSERT_ID();

INSERT IGNORE INTO
    financial_account (balance, account_type)
VALUES (0.00, 'restaurant');

SET @branch1_financial_id = LAST_INSERT_ID();

INSERT IGNORE INTO
    financial_account (balance, account_type)
VALUES (0.00, 'restaurant');

SET @branch2_financial_id = LAST_INSERT_ID();

INSERT IGNORE INTO
    financial_account (balance, account_type)
VALUES (0.00, 'restaurant');

SET @branch3_financial_id = LAST_INSERT_ID();

-- =====================================================
-- 2. ADMINISTRATOR
-- =====================================================
INSERT IGNORE INTO
    administrator (
        first_name,
        middle_name,
        last_name,
        email,
        contact_number,
        username,
        password,
        is_active
    )
VALUES (
        'Admin',
        NULL,
        'User',
        'admin@fitpal.com',
        '09123456789',
        'admin',
        'admin123',
        1
    );

SET @admin_id = LAST_INSERT_ID();

-- =====================================================
-- 3. CUSTOMER
-- =====================================================
INSERT IGNORE INTO
    customer (
        first_name,
        middle_name,
        last_name,
        email,
        contact_number,
        username,
        password,
        is_active
    )
VALUES (
        'User',
        NULL,
        'User',
        'user@example.com',
        '09123456789',
        'user',
        'user123',
        1
    );

SET @customer_id = LAST_INSERT_ID();

INSERT IGNORE INTO
    customer_profile (
        customer_id,
        financial_account_id,
        dietary_preferences,
        allergies,
        fitness_goal,
        height_cm,
        weight_kg
    )
VALUES (
        @customer_id,
        @customer_financial_id,
        'vegan,gluten_free',
        'nuts',
        'maintenance',
        175.0,
        70.0
    );

-- =====================================================
-- 4. RESTAURANTS, OWNERS, BRANCHES, MANAGERS, STAFF & RIDERS
-- =====================================================

-- -----------------------------------------------------
-- Green Bowl Cafe — plant-forward, vegan-leaning
-- -----------------------------------------------------
INSERT IGNORE INTO
    restaurant (
        business_name,
        description,
        cuisine_type,
        dietary_tags,
        verification_status,
        verified_by_admin_id,
        verified_at,
        is_active
    )
VALUES (
        'Green Bowl Cafe',
        'Plant-forward cafe serving vibrant grain bowls, crisp salads, and dairy-free smoothies. The menu leans vegan and gluten-free, with a rotating cast of seasonal vegetables and clean proteins.',
        'Filipino',
        'vegan,organic,gluten_free',
        'verified',
        @admin_id,
        CURRENT_TIMESTAMP,
        1
    );

SET @rest1_id = LAST_INSERT_ID();

INSERT IGNORE INTO
    restaurant_account (
        restaurant_id,
        branch_id,
        first_name,
        middle_name,
        last_name,
        birthdate,
        gender,
        email,
        contact_number,
        username,
        password,
        role,
        is_active
    )
VALUES (
        @rest1_id,
        NULL,
        'Gina',
        NULL,
        'Bautista',
        NULL,
        NULL,
        'owner@greenbowl.ph',
        '09171111111',
        'greenbowl_owner',
        'owner123',
        'owner',
        1
    );

SET @owner1_id = LAST_INSERT_ID();

INSERT IGNORE INTO
    restaurant_branch (
        restaurant_id,
        financial_account_id,
        branch_name,
        branch_code,
        barangay,
        city,
        province,
        is_active
    )
VALUES (
        @rest1_id,
        @branch1_financial_id,
        'Main Branch',
        'GBC001',
        'Barangay San Antonio',
        'Pasig',
        'Metro Manila',
        1
    );

SET @branch1_id = LAST_INSERT_ID();

-- Green Bowl Cafe — manager & staff (branch-scoped)
INSERT IGNORE INTO
    restaurant_account (
        restaurant_id,
        branch_id,
        first_name,
        middle_name,
        last_name,
        birthdate,
        gender,
        email,
        contact_number,
        username,
        password,
        role,
        is_active
    )
VALUES (
        @rest1_id,
        @branch1_id,
        'Rafael',
        NULL,
        'Cruz',
        '1990-05-14',
        'Male',
        'manager@greenbowl.ph',
        '09181111111',
        'greenbowl_manager',
        'manager123',
        'manager',
        1
    ),
    (
        @rest1_id,
        @branch1_id,
        'Liza',
        NULL,
        'Reyes',
        '1998-11-02',
        'Female',
        'staff@greenbowl.ph',
        '09182222222',
        'greenbowl_staff',
        'staff123',
        'staff',
        1
    );

-- -----------------------------------------------------
-- Keto Kitchen — high-fat, low-carb
-- -----------------------------------------------------
INSERT IGNORE INTO
    restaurant (
        business_name,
        description,
        cuisine_type,
        dietary_tags,
        verification_status,
        verified_by_admin_id,
        verified_at,
        is_active
    )
VALUES (
        'Keto Kitchen',
        'A high-fat, low-carb kitchen built for keto and performance-focused diners. Every plate is macro-conscious, from bunless smash burgers to cauliflower-crust pizza and buttered steak plates.',
        'Italian',
        'keto,high_protein,low_carb',
        'verified',
        @admin_id,
        CURRENT_TIMESTAMP,
        1
    );

SET @rest2_id = LAST_INSERT_ID();

INSERT IGNORE INTO
    restaurant_account (
        restaurant_id,
        branch_id,
        first_name,
        middle_name,
        last_name,
        birthdate,
        gender,
        email,
        contact_number,
        username,
        password,
        role,
        is_active
    )
VALUES (
        @rest2_id,
        NULL,
        'Marco',
        NULL,
        'Villanueva',
        NULL,
        NULL,
        'owner@ketokitchen.ph',
        '09172222222',
        'keto_owner',
        'owner123',
        'owner',
        1
    );

SET @owner2_id = LAST_INSERT_ID();

INSERT IGNORE INTO
    restaurant_branch (
        restaurant_id,
        financial_account_id,
        branch_name,
        branch_code,
        barangay,
        city,
        province,
        is_active
    )
VALUES (
        @rest2_id,
        @branch2_financial_id,
        'Main Branch',
        'KK001',
        'Barangay San Lorenzo',
        'Makati',
        'Metro Manila',
        1
    );

SET @branch2_id = LAST_INSERT_ID();

-- Keto Kitchen — manager & staff (branch-scoped)
INSERT IGNORE INTO
    restaurant_account (
        restaurant_id,
        branch_id,
        first_name,
        middle_name,
        last_name,
        birthdate,
        gender,
        email,
        contact_number,
        username,
        password,
        role,
        is_active
    )
VALUES (
        @rest2_id,
        @branch2_id,
        'Daniel',
        NULL,
        'Lopez',
        '1988-03-22',
        'Male',
        'manager@ketokitchen.ph',
        '09183333333',
        'keto_manager',
        'manager123',
        'manager',
        1
    ),
    (
        @rest2_id,
        @branch2_id,
        'Bea',
        NULL,
        'Mendoza',
        '2000-07-19',
        'Female',
        'staff@ketokitchen.ph',
        '09184444444',
        'keto_staff',
        'staff123',
        'staff',
        1
    );

-- -----------------------------------------------------
-- Asian Fusion Fit — gluten-free Asian, includes halal
-- -----------------------------------------------------
INSERT IGNORE INTO
    restaurant (
        business_name,
        description,
        cuisine_type,
        dietary_tags,
        verification_status,
        verified_by_admin_id,
        verified_at,
        is_active
    )
VALUES (
        'Asian Fusion Fit',
        'Gluten-free Asian fusion with a halal-friendly menu. Fresh hand rolls, rainbow poke bowls, and light noodle plates built for clean eating without losing flavor.',
        'Japanese',
        'gluten_free,low_carb,halal',
        'verified',
        @admin_id,
        CURRENT_TIMESTAMP,
        1
    );

SET @rest3_id = LAST_INSERT_ID();

INSERT IGNORE INTO
    restaurant_account (
        restaurant_id,
        branch_id,
        first_name,
        middle_name,
        last_name,
        birthdate,
        gender,
        email,
        contact_number,
        username,
        password,
        role,
        is_active
    )
VALUES (
        @rest3_id,
        NULL,
        'Aiko',
        NULL,
        'Tanaka',
        NULL,
        NULL,
        'owner@asianfusionfit.ph',
        '09173333333',
        'aff_owner',
        'owner123',
        'owner',
        1
    );

SET @owner3_id = LAST_INSERT_ID();

INSERT IGNORE INTO
    restaurant_branch (
        restaurant_id,
        financial_account_id,
        branch_name,
        branch_code,
        barangay,
        city,
        province,
        is_active
    )
VALUES (
        @rest3_id,
        @branch3_financial_id,
        'Main Branch',
        'AFF001',
        'Barangay San Miguel',
        'Quezon City',
        'Metro Manila',
        1
    );

SET @branch3_id = LAST_INSERT_ID();

-- Asian Fusion Fit — manager & staff (branch-scoped)
INSERT IGNORE INTO
    restaurant_account (
        restaurant_id,
        branch_id,
        first_name,
        middle_name,
        last_name,
        birthdate,
        gender,
        email,
        contact_number,
        username,
        password,
        role,
        is_active
    )
VALUES (
        @rest3_id,
        @branch3_id,
        'Hiroshi',
        NULL,
        'Yamada',
        '1992-09-08',
        'Male',
        'manager@asianfusionfit.ph',
        '09185555555',
        'aff_manager',
        'manager123',
        'manager',
        1
    ),
    (
        @rest3_id,
        @branch3_id,
        'Mika',
        NULL,
        'Suzuki',
        '1999-12-01',
        'Female',
        'staff@asianfusionfit.ph',
        '09186666666',
        'aff_staff',
        'staff123',
        'staff',
        1
    );

-- -----------------------------------------------------
-- Delivery riders (3)
-- Each rider needs: financial_account -> delivery_rider -> delivery_rider_profile
-- -----------------------------------------------------

INSERT IGNORE INTO
    financial_account (balance, account_type)
VALUES (0.00, 'rider');

SET @rider1_financial_id = LAST_INSERT_ID();

INSERT IGNORE INTO
    financial_account (balance, account_type)
VALUES (0.00, 'rider');

SET @rider2_financial_id = LAST_INSERT_ID();

INSERT IGNORE INTO
    financial_account (balance, account_type)
VALUES (0.00, 'rider');

SET @rider3_financial_id = LAST_INSERT_ID();

-- Rider 1: Motorcycle, verified, available
INSERT IGNORE INTO
    delivery_rider (
        first_name,
        middle_name,
        last_name,
        birthdate,
        gender,
        email,
        contact_number,
        username,
        password,
        is_active
    )
VALUES (
        'Carlos',
        NULL,
        'Dela Cruz',
        '1995-04-12',
        'Male',
        'rider1@fitpal.com',
        '09187777777',
        'rider_carlos',
        'rider123',
        1
    );

SET @rider1_id = LAST_INSERT_ID();

INSERT IGNORE INTO
    delivery_rider_profile (
        delivery_rider_id,
        financial_account_id,
        vehicle_type,
        vehicle_plate,
        verification_status,
        verified_by_admin_id,
        verified_at,
        average_rating,
        total_deliveries,
        is_available
    )
VALUES (
        @rider1_id,
        @rider1_financial_id,
        'motorcycle',
        'ABC1234',
        'verified',
        @admin_id,
        CURRENT_TIMESTAMP,
        4.8,
        0,
        1
    );

-- Rider 2: Car, verified, available
INSERT IGNORE INTO
    delivery_rider (
        first_name,
        middle_name,
        last_name,
        birthdate,
        gender,
        email,
        contact_number,
        username,
        password,
        is_active
    )
VALUES (
        'Miguel',
        NULL,
        'Santos',
        '1993-08-27',
        'Male',
        'rider2@fitpal.com',
        '09188888888',
        'rider_miguel',
        'rider123',
        1
    );

SET @rider2_id = LAST_INSERT_ID();

INSERT IGNORE INTO
    delivery_rider_profile (
        delivery_rider_id,
        financial_account_id,
        vehicle_type,
        vehicle_plate,
        verification_status,
        verified_by_admin_id,
        verified_at,
        average_rating,
        total_deliveries,
        is_available
    )
VALUES (
        @rider2_id,
        @rider2_financial_id,
        'car',
        'XYZ5678',
        'verified',
        @admin_id,
        CURRENT_TIMESTAMP,
        4.6,
        0,
        1
    );

-- Rider 3: Bicycle, pending verification, not yet available
INSERT IGNORE INTO
    delivery_rider (
        first_name,
        middle_name,
        last_name,
        birthdate,
        gender,
        email,
        contact_number,
        username,
        password,
        is_active
    )
VALUES (
        'Andrei',
        NULL,
        'Fernandez',
        '2000-01-30',
        'Male',
        'rider3@fitpal.com',
        '09189999999',
        'rider_andrei',
        'rider123',
        1
    );

SET @rider3_id = LAST_INSERT_ID();

INSERT IGNORE INTO
    delivery_rider_profile (
        delivery_rider_id,
        financial_account_id,
        vehicle_type,
        vehicle_plate,
        verification_status,
        verified_by_admin_id,
        verified_at,
        average_rating,
        total_deliveries,
        is_available
    )
VALUES (
        @rider3_id,
        @rider3_financial_id,
        'bicycle',
        NULL,
        'pending',
        NULL,
        NULL,
        0.0,
        0,
        0
    );

-- =====================================================
-- 5. DIETARY INFORMATION
-- =====================================================
-- Green Bowl Cafe
INSERT INTO
    dietary_information (
        category,
        dietary_tags,
        allergens,
        calories,
        protein,
        carbs,
        fat
    )
VALUES (
        'Main',
        'vegan,gluten_free',
        'soy',
        280,
        12.0,
        38.0,
        8.0
    ),
    (
        'Main',
        'vegan,gluten_free,low_carb',
        'none',
        320,
        14.0,
        42.0,
        10.0
    ),
    (
        'Appetizer',
        'vegan,gluten_free',
        'soy',
        150,
        6.0,
        18.0,
        5.0
    ),
    (
        'Main',
        'vegan,gluten_free,low_carb',
        'nuts',
        290,
        18.0,
        22.0,
        14.0
    ),
    (
        'Appetizer',
        'vegan,gluten_free,low_carb',
        'none',
        120,
        4.0,
        15.0,
        4.0
    ),
    (
        'Main',
        'vegan,gluten_free,low_carb,organic',
        'soy',
        340,
        16.0,
        28.0,
        16.0
    ),
    (
        'Beverage',
        'vegan,gluten_free,low_carb,keto',
        'nuts',
        95,
        1.0,
        8.0,
        6.0
    ),
    (
        'Main',
        'vegan,gluten_free,low_carb,organic,high_protein',
        'soy,nuts',
        420,
        32.0,
        18.0,
        22.0
    ),
    (
        'Dessert',
        'vegan,gluten_free,low_carb,keto,organic',
        'none',
        180,
        8.0,
        12.0,
        14.0
    ),
    (
        'Appetizer',
        'vegan,gluten_free,low_carb,organic,high_protein',
        'soy',
        160,
        22.0,
        10.0,
        6.0
    );

SELECT MAX(dietary_information_id) INTO @last_diet_green
FROM dietary_information;

SET @diet1_id = @last_diet_green - 9;

SET @diet2_id = @last_diet_green - 8;

SET @diet3_id = @last_diet_green - 7;

SET @diet4_id = @last_diet_green - 6;

SET @diet5_id = @last_diet_green - 5;

SET @diet6_id = @last_diet_green - 4;

SET @diet7_id = @last_diet_green - 3;

SET @diet8_id = @last_diet_green - 2;

SET @diet9_id = @last_diet_green - 1;

SET @diet10_id = @last_diet_green;

-- Keto Kitchen
INSERT INTO
    dietary_information (
        category,
        dietary_tags,
        allergens,
        calories,
        protein,
        carbs,
        fat
    )
VALUES (
        'Main',
        'keto',
        'dairy',
        550,
        42.0,
        6.0,
        40.0
    ),
    (
        'Main',
        'keto,high_protein',
        'eggs',
        480,
        38.0,
        8.0,
        34.0
    ),
    (
        'Appetizer',
        'keto,organic',
        'dairy',
        200,
        12.0,
        4.0,
        16.0
    ),
    (
        'Main',
        'keto,high_protein,organic',
        'none',
        520,
        48.0,
        6.0,
        36.0
    ),
    (
        'Dessert',
        'keto,gluten_free,organic',
        'dairy',
        250,
        8.0,
        5.0,
        22.0
    ),
    (
        'Main',
        'keto,high_protein,gluten_free,organic',
        'eggs,dairy',
        580,
        52.0,
        4.0,
        42.0
    ),
    (
        'Appetizer',
        'keto,gluten_free,low_carb,organic',
        'none',
        140,
        16.0,
        3.0,
        12.0
    ),
    (
        'Main',
        'keto,high_protein,gluten_free,low_carb,organic',
        'dairy',
        620,
        56.0,
        4.0,
        46.0
    ),
    (
        'Beverage',
        'keto,gluten_free,low_carb,organic,vegan',
        'nuts',
        110,
        3.0,
        6.0,
        8.0
    ),
    (
        'Main',
        'keto,high_protein,gluten_free,low_carb,organic',
        'eggs,shellfish',
        540,
        50.0,
        5.0,
        38.0
    );

SELECT MAX(dietary_information_id) INTO @last_diet_keto
FROM dietary_information;

SET @diet11_id = @last_diet_keto - 9;

SET @diet12_id = @last_diet_keto - 8;

SET @diet13_id = @last_diet_keto - 7;

SET @diet14_id = @last_diet_keto - 6;

SET @diet15_id = @last_diet_keto - 5;

SET @diet16_id = @last_diet_keto - 4;

SET @diet17_id = @last_diet_keto - 3;

SET @diet18_id = @last_diet_keto - 2;

SET @diet19_id = @last_diet_keto - 1;

SET @diet20_id = @last_diet_keto;

-- Asian Fusion Fit
INSERT INTO
    dietary_information (
        category,
        dietary_tags,
        allergens,
        calories,
        protein,
        carbs,
        fat
    )
VALUES (
        'Main',
        'gluten_free,halal',
        'shellfish',
        350,
        26.0,
        18.0,
        18.0
    ),
    (
        'Appetizer',
        'gluten_free,organic,halal',
        'soy',
        95,
        5.0,
        12.0,
        4.0
    ),
    (
        'Main',
        'low_carb,organic,halal',
        'fish',
        310,
        30.0,
        14.0,
        18.0
    ),
    (
        'Main',
        'gluten_free,low_carb,organic,halal',
        'shellfish',
        390,
        34.0,
        12.0,
        22.0
    ),
    (
        'Appetizer',
        'gluten_free,vegan,organic,halal',
        'none',
        85,
        4.0,
        14.0,
        2.0
    ),
    (
        'Main',
        'gluten_free,low_carb,organic,high_protein,halal',
        'fish,shellfish',
        450,
        42.0,
        10.0,
        26.0
    ),
    (
        'Dessert',
        'gluten_free,vegan,low_carb,organic,halal',
        'nuts',
        160,
        6.0,
        14.0,
        12.0
    ),
    (
        'Main',
        'gluten_free,low_carb,organic,high_protein,keto,halal',
        'shellfish',
        490,
        46.0,
        8.0,
        30.0
    ),
    (
        'Appetizer',
        'gluten_free,low_carb,organic,vegan,high_protein,halal',
        'soy',
        115,
        20.0,
        6.0,
        8.0
    ),
    (
        'Beverage',
        'gluten_free,low_carb,organic,vegan,keto,halal',
        'none',
        75,
        2.0,
        4.0,
        5.0
    );

SELECT MAX(dietary_information_id) INTO @last_diet_asian
FROM dietary_information;

SET @diet21_id = @last_diet_asian - 9;

SET @diet22_id = @last_diet_asian - 8;

SET @diet23_id = @last_diet_asian - 7;

SET @diet24_id = @last_diet_asian - 6;

SET @diet25_id = @last_diet_asian - 5;

SET @diet26_id = @last_diet_asian - 4;

SET @diet27_id = @last_diet_asian - 3;

SET @diet28_id = @last_diet_asian - 2;

SET @diet29_id = @last_diet_asian - 1;

SET @diet30_id = @last_diet_asian;

-- =====================================================
-- 6. INGREDIENTS
-- =====================================================
INSERT INTO
    ingredient (
        name,
        description,
        unit_price,
        calories,
        protein,
        carbs,
        fat,
        dietary_tags,
        allergens,
        stock_quantity,
        is_active
    )
VALUES (
        'White Rice',
        'Steamed jasmine white rice',
        15.00,
        200,
        4.0,
        45.0,
        0.5,
        '["vegan","gluten_free","halal"]',
        '[]',
        100,
        1
    ),
    (
        'Brown Rice',
        'Steamed whole-grain brown rice',
        20.00,
        180,
        4.0,
        38.0,
        1.5,
        '["vegan","gluten_free","halal"]',
        '[]',
        100,
        1
    ),
    (
        'Cauliflower Rice',
        'Riced cauliflower, pan-toasted',
        25.00,
        50,
        2.0,
        5.0,
        2.0,
        '["vegan","gluten_free","low_carb","keto","halal"]',
        '[]',
        80,
        1
    ),
    (
        'Quinoa',
        'Fluffy cooked tri-color quinoa',
        30.00,
        220,
        8.0,
        39.0,
        3.5,
        '["vegan","gluten_free","high_protein","halal"]',
        '[]',
        90,
        1
    ),
    (
        'Grilled Chicken',
        'Marinated grilled chicken breast',
        50.00,
        250,
        35.0,
        0.0,
        10.0,
        '["gluten_free","high_protein","halal"]',
        '[]',
        120,
        1
    ),
    (
        'Tofu',
        'Organic firm tofu, lightly seared',
        40.00,
        150,
        15.0,
        4.0,
        8.0,
        '["vegan","gluten_free","halal"]',
        '["soy"]',
        100,
        1
    ),
    (
        'Salmon',
        'Fresh grilled Atlantic salmon',
        80.00,
        300,
        34.0,
        0.0,
        18.0,
        '["gluten_free","high_protein","keto","halal"]',
        '["fish"]',
        60,
        1
    ),
    (
        'Beef Patty',
        'Grilled lean beef patty',
        60.00,
        280,
        26.0,
        0.0,
        19.0,
        '["gluten_free","high_protein","keto","halal"]',
        '[]',
        70,
        1
    ),
    (
        'Shrimp',
        'Garlic butter shrimp',
        75.00,
        200,
        24.0,
        2.0,
        10.0,
        '["gluten_free","keto","halal"]',
        '["shellfish"]',
        50,
        1
    ),
    (
        'Eggs',
        'Pasture-raised scrambled eggs',
        30.00,
        140,
        12.0,
        1.0,
        9.5,
        '["keto","high_protein","halal"]',
        '["eggs"]',
        150,
        1
    ),
    (
        'Lettuce',
        'Crisp romaine lettuce',
        10.00,
        10,
        1.0,
        2.0,
        0.1,
        '["vegan","gluten_free","halal"]',
        '[]',
        200,
        1
    ),
    (
        'Tomato',
        'Fresh tomato slices',
        5.00,
        15,
        0.7,
        3.0,
        0.2,
        '["vegan","gluten_free","halal"]',
        '[]',
        200,
        1
    ),
    (
        'Cucumber',
        'Fresh cucumber ribbons',
        5.00,
        10,
        0.5,
        2.0,
        0.1,
        '["vegan","gluten_free","halal"]',
        '[]',
        180,
        1
    ),
    (
        'Avocado',
        'Fresh sliced avocado',
        25.00,
        160,
        2.0,
        9.0,
        15.0,
        '["vegan","gluten_free","keto","halal"]',
        '[]',
        80,
        1
    ),
    (
        'Spinach',
        'Baby spinach leaves',
        15.00,
        20,
        2.5,
        3.0,
        0.2,
        '["vegan","gluten_free","halal"]',
        '[]',
        150,
        1
    ),
    (
        'Seaweed',
        'Toasted nori seaweed sheets',
        20.00,
        30,
        3.0,
        4.0,
        0.5,
        '["vegan","gluten_free","halal"]',
        '[]',
        100,
        1
    ),
    (
        'Cheddar Cheese',
        'Sharp aged cheddar',
        25.00,
        110,
        7.0,
        1.0,
        9.0,
        '["keto","high_protein"]',
        '["dairy"]',
        90,
        1
    ),
    (
        'Vegan Cheese',
        'Cashew-based vegan cheese',
        30.00,
        100,
        3.0,
        4.0,
        8.0,
        '["vegan","gluten_free"]',
        '["soy"]',
        70,
        1
    ),
    (
        'Parmesan Cheese',
        'Freshly grated parmesan',
        30.00,
        120,
        10.0,
        1.0,
        9.0,
        '["keto","high_protein"]',
        '["dairy"]',
        80,
        1
    ),
    (
        'BBQ Sauce',
        'Smoky-sweet BBQ glaze',
        10.00,
        60,
        0.5,
        15.0,
        0.1,
        '["vegan","gluten_free","halal"]',
        '[]',
        120,
        1
    ),
    (
        'Ranch Dressing',
        'Creamy buttermilk ranch',
        10.00,
        80,
        1.0,
        2.0,
        8.0,
        '[]',
        '["dairy","eggs"]',
        100,
        1
    ),
    (
        'Vegan Ranch',
        'Plant-based ranch dressing',
        12.00,
        70,
        1.0,
        3.0,
        6.0,
        '["vegan","gluten_free"]',
        '["soy"]',
        90,
        1
    ),
    (
        'Teriyaki Sauce',
        'Gluten-free teriyaki glaze',
        10.00,
        50,
        1.0,
        11.0,
        0.2,
        '["gluten_free","vegan","halal"]',
        '["soy"]',
        110,
        1
    ),
    (
        'Miso Dressing',
        'Traditional white miso dressing',
        10.00,
        40,
        2.0,
        6.0,
        1.0,
        '["vegan","gluten_free","halal"]',
        '["soy"]',
        80,
        1
    ),
    (
        'Sesame Dressing',
        'Sesame-ginger vinaigrette',
        10.00,
        60,
        1.5,
        5.0,
        4.0,
        '["vegan","gluten_free","halal"]',
        '["sesame"]',
        100,
        1
    );

SET
    @white_rice_id = (
        SELECT ingredient_id
        FROM ingredient
        WHERE
            name = 'White Rice'
    );

SET
    @brown_rice_id = (
        SELECT ingredient_id
        FROM ingredient
        WHERE
            name = 'Brown Rice'
    );

SET
    @cauliflower_rice_id = (
        SELECT ingredient_id
        FROM ingredient
        WHERE
            name = 'Cauliflower Rice'
    );

SET
    @quinoa_id = (
        SELECT ingredient_id
        FROM ingredient
        WHERE
            name = 'Quinoa'
    );

SET
    @grilled_chicken_id = (
        SELECT ingredient_id
        FROM ingredient
        WHERE
            name = 'Grilled Chicken'
    );

SET
    @tofu_id = (
        SELECT ingredient_id
        FROM ingredient
        WHERE
            name = 'Tofu'
    );

SET
    @salmon_id = (
        SELECT ingredient_id
        FROM ingredient
        WHERE
            name = 'Salmon'
    );

SET
    @beef_patty_id = (
        SELECT ingredient_id
        FROM ingredient
        WHERE
            name = 'Beef Patty'
    );

SET
    @shrimp_id = (
        SELECT ingredient_id
        FROM ingredient
        WHERE
            name = 'Shrimp'
    );

SET
    @eggs_id = (
        SELECT ingredient_id
        FROM ingredient
        WHERE
            name = 'Eggs'
    );

SET
    @lettuce_id = (
        SELECT ingredient_id
        FROM ingredient
        WHERE
            name = 'Lettuce'
    );

SET
    @tomato_id = (
        SELECT ingredient_id
        FROM ingredient
        WHERE
            name = 'Tomato'
    );

SET
    @cucumber_id = (
        SELECT ingredient_id
        FROM ingredient
        WHERE
            name = 'Cucumber'
    );

SET
    @avocado_id = (
        SELECT ingredient_id
        FROM ingredient
        WHERE
            name = 'Avocado'
    );

SET
    @spinach_id = (
        SELECT ingredient_id
        FROM ingredient
        WHERE
            name = 'Spinach'
    );

SET
    @seaweed_id = (
        SELECT ingredient_id
        FROM ingredient
        WHERE
            name = 'Seaweed'
    );

SET
    @cheddar_cheese_id = (
        SELECT ingredient_id
        FROM ingredient
        WHERE
            name = 'Cheddar Cheese'
    );

SET
    @vegan_cheese_id = (
        SELECT ingredient_id
        FROM ingredient
        WHERE
            name = 'Vegan Cheese'
    );

SET
    @parmesan_cheese_id = (
        SELECT ingredient_id
        FROM ingredient
        WHERE
            name = 'Parmesan Cheese'
    );

SET
    @bbq_sauce_id = (
        SELECT ingredient_id
        FROM ingredient
        WHERE
            name = 'BBQ Sauce'
    );

SET
    @ranch_dressing_id = (
        SELECT ingredient_id
        FROM ingredient
        WHERE
            name = 'Ranch Dressing'
    );

SET
    @vegan_ranch_id = (
        SELECT ingredient_id
        FROM ingredient
        WHERE
            name = 'Vegan Ranch'
    );

SET
    @teriyaki_sauce_id = (
        SELECT ingredient_id
        FROM ingredient
        WHERE
            name = 'Teriyaki Sauce'
    );

SET
    @miso_dressing_id = (
        SELECT ingredient_id
        FROM ingredient
        WHERE
            name = 'Miso Dressing'
    );

SET
    @sesame_dressing_id = (
        SELECT ingredient_id
        FROM ingredient
        WHERE
            name = 'Sesame Dressing'
    );

-- =====================================================
-- 7. PRODUCTS
-- =====================================================

-- -----------------------------------------------------
-- Green Bowl Cafe — plant-forward, vegan-leaning
-- -----------------------------------------------------
INSERT INTO
    product (
        restaurant_branch_id,
        dietary_information_id,
        name,
        description,
        price,
        stock,
        is_customizable,
        customization_type,
        base_price,
        is_active
    )
VALUES (
        @branch1_id,
        @diet1_id,
        'Garden Harvest Bowl',
        'A vibrant bowl built on a warm grain base with a choice of protein and a rotating cast of seasonal vegetables. Bright, balanced, and filling without being heavy.

Ingredients
- White Rice: 200 kcal, 0.5g fat
- Grilled Chicken: 250 kcal, 10g fat
- Lettuce: 10 kcal, 0.1g fat
- Tomato: 15 kcal, 0.2g fat
- Avocado: 160 kcal, 15g fat',
        220.00,
        100,
        1,
        'structured',
        220.00,
        1
    ),
    (
        @branch1_id,
        @diet2_id,
        'Market Greens Salad',
        'A crisp, refreshing salad built on a bed of fresh greens with layered proteins and a light house dressing. Designed to be adjusted to your own taste.

Ingredients
- Lettuce: 10 kcal, 0.1g fat
- Grilled Chicken: 250 kcal, 10g fat
- Tomato: 15 kcal, 0.2g fat
- Cucumber: 10 kcal, 0.1g fat
- Avocado: 160 kcal, 15g fat',
        180.00,
        80,
        1,
        'structured',
        180.00,
        1
    ),
    (
        @branch1_id,
        @diet7_id,
        'Morning Power Smoothie',
        'A thick, spoonable smoothie built for breakfast or a post-workout refill. Choose your base, mix-ins, and protein add-ons to match your goals.

Ingredients
- White Rice: 200 kcal, 0.5g fat
- Avocado: 160 kcal, 15g fat
- Cucumber: 10 kcal, 0.1g fat',
        190.00,
        60,
        1,
        'mixed',
        190.00,
        1
    ),
    (
        @branch1_id,
        @diet3_id,
        'Classic Vegan Bowl',
        'A simple, satisfying bowl of seasoned tofu over brown rice with fresh seasonal vegetables. A go-to for plant-based regulars.

Ingredients
- Tofu: 150 kcal, 8g fat
- Brown Rice: 180 kcal, 1.5g fat
- Lettuce: 10 kcal, 0.1g fat
- Tomato: 15 kcal, 0.2g fat',
        220.00,
        20,
        0,
        'none',
        0.00,
        1
    ),
    (
        @branch1_id,
        @diet4_id,
        'Edamame Citrus Salad',
        'Steamed edamame tossed with a bright citrus vinaigrette over fresh greens. Light, clean, and packed with plant protein.

Ingredients
- Tofu: 150 kcal, 8g fat
- Lettuce: 10 kcal, 0.1g fat
- Tomato: 15 kcal, 0.2g fat
- Cucumber: 10 kcal, 0.1g fat',
        130.00,
        30,
        0,
        'none',
        0.00,
        1
    ),
    (
        @branch1_id,
        @diet5_id,
        'Zucchini Noodle Pesto',
        'Spiralized zucchini tossed with basil pesto and blistered cherry tomatoes. A low-carb take on a pasta night classic.

Ingredients
- Cauliflower Rice: 50 kcal, 2g fat
- Spinach: 20 kcal, 0.2g fat
- Tomato: 15 kcal, 0.2g fat
- Avocado: 160 kcal, 15g fat',
        260.00,
        12,
        0,
        'none',
        0.00,
        1
    ),
    (
        @branch1_id,
        @diet8_id,
        'Sunrise Breakfast Bowl',
        'A warm breakfast bowl with quinoa, soft-scrambled eggs, and vegetables. A gentle start to the day that still keeps you full.

Ingredients
- Quinoa: 220 kcal, 3.5g fat
- Eggs: 140 kcal, 9.5g fat
- Spinach: 20 kcal, 0.2g fat
- Tomato: 15 kcal, 0.2g fat',
        240.00,
        75,
        1,
        'structured',
        240.00,
        1
    ),
    (
        @branch1_id,
        @diet10_id,
        'Seaside Poke Bowl',
        'A fresh poke-style bowl with cubed salmon, crisp vegetables, and a sesame-soy finish. Bright, clean, and easy to adjust.

Ingredients
- White Rice: 200 kcal, 0.5g fat
- Salmon: 300 kcal, 18g fat
- Cucumber: 10 kcal, 0.1g fat
- Avocado: 160 kcal, 15g fat
- Sesame Dressing: 60 kcal, 4g fat',
        350.00,
        70,
        1,
        'structured',
        350.00,
        1
    ),
    (
        @branch1_id,
        @diet9_id,
        'Superfood Buddha Bowl',
        'A colorful bowl of kale, roasted sweet potato, and chickpeas finished with a turmeric-tahini sauce. Vegan, gluten-free, and rich in fiber.

Ingredients
- Quinoa: 220 kcal, 3.5g fat
- Spinach: 20 kcal, 0.2g fat
- Avocado: 160 kcal, 15g fat
- Tomato: 15 kcal, 0.2g fat',
        350.00,
        8,
        0,
        'none',
        0.00,
        1
    ),
    (
        @branch1_id,
        @diet6_id,
        'Berry Almond Smoothie',
        'A blended smoothie of mixed berries, plant-based protein, and almond milk. Naturally sweet, dairy-free, and filling.

Ingredients
- Avocado: 160 kcal, 15g fat
- Spinach: 20 kcal, 0.2g fat
- Cucumber: 10 kcal, 0.1g fat',
        190.00,
        10,
        0,
        'none',
        0.00,
        1
    );

SELECT MAX(product_id) INTO @last_product_green FROM product;

SET @bowl_product_id = @last_product_green - 9;

SET @salad_product_id = @last_product_green - 8;

SET @smoothie_product_id = @last_product_green - 7;

SET @breakfast_bowl_id = @last_product_green - 3;

SET @poke_product_id = @last_product_green - 2;

-- -----------------------------------------------------
-- Keto Kitchen — high-fat, low-carb
-- -----------------------------------------------------
INSERT INTO
    product (
        restaurant_branch_id,
        dietary_information_id,
        name,
        description,
        price,
        stock,
        is_customizable,
        customization_type,
        base_price,
        is_active
    )
VALUES (
        @branch2_id,
        @diet11_id,
        'Keto Power Bowl',
        'A hearty, low-carb bowl built on cauliflower rice with a choice of protein, healthy fats, and greens. Designed to keep you full without the carbs.

Ingredients
- Cauliflower Rice: 50 kcal, 2g fat
- Grilled Chicken: 250 kcal, 10g fat
- Avocado: 160 kcal, 15g fat
- Spinach: 20 kcal, 0.2g fat',
        480.00,
        90,
        1,
        'structured',
        480.00,
        1
    ),
    (
        @branch2_id,
        @diet14_id,
        'Keto Smash Burger',
        'A juicy beef patty with melted cheese and crisp lettuce, served bunless or with a keto-friendly wrap. Customizable down to the toppings.

Ingredients
- Beef Patty: 280 kcal, 19g fat
- Cheddar Cheese: 110 kcal, 9g fat
- Lettuce: 10 kcal, 0.1g fat
- Tomato: 15 kcal, 0.2g fat
- Avocado: 160 kcal, 15g fat',
        450.00,
        70,
        1,
        'structured',
        450.00,
        1
    ),
    (
        @branch2_id,
        @diet18_id,
        'Keto Garden Salad',
        'A low-carb salad built on a bed of spinach with a choice of protein and fresh vegetables. Light but satisfying.

Ingredients
- Spinach: 20 kcal, 0.2g fat
- Salmon: 300 kcal, 18g fat
- Cucumber: 10 kcal, 0.1g fat
- Avocado: 160 kcal, 15g fat
- Sesame Dressing: 60 kcal, 4g fat',
        380.00,
        85,
        1,
        'structured',
        380.00,
        1
    ),
    (
        @branch2_id,
        @diet12_id,
        'Keto Steak Plate',
        'Grilled ribeye with buttered asparagus and a creamy cauliflower mash. A full keto plate with no compromises.

Ingredients
- Beef Patty: 280 kcal, 19g fat
- Eggs: 140 kcal, 9.5g fat
- Spinach: 20 kcal, 0.2g fat
- Cauliflower Rice: 50 kcal, 2g fat',
        550.00,
        8,
        0,
        'none',
        0.00,
        1
    ),
    (
        @branch2_id,
        @diet13_id,
        'Egg & Avocado Bowl',
        'Soft-scrambled eggs with sliced avocado, crispy bacon, and a touch of cheddar. A simple, high-fat breakfast or brunch.

Ingredients
- Eggs: 140 kcal, 9.5g fat
- Avocado: 160 kcal, 15g fat
- Cheddar Cheese: 110 kcal, 9g fat
- Spinach: 20 kcal, 0.2g fat',
        380.00,
        12,
        0,
        'none',
        0.00,
        1
    ),
    (
        @branch2_id,
        @diet20_id,
        'Chicken Parmesan Plate',
        'Grilled chicken breast topped with parmesan and a rich marinara, served with sautéed greens. Keto-friendly and deeply savory.

Ingredients
- Grilled Chicken: 250 kcal, 10g fat
- Parmesan Cheese: 120 kcal, 9g fat
- Spinach: 20 kcal, 0.2g fat
- Cauliflower Rice: 50 kcal, 2g fat',
        430.00,
        6,
        0,
        'none',
        0.00,
        1
    ),
    (
        @branch2_id,
        @diet17_id,
        'Keto Butcher Plate',
        'A customizable keto plate with your choice of protein and sides. Built for people who want control over their macros.

Ingredients
- Beef Patty: 280 kcal, 19g fat
- Cauliflower Rice: 50 kcal, 2g fat
- Spinach: 20 kcal, 0.2g fat',
        500.00,
        65,
        1,
        'structured',
        500.00,
        1
    ),
    (
        @branch2_id,
        @diet16_id,
        'Keto Cauliflower Pizza',
        'A cauliflower-crust pizza topped with cheddar, parmesan, and grilled chicken. Customizable with extra keto toppings.

Ingredients
- Cauliflower Rice: 50 kcal, 2g fat
- Cheddar Cheese: 110 kcal, 9g fat
- Parmesan Cheese: 120 kcal, 9g fat
- Grilled Chicken: 250 kcal, 10g fat',
        420.00,
        60,
        1,
        'structured',
        420.00,
        1
    ),
    (
        @branch2_id,
        @diet19_id,
        'Salmon Dill Plate',
        'Pan-seared salmon finished with a dill cream sauce and sautéed spinach. Rich, silky, and low-carb.

Ingredients
- Salmon: 300 kcal, 18g fat
- Spinach: 20 kcal, 0.2g fat
- Cauliflower Rice: 50 kcal, 2g fat',
        490.00,
        15,
        0,
        'none',
        0.00,
        1
    ),
    (
        @branch2_id,
        @diet15_id,
        'Shrimp Scampi Zoodles',
        'Garlic-butter shrimp over zucchini noodles with a light lemon finish. Keto, gluten-free, and bright.

Ingredients
- Shrimp: 200 kcal, 10g fat
- Spinach: 20 kcal, 0.2g fat
- Cauliflower Rice: 50 kcal, 2g fat
- Avocado: 160 kcal, 15g fat',
        530.00,
        9,
        0,
        'none',
        0.00,
        1
    );

SELECT MAX(product_id) INTO @last_product_keto FROM product;

SET @keto_bowl_id = @last_product_keto - 9;

SET @keto_burger_id = @last_product_keto - 8;

SET @keto_salad_id = @last_product_keto - 7;

SET @keto_plate_id = @last_product_keto - 3;

SET @keto_pizza_id = @last_product_keto - 2;

-- -----------------------------------------------------
-- Asian Fusion Fit — gluten-free Asian, includes halal
-- -----------------------------------------------------
INSERT INTO
    product (
        restaurant_branch_id,
        dietary_information_id,
        name,
        description,
        price,
        stock,
        is_customizable,
        customization_type,
        base_price,
        is_active
    )
VALUES (
        @branch3_id,
        @diet21_id,
        'Nori Hand Roll Set',
        'A build-your-own hand roll set with toasted nori, a choice of protein, and fresh fillings. Served with gluten-free sauces.

Ingredients
- Seaweed: 30 kcal, 0.5g fat
- Salmon: 300 kcal, 18g fat
- Cucumber: 10 kcal, 0.1g fat
- Avocado: 160 kcal, 15g fat
- Teriyaki Sauce: 50 kcal, 0.2g fat',
        350.00,
        80,
        1,
        'structured',
        350.00,
        1
    ),
    (
        @branch3_id,
        @diet24_id,
        'Rainbow Poke Bowl',
        'A colorful poke bowl with fresh salmon, crisp vegetables, and a sesame-soy finish. Gluten-free and halal-friendly.

Ingredients
- White Rice: 200 kcal, 0.5g fat
- Salmon: 300 kcal, 18g fat
- Cucumber: 10 kcal, 0.1g fat
- Avocado: 160 kcal, 15g fat
- Seaweed: 30 kcal, 0.5g fat',
        390.00,
        75,
        1,
        'structured',
        390.00,
        1
    ),
    (
        @branch3_id,
        @diet26_id,
        'Tokyo Noodle Bowl',
        'A warm noodle bowl with a choice of protein, fresh vegetables, and a light broth. Customizable to your spice and sauce preference.

Ingredients
- White Rice: 200 kcal, 0.5g fat
- Grilled Chicken: 250 kcal, 10g fat
- Spinach: 20 kcal, 0.2g fat
- Cucumber: 10 kcal, 0.1g fat',
        320.00,
        70,
        1,
        'structured',
        320.00,
        1
    ),
    (
        @branch3_id,
        @diet22_id,
        'Gluten-Free Salmon Roll',
        'A fresh salmon roll wrapped in nori and served with gluten-free soy sauce. Clean, simple, and satisfying.

Ingredients
- Seaweed: 30 kcal, 0.5g fat
- Salmon: 300 kcal, 18g fat
- Cucumber: 10 kcal, 0.1g fat
- Avocado: 160 kcal, 15g fat',
        390.00,
        20,
        0,
        'none',
        0.00,
        1
    ),
    (
        @branch3_id,
        @diet23_id,
        'Seaweed Sesame Salad',
        'A chilled seaweed salad tossed with sesame seeds and rice vinegar. Light, briny, and refreshing.

Ingredients
- Seaweed: 30 kcal, 0.5g fat
- Cucumber: 10 kcal, 0.1g fat
- Sesame Dressing: 60 kcal, 4g fat',
        160.00,
        15,
        0,
        'none',
        0.00,
        1
    ),
    (
        @branch3_id,
        @diet25_id,
        'Grilled Fish & Greens',
        'Grilled tilapia with seasonal vegetables and a light herb butter. Simple, clean, and halal-friendly.

Ingredients
- Salmon: 300 kcal, 18g fat
- Spinach: 20 kcal, 0.2g fat
- Cucumber: 10 kcal, 0.1g fat
- Avocado: 160 kcal, 15g fat',
        360.00,
        8,
        0,
        'none',
        0.00,
        1
    ),
    (
        @branch3_id,
        @diet28_id,
        'Osaka Rice Bowl',
        'A customizable Japanese rice bowl with a choice of protein and toppings. Comforting and easy to tailor.

Ingredients
- White Rice: 200 kcal, 0.5g fat
- Grilled Chicken: 250 kcal, 10g fat
- Spinach: 20 kcal, 0.2g fat
- Avocado: 160 kcal, 15g fat',
        300.00,
        85,
        1,
        'structured',
        300.00,
        1
    ),
    (
        @branch3_id,
        @diet29_id,
        'Wok-Tossed Vegetables',
        'A colorful stir fry of seasonal vegetables tossed in a light sauce. Customizable with protein and sauce choices.

Ingredients
- Cauliflower Rice: 50 kcal, 2g fat
- Spinach: 20 kcal, 0.2g fat
- Cucumber: 10 kcal, 0.1g fat
- Tomato: 15 kcal, 0.2g fat',
        280.00,
        90,
        1,
        'structured',
        280.00,
        1
    ),
    (
        @branch3_id,
        @diet27_id,
        'Spicy Tuna Roll',
        'A spicy tuna roll with cucumber and avocado, finished with a light chili glaze. Halal-friendly and gluten-free.

Ingredients
- Seaweed: 30 kcal, 0.5g fat
- Salmon: 300 kcal, 18g fat
- Cucumber: 10 kcal, 0.1g fat
- Avocado: 160 kcal, 15g fat',
        440.00,
        16,
        0,
        'none',
        0.00,
        1
    ),
    (
        @branch3_id,
        @diet30_id,
        'Matcha Banana Smoothie',
        'A refreshing green tea smoothie blended with banana and spinach. Lightly sweet and dairy-free.

Ingredients
- Spinach: 20 kcal, 0.2g fat
- Avocado: 160 kcal, 15g fat
- Cucumber: 10 kcal, 0.1g fat',
        200.00,
        14,
        0,
        'none',
        0.00,
        1
    );

SELECT MAX(product_id) INTO @last_product_asian FROM product;

SET @sushi_id = @last_product_asian - 9;

SET @poke_bowl_id = @last_product_asian - 8;

SET @noodle_bowl_id = @last_product_asian - 7;

SET @rice_bowl_id = @last_product_asian - 3;

SET @stir_fry_id = @last_product_asian - 2;

-- =====================================================
-- 8. PRODUCT COMPOSITION
-- =====================================================
-- Column order: product_id, ingredient_id, is_default, default_quantity,
--               max_quantity, price_modifier, display_order,
--               is_required, min_quantity, max_quantity_per_item
--
-- CONVENTIONS
--   Defaults carry price_modifier = 0.00. The base price already
--   includes them. Only non-defaults have meaningful modifiers.
--
--   Choice groups: only the default row has is_required = 1.
--   Alternatives have is_required = 0.
-- =====================================================

-- -----------------------------------------------------
-- Garden Harvest Bowl (product 1)
-- -----------------------------------------------------
INSERT INTO
    product_composition (
        product_id,
        ingredient_id,
        is_default,
        default_quantity,
        max_quantity,
        price_modifier,
        display_order,
        is_required,
        min_quantity,
        max_quantity_per_item
    )
VALUES
    -- Base choice: White Rice default
    (
        @bowl_product_id,
        @white_rice_id,
        1,
        1,
        1,
        0.00,
        1,
        1,
        1,
        1
    ),
    (
        @bowl_product_id,
        @brown_rice_id,
        0,
        0,
        1,
        5.00,
        1,
        0,
        0,
        1
    ),
    (
        @bowl_product_id,
        @cauliflower_rice_id,
        0,
        0,
        1,
        10.00,
        1,
        0,
        0,
        1
    ),
    (
        @bowl_product_id,
        @quinoa_id,
        0,
        0,
        1,
        15.00,
        1,
        0,
        0,
        1
    ),
    -- Protein choice: Grilled Chicken default
    (
        @bowl_product_id,
        @grilled_chicken_id,
        1,
        1,
        1,
        0.00,
        2,
        1,
        1,
        1
    ),
    (
        @bowl_product_id,
        @tofu_id,
        0,
        0,
        1,
        -10.00,
        2,
        0,
        0,
        1
    ),
    (
        @bowl_product_id,
        @beef_patty_id,
        0,
        0,
        1,
        15.00,
        2,
        0,
        0,
        1
    ),
    -- Vegetable modifiers
    (
        @bowl_product_id,
        @lettuce_id,
        0,
        0,
        4,
        0.00,
        3,
        0,
        0,
        4
    ),
    (
        @bowl_product_id,
        @tomato_id,
        0,
        0,
        4,
        0.00,
        4,
        0,
        0,
        4
    ),
    (
        @bowl_product_id,
        @avocado_id,
        0,
        0,
        2,
        15.00,
        5,
        0,
        0,
        2
    ),
    (
        @bowl_product_id,
        @spinach_id,
        0,
        0,
        4,
        0.00,
        6,
        0,
        0,
        4
    ),
    -- Sauce modifiers
    (
        @bowl_product_id,
        @bbq_sauce_id,
        0,
        0,
        1,
        0.00,
        7,
        0,
        0,
        1
    ),
    (
        @bowl_product_id,
        @vegan_ranch_id,
        0,
        0,
        1,
        2.00,
        8,
        0,
        0,
        1
    ),
    (
        @bowl_product_id,
        @miso_dressing_id,
        0,
        0,
        1,
        0.00,
        9,
        0,
        0,
        1
    );

-- -----------------------------------------------------
-- Market Greens Salad (product 2)
-- -----------------------------------------------------
INSERT INTO
    product_composition (
        product_id,
        ingredient_id,
        is_default,
        default_quantity,
        max_quantity,
        price_modifier,
        display_order,
        is_required,
        min_quantity,
        max_quantity_per_item
    )
VALUES
    -- Base choice: Lettuce default
    (
        @salad_product_id,
        @lettuce_id,
        1,
        1,
        1,
        0.00,
        1,
        1,
        1,
        1
    ),
    (
        @salad_product_id,
        @spinach_id,
        0,
        0,
        1,
        0.00,
        1,
        0,
        0,
        1
    ),
    -- Protein options
    (
        @salad_product_id,
        @grilled_chicken_id,
        0,
        0,
        2,
        20.00,
        2,
        0,
        0,
        2
    ),
    (
        @salad_product_id,
        @tofu_id,
        0,
        0,
        2,
        10.00,
        3,
        0,
        0,
        2
    ),
    (
        @salad_product_id,
        @salmon_id,
        0,
        0,
        2,
        30.00,
        4,
        0,
        0,
        2
    ),
    -- Vegetables
    (
        @salad_product_id,
        @tomato_id,
        0,
        0,
        5,
        0.00,
        5,
        0,
        0,
        5
    ),
    (
        @salad_product_id,
        @cucumber_id,
        0,
        0,
        5,
        0.00,
        6,
        0,
        0,
        5
    ),
    (
        @salad_product_id,
        @avocado_id,
        0,
        0,
        2,
        15.00,
        7,
        0,
        0,
        2
    ),
    -- Dressings
    (
        @salad_product_id,
        @ranch_dressing_id,
        0,
        0,
        1,
        0.00,
        8,
        0,
        0,
        1
    ),
    (
        @salad_product_id,
        @vegan_ranch_id,
        0,
        0,
        1,
        2.00,
        9,
        0,
        0,
        1
    ),
    (
        @salad_product_id,
        @sesame_dressing_id,
        0,
        0,
        1,
        0.00,
        10,
        0,
        0,
        1
    );

-- -----------------------------------------------------
-- Morning Power Smoothie (product 3)
-- -----------------------------------------------------
INSERT INTO
    product_composition (
        product_id,
        ingredient_id,
        is_default,
        default_quantity,
        max_quantity,
        price_modifier,
        display_order,
        is_required,
        min_quantity,
        max_quantity_per_item
    )
VALUES
    -- Base choice: White Rice default
    (
        @smoothie_product_id,
        @white_rice_id,
        1,
        1,
        1,
        0.00,
        1,
        1,
        1,
        1
    ),
    (
        @smoothie_product_id,
        @brown_rice_id,
        0,
        0,
        1,
        5.00,
        1,
        0,
        0,
        1
    ),
    -- Mix-ins
    (
        @smoothie_product_id,
        @avocado_id,
        0,
        0,
        3,
        5.00,
        2,
        0,
        0,
        3
    ),
    (
        @smoothie_product_id,
        @cucumber_id,
        0,
        0,
        3,
        0.00,
        3,
        0,
        0,
        3
    ),
    -- Protein add-ons
    (
        @smoothie_product_id,
        @tofu_id,
        0,
        0,
        2,
        10.00,
        4,
        0,
        0,
        2
    ),
    (
        @smoothie_product_id,
        @eggs_id,
        0,
        0,
        2,
        15.00,
        5,
        0,
        0,
        2
    );

-- -----------------------------------------------------
-- Sunrise Breakfast Bowl (product 7)
-- -----------------------------------------------------
INSERT INTO
    product_composition (
        product_id,
        ingredient_id,
        is_default,
        default_quantity,
        max_quantity,
        price_modifier,
        display_order,
        is_required,
        min_quantity,
        max_quantity_per_item
    )
VALUES
    -- Base choice: Quinoa default
    (
        @breakfast_bowl_id,
        @quinoa_id,
        1,
        1,
        1,
        0.00,
        1,
        1,
        1,
        1
    ),
    (
        @breakfast_bowl_id,
        @cauliflower_rice_id,
        0,
        0,
        1,
        5.00,
        1,
        0,
        0,
        1
    ),
    -- Required modifier: Eggs (1 to 2)
    (
        @breakfast_bowl_id,
        @eggs_id,
        1,
        1,
        2,
        0.00,
        2,
        1,
        1,
        2
    ),
    -- Vegetables
    (
        @breakfast_bowl_id,
        @spinach_id,
        0,
        0,
        3,
        0.00,
        3,
        0,
        0,
        3
    ),
    (
        @breakfast_bowl_id,
        @tomato_id,
        0,
        0,
        3,
        0.00,
        4,
        0,
        0,
        3
    ),
    -- Cheeses
    (
        @breakfast_bowl_id,
        @cheddar_cheese_id,
        0,
        0,
        1,
        10.00,
        5,
        0,
        0,
        1
    ),
    (
        @breakfast_bowl_id,
        @vegan_cheese_id,
        0,
        0,
        1,
        10.00,
        6,
        0,
        0,
        1
    );

-- -----------------------------------------------------
-- Seaside Poke Bowl (product 8)
-- -----------------------------------------------------
INSERT INTO
    product_composition (
        product_id,
        ingredient_id,
        is_default,
        default_quantity,
        max_quantity,
        price_modifier,
        display_order,
        is_required,
        min_quantity,
        max_quantity_per_item
    )
VALUES
    -- Base choice: White Rice default
    (
        @poke_product_id,
        @white_rice_id,
        1,
        1,
        1,
        0.00,
        1,
        1,
        1,
        1
    ),
    (
        @poke_product_id,
        @brown_rice_id,
        0,
        0,
        1,
        5.00,
        1,
        0,
        0,
        1
    ),
    (
        @poke_product_id,
        @quinoa_id,
        0,
        0,
        1,
        10.00,
        1,
        0,
        0,
        1
    ),
    -- Protein choice: Salmon default
    (
        @poke_product_id,
        @salmon_id,
        1,
        1,
        1,
        0.00,
        2,
        1,
        1,
        1
    ),
    (
        @poke_product_id,
        @tofu_id,
        0,
        0,
        1,
        -20.00,
        2,
        0,
        0,
        1
    ),
    (
        @poke_product_id,
        @shrimp_id,
        0,
        0,
        1,
        15.00,
        2,
        0,
        0,
        1
    ),
    -- Toppings
    (
        @poke_product_id,
        @cucumber_id,
        0,
        0,
        4,
        0.00,
        3,
        0,
        0,
        4
    ),
    (
        @poke_product_id,
        @avocado_id,
        0,
        0,
        4,
        10.00,
        4,
        0,
        0,
        4
    ),
    -- Sauces
    (
        @poke_product_id,
        @teriyaki_sauce_id,
        0,
        0,
        1,
        0.00,
        5,
        0,
        0,
        1
    ),
    (
        @poke_product_id,
        @sesame_dressing_id,
        0,
        0,
        1,
        0.00,
        6,
        0,
        0,
        1
    );

-- -----------------------------------------------------
-- Keto Power Bowl (product 11)
-- -----------------------------------------------------
INSERT INTO
    product_composition (
        product_id,
        ingredient_id,
        is_default,
        default_quantity,
        max_quantity,
        price_modifier,
        display_order,
        is_required,
        min_quantity,
        max_quantity_per_item
    )
VALUES
    -- Base choice: Cauliflower Rice default
    (
        @keto_bowl_id,
        @cauliflower_rice_id,
        1,
        1,
        1,
        0.00,
        1,
        1,
        1,
        1
    ),
    -- Protein choice: Grilled Chicken default
    (
        @keto_bowl_id,
        @grilled_chicken_id,
        1,
        1,
        1,
        0.00,
        2,
        1,
        1,
        1
    ),
    (
        @keto_bowl_id,
        @salmon_id,
        0,
        0,
        1,
        25.00,
        2,
        0,
        0,
        1
    ),
    (
        @keto_bowl_id,
        @beef_patty_id,
        0,
        0,
        1,
        15.00,
        2,
        0,
        0,
        1
    ),
    -- Optional eggs
    (
        @keto_bowl_id,
        @eggs_id,
        0,
        0,
        1,
        10.00,
        3,
        0,
        0,
        1
    ),
    -- Healthy fats
    (
        @keto_bowl_id,
        @avocado_id,
        1,
        1,
        2,
        0.00,
        4,
        0,
        0,
        2
    ),
    (
        @keto_bowl_id,
        @cheddar_cheese_id,
        0,
        0,
        2,
        10.00,
        5,
        0,
        0,
        2
    ),
    -- Vegetables
    (
        @keto_bowl_id,
        @spinach_id,
        1,
        1,
        3,
        0.00,
        6,
        0,
        0,
        3
    ),
    (
        @keto_bowl_id,
        @cucumber_id,
        0,
        0,
        3,
        0.00,
        7,
        0,
        0,
        3
    );

-- -----------------------------------------------------
-- Keto Smash Burger (product 12)
-- -----------------------------------------------------
INSERT INTO
    product_composition (
        product_id,
        ingredient_id,
        is_default,
        default_quantity,
        max_quantity,
        price_modifier,
        display_order,
        is_required,
        min_quantity,
        max_quantity_per_item
    )
VALUES
    -- Protein choice: Beef Patty default
    (
        @keto_burger_id,
        @beef_patty_id,
        1,
        1,
        1,
        0.00,
        1,
        1,
        1,
        1
    ),
    (
        @keto_burger_id,
        @grilled_chicken_id,
        0,
        0,
        1,
        0.00,
        1,
        0,
        0,
        1
    ),
    -- Cheese: Cheddar default
    (
        @keto_burger_id,
        @cheddar_cheese_id,
        1,
        1,
        1,
        0.00,
        2,
        0,
        0,
        1
    ),
    (
        @keto_burger_id,
        @vegan_cheese_id,
        0,
        0,
        1,
        10.00,
        3,
        0,
        0,
        1
    ),
    -- Toppings
    (
        @keto_burger_id,
        @lettuce_id,
        1,
        1,
        3,
        0.00,
        4,
        0,
        0,
        3
    ),
    (
        @keto_burger_id,
        @tomato_id,
        0,
        0,
        3,
        0.00,
        5,
        0,
        0,
        3
    ),
    (
        @keto_burger_id,
        @avocado_id,
        0,
        0,
        2,
        15.00,
        6,
        0,
        0,
        2
    );

-- -----------------------------------------------------
-- Keto Garden Salad (product 13)
-- -----------------------------------------------------
INSERT INTO
    product_composition (
        product_id,
        ingredient_id,
        is_default,
        default_quantity,
        max_quantity,
        price_modifier,
        display_order,
        is_required,
        min_quantity,
        max_quantity_per_item
    )
VALUES
    -- Base choice: Spinach default
    (
        @keto_salad_id,
        @spinach_id,
        1,
        1,
        1,
        0.00,
        1,
        1,
        1,
        1
    ),
    (
        @keto_salad_id,
        @lettuce_id,
        0,
        0,
        1,
        0.00,
        1,
        0,
        0,
        1
    ),
    -- Protein choice: Salmon default
    (
        @keto_salad_id,
        @salmon_id,
        1,
        1,
        1,
        0.00,
        2,
        1,
        1,
        1
    ),
    (
        @keto_salad_id,
        @grilled_chicken_id,
        0,
        0,
        1,
        -10.00,
        2,
        0,
        0,
        1
    ),
    -- Vegetables
    (
        @keto_salad_id,
        @cucumber_id,
        0,
        0,
        3,
        0.00,
        3,
        0,
        0,
        3
    ),
    (
        @keto_salad_id,
        @avocado_id,
        0,
        0,
        2,
        15.00,
        4,
        0,
        0,
        2
    ),
    -- Dressings
    (
        @keto_salad_id,
        @ranch_dressing_id,
        0,
        0,
        1,
        0.00,
        5,
        0,
        0,
        1
    ),
    (
        @keto_salad_id,
        @sesame_dressing_id,
        0,
        0,
        1,
        0.00,
        6,
        0,
        0,
        1
    );

-- -----------------------------------------------------
-- Keto Butcher Plate (product 17)
-- -----------------------------------------------------
INSERT INTO
    product_composition (
        product_id,
        ingredient_id,
        is_default,
        default_quantity,
        max_quantity,
        price_modifier,
        display_order,
        is_required,
        min_quantity,
        max_quantity_per_item
    )
VALUES
    -- Protein choice: Beef Patty default
    (
        @keto_plate_id,
        @beef_patty_id,
        1,
        1,
        1,
        0.00,
        1,
        1,
        1,
        1
    ),
    (
        @keto_plate_id,
        @grilled_chicken_id,
        0,
        0,
        1,
        0.00,
        1,
        0,
        0,
        1
    ),
    (
        @keto_plate_id,
        @salmon_id,
        0,
        0,
        1,
        20.00,
        1,
        0,
        0,
        1
    ),
    -- Sides
    (
        @keto_plate_id,
        @cauliflower_rice_id,
        1,
        1,
        2,
        0.00,
        2,
        0,
        0,
        2
    ),
    (
        @keto_plate_id,
        @eggs_id,
        0,
        0,
        2,
        10.00,
        3,
        0,
        0,
        2
    ),
    -- Vegetables
    (
        @keto_plate_id,
        @spinach_id,
        1,
        1,
        3,
        0.00,
        4,
        0,
        0,
        3
    ),
    (
        @keto_plate_id,
        @cucumber_id,
        0,
        0,
        3,
        0.00,
        5,
        0,
        0,
        3
    );

-- -----------------------------------------------------
-- Keto Cauliflower Pizza (product 18)
-- -----------------------------------------------------
INSERT INTO
    product_composition (
        product_id,
        ingredient_id,
        is_default,
        default_quantity,
        max_quantity,
        price_modifier,
        display_order,
        is_required,
        min_quantity,
        max_quantity_per_item
    )
VALUES
    -- Crust choice: Cauliflower Rice default
    (
        @keto_pizza_id,
        @cauliflower_rice_id,
        1,
        1,
        1,
        0.00,
        1,
        1,
        1,
        1
    ),
    -- Required cheese modifiers
    (
        @keto_pizza_id,
        @cheddar_cheese_id,
        1,
        1,
        2,
        0.00,
        2,
        1,
        1,
        2
    ),
    (
        @keto_pizza_id,
        @parmesan_cheese_id,
        1,
        1,
        2,
        0.00,
        3,
        1,
        1,
        2
    ),
    -- Default topping
    (
        @keto_pizza_id,
        @grilled_chicken_id,
        1,
        1,
        3,
        0.00,
        4,
        0,
        0,
        3
    ),
    -- Optional toppings
    (
        @keto_pizza_id,
        @beef_patty_id,
        0,
        0,
        3,
        15.00,
        5,
        0,
        0,
        3
    ),
    (
        @keto_pizza_id,
        @spinach_id,
        0,
        0,
        3,
        0.00,
        6,
        0,
        0,
        3
    ),
    (
        @keto_pizza_id,
        @tomato_id,
        0,
        0,
        3,
        0.00,
        7,
        0,
        0,
        3
    );

-- -----------------------------------------------------
-- Nori Hand Roll Set (product 21)
-- -----------------------------------------------------
INSERT INTO
    product_composition (
        product_id,
        ingredient_id,
        is_default,
        default_quantity,
        max_quantity,
        price_modifier,
        display_order,
        is_required,
        min_quantity,
        max_quantity_per_item
    )
VALUES
    -- Unchangeable wrap: Seaweed
    (
        @sushi_id,
        @seaweed_id,
        1,
        1,
        1,
        0.00,
        1,
        1,
        1,
        1
    ),
    -- Protein choice: Salmon default
    (
        @sushi_id,
        @salmon_id,
        1,
        1,
        1,
        0.00,
        2,
        1,
        1,
        1
    ),
    (
        @sushi_id,
        @tofu_id,
        0,
        0,
        1,
        -10.00,
        2,
        0,
        0,
        1
    ),
    (
        @sushi_id,
        @shrimp_id,
        0,
        0,
        1,
        20.00,
        2,
        0,
        0,
        1
    ),
    -- Fillings
    (
        @sushi_id,
        @cucumber_id,
        0,
        0,
        4,
        0.00,
        3,
        0,
        0,
        4
    ),
    (
        @sushi_id,
        @avocado_id,
        0,
        0,
        4,
        10.00,
        4,
        0,
        0,
        4
    ),
    -- Sauces
    (
        @sushi_id,
        @teriyaki_sauce_id,
        0,
        0,
        1,
        0.00,
        5,
        0,
        0,
        1
    ),
    (
        @sushi_id,
        @sesame_dressing_id,
        0,
        0,
        1,
        0.00,
        6,
        0,
        0,
        1
    );

-- -----------------------------------------------------
-- Rainbow Poke Bowl (product 22)
-- -----------------------------------------------------
INSERT INTO
    product_composition (
        product_id,
        ingredient_id,
        is_default,
        default_quantity,
        max_quantity,
        price_modifier,
        display_order,
        is_required,
        min_quantity,
        max_quantity_per_item
    )
VALUES
    -- Base choice: White Rice default
    (
        @poke_bowl_id,
        @white_rice_id,
        1,
        1,
        1,
        0.00,
        1,
        1,
        1,
        1
    ),
    (
        @poke_bowl_id,
        @brown_rice_id,
        0,
        0,
        1,
        5.00,
        1,
        0,
        0,
        1
    ),
    (
        @poke_bowl_id,
        @quinoa_id,
        0,
        0,
        1,
        10.00,
        1,
        0,
        0,
        1
    ),
    -- Protein choice: Salmon default
    (
        @poke_bowl_id,
        @salmon_id,
        1,
        1,
        1,
        0.00,
        2,
        1,
        1,
        1
    ),
    (
        @poke_bowl_id,
        @shrimp_id,
        0,
        0,
        1,
        10.00,
        2,
        0,
        0,
        1
    ),
    (
        @poke_bowl_id,
        @tofu_id,
        0,
        0,
        1,
        -15.00,
        2,
        0,
        0,
        1
    ),
    -- Toppings
    (
        @poke_bowl_id,
        @cucumber_id,
        0,
        0,
        4,
        0.00,
        3,
        0,
        0,
        4
    ),
    (
        @poke_bowl_id,
        @avocado_id,
        0,
        0,
        4,
        10.00,
        4,
        0,
        0,
        4
    ),
    (
        @poke_bowl_id,
        @seaweed_id,
        0,
        0,
        4,
        5.00,
        5,
        0,
        0,
        4
    ),
    -- Sauces
    (
        @poke_bowl_id,
        @teriyaki_sauce_id,
        0,
        0,
        1,
        0.00,
        6,
        0,
        0,
        1
    ),
    (
        @poke_bowl_id,
        @sesame_dressing_id,
        0,
        0,
        1,
        0.00,
        7,
        0,
        0,
        1
    );

-- -----------------------------------------------------
-- Tokyo Noodle Bowl (product 23)
-- -----------------------------------------------------
INSERT INTO
    product_composition (
        product_id,
        ingredient_id,
        is_default,
        default_quantity,
        max_quantity,
        price_modifier,
        display_order,
        is_required,
        min_quantity,
        max_quantity_per_item
    )
VALUES
    -- Base choice: White Rice default
    (
        @noodle_bowl_id,
        @white_rice_id,
        1,
        1,
        1,
        0.00,
        1,
        1,
        1,
        1
    ),
    (
        @noodle_bowl_id,
        @brown_rice_id,
        0,
        0,
        1,
        5.00,
        1,
        0,
        0,
        1
    ),
    -- Protein choice: Grilled Chicken default
    (
        @noodle_bowl_id,
        @grilled_chicken_id,
        1,
        1,
        1,
        0.00,
        2,
        1,
        1,
        1
    ),
    (
        @noodle_bowl_id,
        @tofu_id,
        0,
        0,
        1,
        -10.00,
        2,
        0,
        0,
        1
    ),
    (
        @noodle_bowl_id,
        @shrimp_id,
        0,
        0,
        1,
        15.00,
        2,
        0,
        0,
        1
    ),
    -- Vegetables
    (
        @noodle_bowl_id,
        @spinach_id,
        1,
        1,
        3,
        0.00,
        3,
        0,
        0,
        3
    ),
    (
        @noodle_bowl_id,
        @cucumber_id,
        0,
        0,
        3,
        0.00,
        4,
        0,
        0,
        3
    ),
    -- Sauces
    (
        @noodle_bowl_id,
        @teriyaki_sauce_id,
        0,
        0,
        1,
        0.00,
        5,
        0,
        0,
        1
    ),
    (
        @noodle_bowl_id,
        @miso_dressing_id,
        0,
        0,
        1,
        0.00,
        6,
        0,
        0,
        1
    );

-- -----------------------------------------------------
-- Osaka Rice Bowl (product 27)
-- -----------------------------------------------------
INSERT INTO
    product_composition (
        product_id,
        ingredient_id,
        is_default,
        default_quantity,
        max_quantity,
        price_modifier,
        display_order,
        is_required,
        min_quantity,
        max_quantity_per_item
    )
VALUES
    -- Base choice: White Rice default
    (
        @rice_bowl_id,
        @white_rice_id,
        1,
        1,
        1,
        0.00,
        1,
        1,
        1,
        1
    ),
    (
        @rice_bowl_id,
        @brown_rice_id,
        0,
        0,
        1,
        5.00,
        1,
        0,
        0,
        1
    ),
    (
        @rice_bowl_id,
        @cauliflower_rice_id,
        0,
        0,
        1,
        10.00,
        1,
        0,
        0,
        1
    ),
    -- Protein choice: Grilled Chicken default
    (
        @rice_bowl_id,
        @grilled_chicken_id,
        1,
        1,
        1,
        0.00,
        2,
        1,
        1,
        1
    ),
    (
        @rice_bowl_id,
        @tofu_id,
        0,
        0,
        1,
        -10.00,
        2,
        0,
        0,
        1
    ),
    (
        @rice_bowl_id,
        @beef_patty_id,
        0,
        0,
        1,
        15.00,
        2,
        0,
        0,
        1
    ),
    -- Vegetables
    (
        @rice_bowl_id,
        @spinach_id,
        1,
        1,
        3,
        0.00,
        3,
        0,
        0,
        3
    ),
    (
        @rice_bowl_id,
        @cucumber_id,
        0,
        0,
        3,
        0.00,
        4,
        0,
        0,
        3
    ),
    (
        @rice_bowl_id,
        @avocado_id,
        0,
        0,
        2,
        10.00,
        5,
        0,
        0,
        2
    );

-- -----------------------------------------------------
-- Wok-Tossed Vegetables (product 28)
-- -----------------------------------------------------
INSERT INTO
    product_composition (
        product_id,
        ingredient_id,
        is_default,
        default_quantity,
        max_quantity,
        price_modifier,
        display_order,
        is_required,
        min_quantity,
        max_quantity_per_item
    )
VALUES
    -- Base choice: Cauliflower Rice default
    (
        @stir_fry_id,
        @cauliflower_rice_id,
        1,
        1,
        1,
        0.00,
        1,
        1,
        1,
        1
    ),
    (
        @stir_fry_id,
        @quinoa_id,
        0,
        0,
        1,
        5.00,
        1,
        0,
        0,
        1
    ),
    -- Vegetables
    (
        @stir_fry_id,
        @spinach_id,
        1,
        1,
        5,
        0.00,
        2,
        0,
        0,
        5
    ),
    (
        @stir_fry_id,
        @cucumber_id,
        0,
        0,
        5,
        0.00,
        3,
        0,
        0,
        5
    ),
    (
        @stir_fry_id,
        @tomato_id,
        0,
        0,
        5,
        0.00,
        4,
        0,
        0,
        5
    ),
    -- Sauces
    (
        @stir_fry_id,
        @teriyaki_sauce_id,
        0,
        0,
        1,
        0.00,
        5,
        0,
        0,
        1
    ),
    (
        @stir_fry_id,
        @miso_dressing_id,
        0,
        0,
        1,
        0.00,
        6,
        0,
        0,
        1
    );

-- =====================================================
-- 9. DERIVE dietary_information.calories FOR CUSTOMIZABLE PRODUCTS
-- =====================================================
UPDATE dietary_information di
JOIN (
    SELECT p.dietary_information_id, SUM(
            i.calories * GREATEST(
                pc.default_quantity, pc.min_quantity
            )
        ) AS total_calories
    FROM
        product p
        JOIN product_composition pc ON pc.product_id = p.product_id
        JOIN ingredient i ON i.ingredient_id = pc.ingredient_id
    WHERE
        p.is_customizable = 1
        AND pc.is_default = 1
    GROUP BY
        p.dietary_information_id
) AS calc ON calc.dietary_information_id = di.dietary_information_id
SET
    di.calories = calc.total_calories;

-- =====================================================
-- 10. ENFORCE BASE PRICE = PRODUCT PRICE
-- =====================================================
UPDATE product
SET
    base_price = price
WHERE
    base_price IS NULL
    OR base_price = 0;

COMMIT;

-- =====================================================
-- VERIFICATION
-- =====================================================
SELECT '=== Seed data loaded ===' AS status;

-- Every customizable product must have sum of default price modifiers = 0.
SELECT
    p.name AS product_name,
    p.price AS product_price,
    (
        SELECT COALESCE(
                SUM(
                    pc.price_modifier * pc.default_quantity
                ), 0
            )
        FROM product_composition pc
        WHERE
            pc.product_id = p.product_id
            AND pc.is_default = 1
    ) AS sum_of_default_price_mods
FROM product p
WHERE
    p.is_customizable = 1
ORDER BY p.name;

-- Every customizable product must have stored calories equal to derived.
SELECT
    p.name AS product_name,
    di.calories AS stored_calories,
    (
        SELECT COALESCE(
                SUM(
                    i.calories * GREATEST(
                        pc.default_quantity, pc.min_quantity
                    )
                ), 0
            )
        FROM
            product_composition pc
            JOIN ingredient i ON i.ingredient_id = pc.ingredient_id
        WHERE
            pc.product_id = p.product_id
            AND pc.is_default = 1
    ) AS derived_calories
FROM
    product p
    JOIN dietary_information di ON di.dietary_information_id = p.dietary_information_id
WHERE
    p.is_customizable = 1
ORDER BY p.name;

-- Every choice group must have at most one is_required = 1 row.
SELECT
    p.name AS product_name,
    pc.display_order,
    SUM(
        CASE
            WHEN pc.is_required = 1 THEN 1
            ELSE 0
        END
    ) AS required_count
FROM
    product_composition pc
    JOIN product p ON p.product_id = pc.product_id
WHERE
    pc.max_quantity_per_item = 1
GROUP BY
    p.product_id,
    p.name,
    pc.display_order
HAVING
    required_count > 1;

-- Every modifier row must have min_quantity <= default_quantity <= max_quantity.
SELECT
    p.name AS product_name,
    i.name AS ingredient_name,
    pc.min_quantity,
    pc.default_quantity,
    pc.max_quantity
FROM
    product_composition pc
    JOIN product p ON p.product_id = pc.product_id
    JOIN ingredient i ON i.ingredient_id = pc.ingredient_id
WHERE
    pc.min_quantity > pc.default_quantity
    OR pc.default_quantity > pc.max_quantity;