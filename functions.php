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
 * Execute a backup job's script. Returns ['exit_code' => int, 'output' => string].
 */
function executeScript($backup)
{
    $type    = $backup['script_type'];
    $content = $backup['script_content'] ?? '';
    $uid     = uniqid();
    $id      = (int)$backup['id'];

    switch ($type)
    {
        case 'bash':       $ext = 'sh';  break;
        case 'batch':      $ext = 'bat'; break;
        case 'powershell': $ext = 'ps1'; break;
        case 'php':        $ext = 'php'; break;
        default:           $ext = 'sh';  break;
    }

    $tmpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "pb_{$id}_{$uid}.{$ext}";
    if($type === 'bash' || $type === 'php')
        $content = str_replace("\r", '', $content);
    file_put_contents($tmpFile, $content);
    if($type === 'bash' || $type === 'php')
        chmod($tmpFile, 0755);

    switch ($type)
    {
        case 'bash':
            $cmd = 'bash ' . escapeshellarg($tmpFile);
            break;
        case 'batch':
            $cmd = 'cmd /c ' . escapeshellarg($tmpFile);
            break;
        case 'powershell':
            $cmd = 'powershell -ExecutionPolicy Bypass -File ' . escapeshellarg($tmpFile);
            break;
        case 'php':
            $cmd = PHP_BINARY . ' ' . escapeshellarg($tmpFile);
            break;
        default:
            $cmd = 'bash ' . escapeshellarg($tmpFile);
            break;
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($cmd, $descriptors, $pipes);
    $output  = '';
    if(is_resource($process))
    {
        fclose($pipes[0]);
        $output   = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
    }
    else
    {
        $output   = 'Failed to start process.';
        $exitCode = -1;
    }

    @unlink($tmpFile);

    return ['exit_code' => $exitCode, 'output' => $output];
}

/**
 * Scan output_directory for files matching file_pattern and sync with backup_files table.
 */
function scanBackupFiles($pdo, $backup)
{
    $dir     = rtrim($backup['output_directory'] ?? '', '/\\');
    $pattern = $backup['file_pattern'] ?? '*';
    
    if(!is_dir($dir))
        return;
        
    $globPattern = $dir . DIRECTORY_SEPARATOR . $pattern;
    $found       = glob($globPattern);
    if($found === false)
        $found = [];
            
    $stmt = $pdo->prepare("SELECT id, filepath, filesize, file_mtime, status FROM backup_files WHERE backup_id = ?");
    $stmt->execute([$backup['id']]);
    $existing = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row)
        $existing[$row['filepath']] = $row;
        
        $now        = time();
        $foundPaths = [];
        
        foreach ($found as $filepath)
        {
            $filepath = realpath($filepath) ?: $filepath;
            $foundPaths[$filepath] = true;
            $mtime = (int)filemtime($filepath);
            $size  = (int)filesize($filepath);
            
            if(!isset($existing[$filepath]))
            {
                $stmt = $pdo->prepare("INSERT INTO backup_files (backup_id, filename, filepath, filesize, file_mtime, status, discovered_at) VALUES (?, ?, ?, ?, ?, 'active', ?)");
                $stmt->execute([$backup['id'], basename($filepath), $filepath, $size, $mtime, $now]);
            }
            else
            {
                $row = $existing[$filepath];
                if($row['status'] === 'deleted')
                {
                    $stmt = $pdo->prepare("UPDATE backup_files SET filesize = ?, file_mtime = ?, status = 'active', deleted_at = NULL WHERE id = ?");
                    $stmt->execute([$size, $mtime, $row['id']]);
                }
                else
                if($row['filesize'] != $size || $row['file_mtime'] != $mtime)
                {
                    $stmt = $pdo->prepare("UPDATE backup_files SET filesize = ?, file_mtime = ?, status = 'active' WHERE id = ?");
                    $stmt->execute([$size, $mtime, $row['id']]);
                }
            }
        }
        
        foreach ($existing as $filepath => $row)
        {
            if($row['status'] !== 'deleted' && !isset($foundPaths[$filepath]))
            {
                $stmt = $pdo->prepare("UPDATE backup_files SET status = 'deleted', deleted_at = ? WHERE id = ?");
                $stmt->execute([$now, $row['id']]);
            }
        }
}


/**
 * Apply retention tiers to a backup job. Returns ['kept' => [...], 'deleted' => [...]].
 * If $dryRun is true, files are not actually deleted.
 */
