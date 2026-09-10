-- =====================================================
-- FitPal Seed Data
-- Version 3.0
--
-- CHANGES FROM 2.1
--   A. Every default row now has price_modifier = 0.00.
--      Previously three defaults carried non-zero modifiers
--      (Keto Burger Cheddar, Keto Pizza Parmesan, Keto Pizza
--      Grilled Chicken), which made the customize step's
--      implied base disagree with the menu card's displayed
--      base.
--   B. Every non-default member of a choice group now has
--      is_required = 0. Previously all members had
--      is_required = 1, which read as "all four alternatives
--      are required" even though only one is chosen. The
--      runtime already OR-aggregated the flag, so this is a
--      data-hygiene fix, not a behavior change.
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
        'vegan',
        'soy',
        280,
        12.0,
        38.0,
        8.0
    ),
    (
        'Main',
        'vegan,gluten_free',
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
        'gluten_free',
        'shellfish',
        350,
        26.0,
        18.0,
        18.0
    ),
    (
        'Appetizer',
        'gluten_free,organic',
        'soy',
        95,
        5.0,
        12.0,
        4.0
    ),
    (
        'Main',
        'low_carb,organic',
        'fish',
        310,
        30.0,
        14.0,
        18.0
    ),
    (
        'Main',
        'gluten_free,low_carb,organic',
        'shellfish',
        390,
        34.0,
        12.0,
        22.0
    ),
    (
        'Appetizer',
        'gluten_free,vegan,organic',
        'none',
        85,
        4.0,
        14.0,
        2.0
    ),
    (
        'Main',
        'gluten_free,low_carb,organic,high_protein',
        'fish,shellfish',
        450,
        42.0,
        10.0,
        26.0
    ),
    (
        'Dessert',
        'gluten_free,vegan,low_carb,organic',
        'nuts',
        160,
        6.0,
        14.0,
        12.0
    ),
    (
        'Main',
        'gluten_free,low_carb,organic,high_protein,keto',
        'shellfish',
        490,
        46.0,
        8.0,
        30.0
    ),
    (
        'Appetizer',
        'gluten_free,low_carb,organic,vegan,high_protein',
        'soy',
        115,
        20.0,
        6.0,
        8.0
    ),
    (
        'Beverage',
        'gluten_free,low_carb,organic,vegan,keto',
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
-- Green Bowl Cafe
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
        'Custom Salad',
        'Design your own fresh salad with premium ingredients',
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
        'Custom Protein Smoothie',
        'Create your own protein smoothie with your choice of fruits and supplements',
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
        'Poke Bowl',
        'Build your own poke bowl with fresh fish and vegetables',
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

SELECT MAX(product_id) INTO @last_product_green FROM product;

SET @bowl_product_id = @last_product_green - 9;

SET @salad_product_id = @last_product_green - 8;

SET @smoothie_product_id = @last_product_green - 7;

SET @breakfast_bowl_id = @last_product_green - 3;

SET @poke_product_id = @last_product_green - 2;

-- Keto Kitchen
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
        'Custom Keto Burger',
        'Design your own keto-friendly burger with lettuce wrap or keto bun',
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
        'Keto Salad',
        'Build your own keto salad with protein and low-carb vegetables',
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
        'Keto Pizza',
        'Customize your keto cauliflower crust pizza',
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
        'Salmon with Cream Sauce',
        'Pan-seared salmon with dill cream sauce and sautéed spinach',
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
        'Shrimp Scampi',
        'Garlic butter shrimp with zucchini noodles',
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

-- Asian Fusion Fit
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
        'Custom Poke Bowl',
        'Design your own poke bowl with fresh fish and toppings',
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
        'Asian Noodle Bowl',
        'Customize your noodle bowl with protein, vegetables, and broth',
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
        8,
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
        'Vegetable Stir Fry',
        'Customize your vegetable stir fry with your choice of vegetables and sauce',
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
-- Build Your Own Bowl (product 1)
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
-- Custom Salad (product 2)
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
-- Custom Protein Smoothie (product 3)
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
-- Breakfast Bowl (product 7)
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
-- Poke Bowl (product 8)
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
-- Keto Bowl (product 11)
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
-- Custom Keto Burger (product 12)
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
    -- Cheese: Cheddar default (price_modifier 0.00, base price includes it)
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
-- Keto Salad (product 13)
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
-- Custom Keto Plate (product 17)
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
-- Keto Pizza (product 18)
-- -----------------------------------------------------
-- All four defaults now have price_modifier = 0.00.
-- Base price 420.00 already includes Cauliflower, Cheddar,
-- Parmesan, and Grilled Chicken at their default quantities.
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
-- Build Your Own Sushi (product 21)
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
-- Custom Poke Bowl (product 22)
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
-- Asian Noodle Bowl (product 23)
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
-- Asian Rice Bowl (product 27)
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
-- Vegetable Stir Fry (product 28)
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

-- Every customizable product must have its stored calories equal to
-- the sum of its default composition calories.
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