-- =====================================================
-- FitPal Seed Data — 3 of 3: PATHS
-- Version 7.1
--
-- ALIGNED WITH: fitpal_food_delivery schema v1.3.1
--
-- RUN ORDER (strict):
--   1. seed-1-core.sql      (structure & essential values)
--   2. seed-2-content.sql   (descriptions & copy)
--   3. seed-3-paths.sql     ← this file
--
-- RESPONSIBILITY
-- --------------
-- Everything that points at a file on disk:
--   * *_profile.profile_picture     (UPDATE)
--   * restaurant_permit rows        (INSERT)
--   * delivery_rider_document rows  (INSERT)
--   * dietary_information.images    (UPDATE)  ← new in v7.1
--
-- v7.1 — product image folder paths
-- ---------------------------------
-- dietary_information.images now stores a project-root-relative
-- FOLDER path per product, the same shape that
-- customer_profile.profile_picture stores a file path.
--
-- The reader (getProductImageFilenames() in
-- customer/backend/database/product-queries.php) globs that folder
-- for image-1.*, image-2.*, ... image-5.* and returns the ordered
-- list of filenames. The page resolves each filename against its
-- own $assetBase and renders it.
--
-- Example value:
--   shared/assets/images/manifest/products/asian-fusion-fit/004-nori-hand-roll/
--
-- Matching strategy — NAME-BASED, one UPDATE per product
-- -------------------------------------------------------
-- The 30 products in seed-1-core.sql are grouped ten per
-- restaurant. The 30 manifest folders on disk are also grouped
-- ten per restaurant, but their slug suffixes do NOT match the
-- product insert order. For example, Green Bowl Cafe inserts
-- "Garden Harvest Bowl" first, but the folder on disk for that
-- product is 004-garden-harvest-bowl, not 001-….
--
-- A positional match would therefore assign every product the
-- wrong folder. Each UPDATE below is keyed on
-- (restaurant.business_name, product.name) and writes the exact
-- folder slug for that product on the right-hand side of SET.
--
-- The ten products per restaurant are matched to their folders
-- as follows (product.name → folder slug):
--
--   Green Bowl Cafe
--     Berry Almond Smoothie        → 001-berry-almond
--     Classic Vegan Bowl           → 002-classic-vegan-bowl
--     Edamame Citrus Salad         → 003-edamame-citrus-salad
--     Garden Harvest Bowl          → 004-garden-harvest-bowl
--     Market Greens Salad          → 005-market-greens-salad
--     Morning Power Smoothie       → 006-morning-power-smoothie
--     Seaside Poke Bowl            → 007-seaside-poke-bowl
--     Sunrise Breakfast Bowl       → 008-sunrise-breakfast-bowl
--     Superfood Buddha Bowl        → 009-superfood-buddha-bowl
--     Zucchini Noodle Pesto        → 010-zucchini-noodle-pesto
--
--   Keto Kitchen
--     Chicken Parmesan Plate       → 001-chicken parmesan plate
--     Egg & Avocado Bowl           → 002-egg avocado bowl
--     Keto Butcher Plate           → 003-keto butcher plate
--     Keto Cauliflower Pizza       → 004-keto cauliflower pizza
--     Keto Garden Salad            → 005-keto garden salad
--     Keto Power Bowl              → 006-keto power bowl
--     Keto Smash Burger            → 007-keto-smash-burger
--     Keto Steak Plate             → 008-keto-steak-plate
--     Salmon Dill Plate            → 009-salmon-dill-plate
--     Shrimp Scampi Zoodles        → 010-shrimp-scampi-zoodles
--
--   Asian Fusion Fit
--     Gluten-Free Salmon Roll      → 001-gluten-free-salmon-roll
--     Grilled Fish & Greens        → 002-grilled-fish-greens
--     Matcha Banana Smoothie       → 003-matcha-banana-smotthie  ← typo
--     Nori Hand Roll Set           → 004-nori-hand-roll
--     Osaka Rice Bowl              → 005-osaka-rice-bowl
--     Rainbow Poke Bowl            → 006-rainbow-poke-bowl
--     Seaweed Sesame Salad         → 007-seaweed-sesame-salad
--     Spicy Tuna Roll              → 008-spicy-tuna-roll
--     Tokyo Noodle Bowl            → 009-tokyo-noodle-bowl
--     Wok-Tossed Vegetables        → 010-wok-tossed-vegetables
--
-- Two folder-name quirks preserved verbatim to match the disk:
--   * asian-fusion-fit/003-matcha-banana-smotthie/   ("smotthie")
--   * the five Keto Kitchen folders that contain literal spaces:
--       001-chicken parmesan plate
--       002-egg avocado bowl
--       003-keto butcher plate
--       004-keto cauliflower pizza
--       005-keto garden salad
--       006-keto power bowl
--
-- The SQL writes the paths exactly as the folders are named on
-- disk. glob() handles spaces and misspellings identically. If a
-- folder is renamed on disk, update the matching string here.
--
-- PREREQUISITE — these files must exist on disk:
--   shared/assets/images/manifest/permits/business-permit.png
--   shared/assets/images/manifest/permits/sanitary-permit.jpg
--   shared/assets/images/manifest/drivers-license/drivers-license-1.jpg
--   shared/assets/images/manifest/drivers-license/drivers-license-2.jpg
--   shared/assets/images/manifest/national-id/national-id-1.jpg
--   shared/assets/images/manifest/profiles/profile-1.jpg
--   shared/assets/images/manifest/profiles/profile-2.jpg
--   shared/assets/images/manifest/profiles/profile-3.jpg
--   (…through profile-10.jpg)
--   shared/assets/images/manifest/products/<restaurant-slug>/<NNN-slug>/image-1.*
--   (…one folder per product, each containing image-1.* and
--    optionally image-2.* through image-5.*)
--
-- These are the curated seed assets. Runtime rider uploads live
-- under shared/uploads/rider/documents/<rider_id>/ and are written
-- by sign-up-handler.php, NOT by this seed.
--
-- IDEMPOTENCY
-- -----------
-- The INSERTs below are prefixed with DELETE statements scoped to
-- the seed's own restaurants and riders, so re-running this file
-- resets permit and document rows to a known state without needing
-- schema-level UNIQUE constraints.
--
-- The dietary_information.images UPDATEs are plain SETs keyed on
-- (restaurant.business_name, product.name), so re-running them
-- leaves the same value in place.
--
-- PRINCIPLES SATISFIED HERE
--   * Every restaurant gets exactly three permit rows
--     (display_order 0..2, contiguous).
--   * Every rider gets exactly one identity document row.
--   * Every rider's id_path is DISTINCT (V20).
--   * Bicycle riders submit a non-driver's-license id_type (V18).
--   * Every rider gets a DISTINCT profile picture from the
--     manifest profiles set (V21).
--   * Every product's dietary_information row carries a distinct
--     folder path (V25).
-- =====================================================

