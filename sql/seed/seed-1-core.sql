-- =====================================================
-- FitPal Seed Data — 1 of 3: CORE
-- Version 6.0
--
-- ALIGNED WITH: fitpal_food_delivery schema v1.3.1
--
-- RUN ORDER (strict):
--   1. seed-1-core.sql      ← this file
--   2. seed-2-content.sql   (descriptions & copy)
--   3. seed-3-paths.sql     (profile pictures, permits, documents)
--
-- RESPONSIBILITY
-- --------------
-- Everything that has to exist for the schema to be internally
-- consistent and for the application to function:
--   * financial accounts
--   * administrator + profile
--   * customer + profile + address
--   * restaurants + branches + restaurant accounts
--   * delivery riders + profiles + addresses + emergency contacts
--   * dietary_information (macros)
--   * ingredients
--   * products
--   * product_composition
--
-- Deliberately LEFT NULL (back-filled by seed 2 / seed 3):
--   administrator_profile.profile_picture      → seed 3
--   customer_profile.profile_picture           → seed 3
--   delivery_rider_profile.profile_picture     → seed 3
--   restaurant.description                     → seed 2
--   product.description                        → seed 2
--   dietary_information.images                 → seed 3 (optional)
--
-- Deliberately OMITTED (inserted by seed 3):
--   restaurant_permit         (9 rows)
--   delivery_rider_document   (3 rows)
--
-- PRINCIPLES
--   1.  Every account gets at least one address row.
--   2.  All FK variables captured via LAST_INSERT_ID().
--   3.  Passwords stored as PLAINTEXT for the demo. DO NOT use
--       in production.
--   4.  dietary_information.calories for customizable products
--       is DERIVED at the end (Section 9).
--   5.  Every choice group has exactly one is_required = 1 row.
--   6.  Every rider gets one emergency contact here; the identity
--       document row arrives in seed 3. After the FULL suite
--       runs, every rider has exactly one document row.
--   7.  Every restaurant gets three permit rows in seed 3.
--   8.  NO seeded money. NO rider pre-marked available.
--   9.  Product descriptions are empty here and filled in seed 2.
--  10.  Bicycle riders submit a non-driver's-license ID (seed 3).
--  11.  No rider is pre-seeded with more than 3 orders across
--       'rider_pending' + 'picking_up' + 'delivering'. The seed
--       inserts zero live orders, so the cap is trivially met.
--  12.  Every rider document points at a DISTINCT manifest file
--       (enforced in seed 3 by V20).
--
-- PREREQUISITE
-- ------------
-- None. This file only needs the schema loaded.
-- =====================================================

USE fitpal_food_delivery;

START TRANSACTION;

-- =====================================================
-- 1. FINANCIAL ACCOUNTS  (all zero balances)
-- =====================================================
INSERT INTO
    financial_account (balance, account_type)
VALUES (0.00, 'customer');

SET @customer_financial_id = LAST_INSERT_ID();

INSERT INTO
    financial_account (balance, account_type)
VALUES (0.00, 'restaurant');

SET @branch1_financial_id = LAST_INSERT_ID();

INSERT INTO
    financial_account (balance, account_type)
VALUES (0.00, 'restaurant');

SET @branch2_financial_id = LAST_INSERT_ID();

INSERT INTO
    financial_account (balance, account_type)
VALUES (0.00, 'restaurant');

SET @branch3_financial_id = LAST_INSERT_ID();

INSERT INTO
    financial_account (balance, account_type)
VALUES (0.00, 'rider');

SET @rider1_financial_id = LAST_INSERT_ID();

INSERT INTO
    financial_account (balance, account_type)
VALUES (0.00, 'rider');

SET @rider2_financial_id = LAST_INSERT_ID();

INSERT INTO
    financial_account (balance, account_type)
VALUES (0.00, 'rider');

SET @rider3_financial_id = LAST_INSERT_ID();

-- =====================================================
-- 2. ADMINISTRATOR  (profile_picture NULL until seed 3)
-- =====================================================
INSERT INTO
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

INSERT INTO
    administrator_profile (
        administrator_id,
        role,
        is_active
    )
VALUES (@admin_id, 'super_admin', 1);

-- =====================================================
-- 3. CUSTOMER + PROFILE + ADDRESS
-- =====================================================
INSERT INTO
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
        'Peter',
        NULL,
        'Parker',
        'user@example.com',
        '09123456789',
        'user',
        'user123',
        1
    );

SET @customer_id = LAST_INSERT_ID();

INSERT INTO
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

INSERT INTO
    customer_address (
        customer_id,
        label,
        block,
        barangay,
        city,
        province,
        region,
        postal_code,
        country,
        is_default
    )
VALUES (
        @customer_id,
        'Home',
        '12-A Sunrise St.',
        'Barangay San Antonio',
        'Pasig',
        'Metro Manila',
        'NCR',
        '1605',
        'Philippines',
        1
    );

-- =====================================================
-- 4. RESTAURANTS, BRANCHES, ACCOUNTS
--    (restaurant.description NULL until seed 2)
-- =====================================================

-- -----------------------------------------------------
-- 4.1 Green Bowl Cafe
-- -----------------------------------------------------
INSERT INTO
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
        NULL,
        'Cafe',
        'vegan,organic,gluten_free',
        'verified',
        @admin_id,
        CURRENT_TIMESTAMP,
        1
    );

SET @rest1_id = LAST_INSERT_ID();

INSERT INTO
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

INSERT INTO
    restaurant_account (
        restaurant_id,
        branch_id,
        first_name,
        middle_name,
        last_name,
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
        'owner@greenbowl.ph',
        '09171111111',
        'greenbowl_owner',
        'owner123',
        'owner',
        1
    );

INSERT INTO
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
    );

INSERT INTO
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
-- 4.2 Keto Kitchen
-- -----------------------------------------------------
INSERT INTO
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
        NULL,
        'American',
        'keto,high_protein,low_carb',
        'verified',
        @admin_id,
        CURRENT_TIMESTAMP,
        1
    );

SET @rest2_id = LAST_INSERT_ID();

INSERT INTO
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

INSERT INTO
    restaurant_account (
        restaurant_id,
        branch_id,
        first_name,
        middle_name,
        last_name,
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
        'owner@ketokitchen.ph',
        '09172222222',
        'keto_owner',
        'owner123',
        'owner',
        1
    );

