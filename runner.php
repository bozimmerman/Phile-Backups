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

/**
 * Phile-Backups runner daemon.
 *
 * Usage:
 *   php runner.php              — run continuously as a daemon
 *   php runner.php --once       — check for due jobs once, then exit
 *   php runner.php --job=N      — run a specific job by ID, then exit
 *
 * The process writes its PID to data/runner.pid and updates
 * data/runner.heartbeat every loop so the web UI can show its status.
 *
 * To stop: send SIGTERM to the PID, or click "Stop Runner" in the dashboard.
 */

$isWeb = (php_sapi_name() !== 'cli');
if ($isWeb)
{
    // Safety: don't let the web server accidentally run the daemon
    header('HTTP/1.1 403 Forbidden');
    exit('runner.php must be run from the CLI.');
}

$appRoot = __DIR__;
$config  = require $appRoot . '/conphig.php';
require_once $appRoot . '/db.php';
require_once $appRoot . '/functions.php';

$dataDir       = $config['data_dir'];
$pidFile       = $dataDir . '/runner.pid';
$heartbeatFile = $dataDir . '/runner.heartbeat';
$logFile       = $dataDir . '/runner.log';

if (!is_dir($dataDir))
    mkdir($dataDir, 0755, true);

// Parse CLI args
$args    = array_slice($argv ?? [], 1);
$once    = in_array('--once', $args, true);
$jobId   = null;
foreach ($args as $arg)
{
    if (strpos($arg, '--job=') === 0)
        $jobId = (int)substr($arg, 6);
}

// ── Signal handling ──────────────────────────────────────────────────────────
$running = true;
if (function_exists('pcntl_signal'))
{
    pcntl_signal(SIGTERM, function() use (&$running) { $running = false; });
    pcntl_signal(SIGINT,  function() use (&$running) { $running = false; });
}

function logLine($msg)
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    echo $line;
}

// ── Write PID ────────────────────────────────────────────────────────────────
file_put_contents($pidFile, getmypid());

logLine("Runner started (PID " . getmypid() . ")");
if ($once)    logLine("Mode: once");
if ($jobId)   logLine("Mode: single job $jobId");

// ── Main loop ────────────────────────────────────────────────────────────────
do
{
    if (function_exists('pcntl_signal_dispatch'))
        pcntl_signal_dispatch();

    if (!$running)
        break;

    // Update heartbeat
    file_put_contents($heartbeatFile, time());

    // Get database connection (reconnect each iteration to avoid stale handles)
    try
    {
        $pdo = getDatabase($config);
    }
    catch (Exception $e)
    {
        logLine("DB error: " . $e->getMessage());
        sleep(30);
        continue;
    }

    // ── Find due jobs ────────────────────────────────────────────────────────
    $now = time();

    if ($jobId)
    {
        // Single specific job
        $stmt = $pdo->prepare("SELECT * FROM backups WHERE id = ? AND is_active = 1");
        $stmt->execute([$jobId]);
        $dueJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    elseif ($once)
    {
        // All scheduled jobs regardless of next_run_at
        $stmt = $pdo->prepare("SELECT * FROM backups WHERE schedule_enabled = 1 AND is_active = 1");
        $stmt->execute();
        $dueJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    else
    {
        // Normal daemon: only jobs whose next_run_at has passed
        $stmt = $pdo->prepare("
            SELECT * FROM backups
            WHERE schedule_enabled = 1
              AND is_active = 1
              AND (next_run_at IS NULL OR next_run_at <= ?)
        ");
        $stmt->execute([$now]);
        $dueJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    foreach ($dueJobs as $backup)
    {
        if (!$running)
            break;

        logLine("Running job #{$backup['id']}: {$backup['name']}");

        try
        {
            $runId = runBackupJob($pdo, $backup, 'scheduler', false);
            logLine("  Job #{$backup['id']} done (run $runId)");

            // Apply retention after each run
            $tierStmt = $pdo->prepare("SELECT COUNT(*) FROM retention_tiers WHERE backup_id = ?");
            $tierStmt->execute([$backup['id']]);
            if ((int)$tierStmt->fetchColumn() > 0 || (int)($backup['retention_max_count'] ?? 0) > 0)
            {
                $retResult = applyRetention($pdo, $backup);
                logLine("  Retention: kept " . count($retResult['kept']) . ", deleted " . count($retResult['deleted']));
            }

            // Advance next_run_at
            $nextAt = $now + (int)$backup['schedule_interval'];
            $stmt = $pdo->prepare("UPDATE backups SET next_run_at = ? WHERE id = ?");
            $stmt->execute([$nextAt, $backup['id']]);
            logLine("  Next run scheduled: " . date('Y-m-d H:i:s', $nextAt));
        }
        catch (Exception $e)
        {
            logLine("  Error running job #{$backup['id']}: " . $e->getMessage());
        }
    }

    if ($once || $jobId)
        break;

    // Sleep 30 seconds between checks
    logLine("Sleeping 30s...");
    for ($i = 0; $i < 30 && $running; $i++)
    {
        sleep(1);
        if (function_exists('pcntl_signal_dispatch'))
            pcntl_signal_dispatch();
    }

} while ($running);

// Cleanup
@unlink($pidFile);
logLine("Runner stopped.");
