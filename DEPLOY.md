# Deployment — SaaS Inventory → Hostinger VPS (Docker)

Target URL: **https://app.growgig.tech**

## Status (already done for you)
- ✅ **DNS:** `app.growgig.tech` → A → `72.62.79.207` (the VPS) is created and live.
- ✅ **Code is deploy-ready:** `config/config.php` reads DB creds + `APP_DEBUG` from the
  environment; `deploy/database.sql` seeds MySQL (schema + users/branches/suppliers/items,
  all quantities/orders cleared).
- ✅ **Stack files:** `Dockerfile`, `docker-compose.yml`, `Caddyfile`.

## Stack
```
Caddy (auto-HTTPS :80/:443)  ->  app (PHP 8.3 + Apache)  ->  db (MySQL 8)
```

## Deploy via SSH (your chosen path)
Run these from your machine. Replace the password with a strong one (or let the runbook
generate it). The VPS already has Docker.

**1. Package the project** (run inside this project folder):
```bash
tar -czf ../inventory.tar.gz .
```

**2. Copy it to the VPS and open a shell:**
```bash
scp ../inventory.tar.gz root@72.62.79.207:/opt/
ssh root@72.62.79.207
```

**3. On the VPS — unpack, configure, launch:**
```bash
mkdir -p /opt/inventory && tar -xzf /opt/inventory.tar.gz -C /opt/inventory
cd /opt/inventory
# generate env (creates a strong DB password automatically)
printf 'DB_PASS=%s\nSITE_DOMAIN=app.growgig.tech\nDB_NAME=saas_inventory\n' "$(openssl rand -base64 18)" > .env
docker compose up -d --build
docker compose logs -f caddy      # watch the TLS certificate get issued, then Ctrl+C
```

**4. Open** `https://app.growgig.tech` — landing page + login should appear over HTTPS.

## Port :80/:443 conflict (only if Caddy fails to start)
The VPS image ships a Traefik template. If something already listens on 80/443:
```bash
docker ps                          # find any web/traefik container on 80 or 443
docker stop <name>                 # stop it, then re-run: docker compose up -d
```

## After it's live
1. **Change every default password** (admin + account users) under *My Account* / *User*.
2. `APP_DEBUG=false` is set by compose, so errors are not shown publicly. Good.
3. Update later: re-copy the folder and `docker compose up -d --build` again
   (the MySQL data volume persists, so seed runs only on the first boot).
4. Pre-clear backup of the old data: `../backup_saas_*.sql` (kept outside this folder).

## Alternative — Hostinger VPS Docker API
If you later prefer a Git-based deploy: push this folder to a GitHub repo and call
`VPS_createNewProjectV1` (vmId `1719841`, content = repo URL,
environment = `DB_PASS=...\nSITE_DOMAIN=app.growgig.tech\nDB_NAME=saas_inventory`).