INSERT INTO
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
    );

INSERT INTO
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
-- 4.3 Asian Fusion Fit
-- -----------------------------------------------------
INSERT INTO
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
        NULL,
        'Asian Fusion',
        'gluten_free,low_carb,halal',
        'verified',
        @admin_id,
        CURRENT_TIMESTAMP,
        1
    );

SET @rest3_id = LAST_INSERT_ID();

INSERT INTO
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

INSERT INTO
    restaurant_account (
        restaurant_id,
        branch_id,
        first_name,
        middle_name,
        last_name,
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
        'owner@asianfusionfit.ph',
        '09173333333',
        'aff_owner',
        'owner123',
        'owner',
        1
    );

INSERT INTO
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
    );

INSERT INTO
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

-- =====================================================
-- 5. DELIVERY RIDERS + PROFILES + ADDRESSES + EMERGENCY CONTACTS
--    (profile_picture NULL until seed 3)
--    (identity documents inserted in seed 3)
-- =====================================================

-- -----------------------------------------------------
-- Rider 1: Carlos — motorcycle, verified, not available
-- -----------------------------------------------------
INSERT INTO
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

INSERT INTO
    delivery_rider_profile (
        delivery_rider_id,
        financial_account_id,
        profile_picture,
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
        NULL,
        'motorcycle',
        'ABC1234',
        'verified',
        @admin_id,
        CURRENT_TIMESTAMP,
        0.0,
        0,
        0
    );

INSERT INTO
    delivery_rider_address (
        delivery_rider_id,
        label,
        block,
        barangay,
        city,
        province,
        region,
        postal_code,
        country,
        is_default
    )
VALUES (
        @rider1_id,
        'Base',
        '88 Rider Hub',
        'Barangay Poblacion',
        'Makati',
        'Metro Manila',
        'NCR',
        '1210',
        'Philippines',
        1
    );

INSERT INTO
    delivery_rider_emergency_contact (
        delivery_rider_id,
        first_name,
        middle_name,
        last_name,
        contact_number,
        relationship,
        address
    )
VALUES (
        @rider1_id,
        'Maria',
        NULL,
        'Dela Cruz',
        '09171112222',
        'Spouse',
        '88 Rider Hub, Barangay Poblacion, Makati, Metro Manila'
    );

-- -----------------------------------------------------
-- Rider 2: Miguel — car, verified, not available
-- -----------------------------------------------------
INSERT INTO
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

INSERT INTO
    delivery_rider_profile (
        delivery_rider_id,
        financial_account_id,
        profile_picture,
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
        NULL,
        'car',
        'XYZ5678',
        'verified',
        @admin_id,
        CURRENT_TIMESTAMP,
        0.0,
        0,
        0
    );

INSERT INTO
    delivery_rider_address (
        delivery_rider_id,
        label,
        block,
        barangay,
        city,
        province,
        region,
        postal_code,
        country,
        is_default
    )
VALUES (
        @rider2_id,
        'Home',
        '22-D Mabini Ave.',
        'Barangay Bel-Air',
        'Makati',
        'Metro Manila',
        'NCR',
        '1209',
        'Philippines',
        1
    );

INSERT INTO
    delivery_rider_emergency_contact (
        delivery_rider_id,
        first_name,
        middle_name,
        last_name,
        contact_number,
        relationship,
        address
    )
VALUES (
        @rider2_id,
        'Jose',
        NULL,
        'Santos',
        '09182223333',
        'Father',
        '22-D Mabini Ave., Barangay Bel-Air, Makati, Metro Manila'
    );

-- -----------------------------------------------------
-- Rider 3: Andrei — bicycle, pending, not available
-- -----------------------------------------------------
INSERT INTO
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

INSERT INTO
    delivery_rider_profile (
        delivery_rider_id,
        financial_account_id,
        profile_picture,
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
        NULL,
        'bicycle',
        NULL,
        'pending',
        NULL,
        NULL,
        0.0,
        0,
        0
    );

INSERT INTO
    delivery_rider_address (
        delivery_rider_id,
        label,
        block,
        barangay,
        city,
        province,
        region,
        postal_code,
        country,
        is_default
    )
VALUES (
        @rider3_id,
        'Home',
        '5-B Luna St.',
        'Barangay Kamuning',
        'Quezon City',
        'Metro Manila',
        'NCR',
        '1103',
        'Philippines',
        1
    );

INSERT INTO
    delivery_rider_emergency_contact (
        delivery_rider_id,
        first_name,
        middle_name,
        last_name,
        contact_number,
        relationship,
        address
    )
VALUES (
        @rider3_id,
        'Lourdes',
        NULL,
        'Fernandez',
        '09183334444',
        'Mother',
        '5-B Luna St., Barangay Kamuning, Quezon City, Metro Manila'
    );

-- =====================================================
-- 6. DIETARY INFORMATION  (macros only; images NULL)
-- =====================================================

-- Green Bowl Cafe — 10 rows
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
    );

SET @diet1_id = LAST_INSERT_ID();

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
        'vegan,gluten_free,low_carb',
        'none',
        320,
        14.0,
        42.0,
        10.0
    );

SET @diet2_id = LAST_INSERT_ID();

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
        'Appetizer',
        'vegan,gluten_free',
        'soy',
        150,
        6.0,
        18.0,
        5.0
    );

SET @diet3_id = LAST_INSERT_ID();

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
        'vegan,gluten_free,low_carb',
        'nuts',
        290,
        18.0,
        22.0,
        14.0
    );

SET @diet4_id = LAST_INSERT_ID();

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
        'Appetizer',
        'vegan,gluten_free,low_carb',
        'none',
        120,
        4.0,
        15.0,
        4.0
    );

SET @diet5_id = LAST_INSERT_ID();

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
        'vegan,gluten_free,low_carb,organic',
        'soy',
        340,
        16.0,
        28.0,
        16.0
    );

SET @diet6_id = LAST_INSERT_ID();

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
        'Beverage',
        'vegan,gluten_free,low_carb,keto',
        'nuts',
        95,
        1.0,
        8.0,
        6.0
    );

SET @diet7_id = LAST_INSERT_ID();

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
        'vegan,gluten_free,low_carb,organic,high_protein',
        'soy,nuts',
        420,
        32.0,
        18.0,
        22.0
    );

