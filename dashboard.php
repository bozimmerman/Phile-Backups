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

// Handle runner start/stop
$runnerMsg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['runner_action']))
{
    $action  = $_POST['runner_action'];
    $dataDir = $config['data_dir'];
    if ($action === 'start')
    {
        $logFile = $dataDir . '/runner.log';
        $runnerPath = escapeshellarg(__DIR__ . '/runner.php');
        exec(PHP_BINARY . " $runnerPath > " . escapeshellarg($logFile) . " 2>&1 &");
        sleep(1);
        $runnerMsg = ['type' => 'success', 'text' => 'Runner started.'];
    }
    elseif ($action === 'stop')
    {
        $pidFile = $dataDir . '/runner.pid';
        if (file_exists($pidFile))
        {
            $pid = (int)file_get_contents($pidFile);
            if ($pid > 0)
                posix_kill($pid, SIGTERM);
            @unlink($pidFile);
        }
        $runnerMsg = ['type' => 'success', 'text' => 'Stop signal sent to runner.'];
    }
    header('Location: dashboard.php');
    exit;
}

// Runner status
$runner = getRunnerStatus($config);

// Summary stats
$totalJobs = (int)$pdo->query("SELECT COUNT(*) FROM backups")->fetchColumn();
$totalFiles = (int)$pdo->query("SELECT COUNT(*) FROM backup_files WHERE status = 'active'")->fetchColumn();
$totalStorage = (int)$pdo->query("SELECT COALESCE(SUM(filesize), 0) FROM backup_files WHERE status = 'active'")->fetchColumn();

$lastFailed = $pdo->query("
    SELECT br.started_at, b.name FROM backup_runs br
    JOIN backups b ON b.id = br.backup_id
    WHERE br.status = 'failure'
    ORDER BY br.started_at DESC LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

// All backup jobs with stats
$stmt = $pdo->query("
    SELECT b.*,
        (SELECT COUNT(*) FROM backup_files bf WHERE bf.backup_id = b.id AND bf.status = 'active') AS file_count,
        (SELECT COALESCE(SUM(filesize),0) FROM backup_files bf WHERE bf.backup_id = b.id AND bf.status = 'active') AS storage_used,
        (SELECT status FROM backup_runs br WHERE br.backup_id = b.id ORDER BY br.started_at DESC LIMIT 1) AS last_status,
        (SELECT started_at FROM backup_runs br WHERE br.backup_id = b.id ORDER BY br.started_at DESC LIMIT 1) AS last_run_time
    FROM backups b
    ORDER BY b.name ASC
");
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Dashboard - <?= htmlspecialchars($config['app_name']) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<header>
    <h1><?= htmlspecialchars($config['app_name']) ?></h1>
    <nav>
        <a href="dashboard.php" class="active">Dashboard</a>
        <a href="backups.php">Backup Jobs</a>
        <a href="edit-backup.php">+ New Job</a>
        <a href="logout.php">Logout</a>
    </nav>
</header>
<div class="container">

    <!-- Runner status bar -->
    <div class="runner-bar">
        <span class="runner-label">Scheduler:</span>
        <?php
        $statusBadge = match($runner['status']) {
            'running' => '<span class="badge badge-success">Running</span>',
            'stale'   => '<span class="badge badge-warning">Stale</span>',
            default   => '<span class="badge badge-secondary">Stopped</span>',
        };
        echo $statusBadge;
        if ($runner['status'] === 'running' && $runner['pid'])
            echo ' <span style="color:#6c757d;font-size:0.9em;">PID ' . $runner['pid'] . '</span>';
        if ($runner['heartbeat'])
            echo ' <span style="color:#6c757d;font-size:0.9em;">Last heartbeat: ' . date('H:i:s', $runner['heartbeat']) . '</span>';
        ?>
        <div class="runner-actions">
            <?php if ($runner['status'] !== 'running'): ?>
                <form method="POST" style="margin:0;">
                    <input type="hidden" name="runner_action" value="start">
                    <button type="submit" class="button success small">Start Runner</button>
                </form>
            <?php else: ?>
                <form method="POST" style="margin:0;">
                    <input type="hidden" name="runner_action" value="stop">
                    <button type="submit" class="button danger small">Stop Runner</button>
                </form>
            <?php endif; ?>
            <?php if ($runner['status'] === 'running'): ?>
                <a href="data/runner.log" class="button secondary small" target="_blank">View Log</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Summary stats -->
    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-value"><?= $totalJobs ?></div>
            <div class="stat-label">Backup Jobs</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $totalFiles ?></div>
            <div class="stat-label">Active Files</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= formatBytes($totalStorage) ?></div>
            <div class="stat-label">Total Storage</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" style="color:<?= $lastFailed ? '#dc3545' : '#28a745' ?>">
                <?= $lastFailed ? date('m/d H:i', $lastFailed['last_run_time']) : 'None' ?>
            </div>
            <div class="stat-label">Last Failure<?= $lastFailed ? ': ' . htmlspecialchars($lastFailed['name']) : '' ?></div>
        </div>
    </div>

    <!-- Job table -->
    <div class="card">
        <h2>Backup Jobs</h2>
        <?php if (empty($jobs)): ?>
            <p style="color:#6c757d;">No backup jobs yet. <a href="edit-backup.php">Create one.</a></p>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Schedule</th>
                    <th>Files</th>
                    <th>Storage</th>
                    <th>Last Run</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($jobs as $job): ?>
                <tr>
                    <td><a href="backup.php?id=<?= $job['id'] ?>"><?= htmlspecialchars($job['name']) ?></a></td>
                    <td><span class="script-type"><?= htmlspecialchars($job['script_type']) ?></span></td>
                    <td>
                        <?php if ($job['schedule_enabled'] && $job['schedule_interval']): ?>
                            <?= htmlspecialchars(formatInterval((int)$job['schedule_interval'])) ?>
                        <?php else: ?>
                            <span style="color:#6c757d;">manual</span>
                        <?php endif; ?>
                    </td>
                    <td><?= (int)$job['file_count'] ?></td>
                    <td><?= formatBytes($job['storage_used']) ?></td>
                    <td>
                        <?php if ($job['last_run_time']): ?>
                            <?= date('Y-m-d H:i', (int)$job['last_run_time']) ?>
                        <?php else: ?>
                            <span style="color:#6c757d;">never</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        $ls = $job['last_status'];
                        if ($ls === 'success')
                            echo '<span class="badge badge-success">OK</span>';
                        elseif ($ls === 'failure')
                            echo '<span class="badge badge-danger">Failed</span>';
                        elseif ($ls === 'running')
                            echo '<span class="badge badge-info">Running</span>';
                        elseif ($job['is_active'])
                            echo '<span class="badge badge-secondary">Idle</span>';
                        else
                            echo '<span class="badge badge-secondary">Disabled</span>';
                        ?>
                    </td>
                    <td class="actions">
                        <a href="backup.php?id=<?= $job['id'] ?>" class="button secondary small">View</a>
                        <a href="edit-backup.php?id=<?= $job['id'] ?>" class="button small">Edit</a>
                        <a href="run-backup.php?id=<?= $job['id'] ?>" class="button success small">Run</a>
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
