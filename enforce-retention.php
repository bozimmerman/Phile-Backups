<?php
/*
 Copyright 2025-2025 Bo Zimmerman

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

// Check for retention tiers
$stmt = $pdo->prepare("SELECT COUNT(*) FROM retention_tiers WHERE backup_id = ?");
$stmt->execute([$id]);
$tierCount = (int)$stmt->fetchColumn();

// Streaming mode
if (isset($_GET['stream']))
{
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    if (ob_get_level()) ob_end_clean();

    echo "=== Enforcing retention for: {$backup['name']} ===\n";

    if ($tierCount === 0 && (int)($backup['retention_max_count'] ?? 0) === 0)
    {
        echo "No retention rules configured. Nothing to do.\n";
        echo "\nREDIRECT:backup.php?id={$id}\n";
        exit;
    }

    // First rescan the directory to catch any new files
    echo "Scanning output directory...\n";
    flush();
    scanBackupFiles($pdo, $backup);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM backup_files WHERE backup_id = ? AND status = 'active'");
    $stmt->execute([$id]);
    $before = (int)$stmt->fetchColumn();
    echo "Active files before retention: $before\n\n";
    flush();

    // Apply retention
    $result = applyRetention($pdo, $backup, dryRun: false);

    echo "Files kept:    " . count($result['kept'])    . "\n";
    echo "Files deleted: " . count($result['deleted']) . "\n";

    if (!empty($result['deleted']))
    {
        echo "\nDeleted files:\n";
        foreach ($result['deleted'] as $f)
            echo "  - " . $f['filename'] . " (" . date('Y-m-d', (int)$f['file_mtime']) . ", " . formatBytes($f['filesize']) . ")\n";
    }

    if (!empty($result['kept']))
    {
        echo "\nKept files:\n";
        foreach ($result['kept'] as $f)
            echo "  + " . $f['filename'] . " (" . date('Y-m-d', (int)$f['file_mtime']) . ", " . formatBytes($f['filesize']) . ")\n";
    }

    echo "\n=== Done ===\n";
    echo "REDIRECT:backup.php?id={$id}\n";
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Enforce Retention: <?= htmlspecialchars($backup['name']) ?> - <?= htmlspecialchars($config['app_name']) ?></title>
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

    <div class="card">
        <h2>Enforce Retention: <?= htmlspecialchars($backup['name']) ?></h2>

        <?php if ($tierCount === 0 && (int)($backup['retention_max_count'] ?? 0) === 0): ?>
            <div class="warning">No retention rules are configured for this job. <a href="edit-backup.php?id=<?= $id ?>">Edit the job</a> to add retention tiers.</div>
            <div class="actions"><a href="backup.php?id=<?= $id ?>" class="button secondary">Back</a></div>
        <?php else: ?>
            <p style="margin-bottom:16px;color:#6c757d;">
                This will scan the output directory, apply retention tier rules, and <strong>permanently delete</strong>
                files that do not qualify for retention. This action cannot be undone.
            </p>
            <div class="actions">
                <button id="run-btn" class="button danger" onclick="startEnforce()">Apply Retention Now</button>
                <a href="backup.php?id=<?= $id ?>" class="button secondary">Cancel</a>
            </div>
        <?php endif; ?>
    </div>

    <div class="card" id="output-card" style="display:none;">
        <h2>Output</h2>
        <div id="output" class="log-output"></div>
        <div id="done-actions" class="actions" style="display:none;margin-top:15px;">
            <a href="backup.php?id=<?= $id ?>" class="button">Back to Job</a>
        </div>
    </div>

</div>
<footer style="text-align:center;padding:20px;font-size:0.8em;color:#999;">
    <?= htmlspecialchars($config['app_name']) ?> v<?= $config['version'] ?>
</footer>
<script>
function startEnforce() {
    var btn = document.getElementById('run-btn');
    btn.disabled = true;
    btn.textContent = 'Working...';
    document.getElementById('output-card').style.display = 'block';

    var outputEl = document.getElementById('output');
    fetch('enforce-retention.php?id=<?= $id ?>&stream=1')
        .then(function(response) {
            var reader = response.body.getReader();
            var decoder = new TextDecoder();
            function read() {
                reader.read().then(function(result) {
                    if (result.done) {
                        btn.textContent = 'Done';
                        document.getElementById('done-actions').style.display = 'flex';
                        return;
                    }
                    var text = decoder.decode(result.value, {stream: true});
                    var lines = text.split('\n');
                    var clean = lines.filter(function(l) { return !l.startsWith('REDIRECT:'); });
                    outputEl.textContent += clean.join('\n');
                    outputEl.scrollTop = outputEl.scrollHeight;
                    read();
                });
            }
            read();
        })
        .catch(function(err) {
            outputEl.textContent += '\nError: ' + err;
            btn.textContent = 'Error';
        });
}
</script>
</body>
</html>
