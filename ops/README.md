# Ops scripts (VPS reference copies)

This directory contains **reference copies** of operational scripts/configured on the production VPS (`keane@20.174.162.13`). Secrets and host-specific credentials live in root-only files on the VPS and are **not** stored in this repository.

## Deployed locations and related config

| Repo copy | VPS path | Purpose |
| --- | --- | --- |
| `cat2-db-backup.sh` | `/usr/local/bin/cat2-db-backup.sh` | Daily DB backup: dump, gzip/integrity check, restore verification to temp DB, local retention, optional offsite hook, failure alerting |
| `cat2-backup-offsite.sh` | `/etc/cat2-backup-offsite.sh` | Offsite backup hook (S3 upload via `aws` CLI, remote retention/cleanup, failure alerting) |
| `cat2-alert.sh` | `/etc/cat2-alert.sh` | Alert dispatcher (currently Slack webhook), used by backup/health scripts |
| `cat2-health-monitor.sh` | `/usr/local/bin/cat2-health-monitor.sh` | Health endpoint monitor; alerts on non-200 responses |
| `cat2-db-backup.cron.daily` | `/etc/cron.daily/cat2-db-backup` | Daily backup cron entry |

**Required VPS config files (root-only, not in repo):**
- `/etc/cat2-backup.cnf` — MariaDB credentials for `mariadb-dump` (backup script)
- `/etc/cat2-backup-aws.env` — `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `CAT2_BACKUP_S3_URI`
- `/etc/cat2-alert.env` — alert destination config (e.g. `SLACK_WEBHOOK_URL`)
- `/etc/cat2-health-token` — bearer token for authenticated health checks

**Schedules/logs (VPS):**
- DB backup: `/etc/cron.daily/cat2-db-backup` → `/var/log/cat2-db-backup.log`
- Health monitor: root cron `*/5 * * * * /usr/local/bin/cat2-health-monitor.sh` → `/var/log/cat2-health-monitor.log`
- Alerts: `/var/log/cat2-alert.log`
- Local backups: `/var/backups/cat2/cat2-*.sql.gz` (root-only)
- Offsite backups: S3 URI configured in `/etc/cat2-backup-aws.env`

## Updating deployed scripts

1. Review/test changes in this repo (and on a safe environment where possible).
2. Copy the updated script to the corresponding VPS path, preserving ownership/permissions:
   - `/usr/local/bin/*` and `/etc/*.sh` scripts: root-owned, executable (typically `700`)
   - `/etc/cron.daily/cat2-db-backup`: root-owned, executable
3. Run a manual verification:
   - Backup/offsite: `sudo /usr/local/bin/cat2-db-backup.sh` (creates/verifies/uploads a backup) or test hook carefully
   - Health monitor: `sudo /usr/local/bin/cat2-health-monitor.sh`
   - Alert delivery: `sudo /etc/cat2-alert.sh 'Test alert'`
4. Check logs for success/failure and confirm any expected alerts are received.

When changing alerting/backup behavior, keep secrets out of git and prefer root-only env/config files on the VPS.
