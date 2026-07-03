-- ============================================================================
-- Phase 2: ensure every account has exactly one subscription row (trial default).
-- Idempotent: only inserts for accounts that lack one.
-- ============================================================================
INSERT INTO `subscriptions` (`account_id`, `plan`, `status`, `trial_ends_at`, `price_per_user`)
SELECT a.id, 'trial', 'trial', DATE_ADD(CURDATE(), INTERVAL 14 DAY), 29.90
FROM `accounts` a
WHERE NOT EXISTS (SELECT 1 FROM `subscriptions` s WHERE s.account_id = a.id);
