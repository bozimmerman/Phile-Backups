<?php
/*
 Copyright 2026-2026 Bo Zimmerman

 Licensed under the Apache License, Version 2.0 (the "License");
 you may not use this file except in compliance with the License.
 You may obtain a copy of the License at

 http://www.apache.org/licenses/LICENSE-2.0

 Unless required by applicable law or agreed to in writing, software
 distributed under the License is distributed on an "AS IS" BASIS,
 WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 See the License for the specific language governing permissions and
 limitations under the License.
*/

require_once __DIR__ . '/auth.php';
$config = require __DIR__ . '/conphig.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
$pdo = getDatabase($config);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { header('Location: backups.php'); exit; }

$stmt = $pdo->prepare("SELECT * FROM backups WHERE id = ?");
$stmt->execute([$id]);
$backup = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$backup) { header('Location: backups.php'); exit; }

$savedMsg = isset($_GET['saved']) ? 'Job saved successfully.' : null;

// Retention tiers
$stmt = $pdo->prepare("SELECT * FROM retention_tiers WHERE backup_id = ? ORDER BY sort_order ASC");
$stmt->execute([$id]);
$tiers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Active files
$stmt = $pdo->prepare("
    SELECT * FROM backup_files
    WHERE backup_id = ?
    ORDER BY file_mtime DESC
");
$stmt->execute([$id]);
$files = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Run history (last 30)
$stmt = $pdo->prepare("
    SELECT * FROM backup_runs
    WHERE backup_id = ?
    ORDER BY started_at DESC
    LIMIT 30
");
$stmt->execute([$id]);
$runs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Stats
$activeFiles   = array_filter($files, fn($f) => $f['status'] === 'active');
$deletedFiles  = array_filter($files, fn($f) => $f['status'] === 'deleted');
$totalStorage  = array_sum(array_column(iterator_to_array((function() use ($activeFiles) { yield from $activeFiles; })()), 'filesize'));

// Requested run log
$viewRunId = isset($_GET['run']) ? (int)$_GET['run'] : null;
$viewRun   = null;
if ($viewRunId)
{
    $stmt = $pdo->prepare("SELECT * FROM backup_runs WHERE id = ? AND backup_id = ?");
    $stmt->execute([$viewRunId, $id]);
    $viewRun = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($backup['name']) ?> - <?= htmlspecialchars($config['app_name']) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<header>
    <h1><?= htmlspecialchars($config['app_name']) ?></h1>
    <nav>
        <a href="dashboard.php">Dashboard</a>
        <a href="backups.php">Backup Jobs</a>
        <a href="edit-backup.php">+ New Job</a>
        <a href="logout.php">Logout</a>
    </nav>
</header>
<div class="container">

    <?php if ($savedMsg): ?>
        <div class="success"><?= htmlspecialchars($savedMsg) ?></div>
    <?php endif; ?>

    <!-- Job header -->
    <div class="card">
        <div style="display:flex;align-items:flex-start;gap:20px;flex-wrap:wrap;">
            <div style="flex:1;">
                <h2><?= htmlspecialchars($backup['name']) ?></h2>
                <?php if ($backup['description']): ?>
                    <p style="color:#6c757d;margin-bottom:10px;"><?= htmlspecialchars($backup['description']) ?></p>
                <?php endif; ?>
                <table style="width:auto;margin:0;">
                    <tr><td style="padding:4px 12px 4px 0;color:#6c757d;">Script type</td><td><span class="script-type"><?= htmlspecialchars($backup['script_type']) ?></span></td></tr>
                    <tr><td style="padding:4px 12px 4px 0;color:#6c757d;">Output directory</td><td><code><?= htmlspecialchars($backup['output_directory'] ?? '-') ?></code></td></tr>
                    <tr><td style="padding:4px 12px 4px 0;color:#6c757d;">File pattern</td><td><code><?= htmlspecialchars($backup['file_pattern'] ?? '*') ?></code></td></tr>
                    <tr><td style="padding:4px 12px 4px 0;color:#6c757d;">Schedule</td>
                        <td><?= $backup['schedule_enabled'] ? htmlspecialchars(formatInterval((int)$backup['schedule_interval'])) : '<span style="color:#6c757d;">manual</span>' ?></td></tr>
                    <tr><td style="padding:4px 12px 4px 0;color:#6c757d;">Active</td>
                        <td><?= $backup['is_active'] ? '<span class="badge badge-success">Yes</span>' : '<span class="badge badge-secondary">No</span>' ?></td></tr>
                    <tr><td style="padding:4px 12px 4px 0;color:#6c757d;">Last run</td>
                        <td><?= $backup['last_run_at'] ? date('Y-m-d H:i:s', (int)$backup['last_run_at']) : '<span style="color:#6c757d;">never</span>' ?></td></tr>
                    <?php if ($backup['schedule_enabled'] && $backup['next_run_at']): ?>
                    <tr><td style="padding:4px 12px 4px 0;color:#6c757d;">Next run</td>
                        <td><?= date('Y-m-d H:i:s', (int)$backup['next_run_at']) ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
            <div>
                <div class="stat-grid" style="margin:0;gap:12px;">
                    <div class="stat-card" style="padding:14px;">
                        <div class="stat-value"><?= count($activeFiles) ?></div>
                        <div class="stat-label">Active Files</div>
                    </div>
                    <div class="stat-card" style="padding:14px;">
                        <div class="stat-value"><?= formatBytes($totalStorage) ?></div>
                        <div class="stat-label">Storage Used</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="actions" style="margin-top:20px;">
            <a href="edit-backup.php?id=<?= $id ?>" class="button">Edit Job</a>
            <a href="run-backup.php?id=<?= $id ?>" class="button success">Run Now</a>
            <a href="enforce-retention.php?id=<?= $id ?>" class="button secondary"
               onclick="return confirm('Apply retention rules and delete expired files?')">Enforce Retention</a>
        </div>
    </div>

    <!-- Retention tiers -->
    <?php if (!empty($tiers)): ?>
    <div class="card">
        <h2>Retention Policy</h2>
        <?php if ($backup['retention_max_count'] > 0): ?>
            <p style="margin-bottom:10px;">Overall cap: <strong><?= (int)$backup['retention_max_count'] ?></strong> files</p>
        <?php endif; ?>
        <table>
            <thead><tr><th>Tier</th><th>Age Range</th><th>Keep</th></tr></thead>
            <tbody>
            <?php
            $prevAge = 0;
            foreach ($tiers as $i => $tier):
                $toAge = $tier['max_age_days'];
                if ($prevAge === 0)
                    $range = 'Up to ' . ($toAge ? $toAge . ' days' : 'any age');
                else
                    $range = ($toAge ? "{$prevAge}–{$toAge} days" : "Older than {$prevAge} days");
            ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><?= $range ?></td>
                    <td><?= htmlspecialchars(granularityLabel($tier['keep_granularity'])) ?></td>
                </tr>
            <?php
                $prevAge = $toAge ?? $prevAge;
            endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- Run log detail (if ?run=N) -->
    <?php if ($viewRun): ?>
    <div class="card">
        <h2>Run Log - <?= date('Y-m-d H:i:s', (int)$viewRun['started_at']) ?></h2>
        <p>
            Status: <?php
                if ($viewRun['status'] === 'success')     echo '<span class="badge badge-success">Success</span>';
                elseif ($viewRun['status'] === 'failure') echo '<span class="badge badge-danger">Failure</span>';
                elseif ($viewRun['status'] === 'running') echo '<span class="badge badge-info">Running</span>';
            ?>
            &nbsp; Exit code: <code><?= $viewRun['exit_code'] ?? '-' ?></code>
            &nbsp; Triggered by: <?= htmlspecialchars($viewRun['triggered_by'] ?? '-') ?>
            <?php if ($viewRun['finished_at']): ?>
                &nbsp; Duration: <?= (int)$viewRun['finished_at'] - (int)$viewRun['started_at'] ?>s
            <?php endif; ?>
        </p>
        <div class="log-output"><?= htmlspecialchars($viewRun['output_log'] ?? '') ?></div>
        <div class="actions">
            <a href="backup.php?id=<?= $id ?>" class="button secondary">Back</a>
        </div>
    </div>
    <?php endif; ?>

    <!-- File list -->
    <div class="card">
        <h2>Backup Files</h2>
        <?php
        $showDeleted = isset($_GET['deleted']);
        $displayFiles = $showDeleted ? $files : $activeFiles;
        ?>
        <div style="margin-bottom:10px;">
            <a href="backup.php?id=<?= $id ?><?= $showDeleted ? '' : '&deleted=1' ?>">
                <?= $showDeleted ? 'Hide deleted files' : 'Show deleted/expired files' ?>
            </a>
        </div>
        <?php if (empty($displayFiles)): ?>
            <p style="color:#6c757d;">No files found. Run the backup job to scan for files.</p>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Filename</th>
                    <th>Size</th>
                    <th>Modified</th>
                    <th>Discovered</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($displayFiles as $file): ?>
                <tr>
                    <td style="font-family:monospace;font-size:0.85em;"><?= htmlspecialchars($file['filename']) ?></td>
                    <td><?= formatBytes($file['filesize']) ?></td>
                    <td><?= $file['file_mtime'] ? date('Y-m-d H:i', (int)$file['file_mtime']) : '-' ?></td>
                    <td><?= $file['discovered_at'] ? date('Y-m-d H:i', (int)$file['discovered_at']) : '-' ?></td>
                    <td class="status-<?= $file['status'] ?>">
                        <?php
                        if ($file['status'] === 'active')
                            echo '<span class="badge badge-success">active</span>';
                        elseif ($file['status'] === 'deleted')
                            echo '<span class="badge badge-danger">deleted</span>';
                        else
                            echo '<span class="badge badge-warning">' . htmlspecialchars($file['status']) . '</span>';
                        ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- Run history -->
    <div class="card">
        <h2>Run History</h2>
        <?php if (empty($runs)): ?>
            <p style="color:#6c757d;">No runs yet.</p>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Started</th>
                    <th>Duration</th>
                    <th>Triggered By</th>
                    <th>Exit Code</th>
                    <th>Status</th>
                    <th>Log</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($runs as $run): ?>
                <tr>
                    <td><?= date('Y-m-d H:i:s', (int)$run['started_at']) ?></td>
                    <td>
                        <?php if ($run['finished_at']): ?>
                            <?= (int)$run['finished_at'] - (int)$run['started_at'] ?>s
                        <?php else: ?>-<?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($run['triggered_by'] ?? '-') ?></td>
                    <td><code><?= $run['exit_code'] ?? '-' ?></code></td>
                    <td>
                        <?php
                        if ($run['status'] === 'success')      echo '<span class="badge badge-success">OK</span>';
                        elseif ($run['status'] === 'failure')  echo '<span class="badge badge-danger">Failed</span>';
                        elseif ($run['status'] === 'running')  echo '<span class="badge badge-info">Running</span>';
                        else                                   echo htmlspecialchars($run['status']);
                        ?>
                    </td>
                    <td>
                        <a href="backup.php?id=<?= $id ?>&run=<?= $run['id'] ?>" class="button secondary small">View Log</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

</div>
<footer style="text-align:center;padding:20px;font-size:0.8em;color:#999;">
    <?= htmlspecialchars($config['app_name']) ?> v<?= $config['version'] ?>
</footer>
</body>
</html>
