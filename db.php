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

function getDatabase($config)
{
    $type = $config['db']['type'] ?? 'sqlite';

    if($type === 'sqlite')
    {
        $dbPath  = $config['db']['path'] ?? __DIR__ . '/data/phile-backups.db';
        $dataDir = dirname($dbPath);
        if(!is_dir($dataDir))
            mkdir($dataDir, 0755, true);
        $pdo = new PDO("sqlite:$dbPath");
        $pdo->exec('PRAGMA foreign_keys = ON');
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='backups'")->fetchAll();
        if(empty($tables))
            initializeSchema($pdo, $config, 'sqlite');
        return new SQLiteCompatPDO($pdo);
    }
    else
    {
        $pdo = new PDO(
            "mysql:host={$config['db']['host']};charset={$config['db']['charset']}",
            $config['db']['user'],
            $config['db']['pass']
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$config['db']['name']}`");
        $pdo->exec("USE `{$config['db']['name']}`");
        try
        {
            $pdo->query("SELECT 1 FROM backups LIMIT 1");
        }
        catch (PDOException $e)
        {
            initializeSchema($pdo, $config, 'mysql');
        }
        return $pdo;
    }
}

class SQLiteCompatPDO
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function prepare($sql)
    {
        $sql = str_replace('NOW()', "datetime('now')", $sql);
        $sql = str_replace('FROM_UNIXTIME(?)', "datetime(?, 'unixepoch')", $sql);
        $sql = preg_replace('/UNIX_TIMESTAMP\s*\(\s*([^)]+)\s*\)/', "strftime('%s', $1)", $sql);
        if(stripos($sql, 'ON DUPLICATE KEY UPDATE') !== false)
        {
            $matches = [];
            preg_match('/ON DUPLICATE KEY UPDATE\s+(.+)$/is', $sql, $matches);
            $updateClause = isset($matches[1]) ? trim($matches[1]) : '';
            $sql = preg_replace('/\s+ON DUPLICATE KEY UPDATE\s+.+$/is', '', $sql);
            if(!empty($updateClause))
            {
                $updateClause = preg_replace('/VALUES\s*\(\s*(\w+)\s*\)/i', 'excluded.$1', $updateClause);
                $sql .= ' ON CONFLICT DO UPDATE SET ' . $updateClause;
            }
            else
                $sql = preg_replace('/INSERT INTO/i', 'INSERT OR IGNORE INTO', $sql);
        }
        $sql = str_replace('`', '"', $sql);
        return $this->pdo->prepare($sql);
    }

    public function __call($method, $args)
    {
        return call_user_func_array([$this->pdo, $method], $args);
    }

    public function query($sql)
    {
        $sql = str_replace('NOW()', "datetime('now')", $sql);
        $sql = str_replace('`', '"', $sql);
        return $this->pdo->query($sql);
    }

    public function exec($sql)
    {
        $sql = str_replace('NOW()', "datetime('now')", $sql);
        $sql = str_replace('`', '"', $sql);
        return $this->pdo->exec($sql);
    }
}

function initializeSchema($pdo, $config, $type)
{
    $sql = file_get_contents(__DIR__ . '/schema.sql');
    if($type === 'sqlite')
    {
        $sql = preg_replace('/CREATE\s+DATABASE[^;]*;/i', '', $sql);
        $sql = preg_replace('/USE\s+\w+\s*;/i', '', $sql);
        $sql = preg_replace('/\bINT\s+AUTO_INCREMENT\s+PRIMARY KEY\b/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
        $sql = preg_replace('/ENGINE\s*=\s*InnoDB/i', '', $sql);
        $sql = str_replace('`', '', $sql);
        $sql = str_replace('INT ', 'INTEGER ', $sql);
        $sql = str_replace('DATETIME', 'TEXT', $sql);
    }
    $statements = explode(';', $sql);
    foreach ($statements as $statement)
    {
        $statement = trim($statement);
        if(!empty($statement))
            $pdo->exec($statement);
    }
}
