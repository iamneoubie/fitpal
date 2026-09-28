-- =====================================================
-- FitPal Seed Data — 2 of 3: CONTENT
-- Version 6.0
--
-- ALIGNED WITH: fitpal_food_delivery schema v1.3.1
--
-- RUN ORDER (strict):
--   1. seed-1-core.sql      (structure & essential values)
--   2. seed-2-content.sql   ← this file
--   3. seed-3-paths.sql     (profile pictures, permits, documents)
--
-- RESPONSIBILITY
-- --------------
-- Human-readable copy. Pure UPDATE statements that back-fill
-- nullable text columns left NULL by seed 1:
--   * restaurant.description   (3 rows)
--   * product.description      (30 rows)
--   * dietary_information.images  (optional; currently no-op)
--
-- This file inserts NO new rows and changes NO foreign keys.
-- Running it twice produces the same result (idempotent).
--
-- MATCHING KEYS
-- -------------
-- Restaurants are matched on business_name (unique in practice).
-- Products are matched on (restaurant.business_name, product.name)
-- so the file is readable without tracking product_id order.
-- =====================================================

USE fitpal_food_delivery;

START TRANSACTION;

-- =====================================================
-- 1. RESTAURANT DESCRIPTIONS
-- =====================================================
UPDATE restaurant
SET
    description = 'Plant-forward cafe serving vibrant grain bowls, crisp salads, and dairy-free smoothies. The menu leans vegan and gluten-free, with a rotating cast of seasonal vegetables and clean proteins.'
WHERE
    business_name = 'Green Bowl Cafe';

UPDATE restaurant
SET
    description = 'A high-fat, low-carb kitchen built for keto and performance-focused diners. Every plate is macro-conscious, from bunless smash burgers to cauliflower-crust pizza and buttered steak plates.'
WHERE
    business_name = 'Keto Kitchen';

UPDATE restaurant
SET
    description = 'Gluten-free Asian fusion with a halal-friendly menu. Fresh hand rolls, rainbow poke bowls, and light noodle plates built for clean eating without losing flavor.'
WHERE
    business_name = 'Asian Fusion Fit';

-- =====================================================
-- 2. PRODUCT DESCRIPTIONS
-- =====================================================
-- Daily Values used (FDA, 2,000 kcal reference):
--   Protein 50g, Carbs 275g, Fat 78g,
--   Dietary Fiber 28g, Vitamin A 900mcg, Vitamin C 90mg.
-- Calories and Sugars have no established Daily Value and are
-- shown in units only.

-- -----------------------------------------------------
-- Green Bowl Cafe
-- -----------------------------------------------------
UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
SET
    p.description = 'A vibrant bowl built on a warm grain base with a choice of protein and a rotating cast of seasonal vegetables. Bright, balanced, and filling without being heavy.

Per serving (default build):
Calories: 390 kcal
Protein: 39g (78%)
Carbs: 45g (16%)
Fat: 10.5g (13%)
Dietary Fiber: 6g (21%)
Sugars: 3g
Vitamin A: 420mcg (47%)
Vitamin C: 18mg (20%)'
WHERE
    r.business_name = 'Green Bowl Cafe'
    AND p.name = 'Garden Harvest Bowl';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
SET
    p.description = 'A crisp, refreshing salad built on a bed of fresh greens with layered proteins and a light house dressing. Designed to be adjusted to your own taste.

Per serving (default build):
Calories: 210 kcal
Protein: 5g (10%)
Carbs: 12g (4%)
Fat: 8.5g (11%)
Dietary Fiber: 4g (14%)
Sugars: 2g
Vitamin A: 380mcg (42%)
Vitamin C: 22mg (24%)'
WHERE
    r.business_name = 'Green Bowl Cafe'
    AND p.name = 'Market Greens Salad';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
SET
    p.description = 'A thick, spoonable smoothie built for breakfast or a post-workout refill. Choose your base, mix-ins, and protein add-ons to match your goals.

