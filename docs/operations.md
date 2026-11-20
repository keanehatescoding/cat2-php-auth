# Operations Runbook (CAT2)

Routine operational checks for the production deployment (`https://bidii.online/cat2/`, VPS: `keane@20.174.162.13`, app: `/var/www/html/cat2`).

## Key components
- **App/health:** `https://bidii.online/cat2/` and `https://bidii.online/cat2/health.php` (Bearer token auth via `/etc/cat2-health-token` when configured).
- **TLS:** Let’s Encrypt cert for `bidii.online`, `www.bidii.online`, `app.bidii.online`, `cat2.bidii.online` (`certbot.timer` active; renewal hooks reload Apache).
- **Backups:** local verified backups in `/var/backups/cat2/`, offsite copies to S3 via `/etc/cat2-backup-offsite.sh` (see `ops/README.md`).
- **Monitoring/alerts:** health monitor every 5 minutes (`/usr/local/bin/cat2-health-monitor.sh`), alerts via `/etc/cat2-alert.sh` (Slack).

## Logs to verify
- `/var/log/cat2-db-backup.log` — daily backup and offsite upload status/failures
- `/var/log/cat2-health-monitor.log` — health check results
- `/var/log/cat2-alert.log` — alert delivery attempts/responses
- Apache logs: `/var/log/apache2/access.log`, `/var/log/apache2/error.log` (and vhost-specific logs if configured)

**Quick checks:**
```bash
sudo tail -n 50 /var/log/cat2-db-backup.log
sudo tail -n 50 /var/log/cat2-health-monitor.log
sudo tail -n 50 /var/log/cat2-alert.log
sudo ls -1t /var/backups/cat2/cat2-*.sql.gz | head
```

## Periodic offsite restore drills
Perform regularly (e.g. monthly/quarterly) and after significant backup/config changes.

1. **Identify backup source:**
   - Local: latest file in `/var/backups/cat2/`
   - Offsite: `source /etc/cat2-backup-aws.env` (as root) and `aws s3 ls "${CAT2_BACKUP_S3_URI}/"`
2. **Download and verify (for offsite):**
   ```bash
   TMP=/tmp/cat2-restore-drill.sql.gz
   sudo bash -lc 'source /etc/cat2-backup-aws.env; aws s3 cp "${CAT2_BACKUP_S3_URI}/<backup-file>" "'"$TMP"'"'
   sudo gzip -t "$TMP"
   ```
3. **Restore to a temporary DB (non-destructive):**
   ```bash
   sudo mariadb -e 'DROP DATABASE IF EXISTS CAT2_restore_drill; CREATE DATABASE CAT2_restore_drill;'
   sudo bash -lc "zcat '$TMP' | sed 's/\`CAT2\`/\`CAT2_restore_drill\`/g' | mariadb"
   sudo mariadb -e 'USE CAT2_restore_drill; SHOW TABLES; SELECT COUNT(*) FROM users; SELECT COUNT(*) FROM feedback; SELECT COUNT(*) FROM activity_log; SELECT COUNT(*) FROM login_attempts;'
   sudo mariadb -e 'DROP DATABASE IF EXISTS CAT2_restore_drill;'
   sudo rm -f "$TMP"
   ```
4. **Record outcome:** note date, backup file used, verification results, and any issues/remediations.

> Production restores are destructive; follow `docs/rollback.md` and take a fresh pre-restore backup when possible.

## Alert delivery verification
- **Health alerts:** `sudo /etc/cat2-alert.sh 'Test alert: CAT2 ops check'` and confirm receipt in Slack.
- **Failure-path confidence:** review backup/health logs for failures and confirm alert hook behavior after script changes. Avoid triggering disruptive failures in production; prefer controlled/manual verification.

## Certificate and dependency hygiene
- **TLS/certbot:** check `systemctl status certbot.timer` and recent renewal logs (`/var/log/letsencrypt/letsencrypt.log`); ensure Apache reload hooks succeed and certs cover all hostnames.
- **OS/packages:** apply security updates on the VPS (Apache, PHP, MariaDB, certbot, awscli/curl/rsync) during maintenance windows.
- **App/deploy dependencies:** review GitHub Actions workflows, action versions, PHP version, and Composer/npm dependencies if added; keep CI and runtime aligned.
- **Secrets/config:** rotate credentials when exposed (e.g. Slack webhook, AWS keys), keep `/etc/cat2-*.env` and tokens root-only, and verify permissions after changes.

## Documentation hygiene
- Keep `docs/rollback.md`, `docs/operations.md`, and `ops/README.md` current after infrastructure, backup, alerting, deploy, or security changes.
- Record notable operational changes/incidents and restore drill results in an ops log or issue tracker as appropriate.
