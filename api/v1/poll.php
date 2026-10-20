<?php
$configDir = __DIR__ . '/../../config/';

function loadJson($file) {
    global $configDir;
    $path = $configDir . $file;
    if (!file_exists($path)) return [];
    return json_decode(file_get_contents($path), true) ?: [];
}

function saveJson($file, $data) {
    global $configDir;
    $path = $configDir . $file;
    $fp = fopen($path, 'c+');
    if ($fp && flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function updateJsonLocked($file, $callback) {
    global $configDir;
    $handle = @fopen($configDir . $file, 'c+');
    if (!$handle || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) fclose($handle);
        return false;
    }
    rewind($handle);
    $data = json_decode(stream_get_contents($handle), true);
    if (!is_array($data)) $data = [];
    $callback($data);
    $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $saved = false;
    if ($encoded !== false) {
        rewind($handle);
        $saved = ftruncate($handle, 0) && fwrite($handle, $encoded) !== false;
        if ($saved) fflush($handle);
    }
    flock($handle, LOCK_UN);
    fclose($handle);
    return $saved;
}

function logActivity($type, $detail) {
    $activity = loadJson('activity.json');
    if (!isset($activity['logs'])) $activity['logs'] = [];
    array_unshift($activity['logs'], [
        'type' => $type,
        'detail' => $detail,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    $activity['logs'] = array_slice($activity['logs'], 0, 5000);
    saveJson('activity.json', $activity);
}

function rateLimitCheck($ip, $limit = 10) {
    $file = sys_get_temp_dir() . '/smskit_rate_' . md5($ip);
    $now = time();
    $window = 60;
    $data = @json_decode(@file_get_contents($file), true) ?: ['tokens' => $limit, 'last' => $now];
    $elapsed = $now - $data['last'];
    $data['tokens'] = min($limit, $data['tokens'] + ($elapsed * ($limit / $window)));
    $data['last'] = $now;
    if ($data['tokens'] < 1) {
        @file_put_contents($file, json_encode($data));
        return false;
    }
    $data['tokens'] -= 1;
    @file_put_contents($file, json_encode($data));
    return true;
}

$settings = loadJson('settings.json');
if (!empty($settings['timezone'])) {
    date_default_timezone_set(stripslashes($settings['timezone']));
}

$deviceId = $_GET['device_id'] ?? '';

if (empty($deviceId)) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    if (!rateLimitCheck($ip, 10)) {
        http_response_code(429);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Rate limit exceeded']);
        exit;
    }
}

$model = $_GET['model'] ?? 'Unknown';
$android = $_GET['android'] ?? 'Unknown';

if (!empty($deviceId)) {
    $tokens = loadJson('device_tokens.json');
    if (!isset($tokens['devices']) || !is_array($tokens['devices'])) $tokens['devices'] = [];
    $found = false;
    $tokensChanged = false;
    $nowTimestamp = time();
    $nowString = date('Y-m-d H:i:s');

    foreach ($tokens['devices'] as &$dev) {
        if (($dev['device_id'] ?? '') === $deviceId) {
            $found = true;
            $lastSeenTimestamp = strtotime($dev['last_seen'] ?? '') ?: 0;

            // Phones may poll every 5 seconds. Persist the heartbeat every 15 seconds
            // instead of rewriting the whole device JSON on every single poll.
            if (($nowTimestamp - $lastSeenTimestamp) >= 15 ||
                ($dev['status'] ?? '') !== 'online' ||
                ($dev['model'] ?? '') !== $model ||
                ($dev['android'] ?? '') !== $android) {
                $dev['last_seen'] = $nowString;
                $dev['model'] = $model;
                $dev['android'] = $android;
                $dev['status'] = 'online';
                $tokensChanged = true;
            }
            break;
        }
    }
    unset($dev);

    if (!$found) {
        $tokens['devices'][] = [
            'device_id' => $deviceId,
            'model' => $model,
            'android' => $android,
            'status' => 'online',
            'last_seen' => $nowString,
            'sms_sent_count' => 0
        ];
        $tokensChanged = true;
    }

    if ($tokensChanged) saveJson('device_tokens.json', $tokens);
}

$schedulerLock = @fopen($configDir . 'scheduler.lock', 'c');
if (!$schedulerLock || !flock($schedulerLock, LOCK_EX)) {
    if (is_resource($schedulerLock)) fclose($schedulerLock);
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Unable to lock scheduler']);
    exit;
}

$db = loadJson('database.json');
$scheduled = $db['scheduled_sms'] ?? [];
$now = time();
$updated = false;

foreach ($scheduled as $key => $sched) {
    if (($sched['status'] ?? '') !== 'pending') continue;
    $scheduleTime = $sched['schedule_time'] ?? '';
    if (empty($scheduleTime)) continue;
    $schedTimestamp = strtotime($scheduleTime);
    if ($schedTimestamp === false || $schedTimestamp > $now) continue;

    $numbers = $sched['numbers'] ?? [];
    $message = $sched['message'] ?? '';
    if (empty($numbers) || empty($message)) {
        $scheduled[$key]['status'] = 'failed';
        $updated = true;
        continue;
    }

    $msgId = 'sched_' . uniqid();
    $queueSaved = updateJsonLocked('sms_queue.json', function (&$queue) use ($numbers, $message, $sched, $msgId) {
        if (!isset($queue['queue']) || !is_array($queue['queue'])) $queue['queue'] = [];
        foreach ($numbers as $num) {
            $queue['queue'][] = [
                'id' => $msgId . '_' . mt_rand(1000, 9999),
                'message_id' => $msgId,
                'to' => trim($num),
                'message' => $message,
                'status' => 'queued',
                'created_at' => date('Y-m-d H:i:s'),
                'sent_at' => null,
                'source' => 'scheduled',
                'api_key_id' => $sched['api_key_id'] ?? null,
                'error' => null,
                'assigned_device' => null
            ];
        }
    });
    if (!$queueSaved) continue;

    $recurring = $sched['recurring'] ?? 'none';
    if ($recurring === 'none') {
        $scheduled[$key]['status'] = 'sent';
    } elseif ($recurring === 'daily') {
        $scheduled[$key]['schedule_time'] = date('Y-m-d\TH:i', strtotime('+1 day', $schedTimestamp));
    } elseif ($recurring === 'weekly') {
        $scheduled[$key]['schedule_time'] = date('Y-m-d\TH:i', strtotime('+1 week', $schedTimestamp));
    } elseif ($recurring === 'monthly') {
        $scheduled[$key]['schedule_time'] = date('Y-m-d\TH:i', strtotime('+1 month', $schedTimestamp));
    }
    $updated = true;
    logActivity('sms_sent', 'Poll dispatched scheduled SMS: ' . $sched['id'] . ' to ' . count($numbers) . ' number(s)');
}

if ($updated) {
    $db['scheduled_sms'] = $scheduled;
    saveJson('database.json', $db);
}

if ($schedulerLock) {
    flock($schedulerLock, LOCK_UN);
    fclose($schedulerLock);
}

$devices = loadJson('device_tokens.json')['devices'] ?? [];
$onlineIds = [];
foreach ($devices as $d) {
    $lastSeen = strtotime($d['last_seen'] ?? '0');
    if ($lastSeen && (time() - $lastSeen) < 600) {
        $onlineIds[] = $d;
    }
}
usort($onlineIds, function($a, $b) {
    return ($a['sms_sent_count'] ?? 0) <=> ($b['sms_sent_count'] ?? 0);
});
$onlineDeviceIds = array_map(function($d) { return $d['device_id']; }, $onlineIds);

// Read, assign and claim queued messages while holding one exclusive lock.
// This prevents two concurrent poll requests from claiming the same SMS.
$queuePath = $configDir . 'sms_queue.json';
$queueHandle = @fopen($queuePath, 'c+');
if (!$queueHandle || !flock($queueHandle, LOCK_EX)) {
    if (is_resource($queueHandle)) fclose($queueHandle);
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Unable to lock SMS queue']);
    exit;
}

rewind($queueHandle);
$queueContent = stream_get_contents($queueHandle);
$queue = json_decode($queueContent, true);
if (!is_array($queue)) $queue = [];
if (!isset($queue['queue']) || !is_array($queue['queue'])) $queue['queue'] = [];

// Load current API key scopes while the queue lock is held.
$dbForScopes = loadJson('database.json');
$apiKeysForScopes = $dbForScopes['api_keys'] ?? [];
$keyScopes = [];
foreach ($apiKeysForScopes as $scopeKey) {
    if (!empty($scopeKey['id'])) $keyScopes[$scopeKey['id']] = $scopeKey;
}

$assignIdx = 0;
foreach ($queue['queue'] as &$item) {
    if (($item['status'] ?? '') !== 'queued') continue;
    $keyId = $item['api_key_id'] ?? null;
    $keyInfo = ($keyId !== null && isset($keyScopes[$keyId])) ? $keyScopes[$keyId] : null;
    $scope = $keyInfo['device_scope'] ?? 'all';

    if ($scope === 'device' && !empty($keyInfo['device_id'])) {
        // A restricted key must never fall back to another device.
        if (($item['assigned_device'] ?? '') !== $keyInfo['device_id']) {
            $item['assigned_device'] = $keyInfo['device_id'];
        }
    } elseif (empty($item['assigned_device']) && !empty($onlineDeviceIds)) {
        $item['assigned_device'] = $onlineDeviceIds[$assignIdx % count($onlineDeviceIds)];
        $assignIdx++;
    }
}
unset($item);

$commands = [];
if (!empty($deviceId)) {
    foreach ($queue['queue'] as &$item) {
        $status = $item['status'] ?? '';
        $assigned = $item['assigned_device'] ?? '';
        if ($status === 'queued' && !empty($assigned) && $assigned === $deviceId) {
            $commands[] = [
                'id' => $item['id'],
                'to' => $item['to'],
                'message' => $item['message']
            ];
            $item['status'] = 'processing';
            $item['sent_at'] = date('Y-m-d H:i:s');
            $item['assigned_device'] = $deviceId;
        }
    }
    unset($item);
}

// Persist queue state before releasing the lock so another poll cannot claim
// any message already returned in this response.
$encodedQueue = json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($encodedQueue === false) {
    flock($queueHandle, LOCK_UN);
    fclose($queueHandle);
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Unable to encode SMS queue']);
    exit;
}
rewind($queueHandle);
if (!ftruncate($queueHandle, 0) || fwrite($queueHandle, $encodedQueue) === false) {
    flock($queueHandle, LOCK_UN);
    fclose($queueHandle);
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Unable to save SMS queue']);
    exit;
}
fflush($queueHandle);
flock($queueHandle, LOCK_UN);
fclose($queueHandle);

header('Content-Type: application/json');
echo json_encode([
    'ok' => true,
    'commands' => $commands,
    'timestamp' => date('Y-m-d H:i:s')
], JSON_PRETTY_PRINT);