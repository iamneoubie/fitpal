-- =====================================================
-- INSERT TEST PRODUCTS (30 products, 10 per branch)
-- WITH CUSTOMIZABLE AND NON-CUSTOMIZABLE OPTIONS
-- For fitpal_food_delivery database
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
        'Test',
        NULL,
        'User',
        'test@example.com',
        '09123456789',
        'testuser',
        'admin123',
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
-- 4. RESTAURANTS & BRANCHES
-- =====================================================

-- Restaurant 1: Green Bowl Cafe
INSERT IGNORE INTO
    restaurant (
        owner_id,
        business_name,
        cuisine_type,
        dietary_tags,
        is_active
    )
VALUES (
        @admin_id,
        'Green Bowl Cafe',
        'Filipino',
        'vegan,organic',
        1
    );

SET @rest1_id = LAST_INSERT_ID();

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

-- Restaurant 2: Keto Kitchen
INSERT IGNORE INTO
    restaurant (
        owner_id,
        business_name,
        cuisine_type,
        dietary_tags,
        is_active
    )
VALUES (
        @admin_id,
        'Keto Kitchen',
        'Italian',
        'keto,high_protein',
        1
    );

SET @rest2_id = LAST_INSERT_ID();

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

-- Restaurant 3: Asian Fusion Fit
INSERT IGNORE INTO
    restaurant (
        owner_id,
        business_name,
        cuisine_type,
        dietary_tags,
        is_active
    )
VALUES (
        @admin_id,
        'Asian Fusion Fit',
        'Japanese',
        'gluten_free,low_carb',
        1
    );

SET @rest3_id = LAST_INSERT_ID();

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

-- =====================================================
-- 5. DIETARY INFORMATION (30 entries with varied tags)
-- =====================================================

-- Green Bowl Cafe - Branch 1 (10 products)
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
        'vegan',
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
        'vegan,gluten_free',
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

-- Keto Kitchen - Branch 2 (10 products)
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

-- Asian Fusion Fit - Branch 3 (10 products)
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
        'gluten_free',
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
        'gluten_free,organic',
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
        'low_carb,organic',
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
        'gluten_free,low_carb,organic',
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
        'gluten_free,vegan,organic',
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
        'gluten_free,low_carb,organic,high_protein',
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
        'gluten_free,vegan,low_carb,organic',
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
        'gluten_free,low_carb,organic,high_protein,keto',
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
        'gluten_free,low_carb,organic,vegan,high_protein',
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
        'gluten_free,low_carb,organic,vegan,keto',
        'none',
        75,
        2.0,
        4.0,
        5.0
    );

SET @diet30_id = LAST_INSERT_ID();

-- =====================================================
-- 6. INGREDIENTS (Master list for customization)
-- =====================================================
INSERT INTO
    ingredient (
        name,
        description,
        unit_price,
        calories,
        dietary_tags,
        allergens,
        stock_quantity,
        is_active
    )
