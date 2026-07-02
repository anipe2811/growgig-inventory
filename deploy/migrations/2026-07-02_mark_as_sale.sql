-- ============================================================================
-- Per-item "Mark as Sale" flag.
--
-- When items.mark_as_sale = 1, that item's stock-out is counted in the Sales
-- Report. This REPLACES the old hardcoded rule that only counted category
-- 'Produk Terapi' plus the names 'BUKU BIRU' and 'PERFUME CAR'.
--
--   docker compose exec -T db mysql -uroot -p"$DB_PASS" "$DB_NAME" < deploy/migrations/2026-07-02_mark_as_sale.sql
--   (or paste into phpMyAdmin > SQL)
--
-- Safe to re-run: the column is added only if missing (works on MySQL 8 and
-- MariaDB). The seed UPDATE is idempotent.
-- ============================================================================

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'items'
               AND COLUMN_NAME  = 'mark_as_sale');
SET @sql := IF(@col = 0,
    'ALTER TABLE `items` ADD COLUMN `mark_as_sale` tinyint(1) NOT NULL DEFAULT 0 AFTER `price`',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Preserve the previous Sales Report behaviour for existing stock.
UPDATE `items`
SET `mark_as_sale` = 1
WHERE `category` = 'Produk Terapi'
   OR `name` IN ('BUKU BIRU', 'PERFUME CAR');