SET @diet8_id = LAST_INSERT_ID();

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
        'Dessert',
        'vegan,gluten_free,low_carb,keto,organic',
        'none',
        180,
        8.0,
        12.0,
        14.0
    );

SET @diet9_id = LAST_INSERT_ID();

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
        'Appetizer',
        'vegan,gluten_free,low_carb,organic,high_protein',
        'soy',
        160,
        22.0,
        10.0,
        6.0
    );

SET @diet10_id = LAST_INSERT_ID();

-- Keto Kitchen — 10 rows
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
    );

SET @diet11_id = LAST_INSERT_ID();

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
        'keto,high_protein',
        'eggs',
        480,
        38.0,
        8.0,
        34.0
    );

SET @diet12_id = LAST_INSERT_ID();

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
        'Appetizer',
        'keto,organic',
        'dairy',
        200,
        12.0,
        4.0,
        16.0
    );

SET @diet13_id = LAST_INSERT_ID();

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
        'keto,high_protein,organic',
        'none',
        520,
        48.0,
        6.0,
        36.0
    );

SET @diet14_id = LAST_INSERT_ID();

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
        'Dessert',
        'keto,gluten_free,organic',
        'dairy',
        250,
        8.0,
        5.0,
        22.0
    );

SET @diet15_id = LAST_INSERT_ID();

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
        'keto,high_protein,gluten_free,organic',
        'eggs,dairy',
        580,
        52.0,
        4.0,
        42.0
    );

SET @diet16_id = LAST_INSERT_ID();

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
        'Appetizer',
        'keto,gluten_free,low_carb,organic',
        'none',
        140,
        16.0,
        3.0,
        12.0
    );

SET @diet17_id = LAST_INSERT_ID();

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
        'keto,high_protein,gluten_free,low_carb,organic',
        'dairy',
        620,
        56.0,
        4.0,
        46.0
    );

SET @diet18_id = LAST_INSERT_ID();

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
        'Beverage',
        'keto,gluten_free,low_carb,organic,vegan',
        'nuts',
        110,
        3.0,
        6.0,
        8.0
    );

SET @diet19_id = LAST_INSERT_ID();

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
        'keto,high_protein,gluten_free,low_carb,organic',
        'eggs,shellfish',
        540,
        50.0,
        5.0,
        38.0
    );

SET @diet20_id = LAST_INSERT_ID();

-- Asian Fusion Fit — 10 rows
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
    );

SET @diet21_id = LAST_INSERT_ID();

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
        'Appetizer',
        'gluten_free,organic,halal',
        'soy',
        95,
        5.0,
        12.0,
        4.0
    );

SET @diet22_id = LAST_INSERT_ID();

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
        'low_carb,organic,halal',
        'fish',
        310,
        30.0,
        14.0,
        18.0
    );

SET @diet23_id = LAST_INSERT_ID();

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
        'gluten_free,low_carb,organic,halal',
        'shellfish',
        390,
        34.0,
        12.0,
        22.0
    );

SET @diet24_id = LAST_INSERT_ID();

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
        'Appetizer',
        'gluten_free,vegan,organic,halal',
        'none',
        85,
        4.0,
        14.0,
        2.0
    );

SET @diet25_id = LAST_INSERT_ID();

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
        'gluten_free,low_carb,organic,high_protein,halal',
        'fish,shellfish',
        450,
        42.0,
        10.0,
        26.0
    );

SET @diet26_id = LAST_INSERT_ID();

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
        'Dessert',
        'gluten_free,vegan,low_carb,organic,halal',
        'nuts',
        160,
        6.0,
        14.0,
        12.0
    );

SET @diet27_id = LAST_INSERT_ID();

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
        'gluten_free,low_carb,organic,high_protein,keto,halal',
        'shellfish',
        490,
        46.0,
        8.0,
        30.0
    );

SET @diet28_id = LAST_INSERT_ID();

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
        'Appetizer',
        'gluten_free,low_carb,organic,vegan,high_protein,halal',
        'soy',
        115,
        20.0,
        6.0,
        8.0
    );

SET @diet29_id = LAST_INSERT_ID();

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
        'Beverage',
        'gluten_free,low_carb,organic,vegan,keto,halal',
        'none',
        75,
        2.0,
        4.0,
        5.0
    );

SET @diet30_id = LAST_INSERT_ID();

-- =====================================================
-- 7. INGREDIENTS
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
-- 8. PRODUCTS  (description NULL until seed 2)
-- =====================================================

-- Green Bowl Cafe — 10 products
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
        NULL,
        220.00,
        100,
        1,
        'structured',
        220.00,
        1
    );

SET @bowl_product_id = LAST_INSERT_ID();

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
        @diet2_id,
        'Market Greens Salad',
        NULL,
        180.00,
        80,
        1,
        'structured',
        180.00,
        1
    );

SET @salad_product_id = LAST_INSERT_ID();

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
        @diet7_id,
        'Morning Power Smoothie',
        NULL,
        190.00,
        60,
        1,
        'mixed',
        190.00,
        1
    );

SET @smoothie_product_id = LAST_INSERT_ID();

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
        @diet3_id,
        'Classic Vegan Bowl',
        NULL,
        220.00,
        20,
        0,
        'none',
        0.00,
        1
    );

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
        @diet4_id,
        'Edamame Citrus Salad',
        NULL,
        130.00,
        30,
        0,
        'none',
        0.00,
        1
    );

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
        @diet5_id,
        'Zucchini Noodle Pesto',
        NULL,
        260.00,
        12,
        0,
        'none',
        0.00,
        1
    );

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
        @diet8_id,
        'Sunrise Breakfast Bowl',
        NULL,
        240.00,
        75,
        1,
        'structured',
        240.00,
        1
    );

SET @breakfast_bowl_id = LAST_INSERT_ID();

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
        @diet10_id,
        'Seaside Poke Bowl',
        NULL,
        350.00,
        70,
        1,
        'structured',
        350.00,
        1
    );

SET @poke_product_id = LAST_INSERT_ID();

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
        @diet9_id,
        'Superfood Buddha Bowl',
        NULL,
        350.00,
        8,
        0,
        'none',
        0.00,
        1
    );

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
        @diet6_id,
        'Berry Almond Smoothie',
        NULL,
        190.00,
        10,
        0,
        'none',
        0.00,
        1
    );

-- Keto Kitchen — 10 products
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
        NULL,
        480.00,
        90,
        1,
        'structured',
        480.00,
        1
    );

