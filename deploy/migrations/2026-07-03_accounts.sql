-- ============================================================================
-- Multi-tenant Phase 1: accounts table + account_id foreign keys + backfill.
-- Idempotent (runs on every deploy). Existing customer becomes account #1.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `accounts` (
  `id`            int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name`          varchar(150) NOT NULL,
  `slug`          varchar(80)  DEFAULT NULL,
  `logo`          varchar(190) DEFAULT NULL,
  `brand_name`    varchar(120) DEFAULT NULL,
  `contact_email` varchar(190) DEFAULT NULL,
  `whatsapp`      varchar(40)  DEFAULT NULL,
  `created_at`    timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_accounts_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @db := DATABASE();

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=@db AND TABLE_NAME='branches' AND COLUMN_NAME='account_id');
SET @s := IF(@c=0,'ALTER TABLE `branches` ADD COLUMN `account_id` int(10) unsigned DEFAULT NULL, ADD KEY `idx_branches_account` (`account_id`)','DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=@db AND TABLE_NAME='users' AND COLUMN_NAME='account_id');
SET @s := IF(@c=0,'ALTER TABLE `users` ADD COLUMN `account_id` int(10) unsigned DEFAULT NULL, ADD KEY `idx_users_account` (`account_id`)','DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=@db AND TABLE_NAME='suppliers' AND COLUMN_NAME='account_id');
SET @s := IF(@c=0,'ALTER TABLE `suppliers` ADD COLUMN `account_id` int(10) unsigned DEFAULT NULL, ADD KEY `idx_suppliers_account` (`account_id`)','DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=@db AND TABLE_NAME='subscriptions' AND COLUMN_NAME='account_id');
SET @s := IF(@c=0,'ALTER TABLE `subscriptions` ADD COLUMN `account_id` int(10) unsigned DEFAULT NULL, ADD UNIQUE KEY `uq_sub_account` (`account_id`)','DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

INSERT INTO `accounts` (`id`, `name`, `brand_name`, `logo`)
SELECT 1, 'Aktifotak Group Sdn Bhd', 'Aktifotak Group Sdn Bhd', 'assets/logo-aktifotak.png'
WHERE NOT EXISTS (SELECT 1 FROM `accounts`);

UPDATE `branches`      SET `account_id` = 1 WHERE `account_id` IS NULL;
UPDATE `suppliers`     SET `account_id` = 1 WHERE `account_id` IS NULL;
UPDATE `subscriptions` SET `account_id` = 1 WHERE `account_id` IS NULL;
UPDATE `users` SET `account_id` = 1
  WHERE `account_id` IS NULL AND `role` NOT IN ('agency_admin','agency_user');
