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
$pdo = getDatabase($config);

$id      = isset($_GET['id']) ? (int)$_GET['id'] : null;
$backup  = null;
$tiers   = [];
$errors  = [];
$success = null;

// Load existing backup if editing
if ($id)
{
    $stmt = $pdo->prepare("SELECT * FROM backups WHERE id = ?");
    $stmt->execute([$id]);
    $backup = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$backup)
    {
        header('Location: backups.php');
        exit;
    }
    $stmt = $pdo->prepare("SELECT * FROM retention_tiers WHERE backup_id = ? ORDER BY sort_order ASC");
    $stmt->execute([$id]);
    $tiers = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Handle form submit
if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $name               = trim($_POST['name'] ?? '');
    $description        = trim($_POST['description'] ?? '');
    $scriptType         = $_POST['script_type'] ?? 'bash';
    $scriptContent      = $_POST['script_content'] ?? '';
    $outputDirectory    = trim($_POST['output_directory'] ?? '');
    $filePattern        = trim($_POST['file_pattern'] ?? '*') ?: '*';
    $retentionMaxCount  = max(0, (int)($_POST['retention_max_count'] ?? 0));
    $scheduleEnabled    = isset($_POST['schedule_enabled']) ? 1 : 0;
    $scheduleInterval   = max(60, (int)($_POST['schedule_interval'] ?? 86400));
    $isActive           = isset($_POST['is_active']) ? 1 : 0;

    $validTypes = ['bash', 'batch', 'powershell', 'php'];
    if (!in_array($scriptType, $validTypes, true))
        $scriptType = 'bash';

    if ($name === '')
        $errors[] = 'Name is required.';
    if ($outputDirectory === '')
        $errors[] = 'Output directory is required.';

    // Parse tier rows
    $submittedTiers = [];
    if (isset($_POST['tier_max_age']) && is_array($_POST['tier_max_age']))
    {
        $granularities = $_POST['tier_gran'] ?? [];
        foreach ($_POST['tier_max_age'] as $i => $rawAge)
        {
            $gran = $granularities[$i] ?? 'all';
            $validGrans = ['all', 'daily', 'weekly', 'monthly', 'yearly'];
            if (!in_array($gran, $validGrans, true))
                $gran = 'all';
            $maxAge = $rawAge === '' || $rawAge === null ? null : max(1, (int)$rawAge);
            $submittedTiers[] = ['max_age_days' => $maxAge, 'keep_granularity' => $gran];
        }
    }

    if (empty($errors))
    {
        if ($id)
        {
            $stmt = $pdo->prepare("
                UPDATE backups SET
                    name = ?, description = ?, script_type = ?, script_content = ?,
                    output_directory = ?, file_pattern = ?, retention_max_count = ?,
                    schedule_enabled = ?, schedule_interval = ?, is_active = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $name, $description, $scriptType, $scriptContent,
                $outputDirectory, $filePattern, $retentionMaxCount,
                $scheduleEnabled, $scheduleInterval, $isActive, $id
            ]);
        }
        else
        {
            $stmt = $pdo->prepare("
                INSERT INTO backups (name, description, script_type, script_content,
                    output_directory, file_pattern, retention_max_count,
                    schedule_enabled, schedule_interval, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $name, $description, $scriptType, $scriptContent,
                $outputDirectory, $filePattern, $retentionMaxCount,
                $scheduleEnabled, $scheduleInterval, $isActive
            ]);
            $id = (int)$pdo->lastInsertId();
        }

        // Replace retention tiers
        $stmt = $pdo->prepare("DELETE FROM retention_tiers WHERE backup_id = ?");
        $stmt->execute([$id]);
        foreach ($submittedTiers as $order => $tier)
        {
            $stmt = $pdo->prepare("INSERT INTO retention_tiers (backup_id, sort_order, max_age_days, keep_granularity) VALUES (?, ?, ?, ?)");
            $stmt->execute([$id, $order + 1, $tier['max_age_days'], $tier['keep_granularity']]);
        }

        // Update next_run_at if scheduling enabled
        if ($scheduleEnabled)
        {
            $next = time() + $scheduleInterval;
            $stmt = $pdo->prepare("UPDATE backups SET next_run_at = ? WHERE id = ? AND (next_run_at IS NULL OR next_run_at < ?)");
            $stmt->execute([$next, $id, time()]);
        }

        header('Location: backup.php?id=' . $id . '&saved=1');
        exit;
    }

    // Re-populate from POST on error
    $backup = [
        'id'                  => $id,
        'name'                => $name,
        'description'         => $description,
        'script_type'         => $scriptType,
        'script_content'      => $scriptContent,
        'output_directory'    => $outputDirectory,
        'file_pattern'        => $filePattern,
        'retention_max_count' => $retentionMaxCount,
        'schedule_enabled'    => $scheduleEnabled,
        'schedule_interval'   => $scheduleInterval,
        'is_active'           => $isActive,
    ];
    $tiers = $submittedTiers;
}