SET @keto_bowl_id = LAST_INSERT_ID();

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
        @diet14_id,
        'Keto Smash Burger',
        NULL,
        450.00,
        70,
        1,
        'structured',
        450.00,
        1
    );

SET @keto_burger_id = LAST_INSERT_ID();

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
        @diet18_id,
        'Keto Garden Salad',
        NULL,
        380.00,
        85,
        1,
        'structured',
        380.00,
        1
    );

SET @keto_salad_id = LAST_INSERT_ID();

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
        @diet12_id,
        'Keto Steak Plate',
        NULL,
        550.00,
        8,
        0,
        'none',
        0.00,
        1
    );

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
        @diet13_id,
        'Egg & Avocado Bowl',
        NULL,
        380.00,
        12,
        0,
        'none',
        0.00,
        1
    );

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
        @diet20_id,
        'Chicken Parmesan Plate',
        NULL,
        430.00,
        6,
        0,
        'none',
        0.00,
        1
    );

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
        @diet17_id,
        'Keto Butcher Plate',
        NULL,
        500.00,
        65,
        1,
        'structured',
        500.00,
        1
    );

SET @keto_plate_id = LAST_INSERT_ID();

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
        @diet16_id,
        'Keto Cauliflower Pizza',
        NULL,
        420.00,
        60,
        1,
        'structured',
        420.00,
        1
    );

SET @keto_pizza_id = LAST_INSERT_ID();

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
        @diet19_id,
        'Salmon Dill Plate',
        NULL,
        490.00,
        15,
        0,
        'none',
        0.00,
        1
    );

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
        @diet15_id,
        'Shrimp Scampi Zoodles',
        NULL,
        530.00,
        9,
        0,
        'none',
        0.00,
        1
    );

-- Asian Fusion Fit — 10 products
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
        NULL,
        350.00,
        80,
        1,
        'structured',
        350.00,
        1
    );

SET @sushi_id = LAST_INSERT_ID();

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
        @diet24_id,
        'Rainbow Poke Bowl',
        NULL,
        390.00,
        75,
        1,
        'structured',
        390.00,
        1
    );

SET @poke_bowl_id = LAST_INSERT_ID();

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
        @diet26_id,
        'Tokyo Noodle Bowl',
        NULL,
        320.00,
        70,
        1,
        'structured',
        320.00,
        1
    );

SET @noodle_bowl_id = LAST_INSERT_ID();

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
        @diet22_id,
        'Gluten-Free Salmon Roll',
        NULL,
        390.00,
        20,
        0,
        'none',
        0.00,
        1
    );

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
        @diet23_id,
        'Seaweed Sesame Salad',
        NULL,
        160.00,
        15,
        0,
        'none',
        0.00,
        1
    );

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
        @diet25_id,
        'Grilled Fish & Greens',
        NULL,
        360.00,
        8,
        0,
        'none',
        0.00,
        1
    );

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
        @diet28_id,
        'Osaka Rice Bowl',
        NULL,
        300.00,
        85,
        1,
        'structured',
        300.00,
        1
    );

SET @rice_bowl_id = LAST_INSERT_ID();

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
        @diet29_id,
        'Wok-Tossed Vegetables',
        NULL,
        280.00,
        90,
        1,
        'structured',
        280.00,
        1
    );

SET @stir_fry_id = LAST_INSERT_ID();

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
        @diet27_id,
        'Spicy Tuna Roll',
        NULL,
        440.00,
        16,
        0,
        'none',
        0.00,
        1
    );

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
        @diet30_id,
        'Matcha Banana Smoothie',
        NULL,
        200.00,
        14,
        0,
        'none',
        0.00,
        1
    );

-- =====================================================
-- 9. PRODUCT COMPOSITION
-- =====================================================

-- 9.1 Garden Harvest Bowl
INSERT INTO
    product_composition (
        product_id,
        ingredient_id,
        is_default,
        default_quantity,
        min_quantity,
        max_quantity,
        price_modifier,
        display_order,
        is_required
    )
VALUES (
        @bowl_product_id,
        @white_rice_id,
        1,
        1,
        1,
        1,
        0.00,
        1,
        1
    ),
    (
        @bowl_product_id,
        @brown_rice_id,
        0,
        0,
        0,
        1,
        5.00,
        1,
        0
    ),
    (
        @bowl_product_id,
        @cauliflower_rice_id,
        0,
        0,
        0,
        1,
        10.00,
        1,
        0
    ),
    (
        @bowl_product_id,
        @quinoa_id,
        0,
        0,
        0,
        1,
        15.00,
        1,
        0
    ),
    (
        @bowl_product_id,
        @grilled_chicken_id,
        1,
        1,
        1,
        1,
        0.00,
        2,
        1
    ),
    (
        @bowl_product_id,
        @tofu_id,
        0,
        0,
        0,
        1,
        -10.00,
        2,
        0
    ),
    (
        @bowl_product_id,
        @beef_patty_id,
        0,
        0,
        0,
        1,
        15.00,
        2,
        0
    ),
    (
        @bowl_product_id,
        @lettuce_id,
        0,
        0,
        0,
        4,
        0.00,
        3,
        0
    ),
    (
        @bowl_product_id,
        @tomato_id,
        0,
        0,
        0,
        4,
        0.00,
        4,
        0
    ),
    (
        @bowl_product_id,
        @avocado_id,
        0,
        0,
        0,
        2,
        15.00,
        5,
        0
    ),
    (
        @bowl_product_id,
        @spinach_id,
        0,
        0,
        0,
        4,
        0.00,
        6,
        0
    ),
    (
        @bowl_product_id,
        @bbq_sauce_id,
        0,
        0,
        0,
        1,
        0.00,
        7,
        0
    ),
    (
        @bowl_product_id,
        @vegan_ranch_id,
        0,
        0,
        0,
        1,
        2.00,
        8,
        0
    ),
    (
        @bowl_product_id,
        @miso_dressing_id,
        0,
        0,
        0,
        1,
        0.00,
        9,
        0
    );

-- 9.2 Market Greens Salad
INSERT INTO
    product_composition (
        product_id,
        ingredient_id,
        is_default,
        default_quantity,
        min_quantity,
        max_quantity,
        price_modifier,
        display_order,
        is_required
    )