USE fitpal_food_delivery;

START TRANSACTION;

-- =====================================================
-- 1. PROFILE PICTURES
-- =====================================================
-- All three *_profile tables share a single manifest avatar
-- in the demo. Swap to distinct files if you later add
-- per-role avatars under shared/assets/images/manifest/avatars/.

-- Administrator profile picture — left NULL by default.
-- Uncomment and point at a real file to enable.
-- UPDATE administrator_profile
-- SET profile_picture = 'shared/assets/images/manifest/avatars/admin.png'
-- WHERE administrator_id = (SELECT administrator_id FROM administrator WHERE email = 'admin@fitpal.com');

-- Customer profile picture — left NULL by default.
-- UPDATE customer_profile
-- SET profile_picture = 'shared/assets/images/manifest/avatars/customer.png'
-- WHERE customer_id = (SELECT customer_id FROM customer WHERE email = 'user@example.com');

-- =====================================================
-- 1b. RIDER PROFILE PICTURES  (v7.0)
-- =====================================================
-- Assign a DISTINCT manifest profile image to each rider.
-- The subquery picks the Nth profile-N.jpg where N is the
-- rider's row number within the ordered rider set, so no two
-- riders share the same picture and re-runs are deterministic.
--
-- Riders are ordered by delivery_rider_id so the mapping is
-- stable. If you add more riders than there are profile-N.jpg
-- files (10), wrap with modulo to recycle safely.

