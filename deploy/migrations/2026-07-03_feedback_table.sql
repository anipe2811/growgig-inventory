-- ============================================================================
-- Feedback / issue tracker table.
--
-- Every "Have a problem?" submission is now stored as a row here (in addition
-- to the in-app notification sent to the agency team), so the agency can see a
-- list of reported issues and move each through a status: open -> in_progress
-- -> fixed.
--
--   docker compose exec -T db mysql -uroot -p"$DB_PASS" "$DB_NAME" < deploy/migrations/2026-07-03_feedback_table.sql
--   (or paste into phpMyAdmin > SQL)
--
-- Safe to re-run: CREATE TABLE IF NOT EXISTS.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `feedback` (
  `id`         int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id`    int(10) unsigned DEFAULT NULL,
  `reporter`   varchar(150) DEFAULT NULL,
  `branch`     varchar(150) DEFAULT NULL,
  `subject`    varchar(160) DEFAULT NULL,
  `message`    varchar(1000) NOT NULL,
  `status`     enum('open','in_progress','fixed') NOT NULL DEFAULT 'open',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_feedback_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
