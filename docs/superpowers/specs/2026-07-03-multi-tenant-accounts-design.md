# Multi-tenant Accounts — Phase 1 Design

**Date:** 2026-07-03
**Status:** Draft for review
**Author:** Claude (with anipe2811)

## 1. Context & current state

The app is branded as a multi-tenant SaaS (agency → account → branch role
hierarchy) but the data model is **single-tenant**:

- One customer only ("Aktifotak Group Sdn Bhd").
- `branches`, `suppliers` are global; `subscriptions` is a single global row.
- `account_admin` currently sees **all** branches globally.
- Agency roles (`agency_admin`, `agency_user`) manage that one customer.

Live production data exists (app.growgig.tech), so every change must be
idempotent and reversible, and every scoped query must be verified.

## 2. Goal (Phase 1)

Introduce the tenancy foundation and an agency **Accounts** panel so the agency
(the SaaS operator, GrowGig) can onboard and manage **many** customer companies,
each fully isolated. Deliver the schema, scoping, migration, and CRUD panel.
Per-account billing and branding land in later phases (schema prepared now).

## 3. Decisions (from brainstorm)

| Topic | Decision |
|-------|----------|
| Supplier scope | **Per-account** (each customer has its own suppliers) |
| Branding | **Per-account** logo + name (schema in P1, applied in P3) |
| Billing | **Per-account** subscription (schema in P1, agency controls in P2) |
| Rollout | **Phased** — P1 foundation+panel, P2 billing, P3 brand+impersonate |

## 4. Data model

### New table `accounts`
```
id            INT UNSIGNED PK AUTO_INCREMENT
name          VARCHAR(150)  NOT NULL        -- company/customer name
slug          VARCHAR(80)   NULL  UNIQUE    -- optional URL-safe id
logo          VARCHAR(190)  NULL            -- brand logo path (P3)
brand_name    VARCHAR(120)  NULL            -- shown to account users (P3)
contact_email VARCHAR(190)  NULL
whatsapp      VARCHAR(40)   NULL
created_at    TIMESTAMP     DEFAULT now()
```

### `account_id` foreign key added to:
- `branches.account_id`      — every branch belongs to one account.
- `users.account_id`         — `account_admin`/`account_user` belong to one
  account; **agency roles keep `account_id = NULL`** (cross-tenant super-admin);
  `supplier` logins get the account of their linked supplier.
- `suppliers.account_id`     — per-account suppliers.
- `subscriptions.account_id` — one subscription row per account (replaces the
  single-global model; unique index on `account_id`).

`items`, `stock_movements`, `purchase_orders` are **not** changed — they are
already keyed on `branch_id` and inherit the account transitively via the
branch. This avoids denormalization drift.

## 5. Migration (idempotent, live-safe)

File `deploy/migrations/2026-07-03_accounts.sql`:
1. `CREATE TABLE IF NOT EXISTS accounts ...`.
2. Add each `account_id` column only if missing (information_schema guard, same
   pattern as `2026-07-02_mark_as_sale.sql`).
3. Seed account #1 "Aktifotak Group Sdn Bhd" if the table is empty.
4. Backfill: all existing `branches`, `suppliers`, and the current
   `subscriptions` row → `account_id = 1`. Existing `account_admin`/
   `account_user` → `account_id = 1`. `supplier` users → account of their
   supplier. Agency users stay `NULL`.

Because migrations run on **every** deploy, each step is guarded to be a no-op
once applied.

## 6. Scoping model

New helper in `config.php`:
```php
current_account_id(): ?int   // account_* -> their account_id (from session)
                             // agency    -> the "acting account" (see below), or null for all
```

- **agency_admin / agency_user** — cross-tenant. They pick an *acting account*
  via an account switcher (dropdown in the sidebar / `?account=<id>` persisted in
  session). With no account selected they see an aggregate ("All accounts") where
  it makes sense (Accounts panel, dashboards) and are prompted to pick one for
  branch-level pages.
- **account_admin** — sees **all branches within their own account** only.
- **account_user** — one branch within their account.
- **supplier** — unchanged portal, scoped to their supplier (hence account).

`role_sees_all_branches()` is reworked: it no longer means "all branches in the
system" for `account_admin`; it means "all branches **in scope**", where scope =
`current_account_id()`. Every branch-loading query gains
`AND branch.account_id = :acct` (or joins through it).

### Files whose branch queries must be account-scoped
`inventory.php`, `stock.php`, `sales_report.php`, `reports.php`,
`inventory_report.php`, `stockcard.php`, `mobile.php`, `orders.php`,
`dashboard.php`, `users.php`. Each is audited so a user can never load another
account's branches/items.

## 7. Agency Accounts panel — `accounts.php` (new)

Visible to agency roles only; new sidebar entry "Accounts".

- **List** all accounts: name, #branches, #users (seats), subscription status,
  monthly value.
- **Create / edit / delete** an account (name, contact email, whatsapp; logo &
  brand_name fields present but wired for upload in P3).
- **Enter account** — select an account as the acting context, then manage its
  branches / users / suppliers / subscription. This reuses the existing
  `users.php` management UI, now filtered to `current_account_id()`.
- Delete guard: block deleting an account that still has branches/users (like the
  existing branch-in-use guard).

## 8. Files touched (summary)

- **New:** `accounts.php`, `deploy/migrations/2026-07-03_accounts.sql`.
- **Schema dump:** `deploy/database.sql` (add table + columns).
- **`config/config.php`:** `accounts` helpers, `current_account_id()`, reworked
  `role_sees_all_branches()`, account switcher state.
- **`includes/header.php`:** "Accounts" nav for agency + account switcher.
- **Branch-scoped pages** (section 6): add account filter.
- **`includes/lang.php`:** new keys (EN + MS).

## 9. Risks & mitigation

- **Live production data.** Migration is idempotent and backfills the existing
  customer as account #1, so current behaviour is preserved on day one.
- **Every scoped query changes.** Mitigation: audit the file list in §6, add the
  account filter uniformly, and test each page locally (logged in as agency,
  account_admin, account_user) before deploying — the same local-first workflow
  used for prior batches.
- **Rollback:** the migration only *adds* a table and nullable columns; reverting
  the code leaves the schema harmless. A documented `DROP`/column-drop script is
  provided but not run automatically.

## 10. Out of scope (later phases)

- **Phase 2 — Billing:** per-account subscription state, `subscription_state()` /
  `stock_frozen_for()` by account, agency billing overview + per-account
  activate/trial/freeze.
- **Phase 3 — Branding & impersonation:** per-account logo upload,
  `current_brand()` reads the acting/owning account's brand, agency "enter
  account" full impersonation.

## 11. Resolved decisions

**Account context switching (agency):** a persistent dropdown switcher in the
sidebar, always visible, with an "All accounts" option for aggregate views.
Selection persists in the session (`acting_account_id`) and can also be set via
`?account=<id>`. Branch-level pages that require a single account prompt the
agency user to pick one when "All accounts" is active.