VALUES (
        @salad_product_id,
        @lettuce_id,
        1,
        1,
        1,
        1,
        0.00,
        1,
        1
    ),
    (
        @salad_product_id,
        @spinach_id,
        0,
        0,
        0,
        1,
        0.00,
        1,
        0
    ),
    (
        @salad_product_id,
        @grilled_chicken_id,
        0,
        0,
        0,
        2,
        20.00,
        2,
        0
    ),
    (
        @salad_product_id,
        @tofu_id,
        0,
        0,
        0,
        2,
        10.00,
        3,
        0
    ),
    (
        @salad_product_id,
        @salmon_id,
        0,
        0,
        0,
        2,
        30.00,
        4,
        0
    ),
    (
        @salad_product_id,
        @tomato_id,
        0,
        0,
        0,
        5,
        0.00,
        5,
        0
    ),
    (
        @salad_product_id,
        @cucumber_id,
        0,
        0,
        0,
        5,
        0.00,
        6,
        0
    ),
    (
        @salad_product_id,
        @avocado_id,
        0,
        0,
        0,
        2,
        15.00,
        7,
        0
    ),
    (
        @salad_product_id,
        @ranch_dressing_id,
        0,
        0,
        0,
        1,
        0.00,
        8,
        0
    ),
    (
        @salad_product_id,
        @vegan_ranch_id,
        0,
        0,
        0,
        1,
        2.00,
        9,
        0
    ),
    (
        @salad_product_id,
        @sesame_dressing_id,
        0,
        0,
        0,
        1,
        0.00,
        10,
        0
    );

-- 9.3 Morning Power Smoothie
INSERT INTO
    product_composition (
        product_id,
        ingredient_id,
        is_default,
        default_quantity,
        min_quantity,
        max_quantity,
        price_modifier,
        display_order,
        is_required
    )
VALUES (
        @smoothie_product_id,
        @white_rice_id,
        1,
        1,
        1,
        1,
        0.00,
        1,
        1
    ),
    (
        @smoothie_product_id,
        @brown_rice_id,
        0,
        0,
        0,
        1,
        5.00,
        1,
        0
    ),
    (
        @smoothie_product_id,
        @avocado_id,
        0,
        0,
        0,
        3,
        5.00,
        2,
        0
    ),
    (
        @smoothie_product_id,
        @cucumber_id,
        0,
        0,
        0,
        3,
        0.00,
        3,
        0
    ),
    (
        @smoothie_product_id,
        @tofu_id,
        0,
        0,
        0,
        2,
        10.00,
        4,
        0
    ),
    (
        @smoothie_product_id,
        @eggs_id,
        0,
        0,
        0,
        2,
        15.00,
        5,
        0
    );

-- 9.4 Sunrise Breakfast Bowl
INSERT INTO
    product_composition (
        product_id,
        ingredient_id,
        is_default,
        default_quantity,
        min_quantity,
        max_quantity,
        price_modifier,
        display_order,
        is_required
    )
VALUES (
        @breakfast_bowl_id,
        @quinoa_id,
        1,
        1,
        1,
        1,
        0.00,
        1,
        1
    ),
    (
        @breakfast_bowl_id,
        @cauliflower_rice_id,
        0,
        0,
        0,
        1,
        5.00,
        1,
        0
    ),
    (
        @breakfast_bowl_id,
        @eggs_id,
        1,
        1,
        1,
        2,
        0.00,
        2,
        1
    ),
    (
        @breakfast_bowl_id,
        @spinach_id,
        0,
        0,
        0,
        3,
        0.00,
        3,
        0
    ),
    (
        @breakfast_bowl_id,
        @tomato_id,
        0,
        0,
        0,
        3,
        0.00,
        4,
        0
    ),
    (
        @breakfast_bowl_id,
        @cheddar_cheese_id,
        0,
        0,
        0,
        1,
        10.00,
        5,
        0
    ),
    (
        @breakfast_bowl_id,
        @vegan_cheese_id,
        0,
        0,
        0,
        1,
        10.00,
        6,
        0
    );

-- 9.5 Seaside Poke Bowl
INSERT INTO
    product_composition (
        product_id,
        ingredient_id,
        is_default,
        default_quantity,
        min_quantity,
        max_quantity,
        price_modifier,
        display_order,
        is_required
    )
VALUES (
        @poke_product_id,
        @white_rice_id,
        1,
        1,
        1,
        1,
        0.00,
        1,
        1
    ),
    (
        @poke_product_id,
        @brown_rice_id,
        0,
        0,
        0,
        1,
        5.00,
        1,
        0
    ),
    (
        @poke_product_id,
        @quinoa_id,
        0,
        0,
        0,
        1,
        10.00,
        1,
        0
    ),
    (
        @poke_product_id,
        @salmon_id,
        1,
        1,
        1,
        1,
        0.00,
        2,
        1
    ),
    (
        @poke_product_id,
        @tofu_id,
        0,
        0,
        0,
        1,
        -20.00,
        2,
        0
    ),
    (
        @poke_product_id,
        @shrimp_id,
        0,
        0,
        0,
        1,
        15.00,
        2,
        0
    ),
    (
        @poke_product_id,
        @cucumber_id,
        0,
        0,
        0,
        4,
        0.00,
        3,
        0
    ),
    (
        @poke_product_id,
        @avocado_id,
        0,
        0,
        0,
        4,
        10.00,
        4,
        0
    ),
    (
        @poke_product_id,
        @teriyaki_sauce_id,
        0,
        0,
        0,
        1,
        0.00,
        5,
        0
    ),
    (
        @poke_product_id,
        @sesame_dressing_id,
        0,
        0,
        0,
        1,
        0.00,
        6,
        0
    );

-- 9.6 Keto Power Bowl
INSERT INTO
    product_composition (
        product_id,
        ingredient_id,
        is_default,
        default_quantity,
        min_quantity,
        max_quantity,
        price_modifier,
        display_order,
        is_required
    )
