# Phile-Backups

![Phile-Backups](logo.png)

A self-hosted PHP web UI for managing backup scripts, scheduling runs, and automatically pruning output files with tiered retention policies.

---

## Features

- **Centralized script management** — write and store backup scripts (Bash, PHP, PowerShell, Batch) directly in the UI with syntax highlighting
- **Scheduler daemon** — background runner checks for due jobs every 30 seconds, no cron required
- **File tracking** — after each run, the output directory is scanned and files are inventoried in the database
- **Tiered retention** — define layered rules such as "keep daily for 30 days, weekly for 90 days, one per year beyond that", with an optional hard file count cap
- **Run history** — every execution is logged with status, exit code, duration, and full script output
- **Restore scripts** — optionally attach a restore script to any job, triggerable manually from the dashboard
- **MySQL and SQLite** — schema is initialized automatically on first run
- **IP allowlist** — optionally restrict access to specific IPs or CIDR ranges

---

## Requirements

- PHP 8.0+
- MySQL 5.7+ **or** SQLite 3
- A web server (Apache, nginx, etc.) with PHP-FPM or mod_php
- `proc_open`, `pcntl` extensions (for script execution and signal handling)

---

## Installation

1. **Deploy files** to your web root:
   ```bash
   cp -r Phile-Backups/ /var/www/html/philebackups/
   ```

2. **Create the data directory** and make it writable by your web server user:
   ```bash
   mkdir /var/www/html/philebackups/data
   chown apache:apache /var/www/html/philebackups/data
   ```

3. **Configure the app** by editing `conphig.php`:
   ```php
   'admin_password' => 'yourpassword',

   'db' => [
       'type' => 'mysql',       // or 'sqlite'
       'host' => 'localhost',
       'name' => 'phile_backups',
       'user' => 'dbuser',
       'pass' => 'dbpass',
   ],
   ```
   The database and schema are created automatically on first access.

4. **Start the scheduler** — the simplest approach is a systemd service:

   Create `/etc/systemd/system/philebackups.service`:
   ```ini
   [Unit]
   Description=Phile Backups Runner
   After=network.target

   [Service]
   Type=simple
   ExecStart=/usr/bin/php /var/www/html/philebackups/runner.php
   Restart=on-failure
   RestartSec=10
   WorkingDirectory=/var/www/html/philebackups
   User=apache
   Group=apache
   StandardOutput=journal
   StandardError=journal
   SyslogIdentifier=philebackups

   [Install]
   WantedBy=multi-user.target
   ```
   ```bash
   systemctl daemon-reload
   systemctl enable --now philebackups
   ```

5. **Log in** — navigate to the app in your browser and log in with your configured password.

---

## Configuration (`conphig.php`)

| Key | Description |
|-----|-------------|
| `app_name` | Display name shown in the header |
| `admin_password` | Single password for all access |
| `db.type` | `'mysql'` or `'sqlite'` |
| `db.path` | Path to SQLite file (SQLite only) |
| `db.host` / `db.name` / `db.user` / `db.pass` | MySQL connection details |
| `data_dir` | Directory for `runner.pid`, `runner.heartbeat`, `runner.log` |
| `security.allowed_ips` | Array of allowed IPs/CIDR blocks; empty = allow all |

### IP Restriction Examples
```php
'allowed_ips' => [
    '127.0.0.1',
    '::1',
    '192.168.1.0/24',
    'fd00::/8',
]
```

---

## Backup Jobs

Each job defines:

- **Script** — the code that runs to produce backup files (Bash, PHP, PowerShell, or Batch)
- **Output directory** — where the script writes its files
- **File pattern** — glob pattern used to detect output files (e.g. `backup_*.tar.gz`)
- **Retention policy** — tiered rules controlling how long files are kept
- **Schedule** — optional automatic interval (from every hour to once a year)
- **Restore script** — optional script for recovering from a backup, manually triggered

### Script Responsibilities

Your script is responsible for creating files in the output directory. Phile-Backups handles everything else — detecting the files, tracking them in the database, and pruning old ones according to your retention rules.

```bash
#!/bin/bash
# Example: nightly database dump
mysqldump -u root mydb | gzip > /var/backups/myapp/mydb_$(date +%Y%m%d_%H%M).sql.gz
```

The script is written to a temp file and executed. `stdout` and `stderr` are both captured and stored in the run log.

---

## Retention Policies

Retention is enforced automatically after each run. Tiers are evaluated top to bottom; each file is matched to the first tier whose age window covers it. Within a tier, only the most recent file per period is kept. Files matching no tier are deleted.

### Example

| Tier | Age Range | Keep |
|------|-----------|------|
| 1 | Up to 7 days | 1 per day |
| 2 | Up to 30 days | 1 per week |
| 3 | All remaining | 1 per year |

**Overall cap**: 20 files

### Notes

- **Age limits** are rolling windows from now (e.g. "30 days" means 30 days before the current moment)
- **Period groupings** (daily, weekly, monthly, yearly) use **calendar boundaries** — "1 per week" keeps the newest file from each ISO calendar week, not one per rolling 7-day window
- **The cap** applies after tier rules and keeps the N newest tier-selected files
- **No specific filename format** is required — files are tracked by filesystem modification time, not name

---

## Scheduler

The runner daemon (`runner.php`) wakes every 30 seconds, queries for jobs where `next_run_at <= now`, executes them, and reschedules them as `now + interval`.

### CLI Usage

```bash
php runner.php              # Run continuously as a daemon
php runner.php --once       # Check for due jobs once, then exit
php runner.php --job=N      # Run a specific job by ID, then exit
```

### Missed Runs

If the scheduler is stopped and restarted, any overdue jobs run **once** immediately. Multiple missed runs are not replayed.

### Dashboard Control

The dashboard shows the runner's live status (Running / Stale / Stopped), PID, and last heartbeat time. Start/Stop buttons are available, though for production use the systemd service approach is recommended.

---

## Run History

Every execution is recorded with:

- Start and finish time
- Exit code
- Full `stdout`+`stderr` output
- Trigger source (`manual`, `scheduler`, `restore`)
- Status (`success`, `failure`, `running`, `interrupted`)

If a run gets stuck in `running` state (e.g. due to a crashed web request), it can be manually reset to `interrupted` from the job detail page.

---

## Restore Scripts

Any job can have an optional restore script. It is never run automatically — it must be triggered manually from the dashboard with an explicit confirmation. The output is streamed in real time and logged to run history.

---

## License

Apache License 2.0 — Copyright 2026 Bo Zimmerman
