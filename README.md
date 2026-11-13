# cat2-php-auth

CAT 2 – Server Administration. A PHP signup/login site with a feedback form, hosted on Debian 13 with Apache, PHP (mod_php) and MariaDB.

## Files
- `config.php` – DB connection (PDO), helpers
- `layout_header.php` / `layout_footer.php` – shared Bootstrap layout
- `index.php`, `signup.php`, `login.php`, `dashboard.php`, `logout.php`
- `feedback.php` – second form (POST → `feedback` table)
- `sql/schema.sql` – creates database `CAT2`, tables `users` and `feedback`, and user `cat2_user`

## Deploy on the server
```bash
sudo apt install -y apache2 mariadb-server php libapache2-mod-php php-mysql git
sudo mariadb < sql/schema.sql
sudo git clone <repo-url> /var/www/html/cat2
sudo chown -R www-data:www-data /var/www/html/cat2
```
Visit `http://<server-ip>/cat2/`.

Set the DB password via `CAT2_DB_PASS` (or edit `config.php`) before deploying. Change the default in `sql/schema.sql` as well.