VALUES (
        @keto_bowl_id,
        @cauliflower_rice_id,
        1,
        1,
        1,
        1,
        0.00,
        1,
        1
    ),
    (
        @keto_bowl_id,
        @grilled_chicken_id,
        1,
        1,
        1,
        1,
        0.00,
        2,
        1
    ),
    (
        @keto_bowl_id,
        @salmon_id,
        0,
        0,
        0,
        1,
        25.00,
        2,
        0
    ),
    (
        @keto_bowl_id,
        @beef_patty_id,
        0,
        0,
        0,
        1,
        15.00,
        2,
        0
    ),
    (
        @keto_bowl_id,
        @eggs_id,
        0,
        0,
        0,
        1,
        10.00,
        3,
        0
    ),
    (
        @keto_bowl_id,
        @avocado_id,
        1,
        1,
        1,
        2,
        0.00,
        4,
        0
    ),
    (
        @keto_bowl_id,
        @cheddar_cheese_id,
        0,
        0,
        0,
        2,
        10.00,
        5,
        0
    ),
    (
        @keto_bowl_id,
        @spinach_id,
        1,
        1,
        1,
        3,
        0.00,
        6,
        0
    ),
    (
        @keto_bowl_id,
        @cucumber_id,
        0,
        0,
        0,
        3,
        0.00,
        7,
        0
    );

-- 9.7 Keto Smash Burger
INSERT INTO
    product_composition (
        product_id,
        ingredient_id,
        is_default,
        default_quantity,
        min_quantity,
        max_quantity,
        price_modifier,
        display_order,
        is_required
    )
VALUES (
        @keto_burger_id,
        @beef_patty_id,
        1,
        1,
        1,
        1,
        0.00,
        1,
        1
    ),
    (
        @keto_burger_id,
        @grilled_chicken_id,
        0,
        0,
        0,
        1,
        0.00,
        1,
        0
    ),
    (
        @keto_burger_id,
        @cheddar_cheese_id,
        1,
        1,
        1,
        1,
        0.00,
        2,
        1
    ),
    (
        @keto_burger_id,
        @vegan_cheese_id,
        0,
        0,
        0,
        1,
        10.00,
        3,
        0
    ),
    (
        @keto_burger_id,
        @lettuce_id,
        1,
        1,
        1,
        3,
        0.00,
        4,
        0
    ),
    (
        @keto_burger_id,
        @tomato_id,
        0,
        0,
        0,
        3,
        0.00,
        5,
        0
    ),
    (
        @keto_burger_id,
        @avocado_id,
        0,
        0,
        0,
        2,
        15.00,
        6,
        0
    );

-- 9.8 Keto Garden Salad
INSERT INTO
    product_composition (
        product_id,
        ingredient_id,
        is_default,
        default_quantity,
        min_quantity,
        max_quantity,
        price_modifier,
        display_order,
        is_required
    )
VALUES (
        @keto_salad_id,
        @spinach_id,
        1,
        1,
        1,
        1,
        0.00,
        1,
        1
    ),
    (
        @keto_salad_id,
        @lettuce_id,
        0,
        0,
        0,
        1,
        0.00,
        1,
        0
    ),
    (
        @keto_salad_id,
        @salmon_id,
        1,
        1,
        1,
        1,
        0.00,
        2,
        1
    ),
    (
        @keto_salad_id,
        @grilled_chicken_id,
        0,
        0,
        0,
        1,
        -10.00,
        2,
        0
    ),
    (
        @keto_salad_id,
        @cucumber_id,
        0,
        0,
        0,
        3,
        0.00,
        3,
        0
    ),
    (
        @keto_salad_id,
        @avocado_id,
        0,
        0,
        0,
        2,
        15.00,
        4,
        0
    ),
    (
        @keto_salad_id,
        @ranch_dressing_id,
        0,
        0,
        0,
        1,
        0.00,
        5,
        0
    ),
    (
        @keto_salad_id,
        @sesame_dressing_id,
        0,
        0,
        0,
        1,
        0.00,
        6,
        0
    );

-- 9.9 Keto Butcher Plate
INSERT INTO
    product_composition (
        product_id,
        ingredient_id,
        is_default,
        default_quantity,
        min_quantity,
        max_quantity,
        price_modifier,
        display_order,
        is_required
    )
VALUES (
        @keto_plate_id,
        @beef_patty_id,
        1,
        1,
        1,
        1,
        0.00,
        1,
        1
    ),
    (
        @keto_plate_id,
        @grilled_chicken_id,
        0,
        0,
        0,
        1,
        0.00,
        1,
        0
    ),
    (
        @keto_plate_id,
        @salmon_id,
        0,
        0,
        0,
        1,
        20.00,
        1,
        0
    ),
    (
        @keto_plate_id,
        @cauliflower_rice_id,
        1,
        1,
        1,
        2,
        0.00,
        2,
        0
    ),
    (
        @keto_plate_id,
        @eggs_id,
        0,
        0,
        0,
        2,
        10.00,
        3,
        0
    ),
    (
        @keto_plate_id,
        @spinach_id,
        1,
        1,
        1,
        3,
        0.00,
        4,
        0
    ),
    (
        @keto_plate_id,
        @cucumber_id,
        0,
        0,
        0,
        3,
        0.00,
        5,
        0
    );

-- 9.10 Keto Cauliflower Pizza
INSERT INTO
    product_composition (
        product_id,
        ingredient_id,
        is_default,
        default_quantity,
        min_quantity,
        max_quantity,
        price_modifier,
        display_order,
        is_required
    )
VALUES (
        @keto_pizza_id,
        @cauliflower_rice_id,
        1,
        1,
        1,
        1,
        0.00,
        1,
        1
    ),
    (
        @keto_pizza_id,
        @cheddar_cheese_id,
        1,
        1,
        1,
        2,
        0.00,
        2,
        1
    ),
    (
        @keto_pizza_id,
        @parmesan_cheese_id,
        1,
        1,
        1,
        2,
        0.00,
        3,
        1
    ),
    (
        @keto_pizza_id,
        @grilled_chicken_id,
        1,
        1,
        1,
        3,
        0.00,
        4,
        0
    ),
    (
        @keto_pizza_id,
        @beef_patty_id,
        0,
        0,
        0,
        3,
        15.00,
        5,
        0
    ),
    (
        @keto_pizza_id,
        @spinach_id,
        0,
        0,
        0,
        3,
        0.00,
        6,
        0
    ),
    (
        @keto_pizza_id,
        @tomato_id,
        0,
        0,
        0,
        3,
        0.00,
        7,
        0
    );

-- 9.11 Nori Hand Roll Set
INSERT INTO
    product_composition (
        product_id,
        ingredient_id,
        is_default,
        default_quantity,
        min_quantity,
        max_quantity,
        price_modifier,
        display_order,
        is_required
    )
