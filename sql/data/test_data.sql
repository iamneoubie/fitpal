-- =====================================================
-- TEST DATA FOR FitPal
-- Run this after the main schema is created.
-- =====================================================

USE fitpal_food_delivery;

START TRANSACTION;

-- =====================================================
-- 1. FINANCIAL ACCOUNTS
-- =====================================================
INSERT INTO financial_account (balance, account_type) VALUES (100.00, 'customer');
SET @customer_financial_id = LAST_INSERT_ID();

INSERT INTO financial_account (balance, account_type) VALUES (0.00, 'restaurant');
SET @branch1_financial_id = LAST_INSERT_ID();

INSERT INTO financial_account (balance, account_type) VALUES (0.00, 'restaurant');
SET @branch2_financial_id = LAST_INSERT_ID();

INSERT INTO financial_account (balance, account_type) VALUES (0.00, 'restaurant');
SET @branch3_financial_id = LAST_INSERT_ID();

-- =====================================================
-- 2. ADMINISTRATOR (owner of restaurants)
-- =====================================================
INSERT INTO administrator (first_name, last_name, email, username, password, is_active)
VALUES ('Admin', 'User', 'admin@fitpal.com', 'admin', 'admin123', 1);
SET @admin_id = LAST_INSERT_ID();

-- =====================================================
-- 3. CUSTOMER (test user)
-- =====================================================
INSERT INTO customer (
    first_name, last_name, email, contact_number, username, password, is_active
) VALUES (
    'Test', 'User', 'test@example.com', '09123456789', 'testuser', 'admin123', 1
);
SET @customer_id = LAST_INSERT_ID();

INSERT INTO customer_profile (
    customer_id, financial_account_id,
    dietary_preferences, allergies, fitness_goal, height_cm, weight_kg
) VALUES (
    @customer_id, @customer_financial_id,
    'vegan,gluten_free', 'nuts', 'maintenance', 175.0, 70.0
);

-- =====================================================
-- 4. RESTAURANTS & BRANCHES
-- =====================================================

-- Restaurant 1: Green Bowl Cafe
INSERT INTO restaurant (owner_id, business_name, cuisine_type, dietary_tags, is_active)
VALUES (@admin_id, 'Green Bowl Cafe', 'Filipino', 'vegan,organic', 1);
SET @rest1_id = LAST_INSERT_ID();

INSERT INTO restaurant_branch (
    restaurant_id, financial_account_id, branch_name, branch_code,
    barangay, city, province, is_active
) VALUES (
    @rest1_id, @branch1_financial_id, 'Main Branch', 'GBC001',
    'Barangay San Antonio', 'Pasig', 'Metro Manila', 1
);
SET @branch1_id = LAST_INSERT_ID();

-- Restaurant 2: Keto Kitchen
INSERT INTO restaurant (owner_id, business_name, cuisine_type, dietary_tags, is_active)
VALUES (@admin_id, 'Keto Kitchen', 'Italian', 'keto,high_protein', 1);
SET @rest2_id = LAST_INSERT_ID();

INSERT INTO restaurant_branch (
    restaurant_id, financial_account_id, branch_name, branch_code,
    barangay, city, province, is_active
) VALUES (
    @rest2_id, @branch2_financial_id, 'Main Branch', 'KK001',
    'Barangay San Lorenzo', 'Makati', 'Metro Manila', 1
);
SET @branch2_id = LAST_INSERT_ID();

-- Restaurant 3: Asian Fusion Fit
INSERT INTO restaurant (owner_id, business_name, cuisine_type, dietary_tags, is_active)
VALUES (@admin_id, 'Asian Fusion Fit', 'Japanese', 'gluten_free,low_carb', 1);
SET @rest3_id = LAST_INSERT_ID();

INSERT INTO restaurant_branch (
    restaurant_id, financial_account_id, branch_name, branch_code,
    barangay, city, province, is_active
) VALUES (
    @rest3_id, @branch3_financial_id, 'Main Branch', 'AFF001',
    'Barangay San Miguel', 'Quezon City', 'Metro Manila', 1
);
SET @branch3_id = LAST_INSERT_ID();

-- =====================================================
-- 5. DIETARY INFORMATION (nutritional data)
-- =====================================================

-- For Green Bowl products
INSERT INTO dietary_information (
    category, dietary_tags, allergens, calories, protein, carbs, fat
) VALUES
    ('Main', 'vegan,gluten_free', 'soy', 350, 15.0, 45.0, 10.0);