VALUES (
        'White Rice',
        'Steamed white rice',
        15.00,
        200,
        '["vegan","gluten_free"]',
        '[]',
        100,
        1
    ),
    (
        'Brown Rice',
        'Steamed brown rice',
        20.00,
        180,
        '["vegan","gluten_free"]',
        '[]',
        100,
        1
    ),
    (
        'Cauliflower Rice',
        'Low-carb cauliflower rice',
        25.00,
        50,
        '["vegan","gluten_free","low_carb","keto"]',
        '[]',
        80,
        1
    ),
    (
        'Quinoa',
        'Protein-rich quinoa',
        30.00,
        220,
        '["vegan","gluten_free","high_protein"]',
        '[]',
        90,
        1
    ),
    (
        'Grilled Chicken',
        'Marinated grilled chicken breast',
        50.00,
        250,
        '["gluten_free","high_protein"]',
        '[]',
        120,
        1
    ),
    (
        'Tofu',
        'Organic firm tofu',
        40.00,
        150,
        '["vegan","gluten_free"]',
        '["soy"]',
        100,
        1
    ),
    (
        'Salmon',
        'Fresh grilled salmon',
        80.00,
        300,
        '["gluten_free","high_protein","keto"]',
        '["fish"]',
        60,
        1
    ),
    (
        'Beef Patty',
        'Grilled beef patty',
        60.00,
        280,
        '["gluten_free","high_protein","keto"]',
        '[]',
        70,
        1
    ),
    (
        'Shrimp',
        'Garlic butter shrimp',
        75.00,
        200,
        '["gluten_free","keto"]',
        '["shellfish"]',
        50,
        1
    ),
    (
        'Eggs',
        'Pasture-raised eggs',
        30.00,
        140,
        '["keto","high_protein"]',
        '["eggs"]',
        150,
        1
    ),
    (
        'Lettuce',
        'Fresh lettuce',
        10.00,
        10,
        '["vegan","gluten_free"]',
        '[]',
        200,
        1
    ),
    (
        'Tomato',
        'Fresh tomato slices',
        5.00,
        15,
        '["vegan","gluten_free"]',
        '[]',
        200,
        1
    ),
    (
        'Cucumber',
        'Fresh cucumber',
        5.00,
        10,
        '["vegan","gluten_free"]',
        '[]',
        180,
        1
    ),
    (
        'Avocado',
        'Fresh avocado',
        25.00,
        160,
        '["vegan","gluten_free","keto"]',
        '[]',
        80,
        1
    ),
    (
        'Spinach',
        'Fresh spinach',
        15.00,
        20,
        '["vegan","gluten_free"]',
        '[]',
        150,
        1
    ),
    (
        'Seaweed',
        'Organic seaweed',
        20.00,
        30,
        '["vegan","gluten_free"]',
        '[]',
        100,
        1
    ),
    (
        'Cheddar Cheese',
        'Sharp cheddar cheese',
        25.00,
        110,
        '["keto","high_protein"]',
        '["dairy"]',
        90,
        1
    ),
    (
        'Vegan Cheese',
        'Plant-based cheese alternative',
        30.00,
        100,
        '["vegan","gluten_free"]',
        '["soy"]',
        70,
        1
    ),
    (
        'Parmesan Cheese',
        'Fresh parmesan',
        30.00,
        120,
        '["keto","high_protein"]',
        '["dairy"]',
        80,
        1
    ),
    (
        'BBQ Sauce',
        'Sweet BBQ sauce',
        10.00,
        60,
        '["vegan","gluten_free"]',
        '[]',
        120,
        1
    ),
    (
        'Ranch Dressing',
        'Creamy ranch dressing',
        10.00,
        80,
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
        '["vegan"]',
        '["soy"]',
        90,
        1
    ),
    (
        'Teriyaki Sauce',
        'Gluten-free teriyaki sauce',
        10.00,
        50,
        '["gluten_free","vegan"]',
        '["soy"]',
        110,
        1
    ),
    (
        'Miso Dressing',
        'Traditional miso dressing',
        10.00,
        40,
        '["vegan","gluten_free"]',
        '["soy"]',
        80,
        1
    ),
    (
        'Sesame Dressing',
        'Sesame ginger dressing',
        10.00,
        60,
        '["vegan","gluten_free"]',
        '["sesame"]',
        100,
        1
    );

-- =====================================================
-- 7. PRODUCTS (10 per branch, 30 total)
-- =====================================================

-- Green Bowl Cafe - Branch 1 (10 products)
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
        'Build Your Own Bowl',
        'Create your perfect meal bowl with your choice of base, protein, and toppings',
        0.00,
        100,
        1,
        'structured',
        220.00,
        1
    ),
    (
        @branch1_id,
        @diet2_id,
        'Custom Salad',
        'Design your own fresh salad with premium ingredients',
        0.00,
        80,
        1,
        'structured',
        180.00,
        1
    ),
    (
        @branch1_id,
        @diet7_id,
        'Custom Protein Smoothie',
        'Create your own protein smoothie with your choice of fruits and supplements',
        0.00,
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
        'Seasoned tofu with brown rice and fresh seasonal vegetables',
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
        'Edamame Salad',
        'Steamed edamame with a light citrus vinaigrette',
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
        'Fresh spiralized zucchini with basil pesto and cherry tomatoes',
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
        'Breakfast Bowl',
        'Customize your breakfast bowl with eggs, vegetables, and grains',
        0.00,
        75,
        1,
        'structured',
        240.00,
        1
    ),
    (
        @branch1_id,
        @diet10_id,
        'Poke Bowl',
        'Build your own poke bowl with fresh fish and vegetables',
        0.00,
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
        'Kale, sweet potato, chickpeas with turmeric-tahini sauce',
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
        'Berry Protein Smoothie',
        'Mixed berries with plant-based protein and almond milk',
        190.00,
        10,
        0,
        'none',
        0.00,
        1
    );

