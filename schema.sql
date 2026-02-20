CREATE TABLE IF NOT EXISTS `backups` (
    `id`                     INT AUTO_INCREMENT PRIMARY KEY,
    `name`                   VARCHAR(255) NOT NULL,
    `description`            TEXT,
    `script_type`            VARCHAR(20)  NOT NULL DEFAULT 'bash',
    `script_content`         TEXT,
    `restore_script_type`    VARCHAR(20)  DEFAULT NULL,
    `restore_script_content` TEXT         DEFAULT NULL,
    `output_directory`       TEXT,
    `file_pattern`           VARCHAR(255) DEFAULT '*',
    `retention_max_count`    INT          DEFAULT 0,
    `schedule_enabled`       INT          DEFAULT 0,
    `schedule_interval`      INT          DEFAULT 86400,
    `last_run_at`            INT          DEFAULT NULL,
    `next_run_at`            INT          DEFAULT NULL,
    `is_active`              INT          DEFAULT 1,
    `created_at`             DATETIME     DEFAULT NOW(),
    `updated_at`             DATETIME     DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS `retention_tiers` (
    `id`               INT AUTO_INCREMENT PRIMARY KEY,
    `backup_id`        INT         NOT NULL,
    `sort_order`       INT         NOT NULL DEFAULT 1,
    `max_age_days`     INT         DEFAULT NULL,
    `keep_granularity` VARCHAR(20) NOT NULL DEFAULT 'all',
    FOREIGN KEY (`backup_id`) REFERENCES `backups`(`id`) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS `backup_files` (
    `id`           INT AUTO_INCREMENT PRIMARY KEY,
    `backup_id`    INT          NOT NULL,
    `filename`     VARCHAR(255) NOT NULL,
    `filepath`     TEXT         NOT NULL,
    `filesize`     INT          DEFAULT 0,
    `file_mtime`   INT          DEFAULT 0,
    `status`       VARCHAR(20)  DEFAULT 'active',
    `discovered_at` INT         DEFAULT 0,
    `deleted_at`   INT          DEFAULT NULL,
    FOREIGN KEY (`backup_id`) REFERENCES `backups`(`id`) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS `backup_runs` (
    `id`           INT AUTO_INCREMENT PRIMARY KEY,
    `backup_id`    INT         NOT NULL,
    `started_at`   INT         DEFAULT 0,
    `finished_at`  INT         DEFAULT NULL,
    `exit_code`    INT         DEFAULT NULL,
    `output_log`   TEXT,
    `status`       VARCHAR(20) DEFAULT 'running',
    `triggered_by` VARCHAR(20) DEFAULT 'manual',
    FOREIGN KEY (`backup_id`) REFERENCES `backups`(`id`) ON DELETE CASCADE
);

CREATE INDEX `idx_backups_active`         ON `backups`(`is_active`);
CREATE INDEX `idx_backup_files_backup`    ON `backup_files`(`backup_id`, `status`);
CREATE INDEX `idx_backup_runs_backup`     ON `backup_runs`(`backup_id`, `started_at`);
CREATE INDEX `idx_retention_tiers_backup` ON `retention_tiers`(`backup_id`, `sort_order`);