UPDATE delivery_rider_profile drp
JOIN (
    SELECT dr.delivery_rider_id, CONCAT(
            'shared/assets/images/manifest/profiles/profile-', (
                (
                    ROW_NUMBER() OVER (
                        ORDER BY dr.delivery_rider_id
                    ) - 1
                ) % 10
            ) + 1, '.jpg'
        ) AS profile_picture
    FROM delivery_rider dr
) AS assign ON assign.delivery_rider_id = drp.delivery_rider_id
SET
    drp.profile_picture = assign.profile_picture;

-- =====================================================
-- 2. RESTAURANT PERMITS  (3 rows per restaurant)
-- =====================================================

-- 2.1 Green Bowl Cafe
DELETE FROM restaurant_permit
WHERE
    restaurant_id = (
        SELECT restaurant_id
        FROM restaurant
        WHERE
            business_name = 'Green Bowl Cafe'
    );

INSERT INTO
    restaurant_permit (
        restaurant_id,
        file_path,
        original_name,
        display_order
    )
SELECT r.restaurant_id, v.file_path, v.original_name, v.display_order
FROM restaurant r
    JOIN (
        SELECT
            'shared/assets/images/manifest/permits/business-permit.png' AS file_path, 'DTI-Certificate-of-Business-Name.png' AS original_name, 0 AS display_order
        UNION ALL
        SELECT 'shared/assets/images/manifest/permits/business-permit.png', 'Mayors-Permit-2026.png', 1
        UNION ALL
        SELECT 'shared/assets/images/manifest/permits/sanitary-permit.jpg', 'Sanitary-Permit.jpg', 2
    ) v
WHERE
    r.business_name = 'Green Bowl Cafe';

-- 2.2 Keto Kitchen
DELETE FROM restaurant_permit
WHERE
    restaurant_id = (
        SELECT restaurant_id
        FROM restaurant
        WHERE
            business_name = 'Keto Kitchen'
    );

INSERT INTO
    restaurant_permit (
        restaurant_id,
        file_path,
        original_name,
        display_order
    )
SELECT r.restaurant_id, v.file_path, v.original_name, v.display_order
FROM restaurant r
    JOIN (
        SELECT
            'shared/assets/images/manifest/permits/business-permit.png' AS file_path, 'DTI-Registration-Keto-Kitchen.png' AS original_name, 0 AS display_order
        UNION ALL
        SELECT 'shared/assets/images/manifest/permits/business-permit.png', 'Business-Permit-Makati.png', 1
        UNION ALL
        SELECT 'shared/assets/images/manifest/permits/sanitary-permit.jpg', 'Sanitary-Permit-KK.jpg', 2
    ) v
WHERE
    r.business_name = 'Keto Kitchen';

-- 2.3 Asian Fusion Fit
DELETE FROM restaurant_permit
WHERE
    restaurant_id = (
        SELECT restaurant_id
        FROM restaurant
        WHERE
            business_name = 'Asian Fusion Fit'
    );

INSERT INTO
    restaurant_permit (
        restaurant_id,
        file_path,
        original_name,
        display_order
    )
SELECT r.restaurant_id, v.file_path, v.original_name, v.display_order
FROM restaurant r
    JOIN (
        SELECT
            'shared/assets/images/manifest/permits/business-permit.png' AS file_path, 'DTI-AFF-Registration.png' AS original_name, 0 AS display_order
        UNION ALL
        SELECT 'shared/assets/images/manifest/permits/business-permit.png', 'Mayors-Permit-Quezon-City.png', 1
        UNION ALL
        SELECT 'shared/assets/images/manifest/permits/sanitary-permit.jpg', 'Business-Permit-AFF.jpg', 2
    ) v
WHERE
    r.business_name = 'Asian Fusion Fit';

-- =====================================================
-- 3. RIDER IDENTITY DOCUMENTS  (1 row per rider)
-- =====================================================
-- Each rider gets a DISTINCT manifest file so no two riders
-- share the same image in the admin KYC review screen.
--   Carlos (motorcycle) → drivers-license-1.jpg
--   Miguel (car)        → drivers-license-2.jpg
--   Andrei (bicycle)    → national-id-1.jpg   (dates NULL)

-- 3.1 Carlos — driver's license
DELETE FROM delivery_rider_document
WHERE
    delivery_rider_id = (
        SELECT delivery_rider_id
        FROM delivery_rider
        WHERE
            email = 'rider1@fitpal.com'
    );

INSERT INTO
    delivery_rider_document (
        delivery_rider_id,
        id_type,
        id_path,
        issue_date,
        expiry_date
    )
