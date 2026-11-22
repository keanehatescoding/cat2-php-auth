# Rollback Procedure (CAT2 PHP Auth)

This document describes how to roll back application deployments and recover data for the CAT2 app (`https://bidii.online/cat2/`, VPS: `keane@20.174.162.13`, app path: `/var/www/html/cat2`).

## 1) Application rollback (code)

### Preferred: revert/fix in Git and redeploy
1. Identify the last known good commit on `main` (e.g. `git log --oneline origin/main`).
2. Create a fix or revert commit:
   - **Revert a bad commit/merge:** `git revert <commit>` (or `git revert -m 1 <merge_commit>` for merge commits), then open/merge a PR to `main`.
   - **Hotfix:** commit the fix to a branch, PR to `main`, merge.
3. Deploy:
   - **CI/CD:** merge/push to `main` triggers `.github/workflows/deploy.yml` (lint/smoke must pass; deploy job runs on `main` push). Ensure Actions secrets (`DEPLOY_HOST`, `DEPLOY_PATH`, `DEPLOY_SSH_KEY`) are valid.
   - **Manual (fallback):** run `scripts/deploy.sh` from a trusted checkout with `TARGET_HOST`, `TARGET_PATH`, and SSH access configured, or pull/reset on the VPS:
     ```bash
     ssh keane@20.174.162.13
     cd /var/www/html/cat2
     sudo git fetch origin main
     sudo git checkout -B main origin/main
     sudo git reset --hard <good_commit_or_origin/main>
     sudo chown -R www-data:www-data /var/www/html/cat2
     sudo php -l config.php login.php health.php
     ```
4. Verify: check `https://bidii.online/cat2/` and `https://bidii.online/cat2/health.php` (with `Authorization: Bearer <CAT2_HEALTH_TOKEN>` if configured), and review logs (`/var/log/apache2/*`, app error logs).

### Notes
- `scripts/deploy.sh` syncs a defined set of app files; if a rollback must remove files added by a bad release, prefer `git reset --hard`/clean on the VPS or a follow-up cleanup commit.
- Apache reload/restart is only needed for server config changes (vhost/env/TLS), not typical PHP file rollbacks.

## 2) Database rollback / recovery

### Backups
- **Local backups:** `/var/backups/cat2/cat2-*.sql.gz` (root-only, daily cron via `/usr/local/bin/cat2-db-backup.sh`, local retention ~14 days). Each backup is gzip-tested and restore-verified to a temporary DB before finalization.
- **Offsite backups:** uploaded to S3 `s3://cat2-php-auth-backups-154648811538/cat2/` via `/etc/cat2-backup-offsite.sh` (credentials in `/etc/cat2-backup-aws.env`, root-only; remote retention ~30 days best-effort).
- **Backup logs:** `/var/log/cat2-db-backup.log`

### Restore drill (non-destructive validation)
Restore to a temporary database before touching production:
```bash
LATEST=$(sudo ls -1t /var/backups/cat2/cat2-*.sql.gz | head -1)
sudo mariadb -e 'DROP DATABASE IF EXISTS CAT2_restore_drill; CREATE DATABASE CAT2_restore_drill;'
sudo bash -lc "zcat '$LATEST' | sed 's/`CAT2`/`CAT2_restore_drill`/g' | mariadb"
sudo mariadb -e 'USE CAT2_restore_drill; SHOW TABLES;'
# ...validate data...
sudo mariadb -e 'DROP DATABASE IF EXISTS CAT2_restore_drill;'
```
(For offsite: download with `aws s3 cp s3://cat2-php-auth-backups-154648811538/cat2/<file> /tmp/<file>` after sourcing `/etc/cat2-backup-aws.env`, then restore/verify.)

### Production restore (destructive to current DB state)
Use only when necessary and during a maintenance window if possible.
1. Put app in maintenance/notify stakeholders if applicable.
2. Take a fresh pre-restore backup/snapshot if possible.
3. Restore:
   ```bash
   LATEST=/path/to/selected/cat2-YYYYmmddTHHMMSSZ.sql.gz
   sudo mariadb -e 'DROP DATABASE IF EXISTS CAT2; CREATE DATABASE CAT2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
   sudo bash -lc "zcat '$LATEST' | mariadb CAT2"
   ```
4. Verify schema/data and application flows; monitor health and logs.

## 3) Configuration/TLS rollback
- **Apache vhost:** `/etc/apache2/sites-available/000-default.conf` (test with `sudo apache2ctl configtest`, reload with `sudo systemctl reload apache2`).
- **App env/config:** `/etc/apache2/cat2-db-env.conf` (root-only include), `/etc/cat2-health-token`, backup/alert configs (`/etc/cat2-backup-aws.env`, `/etc/cat2-backup-offsite.sh`, `/etc/cat2-alert.env`, `/etc/cat2-alert.sh`).
- **TLS:** Let’s Encrypt certs in `/etc/letsencrypt/live/bidii.online/` (renewal hooks reload Apache). If a cert change causes issues, restore previous known-good Apache SSL config/certs and reload after `configtest`.

## 4) Monitoring & verification after rollback
- Check health endpoint and key user flows (login/signup/feedback/dashboard).
- Confirm cron jobs/monitors remain intact (`/etc/cron.daily/cat2-db-backup`, health monitor cron, certbot timer).
- Review `/var/log/cat2-db-backup.log`, `/var/log/cat2-alert.log`, and Apache logs for errors.
