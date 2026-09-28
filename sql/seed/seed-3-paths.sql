-- =====================================================
-- FitPal Seed Data — 3 of 3: PATHS
-- Version 7.0
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
-- PRINCIPLES SATISFIED HERE
--   * Every restaurant gets exactly three permit rows
--     (display_order 0..2, contiguous).
--   * Every rider gets exactly one identity document row.
--   * Every rider's id_path is DISTINCT (V20).
--   * Bicycle riders submit a non-driver's-license id_type (V18).
--   * Every rider gets a DISTINCT profile picture from the
--     manifest profiles set (V21 — new in v7.0).
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
-- 1b. RIDER PROFILE PICTURES  (v7.0 — new)
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

COMMIT;

-- =====================================================
-- VERIFICATION — PATHS
-- =====================================================
SELECT '=== seed-3-paths.sql loaded (v7.0) ===' AS status;

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

-- V21. No two riders share the same profile_picture (v7.0 — new).
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

-- V22. Every rider has a profile_picture assigned (v7.0 — new).
SELECT dr.delivery_rider_id, dr.email, drp.profile_picture
FROM
    delivery_rider dr
    LEFT JOIN delivery_rider_profile drp ON drp.delivery_rider_id = dr.delivery_rider_id
WHERE
    drp.profile_picture IS NULL
    OR drp.profile_picture = '';