SELECT dr.delivery_rider_id, 'drivers_license', 'shared/assets/images/manifest/drivers-license/drivers-license-1.jpg', '2021-06-15', DATE_ADD(CURDATE(), INTERVAL 10 YEAR)
FROM delivery_rider dr
WHERE
    dr.email = 'rider1@fitpal.com';

-- 3.2 Miguel — driver's license (distinct file)
DELETE FROM delivery_rider_document
WHERE
    delivery_rider_id = (
        SELECT delivery_rider_id
        FROM delivery_rider
        WHERE
            email = 'rider2@fitpal.com'
    );

INSERT INTO
    delivery_rider_document (
        delivery_rider_id,
        id_type,
        id_path,
        issue_date,
        expiry_date
    )
SELECT dr.delivery_rider_id, 'drivers_license', 'shared/assets/images/manifest/drivers-license/drivers-license-2.jpg', '2020-11-03', DATE_ADD(CURDATE(), INTERVAL 10 YEAR)
FROM delivery_rider dr
WHERE
    dr.email = 'rider2@fitpal.com';

-- 3.3 Andrei — national ID (bicycle rider; both dates NULL)
DELETE FROM delivery_rider_document
WHERE
    delivery_rider_id = (
        SELECT delivery_rider_id
        FROM delivery_rider
        WHERE
            email = 'rider3@fitpal.com'
    );

INSERT INTO
    delivery_rider_document (
        delivery_rider_id,
        id_type,
        id_path,
        issue_date,
        expiry_date
    )
SELECT dr.delivery_rider_id, 'national_id', 'shared/assets/images/manifest/national-id/national-id-1.jpg', NULL, NULL
FROM delivery_rider dr
WHERE
    dr.email = 'rider3@fitpal.com';

-- =====================================================
-- 4. PRODUCT IMAGE FOLDER PATHS  (v7.1 — new)
-- =====================================================
-- dietary_information.images gets a project-root-relative FOLDER
-- path per product. The reader globs that folder for image-1.*
-- through image-5.* and returns the ordered filenames.
--
-- Each UPDATE is keyed on (restaurant.business_name, product.name),
-- which uniquely identifies every product in the seed. The RHS of
-- SET is the exact folder slug as it exists on disk, including
-- literal spaces and the "smotthie" typo noted in the header.
--
-- The JOIN through restaurant_branch and restaurant is necessary
-- because product only stores a restaurant_branch_id, and this
-- file must not depend on the specific branch_id values assigned
-- by seed-1.
--
-- A helper variable is captured for each restaurant to keep the
-- UPDATE bodies short and to make the intent legible: the folder
-- prefix is written once and the slug is appended per product.

-- -----------------------------------------------------
-- 4.1 Green Bowl Cafe
--     Folder prefix:
--     shared/assets/images/manifest/products/green-bowl-cafe/
-- -----------------------------------------------------

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
SET
    di.images = 'shared/assets/images/manifest/products/green-bowl-cafe/001-berry-almond/'
WHERE
    r.business_name = 'Green Bowl Cafe'
    AND p.name = 'Berry Almond Smoothie';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
SET
    di.images = 'shared/assets/images/manifest/products/green-bowl-cafe/002-classic-vegan-bowl/'
WHERE
    r.business_name = 'Green Bowl Cafe'
    AND p.name = 'Classic Vegan Bowl';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
SET
    di.images = 'shared/assets/images/manifest/products/green-bowl-cafe/003-edamame-citrus-salad/'
WHERE
    r.business_name = 'Green Bowl Cafe'
    AND p.name = 'Edamame Citrus Salad';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
SET
    di.images = 'shared/assets/images/manifest/products/green-bowl-cafe/004-garden-harvest-bowl/'
WHERE
    r.business_name = 'Green Bowl Cafe'
    AND p.name = 'Garden Harvest Bowl';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
SET
    di.images = 'shared/assets/images/manifest/products/green-bowl-cafe/005-market-greens-salad/'
WHERE
    r.business_name = 'Green Bowl Cafe'
    AND p.name = 'Market Greens Salad';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
SET
    di.images = 'shared/assets/images/manifest/products/green-bowl-cafe/006-morning-power-smoothie/'
WHERE
    r.business_name = 'Green Bowl Cafe'
    AND p.name = 'Morning Power Smoothie';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
