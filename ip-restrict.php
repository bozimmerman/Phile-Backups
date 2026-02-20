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
 * IP / subnet restriction enforcement.
 * Include this file once per request to enforce the 'security.allowed_ips'
 * setting from conphig.php. Safe to require_once from multiple files.
 */
if(defined('PB_IP_RESTRICT_LOADED'))
    return;
define('PB_IP_RESTRICT_LOADED', true);

if(!function_exists('pb_ip_is_allowed'))
{
    /**
     * Return true if $ip appears in $allowedList.
     * Each entry in $allowedList may be an exact IP or a CIDR range.
     */
    function pb_ip_is_allowed($ip, array $allowedList)
    {
        foreach($allowedList as $entry)
        {
            $entry = trim((string)$entry);
            if($entry === '')
                continue;
            if(strpos($entry, '/') !== false)
            {
                if(pb_cidr_match($ip, $entry))
                    return true;
            }
            else
            {
                if($ip === $entry)
                    return true;
            }
        }
        return false;
    }

    /**
     * Return true if $ip falls within the CIDR block $cidr.
     * Supports both IPv4 (e.g. 192.168.1.0/24) and IPv6 (e.g. fd00::/8).
     */
    function pb_cidr_match($ip, $cidr)
    {
        $parts  = explode('/', $cidr, 2);
        $subnet = $parts[0];
        $prefix = isset($parts[1]) ? (int)$parts[1] : 32;

        // IPv6 path
        if(strpos($ip, ':') !== false || strpos($subnet, ':') !== false)
        {
            $ipBin  = @inet_pton($ip);
            $subBin = @inet_pton($subnet);
            if($ipBin === false || $subBin === false)
                return false;
            $len = strlen($ipBin); // 16 bytes for IPv6
            for($i = 0; $i < $len; $i++)
            {
                $bits = min(8, $prefix - $i * 8);
                if($bits <= 0)
                    break;
                $mask = 0xFF & (0xFF << (8 - $bits));
                if((ord($ipBin[$i]) & $mask) !== (ord($subBin[$i]) & $mask))
                    return false;
            }
            return true;
        }

        // IPv4 path
        $ipLong  = ip2long($ip);
        $subLong = ip2long($subnet);
        if($ipLong === false || $subLong === false)
            return false;
        if($prefix >= 32)
            return $ipLong === $subLong;
        $mask = (-1 << (32 - $prefix));
        return ($ipLong & $mask) === ($subLong & $mask);
    }
}

// Load the configuration and enforce the allowlist for this request.
$_pb_restrict_cfg  = require __DIR__ . '/conphig.php';
$_pb_allowed_ips   = $_pb_restrict_cfg['security']['allowed_ips'] ?? [];

if(!empty($_pb_allowed_ips))
{
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
    if(!pb_ip_is_allowed($clientIp, $_pb_allowed_ips))
    {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        $safeIp = htmlspecialchars($clientIp, ENT_QUOTES, 'UTF-8');
        echo <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>403 Forbidden</title>
    <style>
        body { font-family: sans-serif; text-align: center; padding: 80px 20px; color: #333; }
        h1   { font-size: 2em; margin-bottom: 12px; }
        p    { color: #666; }
        code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; }
    </style>
</head>
<body>
    <h1>403 Forbidden</h1>
    <p>Your IP address (<code>$safeIp</code>) is not authorised to access this application.</p>
    <p>Contact your administrator if you believe this is an error.</p>
</body>
</html>
HTML;
        exit;
    }
}
