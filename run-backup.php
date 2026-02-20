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

// Streaming execution mode — called by JS fetch
if (isset($_GET['stream']))
{
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    if (ob_get_level())
        ob_end_clean();

    $now  = time();
    $stmt = $pdo->prepare("INSERT INTO backup_runs (backup_id, started_at, status, triggered_by) VALUES (?, ?, 'running', 'manual')");
    $stmt->execute([$id, $now]);
    $runId = (int)$pdo->lastInsertId();

    echo "=== Starting: {$backup['name']} ===\n";
    echo "Script type: {$backup['script_type']}\n";
    echo "Output dir:  " . ($backup['output_directory'] ?? '(not set)') . "\n";
    echo str_repeat('-', 50) . "\n";
    flush();

    $result   = executeScript($backup);
    $finished = time();

    echo $result['output'];
    echo "\n" . str_repeat('-', 50) . "\n";
    echo "Exit code: {$result['exit_code']}\n";
    echo "Duration: " . ($finished - $now) . "s\n";

    $status = $result['exit_code'] === 0 ? 'success' : 'failure';
    $stmt = $pdo->prepare("UPDATE backup_runs SET finished_at = ?, exit_code = ?, output_log = ?, status = ? WHERE id = ?");
    $stmt->execute([$finished, $result['exit_code'], $result['output'], $status, $runId]);

    $stmt = $pdo->prepare("UPDATE backups SET last_run_at = ? WHERE id = ?");
    $stmt->execute([$now, $id]);

    // Scan for output files
    echo "\nScanning output directory for backup files...\n";
    flush();
    scanBackupFiles($pdo, $backup);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM backup_files WHERE backup_id = ? AND status = 'active'");
    $stmt->execute([$id]);
    $fileCount = $stmt->fetchColumn();
    echo "Found $fileCount active backup file(s).\n";

    if (isset($_GET['retain']))
    {
        echo "\nApplying retention rules...\n";
        flush();
        $result2 = applyRetention($pdo, $backup);
        echo "Kept: " . count($result2['kept']) . " file(s).\n";
        echo "Deleted: " . count($result2['deleted']) . " file(s).\n";
    }

    echo "\n=== Done (run ID: $runId) ===\n";
    echo "REDIRECT:backup.php?id={$id}&run={$runId}\n";
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Run: <?= htmlspecialchars($backup['name']) ?> - <?= htmlspecialchars($config['app_name']) ?></title>
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
        <h2>Run Backup: <?= htmlspecialchars($backup['name']) ?></h2>
        <table style="width:auto;margin:0 0 16px 0;">
            <tr><td style="padding:4px 12px 4px 0;color:#6c757d;">Type</td><td><span class="script-type"><?= htmlspecialchars($backup['script_type']) ?></span></td></tr>
            <tr><td style="padding:4px 12px 4px 0;color:#6c757d;">Output dir</td><td><code><?= htmlspecialchars($backup['output_directory'] ?? '—') ?></code></td></tr>
            <tr><td style="padding:4px 12px 4px 0;color:#6c757d;">Pattern</td><td><code><?= htmlspecialchars($backup['file_pattern'] ?? '*') ?></code></td></tr>
        </table>

        <div style="margin-bottom:16px;">
            <label style="font-weight:normal;">
                <input type="checkbox" id="auto_retain" checked>
                Enforce retention rules after run
            </label>
        </div>

        <div class="actions">
            <button id="run-btn" class="button success" onclick="startRun()">Run Now</button>
            <a href="backup.php?id=<?= $id ?>" class="button secondary">Cancel</a>
        </div>
    </div>

    <div class="card" id="output-card" style="display:none;">
        <h2>Output</h2>
        <div id="output" class="log-output"></div>
        <div id="done-actions" class="actions" style="display:none;margin-top:15px;">
            <a id="view-run-link" href="#" class="button">View Run Details</a>
            <a href="backup.php?id=<?= $id ?>" class="button secondary">Back to Job</a>
        </div>
    </div>

</div>
<footer style="text-align:center;padding:20px;font-size:0.8em;color:#999;">
    <?= htmlspecialchars($config['app_name']) ?> v<?= $config['version'] ?>
</footer>
<script>
function startRun() {
    var retain = document.getElementById('auto_retain').checked;
    var btn    = document.getElementById('run-btn');
    btn.disabled = true;
    btn.textContent = 'Running...';
    document.getElementById('output-card').style.display = 'block';

    var url = 'run-backup.php?id=<?= $id ?>&stream=1' + (retain ? '&retain=1' : '');
    var outputEl = document.getElementById('output');

    fetch(url)
        .then(function(response) {
            var reader = response.body.getReader();
            var decoder = new TextDecoder();
            var redirectTo = null;

            function read() {
                reader.read().then(function(result) {
                    if (result.done) {
                        btn.textContent = 'Done';
                        document.getElementById('done-actions').style.display = 'flex';
                        if (redirectTo) {
                            document.getElementById('view-run-link').href = redirectTo;
                        }
                        return;
                    }
                    var text = decoder.decode(result.value, {stream: true});
                    // Check for redirect hint
                    var lines = text.split('\n');
                    var clean = [];
                    lines.forEach(function(line) {
                        if (line.startsWith('REDIRECT:')) {
                            redirectTo = line.substring(9);
                        } else {
                            clean.push(line);
                        }
                    });
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