SET
    di.images = 'shared/assets/images/manifest/products/green-bowl-cafe/007-seaside-poke-bowl/'
WHERE
    r.business_name = 'Green Bowl Cafe'
    AND p.name = 'Seaside Poke Bowl';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
SET
    di.images = 'shared/assets/images/manifest/products/green-bowl-cafe/008-sunrise-breakfast-bowl/'
WHERE
    r.business_name = 'Green Bowl Cafe'
    AND p.name = 'Sunrise Breakfast Bowl';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
SET
    di.images = 'shared/assets/images/manifest/products/green-bowl-cafe/009-superfood-buddha-bowl/'
WHERE
    r.business_name = 'Green Bowl Cafe'
    AND p.name = 'Superfood Buddha Bowl';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
SET
    di.images = 'shared/assets/images/manifest/products/green-bowl-cafe/010-zucchini-noodle-pesto/'
WHERE
    r.business_name = 'Green Bowl Cafe'
    AND p.name = 'Zucchini Noodle Pesto';

-- -----------------------------------------------------
-- 4.2 Keto Kitchen
--     Folder prefix:
--     shared/assets/images/manifest/products/keto-kitchen/
--     Note: the first six folders contain literal spaces.
-- -----------------------------------------------------

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
SET
    di.images = 'shared/assets/images/manifest/products/keto-kitchen/001-chicken parmesan plate/'
WHERE
    r.business_name = 'Keto Kitchen'
    AND p.name = 'Chicken Parmesan Plate';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
SET
    di.images = 'shared/assets/images/manifest/products/keto-kitchen/002-egg avocado bowl/'
WHERE
    r.business_name = 'Keto Kitchen'
    AND p.name = 'Egg & Avocado Bowl';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
SET
    di.images = 'shared/assets/images/manifest/products/keto-kitchen/003-keto butcher plate/'
WHERE
    r.business_name = 'Keto Kitchen'
    AND p.name = 'Keto Butcher Plate';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
SET
    di.images = 'shared/assets/images/manifest/products/keto-kitchen/004-keto cauliflower pizza/'
WHERE
    r.business_name = 'Keto Kitchen'
    AND p.name = 'Keto Cauliflower Pizza';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
SET
    di.images = 'shared/assets/images/manifest/products/keto-kitchen/005-keto garden salad/'
WHERE
    r.business_name = 'Keto Kitchen'
    AND p.name = 'Keto Garden Salad';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
SET
    di.images = 'shared/assets/images/manifest/products/keto-kitchen/006-keto power bowl/'
WHERE
    r.business_name = 'Keto Kitchen'
    AND p.name = 'Keto Power Bowl';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
SET
    di.images = 'shared/assets/images/manifest/products/keto-kitchen/007-keto-smash-burger/'
WHERE
    r.business_name = 'Keto Kitchen'
    AND p.name = 'Keto Smash Burger';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
SET
    di.images = 'shared/assets/images/manifest/products/keto-kitchen/008-keto-steak-plate/'
WHERE
    r.business_name = 'Keto Kitchen'
    AND p.name = 'Keto Steak Plate';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
SET
    di.images = 'shared/assets/images/manifest/products/keto-kitchen/009-salmon-dill-plate/'
WHERE
    r.business_name = 'Keto Kitchen'
    AND p.name = 'Salmon Dill Plate';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
SET
    di.images = 'shared/assets/images/manifest/products/keto-kitchen/010-shrimp-scampi-zoodles/'
WHERE
    r.business_name = 'Keto Kitchen'
    AND p.name = 'Shrimp Scampi Zoodles';

-- -----------------------------------------------------
-- 4.3 Asian Fusion Fit
--     Folder prefix:
--     shared/assets/images/manifest/products/asian-fusion-fit/
--     Note: 003-… contains the disk typo "smotthie".
-- -----------------------------------------------------

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
SET
    di.images = 'shared/assets/images/manifest/products/asian-fusion-fit/001-gluten-free-salmon-roll/'
WHERE
    r.business_name = 'Asian Fusion Fit'
    AND p.name = 'Gluten-Free Salmon Roll';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
SET
    di.images = 'shared/assets/images/manifest/products/asian-fusion-fit/002-grilled-fish-greens/'
