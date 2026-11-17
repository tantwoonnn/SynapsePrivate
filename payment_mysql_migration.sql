-- For an existing local MySQL database. Safe to run more than once.
SET @has_completion_confirmed_at = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'completion_confirmed_at');
SET @ddl_completion_confirmed_at = IF(@has_completion_confirmed_at = 0, 'ALTER TABLE appointments ADD COLUMN completion_confirmed_at DATETIME NULL AFTER rejection_reason', 'SELECT 1');
PREPARE stmt FROM @ddl_completion_confirmed_at; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_completion_issue = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'completion_issue');
SET @ddl_completion_issue = IF(@has_completion_issue = 0, 'ALTER TABLE appointments ADD COLUMN completion_issue VARCHAR(500) NULL AFTER completion_confirmed_at', 'SELECT 1');
PREPARE stmt FROM @ddl_completion_issue; EXECUTE stmt; DEALLOCATE PREPARE stmt;
CREATE TABLE IF NOT EXISTS appointment_payments (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT NOT NULL UNIQUE,
    reference_number VARCHAR(80) NOT NULL UNIQUE,
    checkout_session_id VARCHAR(100) NULL UNIQUE,
    checkout_url TEXT NULL,
    amount_minor INT NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'PHP',
    payment_method ENUM('PAYMONGO', 'CASH') NOT NULL DEFAULT 'PAYMONGO',
    status ENUM('PENDING', 'PAID') NOT NULL DEFAULT 'PENDING',
    provider_payment_id VARCHAR(100) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    paid_at DATETIME NULL,
    CONSTRAINT fk_payment_appointment FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE
) ENGINE=InnoDB;

SET @has_payment_method = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointment_payments' AND COLUMN_NAME = 'payment_method');
SET @ddl_payment_method = IF(@has_payment_method = 0, 'ALTER TABLE appointment_payments ADD COLUMN payment_method ENUM(''PAYMONGO'', ''CASH'') NOT NULL DEFAULT ''PAYMONGO'' AFTER currency', 'SELECT 1');
PREPARE stmt FROM @ddl_payment_method; EXECUTE stmt; DEALLOCATE PREPARE stmt;