Per serving (default build):
Calories: 415 kcal
Protein: 8g (16%)
Carbs: 48g (17%)
Fat: 8g (10%)
Dietary Fiber: 7g (25%)
Sugars: 5g
Vitamin A: 90mcg (10%)
Vitamin C: 8mg (9%)'
WHERE
    r.business_name = 'Green Bowl Cafe'
    AND p.name = 'Morning Power Smoothie';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
SET
    p.description = 'A simple, satisfying bowl of seasoned tofu over brown rice with fresh seasonal vegetables. A go-to for plant-based regulars.

Per serving:
Calories: 280 kcal
Protein: 12g (24%)
Carbs: 38g (14%)
Fat: 8g (10%)
Dietary Fiber: 6g (21%)
Sugars: 2g
Vitamin A: 210mcg (23%)
Vitamin C: 14mg (16%)'
WHERE
    r.business_name = 'Green Bowl Cafe'
    AND p.name = 'Classic Vegan Bowl';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
SET
    p.description = 'Steamed edamame tossed with a bright citrus vinaigrette over fresh greens. Light, clean, and packed with plant protein.

Per serving:
Calories: 290 kcal
Protein: 18g (36%)
Carbs: 22g (8%)
Fat: 14g (18%)
Dietary Fiber: 8g (29%)
Sugars: 4g
Vitamin A: 160mcg (18%)
Vitamin C: 26mg (29%)'
WHERE
    r.business_name = 'Green Bowl Cafe'
    AND p.name = 'Edamame Citrus Salad';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
SET
    p.description = 'Spiralized zucchini tossed with basil pesto and blistered cherry tomatoes. A low-carb take on a pasta night classic.

Per serving:
Calories: 120 kcal
Protein: 4g (8%)
Carbs: 15g (5%)
Fat: 4g (5%)
Dietary Fiber: 3g (11%)
Sugars: 3g
Vitamin A: 140mcg (16%)
Vitamin C: 20mg (22%)'
WHERE
    r.business_name = 'Green Bowl Cafe'
    AND p.name = 'Zucchini Noodle Pesto';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
SET
    p.description = 'A warm breakfast bowl with quinoa, soft-scrambled eggs, and vegetables. A gentle start to the day that still keeps you full.

Per serving (default build):
Calories: 420 kcal
Protein: 32g (64%)
Carbs: 18g (7%)
Fat: 22g (28%)
Dietary Fiber: 5g (18%)
Sugars: 2g
Vitamin A: 310mcg (34%)
Vitamin C: 12mg (13%)'
WHERE
    r.business_name = 'Green Bowl Cafe'
    AND p.name = 'Sunrise Breakfast Bowl';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
SET
    p.description = 'A fresh poke-style bowl with cubed salmon, crisp vegetables, and a sesame-soy finish. Bright, clean, and easy to adjust.

Per serving (default build):
Calories: 380 kcal
Protein: 38g (76%)
Carbs: 42g (15%)
Fat: 18.5g (24%)
Dietary Fiber: 4g (14%)
Sugars: 3g
Vitamin A: 180mcg (20%)
Vitamin C: 16mg (18%)'
WHERE
    r.business_name = 'Green Bowl Cafe'
    AND p.name = 'Seaside Poke Bowl';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
SET
    p.description = 'A colorful bowl of kale, roasted sweet potato, and chickpeas finished with a turmeric-tahini sauce. Vegan, gluten-free, and rich in fiber.

Per serving:
Calories: 350 kcal
Protein: 16g (32%)
Carbs: 28g (10%)
Fat: 16g (21%)
Dietary Fiber: 9g (32%)
Sugars: 4g
Vitamin A: 680mcg (76%)
Vitamin C: 34mg (38%)'
WHERE
    r.business_name = 'Green Bowl Cafe'
    AND p.name = 'Superfood Buddha Bowl';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
SET
    p.description = 'A blended smoothie of mixed berries, plant-based protein, and almond milk. Naturally sweet, dairy-free, and filling.