-- Keto Kitchen - Branch 2 (10 products)
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
        'Keto Bowl',
        'Build your perfect keto bowl with protein, vegetables, and healthy fats',
        0.00,
        90,
        1,
        'structured',
        480.00,
        1
    ),
    (
        @branch2_id,
        @diet14_id,
        'Custom Keto Burger',
        'Design your own keto-friendly burger with lettuce wrap or keto bun',
        0.00,
        70,
        1,
        'structured',
        450.00,
        1
    ),
    (
        @branch2_id,
        @diet18_id,
        'Keto Salad',
        'Build your own keto salad with protein and low-carb vegetables',
        0.00,
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
        'Grilled ribeye with buttered asparagus and cauliflower mash',
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
        'Scrambled pasture-raised eggs with avocado and crispy bacon',
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
        'Chicken Parmesan',
        'Grilled chicken breast with parmesan cheese and marinara sauce',
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
        'Custom Keto Plate',
        'Create your own keto plate with your choice of protein and sides',
        0.00,
        65,
        1,
        'structured',
        500.00,
        1
    ),
    (
        @branch2_id,
        @diet16_id,
        'Keto Pizza',
        'Customize your keto cauliflower crust pizza',
        0.00,
        60,
        1,
        'structured',
        420.00,
        1
    ),
    (
        @branch2_id,
        @diet19_id,
        'Salmon with Cream Sauce',
        'Pan-seared salmon with dill cream sauce and sautéed spinach',
        490.00,
        0,
        0,
        'none',
        0.00,
        1
    ),
    (
        @branch2_id,
        @diet15_id,
        'Shrimp Scampi',
        'Garlic butter shrimp with zucchini noodles',
        530.00,
        9,
        0,
        'none',
        0.00,
        1
    );

-- Asian Fusion Fit - Branch 3 (10 products)
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
        'Build Your Own Sushi',
        'Create your own sushi roll with your choice of fillings',
        0.00,
        80,
        1,
        'structured',
        350.00,
        1
    ),
    (
        @branch3_id,
        @diet24_id,
        'Custom Poke Bowl',
        'Design your own poke bowl with fresh fish and toppings',
        0.00,
        75,
        1,
        'structured',
        390.00,
        1
    ),
    (
        @branch3_id,
        @diet26_id,
        'Asian Noodle Bowl',
        'Customize your noodle bowl with protein, vegetables, and broth',
        0.00,
        70,
        1,
        'structured',
        320.00,
        1
    ),
    (
        @branch3_id,
        @diet22_id,
        'Gluten-Free Sushi Roll',
        'Fresh salmon roll with gluten-free soy sauce',
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
        'Seaweed Salad',
        'Organic seaweed salad with sesame seeds and rice vinegar',
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
        'Grilled Fish with Veggies',
        'Grilled tilapia with seasonal vegetables and herb butter',
        360.00,
        0,
        0,
        'none',
        0.00,
        1
    ),
    (
        @branch3_id,
        @diet28_id,
        'Asian Rice Bowl',
        'Build your own Asian rice bowl with your choice of protein and toppings',
        0.00,
        85,
        1,
        'structured',
        300.00,
        1
    ),
    (
        @branch3_id,
        @diet29_id,
        'Vegetable Stir Fry',
        'Customize your vegetable stir fry with your choice of vegetables and sauce',
        0.00,
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
        'Spicy tuna roll with cucumber and avocado',
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
        'Green Tea Smoothie',
        'Refreshing green tea smoothie with banana and spinach',
        200.00,
        14,
        0,
        'none',
        0.00,
        1
    );

-- =====================================================
-- 8. PRODUCT COMPOSITION (Customization rules)
-- Store product IDs in variables first to avoid subquery issues
-- =====================================================

-- Get product IDs for customization
SELECT product_id INTO @bowl_product_id
FROM product
WHERE
    name = 'Build Your Own Bowl'
    AND restaurant_branch_id = @branch1_id;

SELECT product_id INTO @sushi_product_id
FROM product
WHERE
    name = 'Build Your Own Sushi'
    AND restaurant_branch_id = @branch3_id;

SELECT
    product_id INTO @keto_bowl_product_id
FROM product
WHERE
    name = 'Keto Bowl'
    AND restaurant_branch_id = @branch2_id;

-- Get ingredient IDs
SELECT ingredient_id INTO @white_rice_id
FROM ingredient
WHERE
    name = 'White Rice';

SELECT ingredient_id INTO @brown_rice_id
FROM ingredient
WHERE
    name = 'Brown Rice';

SELECT
    ingredient_id INTO @cauliflower_rice_id
FROM ingredient
WHERE
    name = 'Cauliflower Rice';

SELECT ingredient_id INTO @quinoa_id
FROM ingredient
WHERE
    name = 'Quinoa';