WHERE
    r.business_name = 'Asian Fusion Fit'
    AND p.name = 'Grilled Fish & Greens';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
SET
    di.images = 'shared/assets/images/manifest/products/asian-fusion-fit/003-matcha-banana-smotthie/'
WHERE
    r.business_name = 'Asian Fusion Fit'
    AND p.name = 'Matcha Banana Smoothie';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
SET
    di.images = 'shared/assets/images/manifest/products/asian-fusion-fit/004-nori-hand-roll/'
WHERE
    r.business_name = 'Asian Fusion Fit'
    AND p.name = 'Nori Hand Roll Set';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
SET
    di.images = 'shared/assets/images/manifest/products/asian-fusion-fit/005-osaka-rice-bowl/'
WHERE
    r.business_name = 'Asian Fusion Fit'
    AND p.name = 'Osaka Rice Bowl';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
SET
    di.images = 'shared/assets/images/manifest/products/asian-fusion-fit/006-rainbow-poke-bowl/'
WHERE
    r.business_name = 'Asian Fusion Fit'
    AND p.name = 'Rainbow Poke Bowl';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
SET
    di.images = 'shared/assets/images/manifest/products/asian-fusion-fit/007-seaweed-sesame-salad/'
WHERE
    r.business_name = 'Asian Fusion Fit'
    AND p.name = 'Seaweed Sesame Salad';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
SET
    di.images = 'shared/assets/images/manifest/products/asian-fusion-fit/008-spicy-tuna-roll/'
WHERE
    r.business_name = 'Asian Fusion Fit'
    AND p.name = 'Spicy Tuna Roll';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
SET
    di.images = 'shared/assets/images/manifest/products/asian-fusion-fit/009-tokyo-noodle-bowl/'
WHERE
    r.business_name = 'Asian Fusion Fit'
    AND p.name = 'Tokyo Noodle Bowl';

UPDATE product p
JOIN restaurant_branch rb ON p.restaurant_branch_id = rb.restaurant_branch_id
JOIN restaurant r ON rb.restaurant_id = r.restaurant_id
JOIN dietary_information di ON p.dietary_information_id = di.dietary_information_id
SET
    di.images = 'shared/assets/images/manifest/products/asian-fusion-fit/010-wok-tossed-vegetables/'
WHERE
    r.business_name = 'Asian Fusion Fit'
    AND p.name = 'Wok-Tossed Vegetables';

COMMIT;

-- =====================================================
-- VERIFICATION — PATHS
-- =====================================================
SELECT '=== seed-3-paths.sql loaded (v7.1) ===' AS status;

-- V10. Every rider has an identity document row.
--      Surfaces id_type so a reviewer can see what each rider submitted.
SELECT dr.delivery_rider_id, dr.email, drp.vehicle_type, drd.document_id, drd.id_type, drd.id_path, drd.issue_date, drd.expiry_date
FROM
    delivery_rider dr
    LEFT JOIN delivery_rider_profile drp ON drp.delivery_rider_id = dr.delivery_rider_id
    LEFT JOIN delivery_rider_document drd ON drd.delivery_rider_id = dr.delivery_rider_id
ORDER BY dr.delivery_rider_id;

-- V12. Every restaurant has at least one permit row.
SELECT r.restaurant_id, r.business_name, COUNT(rp.permit_id) AS permit_count
FROM
    restaurant r
    LEFT JOIN restaurant_permit rp ON rp.restaurant_id = r.restaurant_id
GROUP BY
    r.restaurant_id,
    r.business_name
HAVING
    permit_count = 0;

-- V13. No permit row has a NULL or empty original_name.
SELECT
    permit_id,
    restaurant_id,
    file_path,
    original_name
FROM restaurant_permit
WHERE
    original_name IS NULL
    OR original_name = '';

-- V14. Permit display_order values within each restaurant are
--      contiguous starting at 0.
SELECT
    rp.restaurant_id,
    COUNT(*) AS permit_count,
    MIN(rp.display_order) AS min_order,
    MAX(rp.display_order) AS max_order,
    CASE
        WHEN MIN(rp.display_order) <> 0 THEN 'not_zero_based'
        WHEN MAX(rp.display_order) <> COUNT(*) - 1 THEN 'not_contiguous'
        ELSE 'ok'
    END AS order_state
FROM restaurant_permit rp
GROUP BY
    rp.restaurant_id