VALUES (
        @sushi_id,
        @seaweed_id,
        1,
        1,
        1,
        1,
        0.00,
        1,
        1
    ),
    (
        @sushi_id,
        @salmon_id,
        1,
        1,
        1,
        1,
        0.00,
        2,
        1
    ),
    (
        @sushi_id,
        @tofu_id,
        0,
        0,
        0,
        1,
        -10.00,
        2,
        0
    ),
    (
        @sushi_id,
        @shrimp_id,
        0,
        0,
        0,
        1,
        20.00,
        2,
        0
    ),
    (
        @sushi_id,
        @cucumber_id,
        0,
        0,
        0,
        4,
        0.00,
        3,
        0
    ),
    (
        @sushi_id,
        @avocado_id,
        0,
        0,
        0,
        4,
        10.00,
        4,
        0
    ),
    (
        @sushi_id,
        @teriyaki_sauce_id,
        0,
        0,
        0,
        1,
        0.00,
        5,
        0
    ),
    (
        @sushi_id,
        @sesame_dressing_id,
        0,
        0,
        0,
        1,
        0.00,
        6,
        0
    );

-- 9.12 Rainbow Poke Bowl
INSERT INTO
    product_composition (
        product_id,
        ingredient_id,
        is_default,
        default_quantity,
        min_quantity,
        max_quantity,
        price_modifier,
        display_order,
        is_required
    )
VALUES (
        @poke_bowl_id,
        @white_rice_id,
        1,
        1,
        1,
        1,
        0.00,
        1,
        1
    ),
    (
        @poke_bowl_id,
        @brown_rice_id,
        0,
        0,
        0,
        1,
        5.00,
        1,
        0
    ),
    (
        @poke_bowl_id,
        @quinoa_id,
        0,
        0,
        0,
        1,
        10.00,
        1,
        0
    ),
    (
        @poke_bowl_id,
        @salmon_id,
        1,
        1,
        1,
        1,
        0.00,
        2,
        1
    ),
    (
        @poke_bowl_id,
        @shrimp_id,
        0,
        0,
        0,
        1,
        10.00,
        2,
        0
    ),
    (
        @poke_bowl_id,
        @tofu_id,
        0,
        0,
        0,
        1,
        -15.00,
        2,
        0
    ),
    (
        @poke_bowl_id,
        @cucumber_id,
        0,
        0,
        0,
        4,
        0.00,
        3,
        0
    ),
    (
        @poke_bowl_id,
        @avocado_id,
        0,
        0,
        0,
        4,
        10.00,
        4,
        0
    ),
    (
        @poke_bowl_id,
        @seaweed_id,
        0,
        0,
        0,
        4,
        5.00,
        5,
        0
    ),
    (
        @poke_bowl_id,
        @teriyaki_sauce_id,
        0,
        0,
        0,
        1,
        0.00,
        6,
        0
    ),
    (
        @poke_bowl_id,
        @sesame_dressing_id,
        0,
        0,
        0,
        1,
        0.00,
        7,
        0
    );

-- 9.13 Tokyo Noodle Bowl
INSERT INTO
    product_composition (
        product_id,
        ingredient_id,
        is_default,
        default_quantity,
        min_quantity,
        max_quantity,
        price_modifier,
        display_order,
        is_required
    )
VALUES (
        @noodle_bowl_id,
        @white_rice_id,
        1,
        1,
        1,
        1,
        0.00,
        1,
        1
    ),
    (
        @noodle_bowl_id,
        @brown_rice_id,
        0,
        0,
        0,
        1,
        5.00,
        1,
        0
    ),
    (
        @noodle_bowl_id,
        @grilled_chicken_id,
        1,
        1,
        1,
        1,
        0.00,
        2,
        1
    ),
    (
        @noodle_bowl_id,
        @tofu_id,
        0,
        0,
        0,
        1,
        -10.00,
        2,
        0
    ),
    (
        @noodle_bowl_id,
        @shrimp_id,
        0,
        0,
        0,
        1,
        15.00,
        2,
        0
    ),
    (
        @noodle_bowl_id,
        @spinach_id,
        1,
        1,
        1,
        3,
        0.00,
        3,
        0
    ),
    (
        @noodle_bowl_id,
        @cucumber_id,
        0,
        0,
        0,
        3,
        0.00,
        4,
        0
    ),
    (
        @noodle_bowl_id,
        @teriyaki_sauce_id,
        0,
        0,
        0,
        1,
        0.00,
        5,
        0
    ),
    (
        @noodle_bowl_id,
        @miso_dressing_id,
        0,
        0,
        0,
        1,
        0.00,
        6,
        0
    );

-- 9.14 Osaka Rice Bowl
INSERT INTO
    product_composition (
        product_id,
        ingredient_id,
        is_default,
        default_quantity,
        min_quantity,
        max_quantity,
        price_modifier,
        display_order,
        is_required
    )
VALUES (
        @rice_bowl_id,
        @white_rice_id,
        1,
        1,
        1,
        1,
        0.00,
        1,
        1
    ),
    (
        @rice_bowl_id,
        @brown_rice_id,
        0,
        0,
        0,
        1,
        5.00,
        1,
        0
    ),
    (
        @rice_bowl_id,
        @cauliflower_rice_id,
        0,
        0,
        0,
        1,
        10.00,
        1,
        0
    ),
    (
        @rice_bowl_id,
        @grilled_chicken_id,
        1,
        1,
        1,
        1,
        0.00,
        2,
        1
    ),
    (
        @rice_bowl_id,
        @tofu_id,
        0,
        0,
        0,
        1,
        -10.00,
        2,
        0
    ),
    (
        @rice_bowl_id,
        @beef_patty_id,
        0,
        0,
        0,
        1,
        15.00,
        2,
        0
    ),
    (
        @rice_bowl_id,
        @spinach_id,
        1,
        1,
        1,
        3,
        0.00,
        3,
        0
    ),
    (
        @rice_bowl_id,
        @cucumber_id,
        0,
        0,
        0,
        3,
        0.00,
        4,
        0
    ),
    (
        @rice_bowl_id,
        @avocado_id,
        0,
        0,
        0,
        2,
        10.00,
        5,
        0
    );

-- 9.15 Wok-Tossed Vegetables
INSERT INTO
    product_composition (
        product_id,
        ingredient_id,
        is_default,
        default_quantity,
        min_quantity,
        max_quantity,
        price_modifier,
        display_order,
        is_required
    )