SELECT
    ingredient_id INTO @grilled_chicken_id
FROM ingredient
WHERE
    name = 'Grilled Chicken';

SELECT ingredient_id INTO @tofu_id
FROM ingredient
WHERE
    name = 'Tofu';

SELECT ingredient_id INTO @beef_patty_id
FROM ingredient
WHERE
    name = 'Beef Patty';

SELECT ingredient_id INTO @lettuce_id
FROM ingredient
WHERE
    name = 'Lettuce';

SELECT ingredient_id INTO @tomato_id
FROM ingredient
WHERE
    name = 'Tomato';

SELECT ingredient_id INTO @avocado_id
FROM ingredient
WHERE
    name = 'Avocado';

SELECT ingredient_id INTO @spinach_id
FROM ingredient
WHERE
    name = 'Spinach';

SELECT ingredient_id INTO @bbq_sauce_id
FROM ingredient
WHERE
    name = 'BBQ Sauce';

SELECT ingredient_id INTO @vegan_ranch_id
FROM ingredient
WHERE
    name = 'Vegan Ranch';

SELECT
    ingredient_id INTO @miso_dressing_id
FROM ingredient
WHERE
    name = 'Miso Dressing';

SELECT ingredient_id INTO @salmon_id
FROM ingredient
WHERE
    name = 'Salmon';

SELECT ingredient_id INTO @shrimp_id
FROM ingredient
WHERE
    name = 'Shrimp';

SELECT ingredient_id INTO @eggs_id
FROM ingredient
WHERE
    name = 'Eggs';

SELECT
    ingredient_id INTO @cheddar_cheese_id
FROM ingredient
WHERE
    name = 'Cheddar Cheese';

SELECT ingredient_id INTO @cucumber_id
FROM ingredient
WHERE
    name = 'Cucumber';

SELECT ingredient_id INTO @seaweed_id
FROM ingredient
WHERE
    name = 'Seaweed';

SELECT
    ingredient_id INTO @teriyaki_sauce_id
FROM ingredient
WHERE
    name = 'Teriyaki Sauce';

SELECT
    ingredient_id INTO @sesame_dressing_id
FROM ingredient
WHERE
    name = 'Sesame Dressing';

-- Green Bowl Cafe - Build Your Own Bowl (Product 1)
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
        max_quantity_per_item
    )
VALUES
    -- Base options (required, choose 1)
    (
        @bowl_product_id,
        @white_rice_id,
        1,
        1,
        1,
        0.00,
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
        2,
        1,
        1
    ),
    (
        @bowl_product_id,
        @cauliflower_rice_id,
        0,
        0,
        1,
        10.00,
        3,
        1,
        1
    ),
    (
        @bowl_product_id,
        @quinoa_id,
        0,
        0,
        1,
        15.00,
        4,
        1,
        1
    ),
    -- Protein options (choose 1-2)
    (
        @bowl_product_id,
        @grilled_chicken_id,
        1,
        1,
        2,
        0.00,
        5,
        0,
        2
    ),
    (
        @bowl_product_id,
        @tofu_id,
        0,
        0,
        2,
        -10.00,
        6,
        0,
        2
    ),
    (
        @bowl_product_id,
        @beef_patty_id,
        0,
        0,
        2,
        15.00,
        7,
        0,
        2
    ),
    -- Vegetables (choose 2-4)
    (
        @bowl_product_id,
        @lettuce_id,
        1,
        1,
        4,
        0.00,
        8,
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
        9,
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
        10,
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
        11,
        0,
        4
    ),
    -- Sauces (choose 1)
    (
        @bowl_product_id,
        @bbq_sauce_id,
        0,
        0,
        1,
        0.00,
        12,
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
        13,
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
        14,
        0,
        1
    );

-- Keto Kitchen - Keto Bowl (Product 11)
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
        max_quantity_per_item
    )