HAVING
    order_state <> 'ok';

-- V17. No rider document row has a NULL id_type.
SELECT
    document_id,
    delivery_rider_id,
    id_type,
    id_path
FROM delivery_rider_document
WHERE
    id_type IS NULL
    OR id_type = '';

-- V18. No bicycle rider carries a 'drivers_license' id_type.
SELECT dr.delivery_rider_id, dr.email, drp.vehicle_type, drd.id_type
FROM
    delivery_rider dr
    JOIN delivery_rider_profile drp ON drp.delivery_rider_id = dr.delivery_rider_id
    JOIN delivery_rider_document drd ON drd.delivery_rider_id = dr.delivery_rider_id
WHERE
    drp.vehicle_type = 'bicycle'
    AND drd.id_type = 'drivers_license';

-- V20. No two riders share the same id_path.
SELECT
    id_path,
    COUNT(*) AS rider_count,
    GROUP_CONCAT(
        delivery_rider_id
        ORDER BY delivery_rider_id
    ) AS rider_ids
FROM delivery_rider_document
GROUP BY
    id_path
HAVING
    rider_count > 1;

-- V21. No two riders share the same profile_picture.
SELECT drp.profile_picture, COUNT(*) AS rider_count, GROUP_CONCAT(
        drp.delivery_rider_id
        ORDER BY drp.delivery_rider_id
    ) AS rider_ids
FROM delivery_rider_profile drp
WHERE
    drp.profile_picture IS NOT NULL
GROUP BY
    drp.profile_picture
HAVING
    rider_count > 1;

-- V22. Every rider has a profile_picture assigned.
SELECT dr.delivery_rider_id, dr.email, drp.profile_picture
FROM
    delivery_rider dr
    LEFT JOIN delivery_rider_profile drp ON drp.delivery_rider_id = dr.delivery_rider_id
WHERE
    drp.profile_picture IS NULL
    OR drp.profile_picture = '';

-- V23. (v7.1) Every seeded product's dietary_information.images is
--      non-NULL, non-empty, and ends with a forward slash. A value
--      that fails this check cannot be globbed by the reader.
SELECT di.dietary_information_id, di.images, p.name AS product_name
FROM
    dietary_information di
    JOIN product p ON p.dietary_information_id = di.dietary_information_id
WHERE
    di.images IS NULL
    OR di.images = ''
    OR RIGHT(di.images, 1) <> '/';

-- V24. (v7.1) Every seeded product's dietary_information.images
--      starts with the expected manifest prefix. MySQL cannot see
--      the disk, so this check is a string-prefix guard: it catches
--      a mistyped prefix but not a missing folder.
SELECT di.dietary_information_id, di.images, p.name AS product_name
FROM
    dietary_information di
    JOIN product p ON p.dietary_information_id = di.dietary_information_id
WHERE
    di.images IS NOT NULL
    AND di.images <> ''
    AND di.images NOT LIKE 'shared/assets/images/manifest/products/%/';

-- V25. (v7.1) No two products share the same dietary_information.images
--      folder path. A collision means two products would render the
--      same images.
SELECT
    di.images,
    COUNT(*) AS product_count,
    GROUP_CONCAT(
        p.product_id
        ORDER BY p.product_id
    ) AS product_ids,
    GROUP_CONCAT(
        p.name
        ORDER BY p.product_id SEPARATOR ' | '
    ) AS product_names
FROM
    dietary_information di
    JOIN product p ON p.dietary_information_id = di.dietary_information_id
WHERE
    di.images IS NOT NULL
    AND di.images <> ''
GROUP BY
    di.images
HAVING
    product_count > 1;

-- V26. (v7.1) Distribution sanity: exactly 30 rows share the
--      manifest/products prefix, ten per restaurant slug. This is
--      a read-out, not a failure condition; a human reviewer can
--      see at a glance that the counts are 10/10/10.
SELECT SUBSTRING_INDEX(
        SUBSTRING_INDEX(di.images, '/', 6), '/', -1
    ) AS restaurant_slug, COUNT(*) AS row_count
FROM
    dietary_information di
    JOIN product p ON p.dietary_information_id = di.dietary_information_id
WHERE
    di.images LIKE 'shared/assets/images/manifest/products/%'
GROUP BY
    restaurant_slug
ORDER BY restaurant_slug;