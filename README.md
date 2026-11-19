# cat2-php-auth

CAT 2 – Server Administration. A PHP signup/login site with a feedback form, hosted with Apache, PHP (mod_php) and MariaDB.

## Features
- User signup/login with securely hashed passwords (`password_hash` / `password_verify`)
- CSRF-protected forms and POST-only logout
- Input validation and output escaping
- Feedback form persisted to MariaDB
- Creative/security enhancements: login throttling, activity/audit logging, dashboard security view, password strength meter, and a serverless feedback-handler demo (`serverless_demo.php` + `serverless/`)

## Files
- `config.php` – DB connection (PDO), helpers, session/CSRF/security utilities
- `layout_header.php` / `layout_footer.php` – shared Bootstrap layout
- `index.php`, `signup.php`, `login.php`, `dashboard.php`, `logout.php`
- `feedback.php` – feedback form (POST → `feedback` table)
- `serverless_demo.php` – in-app demo invoking the simulated Lambda feedback handler
- `serverless/` – simulated AWS Lambda PHP runtime/handler (container + local emulator flow)
- `sql/schema.sql` – creates core tables (`users`, `feedback`) and security tables (`login_attempts`, `activity_log`)

## Deploy on a server (host install)
1. Install packages:
```bash
sudo apt install -y apache2 mariadb-server php libapache2-mod-php php-mysql git
```

2. Create the database and application user (use the same password you will configure as `CAT2_DB_PASS`), then import the schema:
```bash
sudo mariadb <<'SQL'
CREATE DATABASE IF NOT EXISTS CAT2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'cat2_user'@'localhost' IDENTIFIED BY 'change-me-app';
GRANT ALL PRIVILEGES ON CAT2.* TO 'cat2_user'@'localhost';
FLUSH PRIVILEGES;
SQL
sudo mariadb CAT2 < sql/schema.sql
```
Replace `change-me-app` with a strong password and keep it in sync with `CAT2_DB_PASS`.

3. Deploy the app and set environment/config:
```bash
sudo git clone <repo-url> /var/www/html/cat2
sudo chown -R www-data:www-data /var/www/html/cat2
```
Set `CAT2_DB_PASS` (and optionally `CAT2_DB_HOST`, `CAT2_DB_NAME`, `CAT2_DB_USER`) in the web server environment before users access the site. The app also attempts to create the security tables automatically on first DB connection if they are missing.

4. Configure TLS before exposing the site (HTTPS required for deployed access). For example, enable Apache SSL, configure an SSL virtual host for your domain, and install a certificate from a trusted source such as Let’s Encrypt/Certbot or ZeroSSL:
```bash
sudo a2enmod ssl
sudo a2ensite default-ssl.conf   # or your domain's SSL vhost
sudo systemctl reload apache2
```
Complete certificate issuance/installation for your domain according to your certificate provider’s instructions, then ensure HTTP redirects to HTTPS.

5. Visit `https://<your-domain>/cat2/` after TLS is configured.

### Alternative: Docker Compose
The included `docker-compose.yml` is a supported containerized deployment path. It provisions the MariaDB database/user/password from environment variables and initializes tables from `sql/schema.sql`. Set `CAT2_DB_PASS` and `MARIADB_ROOT_PASSWORD` (e.g. in `.env`) before starting the stack. For public deployments, place the service behind TLS/HTTPS before users access it.

## Operations
- **Health check:** `health.php` returns minimal JSON status. If `CAT2_HEALTH_TOKEN` is set in the web server environment, requests must include an `Authorization: Bearer <token>` header; otherwise the endpoint responds with `403`. Do not pass the token in the URL/query string. Example: `curl -H "Authorization: Bearer $CAT2_HEALTH_TOKEN" https://<your-domain>/cat2/health.php`.
- **Database backups:** run regular `mariadb-dump` backups for `CAT2` (e.g. daily cron), store them in a restricted location (e.g. `/var/backups/cat2`, `700`), and apply retention/pruning and restore verification.
- **Deployments:** keep `/var/www/html/cat2` (or your deploy path) in sync with the `main` branch, run `php -l` on changed PHP files, and reload/restart web services only when configuration changes require it.
- **CI:** a PHP lint workflow is recommended for push/PR validation (`php -l` across tracked `*.php` files). If your Git push credentials lack the `workflow` scope, add/update `.github/workflows` using credentials that include it.