VALUES
    -- Base options (required, choose 1)
    (
        @keto_bowl_product_id,
        @cauliflower_rice_id,
        1,
        1,
        1,
        0.00,
        1,
        1,
        1
    ),
    -- Protein options (choose 1-2)
    (
        @keto_bowl_product_id,
        @grilled_chicken_id,
        1,
        1,
        2,
        0.00,
        2,
        0,
        2
    ),
    (
        @keto_bowl_product_id,
        @salmon_id,
        0,
        0,
        2,
        25.00,
        3,
        0,
        2
    ),
    (
        @keto_bowl_product_id,
        @beef_patty_id,
        0,
        0,
        2,
        15.00,
        4,
        0,
        2
    ),
    (
        @keto_bowl_product_id,
        @eggs_id,
        0,
        0,
        2,
        10.00,
        5,
        0,
        2
    ),
    -- Healthy fats (choose 1-2)
    (
        @keto_bowl_product_id,
        @avocado_id,
        1,
        1,
        2,
        0.00,
        6,
        0,
        2
    ),
    (
        @keto_bowl_product_id,
        @cheddar_cheese_id,
        0,
        0,
        2,
        10.00,
        7,
        0,
        2
    ),
    -- Vegetables
    (
        @keto_bowl_product_id,
        @spinach_id,
        1,
        1,
        3,
        0.00,
        8,
        0,
        3
    ),
    (
        @keto_bowl_product_id,
        @cucumber_id,
        0,
        0,
        3,
        0.00,
        9,
        0,
        3
    );

-- Asian Fusion Fit - Build Your Own Sushi (Product 21)
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
        max_quantity_per_item
    )
VALUES
    -- Base options (required)
    (
        @sushi_product_id,
        @seaweed_id,
        1,
        1,
        1,
        0.00,
        1,
        1,
        1
    ),
    -- Protein options (choose 1)
    (
        @sushi_product_id,
        @salmon_id,
        1,
        1,
        1,
        0.00,
        2,
        1,
        1
    ),
    (
        @sushi_product_id,
        @tofu_id,
        0,
        0,
        1,
        -10.00,
        3,
        1,
        1
    ),
    (
        @sushi_product_id,
        @shrimp_id,
        0,
        0,
        1,
        20.00,
        4,
        1,
        1
    ),
    -- Fillings (choose 2-4)
    (
        @sushi_product_id,
        @cucumber_id,
        1,
        1,
        4,
        0.00,
        5,
        0,
        4
    ),
    (
        @sushi_product_id,
        @avocado_id,
        0,
        0,
        4,
        10.00,
        6,
        0,
        4
    ),
    -- Sauces
    (
        @sushi_product_id,
        @teriyaki_sauce_id,
        0,
        0,
        1,
        0.00,
        7,
        0,
        1
    ),
    (
        @sushi_product_id,
        @sesame_dressing_id,
        0,
        0,
        1,
        0.00,
        8,
        0,
        1
    );

COMMIT;

-- =====================================================
-- VERIFICATION QUERIES
-- =====================================================
SELECT '=== Test Data Inserted ===' AS status;

SELECT COUNT(*) AS total_restaurants FROM restaurant;

SELECT COUNT(*) AS total_branches FROM restaurant_branch;

SELECT COUNT(*) AS total_products FROM product;

SELECT COUNT(*) AS customizable_products
FROM product
WHERE
    is_customizable = 1;

SELECT COUNT(*) AS non_customizable_products
FROM product
WHERE
    is_customizable = 0;

SELECT COUNT(*) AS active_products
FROM product
WHERE
    is_active = 1
    AND stock > 0;

SELECT COUNT(*) AS out_of_stock FROM product WHERE stock = 0;

-- Show breakdown by restaurant with customization stats
SELECT
    r.business_name,
    COUNT(p.product_id) AS total_products,
    SUM(
        CASE
            WHEN p.is_customizable = 1 THEN 1
            ELSE 0
        END
    ) AS customizable,
    SUM(
        CASE
            WHEN p.is_customizable = 0 THEN 1
            ELSE 0
        END
    ) AS non_customizable,
    SUM(
        CASE
            WHEN p.stock > 0
            AND p.is_active = 1 THEN 1
            ELSE 0
        END
    ) AS active_products,
    SUM(
        CASE
            WHEN p.stock = 0 THEN 1
            ELSE 0
        END
    ) AS out_of_stock
FROM
    restaurant r
    JOIN restaurant_branch rb ON r.restaurant_id = rb.restaurant_id
    JOIN product p ON rb.restaurant_branch_id = p.restaurant_branch_id
GROUP BY
    r.business_name;

-- Show customization composition statistics
SELECT
    p.name AS product_name,
    COUNT(pc.composition_id) AS total_ingredient_options,
    SUM(
        CASE
            WHEN pc.is_required = 1 THEN 1
            ELSE 0
        END
    ) AS required_options,
    SUM(
        CASE
            WHEN pc.is_default = 1 THEN 1
            ELSE 0
        END
    ) AS default_options
FROM
    product p
    JOIN product_composition pc ON p.product_id = pc.product_id
WHERE
    p.is_customizable = 1
GROUP BY
    p.product_id,
    p.name
ORDER BY p.name;