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
if(!$id) { header('Location: backups.php'); exit; }

$stmt = $pdo->prepare("SELECT * FROM backups WHERE id = ?");
$stmt->execute([$id]);
$backup = $stmt->fetch(PDO::FETCH_ASSOC);
if(!$backup) { header('Location: backups.php'); exit; }

if(empty($backup['restore_script_content']))
{
    header('Location: backup.php?id=' . $id);
    exit;
}

if(isset($_GET['stream']))
{
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    if(ob_get_level())
        ob_end_clean();

    $now  = time();
    $stmt = $pdo->prepare("INSERT INTO backup_runs (backup_id, started_at, status, triggered_by) VALUES (?, ?, 'running', 'restore')");
    $stmt->execute([$id, $now]);
    $runId = (int)$pdo->lastInsertId();

    echo "=== Starting Restore: {$backup['name']} ===\n";
    echo "Script type: {$backup['restore_script_type']}\n";
    echo str_repeat('-', 50) . "\n";
    flush();

    $restoreJob = array_merge($backup, [
        'script_type'    => $backup['restore_script_type'],
        'script_content' => $backup['restore_script_content'],
    ]);

    $result   = executeScript($restoreJob);
    $finished = time();

    echo $result['output'];
    echo "\n" . str_repeat('-', 50) . "\n";
    echo "Exit code: {$result['exit_code']}\n";
    echo "Duration: " . ($finished - $now) . "s\n";

    $status = $result['exit_code'] === 0 ? 'success' : 'failure';
    $stmt = $pdo->prepare("UPDATE backup_runs SET finished_at = ?, exit_code = ?, output_log = ?, status = ? WHERE id = ?");
    $stmt->execute([$finished, $result['exit_code'], $result['output'], $status, $runId]);

    echo "\n=== Done (run ID: $runId) ===\n";
    echo "REDIRECT:backup.php?id={$id}&run={$runId}\n";
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Restore: <?= htmlspecialchars($backup['name']) ?> - <?= htmlspecialchars($config['app_name']) ?></title>
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
        <h2>Run Restore: <?= htmlspecialchars($backup['name']) ?></h2>
        <div class="alert" style="background:#fff3cd;border:1px solid #ffc107;color:#856404;padding:12px 16px;border-radius:4px;margin-bottom:16px;">
            <strong>Warning:</strong> Running the restore script may overwrite or modify existing data.
            Proceed only if you intend to restore from a backup.
        </div>
        <table style="width:auto;margin:0 0 16px 0;">
            <tr><td style="padding:4px 12px 4px 0;color:#6c757d;">Restore script type</td><td><span class="script-type"><?= htmlspecialchars($backup['restore_script_type']) ?></span></td></tr>
            <tr><td style="padding:4px 12px 4px 0;color:#6c757d;">Backup job</td><td><a href="backup.php?id=<?= $id ?>"><?= htmlspecialchars($backup['name']) ?></a></td></tr>
        </table>

        <div class="actions">
            <button id="run-btn" class="button danger"
                    onclick="if(confirm('Are you sure you want to run the restore script? This may overwrite existing data.')) startRun();">
                Run Restore Now
            </button>
            <a href="backup.php?id=<?= $id ?>" class="button secondary">Cancel</a>
        </div>
    </div>

    <div class="card" id="output-card" style="display:none;">
        <h2>Restore Output</h2>
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
function startRun()
{
    var btn = document.getElementById('run-btn');
    btn.disabled = true;
    btn.textContent = 'Running...';
    document.getElementById('output-card').style.display = 'block';

    var url = 'run-restore.php?id=<?= $id ?>&stream=1';
    var outputEl = document.getElementById('output');

    fetch(url)
        .then(function(response)
        {
            var reader = response.body.getReader();
            var decoder = new TextDecoder();
            var redirectTo = null;

            function read()
            {
                reader.read().then(function(result)
                {
                    if(result.done)
                    {
                        btn.textContent = 'Done';
                        document.getElementById('done-actions').style.display = 'flex';
                        if(redirectTo)
                        {
                            document.getElementById('view-run-link').href = redirectTo;
                        }
                        return;
                    }
                    var text = decoder.decode(result.value, {stream: true});
                    var lines = text.split('\n');
                    var clean = [];
                    lines.forEach(function(line)
                    {
                        if(line.startsWith('REDIRECT:'))
                        {
                            redirectTo = line.substring(9);
                        }
                        else
                        {
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
        .catch(function(err)
        {
            outputEl.textContent += '\nError: ' + err;
            btn.textContent = 'Error';
        });
}
</script>
</body>
</html>