$pageTitle = $id ? 'Edit Backup Job' : 'New Backup Job';
$scriptType = $backup['script_type'] ?? 'bash';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title><?= $pageTitle ?> - <?= htmlspecialchars($config['app_name']) ?></title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/theme/monokai.min.css">
</head>
<body>
<header>
    <h1><?= htmlspecialchars($config['app_name']) ?></h1>
    <nav>
        <a href="dashboard.php">Dashboard</a>
        <a href="backups.php">Backup Jobs</a>
        <a href="edit-backup.php" class="active">+ New Job</a>
        <a href="logout.php">Logout</a>
    </nav>
</header>
<div class="container">

    <?php foreach ($errors as $err): ?>
        <div class="error"><?= htmlspecialchars($err) ?></div>
    <?php endforeach; ?>

    <form method="POST">
        <!-- Basic info -->
        <div class="card">
            <h2><?= $pageTitle ?></h2>

            <div class="form-row">
                <div>
                    <label for="name">Job Name *</label>
                    <input type="text" id="name" name="name" required
                           value="<?= htmlspecialchars($backup['name'] ?? '') ?>">
                </div>
                <div>
                    <label for="script_type">Script Type</label>
                    <select id="script_type" name="script_type">
                        <?php foreach (['bash', 'batch', 'powershell', 'php'] as $t): ?>
                            <option value="<?= $t ?>" <?= ($backup['script_type'] ?? 'bash') === $t ? 'selected' : '' ?>><?= $t ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <label for="description">Description</label>
            <textarea id="description" name="description" style="min-height:60px;"><?= htmlspecialchars($backup['description'] ?? '') ?></textarea>
        </div>

        <!-- Script editor -->
        <div class="card">
            <h2>Script</h2>
            <p class="form-hint" style="margin-bottom:12px;">
                This script will be written to a temp file and executed when the backup runs.
                The script is responsible for creating backup files in the output directory.
            </p>
            <textarea id="script_content" name="script_content"><?= htmlspecialchars($backup['script_content'] ?? '') ?></textarea>
        </div>

        <!-- File detection -->
        <div class="card">
            <h2>Output Files</h2>
            <div class="form-row">
                <div>
                    <label for="output_directory">Output Directory *</label>
                    <input type="text" id="output_directory" name="output_directory"
                           placeholder="/var/backups/myapp"
                           value="<?= htmlspecialchars($backup['output_directory'] ?? '') ?>">
                    <p class="form-hint">Absolute path where backup files are written.</p>
                </div>
                <div>
                    <label for="file_pattern">File Pattern</label>
                    <input type="text" id="file_pattern" name="file_pattern"
                           placeholder="*.tar.gz"
                           value="<?= htmlspecialchars($backup['file_pattern'] ?? '*') ?>">
                    <p class="form-hint">Glob pattern to match backup files (e.g. <code>backup_*.tar.gz</code>).</p>
                </div>
            </div>
        </div>

        <!-- Retention -->
        <div class="card">
            <h2>Retention Policy</h2>
            <p style="margin-bottom:15px;color:#6c757d;">
                Tiers are evaluated from top to bottom. Each file is matched to the first tier
                whose age limit covers it. Within a tier, only the most recent file per period
                is kept. Files matching no tier are deleted.
            </p>

            <div id="tier-list" class="tier-list">
                <?php if (empty($tiers)): ?>
                    <!-- default: keep all -->
                    <div class="tier-row" data-tier>
                        <label>For files up to</label>
                        <input type="number" name="tier_max_age[]" value="30" min="1" style="width:80px;">
                        <label>days old:</label>
                        <select name="tier_gran[]">
                            <?php foreach (['all'=>'Keep all','daily'=>'1 per day','weekly'=>'1 per week','monthly'=>'1 per month','yearly'=>'1 per year'] as $v=>$l): ?>
                                <option value="<?= $v ?>"><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="tier-remove" onclick="removeTier(this)">Remove</button>
                    </div>
                    <div class="tier-row" data-tier>
                        <label>For all remaining files:</label>
                        <select name="tier_gran[]">
                            <?php foreach (['all'=>'Keep all','daily'=>'1 per day','weekly'=>'1 per week','monthly'=>'1 per month','yearly'=>'1 per year'] as $v=>$l): ?>
                                <option value="<?= $v ?>" <?= $v==='yearly'?'selected':'' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="tier_max_age[]" value="">
                        <button type="button" class="tier-remove" onclick="removeTier(this)">Remove</button>
                    </div>
                <?php else: ?>
                    <?php foreach ($tiers as $i => $tier): ?>
                        <?php $isLast = ($tier['max_age_days'] === null); ?>
                        <div class="tier-row" data-tier>
                            <?php if (!$isLast): ?>
                                <label>For files up to</label>
                                <input type="number" name="tier_max_age[]"
                                       value="<?= (int)$tier['max_age_days'] ?>" min="1" style="width:80px;">
                                <label>days old:</label>
                            <?php else: ?>
                                <label>For all remaining files:</label>
                                <input type="hidden" name="tier_max_age[]" value="">
                            <?php endif; ?>
                            <select name="tier_gran[]">
                                <?php foreach (['all'=>'Keep all','daily'=>'1 per day','weekly'=>'1 per week','monthly'=>'1 per month','yearly'=>'1 per year'] as $v=>$l): ?>
                                    <option value="<?= $v ?>" <?= $tier['keep_granularity']===$v?'selected':'' ?>><?= $l ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="tier-remove" onclick="removeTier(this)">Remove</button>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="actions">
                <button type="button" onclick="addTierBounded()" class="secondary small">+ Add Bounded Tier</button>
                <button type="button" onclick="addTierCatchAll()" class="secondary small">+ Add Catch-All Tier</button>
            </div>

            <div style="margin-top:20px;">
                <label for="retention_max_count">Overall Maximum File Count</label>
                <input type="number" id="retention_max_count" name="retention_max_count" min="0"
                       value="<?= (int)($backup['retention_max_count'] ?? 0) ?>" style="width:160px;">
                <p class="form-hint">Hard cap on total files kept after tier rules. 0 = unlimited.</p>
            </div>
        </div>

        <!-- Schedule -->
        <div class="card">
            <h2>Schedule</h2>
            <div style="margin-bottom:15px;">
                <label>
                    <input type="checkbox" name="schedule_enabled" value="1"
                           <?= ($backup['schedule_enabled'] ?? 0) ? 'checked' : '' ?>
                           onchange="document.getElementById('schedule-opts').style.display=this.checked?'block':'none'">
                    Enable automatic scheduling
                </label>
            </div>
            <div id="schedule-opts" style="display:<?= ($backup['schedule_enabled'] ?? 0) ? 'block' : 'none' ?>;">
                <label for="schedule_preset">Run Interval</label>
                <select id="schedule_preset" onchange="applyPreset(this.value)">
                    <option value="">-- pick preset --</option>
                    <option value="3600">Every 1 hour</option>
                    <option value="21600">Every 6 hours</option>
                    <option value="43200">Every 12 hours</option>
                    <option value="86400">Every 1 day</option>
                    <option value="604800">Every 1 week</option>
                    <option value="custom">Custom (seconds)</option>
                </select>
                <div style="margin-top:10px;">
                    <label for="schedule_interval">Interval (seconds)</label>
                    <input type="number" id="schedule_interval" name="schedule_interval" min="60"
                           value="<?= (int)($backup['schedule_interval'] ?? 86400) ?>" style="width:200px;">
                    <p class="form-hint">Minimum 60 seconds.</p>
                </div>
            </div>
        </div>

        <!-- Active toggle -->
        <div class="card">
            <label>
                <input type="checkbox" name="is_active" value="1"
                       <?= ($backup['is_active'] ?? 1) ? 'checked' : '' ?>>
                Job is active (will run on schedule and can be triggered manually)
            </label>
        </div>

        <div class="actions">
            <button type="submit">Save Job</button>
            <?php if ($id): ?>
                <a href="backup.php?id=<?= $id ?>" class="button secondary">Cancel</a>
            <?php else: ?>
                <a href="backups.php" class="button secondary">Cancel</a>
            <?php endif; ?>
        </div>
    </form>

