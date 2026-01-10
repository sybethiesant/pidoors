-- PiDoors Database Migration: Convert doors from space-separated to comma-separated
-- Version: 2.2.1
-- Date: 2026-01-10
--
-- This migration converts the 'doors' column in the cards table from
-- space-separated format to comma-separated format for compatibility
-- with FIND_IN_SET() SQL function.
--
-- IMPORTANT: Backup your database before running this migration!
-- mysqldump -u pidoors -p access > access_backup_$(date +%Y%m%d).sql

USE access;

-- Show current state (for verification)
SELECT 'Before migration - Sample records:' AS status;
SELECT card_id, user_id, doors FROM cards WHERE doors != '' AND doors IS NOT NULL LIMIT 10;

-- Count affected records
SELECT CONCAT('Total cards to migrate: ', COUNT(*)) AS status
FROM cards
WHERE doors LIKE '% %' AND doors != '*';

-- Perform the migration: Replace spaces with commas
-- This handles multiple spaces and trims whitespace
UPDATE cards
SET doors = TRIM(BOTH ',' FROM REPLACE(REPLACE(REPLACE(TRIM(doors), '  ', ' '), '  ', ' '), ' ', ','))
WHERE doors LIKE '% %'
  AND doors != '*'
  AND doors IS NOT NULL;

-- Clean up any double commas that might have been created
UPDATE cards
SET doors = REPLACE(REPLACE(doors, ',,', ','), ',,', ',')
WHERE doors LIKE '%,,%';

-- Trim leading/trailing commas
UPDATE cards
SET doors = TRIM(BOTH ',' FROM doors)
WHERE doors LIKE ',%' OR doors LIKE '%,';

-- Show results
SELECT 'After migration - Sample records:' AS status;
SELECT card_id, user_id, doors FROM cards WHERE doors != '' AND doors IS NOT NULL LIMIT 10;

-- Verify no space-separated values remain (should return 0)
SELECT CONCAT('Records still with spaces: ', COUNT(*)) AS verification
FROM cards
WHERE doors LIKE '% %' AND doors != '*';

SELECT 'Migration complete!' AS status;