function applyRetention($pdo, $backup, $dryRun = false)
{
    $now = time();

    $stmt = $pdo->prepare("SELECT * FROM retention_tiers WHERE backup_id = ? ORDER BY sort_order ASC");
    $stmt->execute([$backup['id']]);
    $tiers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if(empty($tiers))
        return ['kept' => [], 'deleted' => []];

    $stmt = $pdo->prepare("SELECT * FROM backup_files WHERE backup_id = ? AND status = 'active' ORDER BY file_mtime DESC");
    $stmt->execute([$backup['id']]);
    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $keepIds     = [];
    $tierGroups  = [];

    foreach ($files as $file)
    {
        $ageDays = ($now - (int)$file['file_mtime']) / 86400.0;

        $matchedTier = null;
        foreach ($tiers as $tier)
        {
            if($tier['max_age_days'] === null || $ageDays <= (float)$tier['max_age_days'])
            {
                $matchedTier = $tier;
                break;
            }
        }
        if($matchedTier === null)
            continue;

        $gran  = $matchedTier['keep_granularity'];
        $tidx  = $matchedTier['id'];
        $mtime = (int)$file['file_mtime'];

        if($gran === 'all')
        {
            $keepIds[$file['id']] = true;
        }
        else
        {
            switch ($gran)
            {
                case 'daily':   $key = date('Y-m-d', $mtime); break;
                case 'weekly':  $key = date('Y-W',   $mtime); break;
                case 'monthly': $key = date('Y-m',   $mtime); break;
                case 'yearly':  $key = date('Y',     $mtime); break;
                default:        $key = date('Y-m-d', $mtime); break;
            }
            if(!isset($tierGroups[$tidx][$key]))
            {
                $tierGroups[$tidx][$key] = true;
                $keepIds[$file['id']]    = true;
            }
        }
    }

    $maxCount = (int)($backup['retention_max_count'] ?? 0);
    if($maxCount > 0 && count($keepIds) > $maxCount)
    {
        $trimmed = [];
        $count   = 0;
        foreach ($files as $file)
        {
            if(isset($keepIds[$file['id']]))
            {
                if($count < $maxCount)
                {
                    $trimmed[$file['id']] = true;
                    $count++;
                }
            }
        }
        $keepIds = $trimmed;
    }

    $kept    = [];
    $deleted = [];

    foreach ($files as $file)
    {
        if(isset($keepIds[$file['id']]))
        {
            $kept[] = $file;
        }
        else
        {
            $deleted[] = $file;
            if(!$dryRun)
            {
                if(file_exists($file['filepath']))
                    @unlink($file['filepath']);
                $stmt = $pdo->prepare("UPDATE backup_files SET status = 'deleted', deleted_at = ? WHERE id = ?");
                $stmt->execute([$now, $file['id']]);
            }
        }
    }

    return ['kept' => $kept, 'deleted' => $deleted];
}

/**
 * Run a full backup job: execute script, scan files, log the run.
 * Returns the backup_runs row id.
 */
function runBackupJob($pdo, $backup, $triggeredBy = 'manual', $streamOutput = false)
{
    $now = time();

    $stmt = $pdo->prepare("INSERT INTO backup_runs (backup_id, started_at, status, triggered_by) VALUES (?, ?, 'running', ?)");
    $stmt->execute([$backup['id'], $now, $triggeredBy]);
    $runId = $pdo->lastInsertId();

    if($streamOutput)
        echo "Starting backup: {$backup['name']}...\n";

    $result   = executeScript($backup);
    $finished = time();

    if($streamOutput)
        echo $result['output'];

    $status = $result['exit_code'] === 0 ? 'success' : 'failure';

    $stmt = $pdo->prepare("UPDATE backup_runs SET finished_at = ?, exit_code = ?, output_log = ?, status = ? WHERE id = ?");
    $stmt->execute([$finished, $result['exit_code'], $result['output'], $status, $runId]);

    $stmt = $pdo->prepare("UPDATE backups SET last_run_at = ? WHERE id = ?");
    $stmt->execute([$now, $backup['id']]);

    scanBackupFiles($pdo, $backup);

    if($streamOutput)
        echo "\nScan complete. Exit code: {$result['exit_code']}\n";

    return $runId;
}

function formatBytes($bytes)
{
    $bytes = (int)$bytes;
    if($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if($bytes >= 1048576)    return round($bytes / 1048576, 2)    . ' MB';
    if($bytes >= 1024)       return round($bytes / 1024, 2)       . ' KB';
    return $bytes . ' B';
}

function formatInterval($seconds)
{
    $seconds = (int)$seconds;
    if($seconds <= 0)      return 'never';
    if($seconds < 3600)    return 'every ' . round($seconds / 60) . ' min';
    if($seconds < 86400)   return 'every ' . round($seconds / 3600) . 'h';
    if($seconds < 604800)  return 'every ' . round($seconds / 86400) . 'd';
    return 'every ' . round($seconds / 604800) . 'w';
}

function granularityLabel($gran)
{
    $labels = [
        'all'     => 'Keep all',
        'daily'   => '1 per day',
        'weekly'  => '1 per week',
        'monthly' => '1 per month',
        'yearly'  => '1 per year',
    ];
    return $labels[$gran] ?? $gran;
}

function getRunnerStatus($config)
{
    $dataDir       = $config['data_dir'] ?? (__DIR__ . '/data');
    $pidFile       = $dataDir . '/runner.pid';
    $heartbeatFile = $dataDir . '/runner.heartbeat';

    if(!file_exists($pidFile))
        return ['status' => 'stopped', 'pid' => null, 'heartbeat' => null];

    $pid       = (int)file_get_contents($pidFile);
    $heartbeat = file_exists($heartbeatFile) ? (int)file_get_contents($heartbeatFile) : null;

    if($pid > 0 && file_exists("/proc/$pid"))
    {
        $age = $heartbeat ? (time() - $heartbeat) : null;
        if($age !== null && $age > 120)
            return ['status' => 'stale', 'pid' => $pid, 'heartbeat' => $heartbeat];
        return ['status' => 'running', 'pid' => $pid, 'heartbeat' => $heartbeat];
    }

    return ['status' => 'stopped', 'pid' => null, 'heartbeat' => $heartbeat];
}
