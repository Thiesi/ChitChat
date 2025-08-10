# ChitChat — Full Source (v0.10.25)

This bundle is ready for **fresh installations** on vanilla systems.

## Requirements
- Apache or any PHP-capable web server (PHP 8.1+ recommended)
- MySQL 8+ or PostgreSQL 13+
- PHP extensions: pdo, pdo_mysql and/or pdo_pgsql, mbstring, json

## Setup
1. **Unpack** to your web root (or a vhost docroot).
2. **Create DB**:
   - **MySQL**
     ```sql
     CREATE DATABASE chitchat CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
     ```
     Then run: `sql/mysql_schema_full.sql`
   - **PostgreSQL**
     ```sql
     CREATE DATABASE chitchat;
     ```
     Then run: `sql/pgsql_schema_full.sql`
3. **Configure DB** via environment (preferred) or `backend/config.php`:
   - Env examples:
     - MySQL: `DB_DRIVER=mysql`, `DB_HOST=localhost`, `DB_PORT=3306`, `DB_NAME=chitchat`, `DB_USERNAME=...`, `DB_PASSWORD=...`
     - PostgreSQL: `DB_DRIVER=pgsql`, `DB_HOST=localhost`, `DB_PORT=5432`, `DB_NAME=chitchat`, `DB_USERNAME=...`, `DB_PASSWORD=...`
4. **First login**: register the first account — it becomes **Super-Admin** automatically (existing feature).
5. Optional: apply patch SQLs in `sql/` if upgrading from older versions.

## Paths
- Frontend: `public/`
- Backend endpoints: `backend/`
- SQL: `sql/`

## Notes
- Default system name: **ChitChat**
- Password policy defaults to **low** and always enforces “does not contain username”.
- You can manage policies, messages and toggles in **public/sa.html** (Super-Admin only).