SET @diet1_id = LAST_INSERT_ID();

INSERT INTO dietary_information (
    category, dietary_tags, allergens, calories, protein, carbs, fat
) VALUES
    ('Appetizer', 'vegan', 'none', 150, 5.0, 20.0, 5.0);
SET @diet2_id = LAST_INSERT_ID();

INSERT INTO dietary_information (
    category, dietary_tags, allergens, calories, protein, carbs, fat
) VALUES
    ('Beverage', 'vegan,gluten_free', 'nuts', 120, 2.0, 25.0, 1.0);
SET @diet3_id = LAST_INSERT_ID();

-- For Keto Kitchen
INSERT INTO dietary_information (
    category, dietary_tags, allergens, calories, protein, carbs, fat
) VALUES
    ('Main', 'keto,high_protein', 'dairy', 600, 45.0, 8.0, 42.0);
SET @diet4_id = LAST_INSERT_ID();

INSERT INTO dietary_information (
    category, dietary_tags, allergens, calories, protein, carbs, fat
) VALUES
    ('Main', 'keto', 'eggs', 520, 35.0, 10.0, 38.0);
SET @diet5_id = LAST_INSERT_ID();

INSERT INTO dietary_information (
    category, dietary_tags, allergens, calories, protein, carbs, fat
) VALUES
    ('Dessert', 'keto', 'dairy', 280, 10.0, 6.0, 24.0);
SET @diet6_id = LAST_INSERT_ID();

-- For Asian Fusion Fit
INSERT INTO dietary_information (
    category, dietary_tags, allergens, calories, protein, carbs, fat
) VALUES
    ('Main', 'gluten_free,low_carb', 'shellfish', 380, 28.0, 15.0, 20.0);
SET @diet7_id = LAST_INSERT_ID();

INSERT INTO dietary_information (
    category, dietary_tags, allergens, calories, protein, carbs, fat
) VALUES
    ('Main', 'gluten_free', 'fish', 420, 32.0, 18.0, 22.0);
SET @diet8_id = LAST_INSERT_ID();

INSERT INTO dietary_information (
    category, dietary_tags, allergens, calories, protein, carbs, fat
) VALUES
    ('Appetizer', 'low_carb', 'soy', 90, 6.0, 4.0, 5.0);
SET @diet9_id = LAST_INSERT_ID();

-- =====================================================
-- 6. PRODUCTS
-- =====================================================

-- Green Bowl Cafe products
INSERT INTO product (
    restaurant_branch_id, dietary_information_id,
    name, description, price, stock, is_active
) VALUES
    (@branch1_id, @diet1_id,
     'Vegan Tofu Bowl', 'Seasoned tofu with brown rice and fresh veggies', 250.00, 15, 1),
    (@branch1_id, @diet2_id,
     'Edamame Salad', 'Steamed edamame with a light vinaigrette', 120.00, 30, 1),
    (@branch1_id, @diet3_id,
     'Almond Milk Smoothie', 'Creamy almond milk with banana and spinach', 180.00, 10, 1);

-- Keto Kitchen products
INSERT INTO product (
    restaurant_branch_id, dietary_information_id,
    name, description, price, stock, is_active
) VALUES
    (@branch2_id, @diet4_id,
     'Keto Steak Plate', 'Grilled ribeye with buttered vegetables', 450.00, 8, 1),
    (@branch2_id, @diet5_id,
     'Egg & Avocado Bowl', 'Scrambled eggs with avocado and bacon', 320.00, 12, 1),
    (@branch2_id, @diet6_id,
     'Keto Cheesecake', 'Low-carb cheesecake with berry topping', 200.00, 5, 1);

-- Asian Fusion Fit products
INSERT INTO product (
    restaurant_branch_id, dietary_information_id,
    name, description, price, stock, is_active
) VALUES
    (@branch3_id, @diet7_id,
     'Gluten-Free Sushi Roll', 'Fresh salmon roll with gluten-free soy sauce', 380.00, 20, 1),
    (@branch3_id, @diet8_id,
     'Grilled Fish with Veggies', 'Grilled tilapia with seasonal vegetables', 350.00, 0, 1),  -- out of stock to test
    (@branch3_id, @diet9_id,
     'Spicy Edamame', 'Edamame tossed in chili and garlic', 110.00, 25, 1);

COMMIT;

-- =====================================================
-- VERIFICATION (optional)
-- =====================================================
-- SELECT 'Test data inserted successfully!' AS status;