VALUES (
        @stir_fry_id,
        @cauliflower_rice_id,
        1,
        1,
        1,
        1,
        0.00,
        1,
        1
    ),
    (
        @stir_fry_id,
        @quinoa_id,
        0,
        0,
        0,
        1,
        5.00,
        1,
        0
    ),
    (
        @stir_fry_id,
        @spinach_id,
        1,
        1,
        1,
        5,
        0.00,
        2,
        0
    ),
    (
        @stir_fry_id,
        @cucumber_id,
        0,
        0,
        0,
        5,
        0.00,
        3,
        0
    ),
    (
        @stir_fry_id,
        @tomato_id,
        0,
        0,
        0,
        5,
        0.00,
        4,
        0
    ),
    (
        @stir_fry_id,
        @teriyaki_sauce_id,
        0,
        0,
        0,
        1,
        0.00,
        5,
        0
    ),
    (
        @stir_fry_id,
        @miso_dressing_id,
        0,
        0,
        0,
        1,
        0.00,
        6,
        0
    );

-- =====================================================
-- 10. DERIVE dietary_information.calories FOR CUSTOMIZABLE PRODUCTS
-- =====================================================
UPDATE dietary_information di
JOIN (
    SELECT p.dietary_information_id, SUM(
            i.calories * pc.default_quantity
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
) calc ON calc.dietary_information_id = di.dietary_information_id
SET
    di.calories = calc.total_calories;

-- =====================================================
-- 11. ENFORCE base_price = price FOR CUSTOMIZABLE PRODUCTS
-- =====================================================
UPDATE product
SET
    base_price = price
WHERE
    is_customizable = 1
    AND (
        base_price IS NULL
        OR base_price = 0
    );

COMMIT;

-- =====================================================
-- VERIFICATION — STRUCTURAL (paths & documents not yet loaded)
-- =====================================================
SELECT '=== seed-1-core.sql loaded (v6.0) ===' AS status;

-- V1. Every customizable product's default price modifiers sum to 0.
SELECT
    p.name AS product_name,
    p.price AS product_price,
    COALESCE(SUM(pc.price_modifier), 0) AS sum_of_default_price_mods
FROM
    product p
    LEFT JOIN product_composition pc ON pc.product_id = p.product_id
    AND pc.is_default = 1
WHERE
    p.is_customizable = 1
GROUP BY
    p.product_id,
    p.name,
    p.price
ORDER BY p.name;

-- V2. Every customizable product's stored calories match derived.
SELECT
    p.name AS product_name,
    di.calories AS stored_calories,
    COALESCE(
        (
            SELECT SUM(
                    i.calories * pc.default_quantity
                )
            FROM
                product_composition pc
                JOIN ingredient i ON i.ingredient_id = pc.ingredient_id
            WHERE
                pc.product_id = p.product_id
                AND pc.is_default = 1
        ),
        0
    ) AS derived_calories
FROM
    product p
    JOIN dietary_information di ON di.dietary_information_id = p.dietary_information_id
WHERE
    p.is_customizable = 1
ORDER BY p.name;

-- V3. No choice group has more than one is_required = 1 row.
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
GROUP BY
    p.product_id,
    p.name,
    pc.display_order
HAVING
    required_count > 1;

-- V4. min_quantity <= default_quantity <= max_quantity for every row.
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

-- V5. Every customer has at least one address.
SELECT c.customer_id, c.email, COUNT(ca.customer_address_id) AS address_count
FROM
    customer c
    LEFT JOIN customer_address ca ON ca.customer_id = c.customer_id
GROUP BY
    c.customer_id,
    c.email
HAVING
    address_count = 0;

-- V6. Every rider has at least one address.
SELECT dr.delivery_rider_id, dr.email, COUNT(dra.delivery_rider_address_id) AS address_count
FROM
    delivery_rider dr
    LEFT JOIN delivery_rider_address dra ON dra.delivery_rider_id = dr.delivery_rider_id
GROUP BY
    dr.delivery_rider_id,
    dr.email
HAVING
    address_count = 0;

-- V7. Every branch has a financial account of type 'restaurant'.
SELECT rb.branch_code, rb.branch_name, fa.account_type
FROM
    restaurant_branch rb
    JOIN financial_account fa ON fa.financial_account_id = rb.financial_account_id
WHERE
    fa.account_type <> 'restaurant';

-- V8. Every product has a dietary_information row.
SELECT p.product_id, p.name
FROM
    product p
    LEFT JOIN dietary_information di ON di.dietary_information_id = p.dietary_information_id
WHERE
    di.dietary_information_id IS NULL;

-- V9. Every rider has at least one emergency contact.
SELECT dr.delivery_rider_id, dr.email, COUNT(ec.emergency_contact_id) AS emergency_contact_count
FROM
    delivery_rider dr
    LEFT JOIN delivery_rider_emergency_contact ec ON ec.delivery_rider_id = dr.delivery_rider_id
GROUP BY
    dr.delivery_rider_id,
    dr.email
HAVING
    emergency_contact_count = 0;

-- V10. Rider profile pictures are NULL AND is_available = 0.
--      (profile_picture is filled by seed 3; is_available stays 0.)
SELECT dr.delivery_rider_id, dr.email, drp.profile_picture, drp.is_available, drp.verification_status
FROM
    delivery_rider dr
    JOIN delivery_rider_profile drp ON drp.delivery_rider_id = dr.delivery_rider_id
ORDER BY dr.delivery_rider_id;

-- V11. Every financial account balance is 0.00.
SELECT
    financial_account_id,
    account_type,
    balance
FROM financial_account
WHERE
    balance <> 0.00;

-- V12. No rider is marked available after seeding.
SELECT dr.delivery_rider_id, dr.email, drp.is_available
FROM
    delivery_rider dr
    JOIN delivery_rider_profile drp ON drp.delivery_rider_id = dr.delivery_rider_id
WHERE
    drp.is_available <> 0;

-- V13. No rider is seeded with more than 3 live orders.
SELECT dr.delivery_rider_id, dr.email, COUNT(o.order_id) AS live_order_count
FROM delivery_rider dr
    JOIN orders o ON o.delivery_rider_id = dr.delivery_rider_id
WHERE
    o.order_status IN (
        'rider_pending',
        'picking_up',
        'delivering'
    )
GROUP BY
    dr.delivery_rider_id,
    dr.email
HAVING
    live_order_count > 3;