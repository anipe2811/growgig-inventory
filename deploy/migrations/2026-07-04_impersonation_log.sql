-- ============================================================================
-- Audit trail for agency login-as-user impersonation (start + stop events).
-- Idempotent: CREATE TABLE IF NOT EXISTS.
-- ============================================================================
CREATE TABLE IF NOT EXISTS `impersonation_log` (
  `id`                int(10) unsigned NOT NULL AUTO_INCREMENT,
  `impersonator_id`   int(10) unsigned DEFAULT NULL,
  `impersonator_name` varchar(150) DEFAULT NULL,
  `target_id`         int(10) unsigned DEFAULT NULL,
  `target_name`       varchar(150) DEFAULT NULL,
  `account_id`        int(10) unsigned DEFAULT NULL,
  `action`            enum('start','stop') NOT NULL,
  `created_at`        timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_implog_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
