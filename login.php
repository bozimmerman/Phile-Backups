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

if (session_status() === PHP_SESSION_NONE)
    session_start();

if (isset($_SESSION['pb_logged_in']) && $_SESSION['pb_logged_in'] === true)
{
    header('Location: dashboard.php');
    exit;
}

$config = require __DIR__ . '/conphig.php';
$error  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password']))
{
    if ($_POST['password'] === $config['admin_password'])
    {
        $_SESSION['pb_logged_in'] = true;
        header('Location: dashboard.php');
        exit;
    }
    $error = 'Invalid password';
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Login - <?= htmlspecialchars($config['app_name']) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="login-box">
        <h2><?= htmlspecialchars($config['app_name']) ?></h2>
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="password" name="password" placeholder="Password" required autofocus>
            <button type="submit">Login</button>
        </form>
    </div>
    <footer style="text-align:center;padding:20px;font-size:0.8em;color:#999;">
        <?= htmlspecialchars($config['app_name']) ?> v<?= $config['version'] ?>
    </footer>
</body>
</html>