</div>
<footer style="text-align:center;padding:20px;font-size:0.8em;color:#999;">
    <?= htmlspecialchars($config['app_name']) ?> v<?= $config['version'] ?>
</footer>

<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/shell/shell.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/php/php.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/htmlmixed/htmlmixed.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/xml/xml.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/javascript/javascript.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/css/css.min.js"></script>
<script>
// CodeMirror setup
var modeMap = {bash: 'shell', batch: null, powershell: null, php: 'php'};
var editor = CodeMirror.fromTextArea(document.getElementById('script_content'), {
    mode: modeMap['<?= $scriptType ?>'] || null,
    theme: 'monokai',
    lineNumbers: true,
    indentWithTabs: true,
    tabSize: 4,
    lineWrapping: false,
});
document.getElementById('script_type').addEventListener('change', function() {
    var mode = modeMap[this.value] || null;
    editor.setOption('mode', mode);
});

// Retention tier editor
var granOptions = '<option value="all">Keep all</option><option value="daily">1 per day</option><option value="weekly">1 per week</option><option value="monthly">1 per month</option><option value="yearly">1 per year</option>';

function addTierBounded() {
    var row = document.createElement('div');
    row.className = 'tier-row';
    row.setAttribute('data-tier', '');
    row.innerHTML = '<label>For files up to</label>' +
        '<input type="number" name="tier_max_age[]" value="30" min="1" style="width:80px;">' +
        '<label>days old:</label>' +
        '<select name="tier_gran[]">' + granOptions + '</select>' +
        '<button type="button" class="tier-remove" onclick="removeTier(this)">Remove</button>';
    document.getElementById('tier-list').appendChild(row);
}

function addTierCatchAll() {
    var row = document.createElement('div');
    row.className = 'tier-row';
    row.setAttribute('data-tier', '');
    row.innerHTML = '<label>For all remaining files:</label>' +
        '<select name="tier_gran[]">' + granOptions + '</select>' +
        '<input type="hidden" name="tier_max_age[]" value="">' +
        '<button type="button" class="tier-remove" onclick="removeTier(this)">Remove</button>';
    document.getElementById('tier-list').appendChild(row);
}

function removeTier(btn) {
    btn.closest('[data-tier]').remove();
}

// Schedule preset
function applyPreset(val) {
    if (val && val !== 'custom')
        document.getElementById('schedule_interval').value = val;
}
</script>
</body>
</html>
