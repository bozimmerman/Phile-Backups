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
$config  = require __DIR__ . '/conphig.php';
$logFile = $config['data_dir'] . '/runner.log';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Runner Log - <?= htmlspecialchars($config['app_name']) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<header>
    <img src="logo.png" alt="<?= htmlspecialchars($config['app_name']) ?>" class="header-logo">
    <h1><?= htmlspecialchars($config['app_name']) ?></h1>
    <nav>
        <a href="dashboard.php">Dashboard</a>
        <a href="backups.php">Backup Jobs</a>
        <a href="edit-backup.php">+ New Job</a>
        <?php if(!empty($config['admin_password'])): ?><a href="logout.php">Logout</a><?php endif; ?>
    </nav>
</header>
<div class="container">
    <div class="card">
        <h2>Runner Log</h2>
        <div class="actions" style="margin-bottom:12px;">
            <a href="dashboard.php" class="button secondary small">← Dashboard</a>
        </div>
        <div class="log-output"><?php
        if(!file_exists($logFile))
            echo '(no log file yet)';
        else
            echo htmlspecialchars(file_get_contents($logFile));
        ?></div>
    </div>
</div>
<footer style="text-align:center;padding:20px;font-size:0.8em;color:#999;">
    <?= htmlspecialchars($config['app_name']) ?> v<?= $config['version'] ?>
</footer>
</body>
</html>