Per serving:
Calories: 180 kcal
Protein: 8g (16%)
Carbs: 12g (4%)
Fat: 14g (18%)
Dietary Fiber: 4g (14%)
Sugars: 6g
Vitamin A: 60mcg (7%)
Vitamin C: 18mg (20%)'
WHERE
    r.business_name = 'Green Bowl Cafe'
    AND p.name = 'Berry Almond Smoothie';

-- -----------------------------------------------------
-- Keto Kitchen
-- -----------------------------------------------------
UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
SET
    p.description = 'A hearty, low-carb bowl built on cauliflower rice with a choice of protein, healthy fats, and greens. Designed to keep you full without the carbs.

Per serving (default build):
Calories: 510 kcal
Protein: 52g (104%)
Carbs: 12g (4%)
Fat: 38g (49%)
Dietary Fiber: 7g (25%)
Sugars: 3g
Vitamin A: 480mcg (53%)
Vitamin C: 24mg (27%)'
WHERE
    r.business_name = 'Keto Kitchen'
    AND p.name = 'Keto Power Bowl';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
SET
    p.description = 'A juicy beef patty with melted cheese and crisp lettuce, served bunless or with a keto-friendly wrap. Customizable down to the toppings.

Per serving (default build):
Calories: 520 kcal
Protein: 48g (96%)
Carbs: 6g (2%)
Fat: 36g (46%)
Dietary Fiber: 2g (7%)
Sugars: 1g
Vitamin A: 320mcg (36%)
Vitamin C: 6mg (7%)'
WHERE
    r.business_name = 'Keto Kitchen'
    AND p.name = 'Keto Smash Burger';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
SET
    p.description = 'A low-carb salad built on a bed of spinach with a choice of protein and fresh vegetables. Light but satisfying.

Per serving (default build):
Calories: 620 kcal
Protein: 56g (112%)
Carbs: 4g (1%)
Fat: 46g (59%)
Dietary Fiber: 3g (11%)
Sugars: 1g
Vitamin A: 520mcg (58%)
Vitamin C: 14mg (16%)'
WHERE
    r.business_name = 'Keto Kitchen'
    AND p.name = 'Keto Garden Salad';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
SET
    p.description = 'Grilled ribeye with buttered asparagus and a creamy cauliflower mash. A full keto plate with no compromises.

Per serving:
Calories: 480 kcal
Protein: 38g (76%)
Carbs: 8g (3%)
Fat: 34g (44%)
Dietary Fiber: 2g (7%)
Sugars: 1g
Vitamin A: 280mcg (31%)
Vitamin C: 10mg (11%)'
WHERE
    r.business_name = 'Keto Kitchen'
    AND p.name = 'Keto Steak Plate';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
SET
    p.description = 'Soft-scrambled eggs with sliced avocado, crispy bacon, and a touch of cheddar. A simple, high-fat breakfast or brunch.

Per serving:
Calories: 200 kcal
Protein: 12g (24%)
Carbs: 4g (1%)
Fat: 16g (21%)
Dietary Fiber: 1g (4%)
Sugars: 0g
Vitamin A: 240mcg (27%)
Vitamin C: 4mg (4%)'
WHERE
    r.business_name = 'Keto Kitchen'
    AND p.name = 'Egg & Avocado Bowl';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
SET
    p.description = 'Grilled chicken breast topped with parmesan and a rich marinara, served with sautéed greens. Keto-friendly and deeply savory.

Per serving:
Calories: 540 kcal
Protein: 50g (100%)
Carbs: 5g (2%)
Fat: 38g (49%)
Dietary Fiber: 2g (7%)
Sugars: 2g
Vitamin A: 360mcg (40%)
Vitamin C: 12mg (13%)'
WHERE
    r.business_name = 'Keto Kitchen'
    AND p.name = 'Chicken Parmesan Plate';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
SET
    p.description = 'A customizable keto plate with your choice of protein and sides. Built for people who want control over their macros.

Per serving (default build):
Calories: 140 kcal
Protein: 16g (32%)
Carbs: 3g (1%)
Fat: 12g (15%)
Dietary Fiber: 1g (4%)
Sugars: 0g
Vitamin A: 180mcg (20%)
Vitamin C: 3mg (3%)'
WHERE
    r.business_name = 'Keto Kitchen'
    AND p.name = 'Keto Butcher Plate';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
