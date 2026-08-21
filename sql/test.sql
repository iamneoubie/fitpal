-- Check all tables exist
SHOW TABLES;

-- Check foreign keys
SELECT
    TABLE_NAME,
    COLUMN_NAME,
    CONSTRAINT_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE
    TABLE_SCHEMA = 'fitpal_food_delivery'
    AND REFERENCED_TABLE_NAME IS NOT NULL;

-- Check triggers
SHOW TRIGGERS;

-- Check stored procedures
SHOW PROCEDURE STATUS WHERE Db = 'fitpal_food_delivery';

-- Check views
SHOW FULL TABLES WHERE Table_type = 'VIEW';