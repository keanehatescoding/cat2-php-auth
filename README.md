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

## Deploy on a server
```bash
sudo apt install -y apache2 mariadb-server php libapache2-mod-php php-mysql git
sudo mariadb < sql/schema.sql
sudo git clone <repo-url> /var/www/html/cat2
sudo chown -R www-data:www-data /var/www/html/cat2
```
Visit `https://<your-domain>/cat2/` (or `http://<server-ip>/cat2/`).

Set the DB password via `CAT2_DB_PASS` in the web server environment before deploying. The app also attempts to create the security tables automatically on first DB connection if they are missing.