SET
    p.description = 'A cauliflower-crust pizza topped with cheddar, parmesan, and grilled chicken. Customizable with extra keto toppings.

Per serving (default build):
Calories: 500 kcal
Protein: 42g (84%)
Carbs: 8g (3%)
Fat: 34g (44%)
Dietary Fiber: 3g (11%)
Sugars: 2g
Vitamin A: 300mcg (33%)
Vitamin C: 8mg (9%)'
WHERE
    r.business_name = 'Keto Kitchen'
    AND p.name = 'Keto Cauliflower Pizza';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
SET
    p.description = 'Pan-seared salmon finished with a dill cream sauce and sautéed spinach. Rich, silky, and low-carb.

Per serving:
Calories: 110 kcal
Protein: 3g (6%)
Carbs: 6g (2%)
Fat: 8g (10%)
Dietary Fiber: 2g (7%)
Sugars: 1g
Vitamin A: 220mcg (24%)
Vitamin C: 5mg (6%)'
WHERE
    r.business_name = 'Keto Kitchen'
    AND p.name = 'Salmon Dill Plate';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
SET
    p.description = 'Garlic-butter shrimp over zucchini noodles with a light lemon finish. Keto, gluten-free, and bright.

Per serving:
Calories: 250 kcal
Protein: 8g (16%)
Carbs: 5g (2%)
Fat: 22g (28%)
Dietary Fiber: 1g (4%)
Sugars: 2g
Vitamin A: 140mcg (16%)
Vitamin C: 8mg (9%)'
WHERE
    r.business_name = 'Keto Kitchen'
    AND p.name = 'Shrimp Scampi Zoodles';

-- -----------------------------------------------------
-- Asian Fusion Fit
-- -----------------------------------------------------
UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
SET
    p.description = 'A build-your-own hand roll set with toasted nori, a choice of protein, and fresh fillings. Served with gluten-free sauces.

Per serving (default build):
Calories: 330 kcal
Protein: 37g (74%)
Carbs: 4g (1%)
Fat: 18g (23%)
Dietary Fiber: 3g (11%)
Sugars: 1g
Vitamin A: 120mcg (13%)
Vitamin C: 6mg (7%)'
WHERE
    r.business_name = 'Asian Fusion Fit'
    AND p.name = 'Nori Hand Roll Set';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
SET
    p.description = 'A colorful poke bowl with fresh salmon, crisp vegetables, and a sesame-soy finish. Gluten-free and halal-friendly.

Per serving (default build):
Calories: 85 kcal
Protein: 4g (8%)
Carbs: 14g (5%)
Fat: 2g (3%)
Dietary Fiber: 4g (14%)
Sugars: 3g
Vitamin A: 100mcg (11%)
Vitamin C: 18mg (20%)'
WHERE
    r.business_name = 'Asian Fusion Fit'
    AND p.name = 'Rainbow Poke Bowl';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
SET
    p.description = 'A warm noodle bowl with a choice of protein, fresh vegetables, and a light broth. Customizable to your spice and sauce preference.

Per serving (default build):
Calories: 450 kcal
Protein: 42g (84%)
Carbs: 10g (4%)
Fat: 26g (33%)
Dietary Fiber: 3g (11%)
Sugars: 2g
Vitamin A: 260mcg (29%)
Vitamin C: 14mg (16%)'
WHERE
    r.business_name = 'Asian Fusion Fit'
    AND p.name = 'Tokyo Noodle Bowl';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
SET
    p.description = 'A fresh salmon roll wrapped in nori and served with gluten-free soy sauce. Clean, simple, and satisfying.

Per serving:
Calories: 390 kcal
Protein: 26g (52%)
Carbs: 18g (7%)
Fat: 18g (23%)
Dietary Fiber: 3g (11%)
Sugars: 2g
Vitamin A: 140mcg (16%)
Vitamin C: 8mg (9%)'
WHERE
    r.business_name = 'Asian Fusion Fit'
    AND p.name = 'Gluten-Free Salmon Roll';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
