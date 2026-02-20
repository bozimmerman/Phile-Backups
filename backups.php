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

$message = null;

if(isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id']))
{
    $id = (int)$_GET['id'];
    $stmt = $pdo->prepare("DELETE FROM backups WHERE id = ?");
    $stmt->execute([$id]);
    $message = ['type' => 'success', 'text' => 'Backup job deleted.'];
}

if(isset($_GET['action']) && $_GET['action'] === 'toggle' && isset($_GET['id']))
{
    $id = (int)$_GET['id'];
    $stmt = $pdo->prepare("UPDATE backups SET is_active = 1 - is_active WHERE id = ?");
    $stmt->execute([$id]);
    header('Location: backups.php');
    exit;
}

$stmt = $pdo->query("
    SELECT b.*,
        (SELECT COUNT(*) FROM backup_files bf WHERE bf.backup_id = b.id AND bf.status = 'active') AS file_count,
        (SELECT status FROM backup_runs br WHERE br.backup_id = b.id ORDER BY br.started_at DESC LIMIT 1) AS last_status
    FROM backups b
    ORDER BY b.name ASC
");
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Backup Jobs - <?= htmlspecialchars($config['app_name']) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<header>
    <h1><?= htmlspecialchars($config['app_name']) ?></h1>
    <nav>
        <a href="dashboard.php">Dashboard</a>
        <a href="backups.php" class="active">Backup Jobs</a>
        <a href="edit-backup.php">+ New Job</a>
        <a href="logout.php">Logout</a>
    </nav>
</header>
<div class="container">

    <?php if($message): ?>
        <div class="<?= $message['type'] ?>"><?= htmlspecialchars($message['text']) ?></div>
    <?php endif; ?>

    <div class="card">
        <h2>Backup Jobs</h2>
        <div class="actions" style="margin-bottom:15px;">
            <a href="edit-backup.php" class="button">+ New Backup Job</a>
        </div>

        <?php if(empty($jobs)): ?>
            <p style="color:#6c757d;">No backup jobs configured yet.</p>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Output Directory</th>
                    <th>Pattern</th>
                    <th>Files</th>
                    <th>Schedule</th>
                    <th>Active</th>
                    <th>Last Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($jobs as $job): ?>
                <tr>
                    <td><a href="backup.php?id=<?= $job['id'] ?>"><?= htmlspecialchars($job['name']) ?></a></td>
                    <td><span class="script-type"><?= htmlspecialchars($job['script_type']) ?></span></td>
                    <td style="font-family:monospace;font-size:0.85em;"><?= htmlspecialchars($job['output_directory'] ?? '') ?></td>
                    <td><code><?= htmlspecialchars($job['file_pattern'] ?? '*') ?></code></td>
                    <td><?= (int)$job['file_count'] ?></td>
                    <td>
                        <?php if($job['schedule_enabled']): ?>
                            <?= htmlspecialchars(formatInterval((int)$job['schedule_interval'])) ?>
                        <?php else: ?>
                            <span style="color:#6c757d;">manual</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="backups.php?action=toggle&id=<?= $job['id'] ?>">
                            <?= $job['is_active'] ? '<span class="badge badge-success">Yes</span>' : '<span class="badge badge-secondary">No</span>' ?>
                        </a>
                    </td>
                    <td>
                        <?php
                        $ls = $job['last_status'];
                        if($ls === 'success')      echo '<span class="badge badge-success">OK</span>';
                        elseif($ls === 'failure')  echo '<span class="badge badge-danger">Failed</span>';
                        elseif($ls === 'running')  echo '<span class="badge badge-info">Running</span>';
                        else                        echo '<span class="badge badge-secondary">Never</span>';
                        ?>
                    </td>
                    <td class="actions">
                        <a href="backup.php?id=<?= $job['id'] ?>" class="button secondary small">View</a>
                        <a href="edit-backup.php?id=<?= $job['id'] ?>" class="button small">Edit</a>
                        <a href="run-backup.php?id=<?= $job['id'] ?>" class="button success small">Run</a>
                        <a href="backups.php?action=delete&id=<?= $job['id'] ?>"
                           class="button danger small"
                           onclick="return confirm('Delete this backup job and all its history? This cannot be undone.')">Delete</a>
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
