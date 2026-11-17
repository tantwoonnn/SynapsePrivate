-- Adds a learner-selected meeting place and map pin to existing MySQL databases.
SET @has_meeting_place = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'meeting_place');
SET @ddl_meeting_place = IF(@has_meeting_place = 0, 'ALTER TABLE appointments ADD COLUMN meeting_place VARCHAR(200) NULL AFTER end_time', 'SELECT 1');
PREPARE stmt FROM @ddl_meeting_place; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_meeting_latitude = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'meeting_latitude');
SET @ddl_meeting_latitude = IF(@has_meeting_latitude = 0, 'ALTER TABLE appointments ADD COLUMN meeting_latitude DECIMAL(10,7) NULL AFTER meeting_place', 'SELECT 1');
PREPARE stmt FROM @ddl_meeting_latitude; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_meeting_longitude = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'meeting_longitude');
SET @ddl_meeting_longitude = IF(@has_meeting_longitude = 0, 'ALTER TABLE appointments ADD COLUMN meeting_longitude DECIMAL(10,7) NULL AFTER meeting_latitude', 'SELECT 1');
PREPARE stmt FROM @ddl_meeting_longitude; EXECUTE stmt; DEALLOCATE PREPARE stmt;