SET
    p.description = 'A chilled seaweed salad tossed with sesame seeds and rice vinegar. Light, briny, and refreshing.

Per serving:
Calories: 310 kcal
Protein: 30g (60%)
Carbs: 14g (5%)
Fat: 18g (23%)
Dietary Fiber: 4g (14%)
Sugars: 2g
Vitamin A: 180mcg (20%)
Vitamin C: 10mg (11%)'
WHERE
    r.business_name = 'Asian Fusion Fit'
    AND p.name = 'Seaweed Sesame Salad';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
SET
    p.description = 'Grilled tilapia with seasonal vegetables and a light herb butter. Simple, clean, and halal-friendly.

Per serving:
Calories: 160 kcal
Protein: 6g (12%)
Carbs: 14g (5%)
Fat: 12g (15%)
Dietary Fiber: 3g (11%)
Sugars: 4g
Vitamin A: 200mcg (22%)
Vitamin C: 16mg (18%)'
WHERE
    r.business_name = 'Asian Fusion Fit'
    AND p.name = 'Grilled Fish & Greens';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
SET
    p.description = 'A customizable Japanese rice bowl with a choice of protein and toppings. Comforting and easy to tailor.

Per serving (default build):
Calories: 490 kcal
Protein: 46g (92%)
Carbs: 8g (3%)
Fat: 30g (38%)
Dietary Fiber: 2g (7%)
Sugars: 1g
Vitamin A: 220mcg (24%)
Vitamin C: 9mg (10%)'
WHERE
    r.business_name = 'Asian Fusion Fit'
    AND p.name = 'Osaka Rice Bowl';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
SET
    p.description = 'A colorful stir fry of seasonal vegetables tossed in a light sauce. Customizable with protein and sauce choices.

Per serving (default build):
Calories: 115 kcal
Protein: 20g (40%)
Carbs: 6g (2%)
Fat: 8g (10%)
Dietary Fiber: 3g (11%)
Sugars: 2g
Vitamin A: 320mcg (36%)
Vitamin C: 28mg (31%)'
WHERE
    r.business_name = 'Asian Fusion Fit'
    AND p.name = 'Wok-Tossed Vegetables';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
SET
    p.description = 'A spicy tuna roll with cucumber and avocado, finished with a light chili glaze. Halal-friendly and gluten-free.

Per serving:
Calories: 440 kcal
Protein: 34g (68%)
Carbs: 12g (4%)
Fat: 22g (28%)
Dietary Fiber: 3g (11%)
Sugars: 2g
Vitamin A: 160mcg (18%)
Vitamin C: 12mg (13%)'
WHERE
    r.business_name = 'Asian Fusion Fit'
    AND p.name = 'Spicy Tuna Roll';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
SET
    p.description = 'A refreshing green tea smoothie blended with banana and spinach. Lightly sweet and dairy-free.

Per serving:
Calories: 75 kcal
Protein: 2g (4%)
Carbs: 4g (1%)
Fat: 5g (6%)
Dietary Fiber: 2g (7%)
Sugars: 3g
Vitamin A: 80mcg (9%)
Vitamin C: 10mg (11%)'
WHERE
    r.business_name = 'Asian Fusion Fit'
    AND p.name = 'Matcha Banana Smoothie';

COMMIT;

-- =====================================================
-- VERIFICATION — CONTENT
-- =====================================================
SELECT '=== seed-2-content.sql loaded (v6.0) ===' AS status;

-- C1. No restaurant has a NULL or empty description.
SELECT
    restaurant_id,
    business_name,
    description
FROM restaurant
WHERE
    description IS NULL
    OR description = '';

-- C2. No product has a NULL or empty description.
SELECT product_id, name, description
FROM product
WHERE
    description IS NULL
    OR description = '';

-- C3. Sanity: every product description mentions a Calorie line.
SELECT p.product_id, p.name
FROM product p
WHERE
    p.description NOT LIKE '%Calories:%';