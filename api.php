<?php
/**
 * SMSKIT unified API controller; PHP 7.4 compatible.
 * Public endpoint URLs remain /api/v1/{endpoint} for Android compatibility.
 */
header('Content-Type: application/json; charset=utf-8');
$route = isset($_GET['route']) ? (string) $_GET['route'] : '';
if (!preg_match('~^api/v1/([A-Za-z0-9_-]+)$~D', $route, $matches)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'API endpoint not found']);
    exit;
}
$endpoint = $matches[1];
if ($endpoint === 'poll') {
$configDir = __DIR__ . '/config/';

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
}

if ($endpoint === 'receive') {
/**
 * TextFlow API - Receive Endpoint (incoming SMS from Android)
 * POST /api/v1/receive
 * Params: device_id, from, message, timestamp
 */

header('Content-Type: application/json');

$configDir = __DIR__ . '/config/';

function loadJson($file) {
    global $configDir;
    $path = $configDir . $file;
    if (!file_exists($path)) return [];
    $content = file_get_contents($path);
    return json_decode($content, true) ?: [];
}

function saveJson($file, $data) {
    global $configDir;
    $path = $configDir . $file;
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed. Use POST.']);
    exit;
}

$deviceId = $_POST['device_id'] ?? '';
$from = $_POST['from'] ?? '';
$message = $_POST['message'] ?? '';
$timestamp = $_POST['timestamp'] ?? date('Y-m-d H:i:s');

if (empty($deviceId)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'device_id is required']);
    exit;
}
if (empty($from)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'from is required']);
    exit;
}
if (empty($message)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'message is required']);
    exit;
}

// Log incoming SMS
$activity = loadJson('activity.json');
if (!isset($activity['logs'])) $activity['logs'] = [];
array_unshift($activity['logs'], [
    'type' => 'sms_received',
    'detail' => 'SMS from ' . $from . ': ' . substr($message, 0, 100),
    'timestamp' => $timestamp,
    'meta' => [
        'from' => $from,
        'message' => substr($message, 0, 100),
        'device_id' => $deviceId
    ]
]);
$activity['logs'] = array_slice($activity['logs'], 0, 5000);
saveJson('activity.json', $activity);

// Update device last seen
$deviceTokens = loadJson('device_tokens.json');
if (isset($deviceTokens['devices'])) {
    foreach ($deviceTokens['devices'] as $idx => $device) {
        if (($device['device_id'] ?? '') === $deviceId) {
            $deviceTokens['devices'][$idx]['last_seen'] = date('Y-m-d H:i:s');
            $deviceTokens['devices'][$idx]['status'] = 'online';
            break;
        }
    }
    saveJson('device_tokens.json', $deviceTokens);
}

echo json_encode(['ok' => true]);

}

if ($endpoint === 'report') {
header('Content-Type: application/json');

$configDir = __DIR__ . '/config/';

function loadJson($file) {
    global $configDir;
    $path = $configDir . $file;
    if (!file_exists($path)) return [];
    $content = file_get_contents($path);
    return json_decode($content, true) ?: [];
}

function saveJson($file, $data) {
    global $configDir;
    $path = $configDir . $file;
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
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

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed. Use POST.']);
    exit;
}

$deviceId = $_POST['device_id'] ?? '';
$messageId = $_POST['message_id'] ?? '';
$status = $_POST['status'] ?? '';
$error = $_POST['error'] ?? null;

if (empty($deviceId)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'device_id is required']);
    exit;
}
if (empty($messageId)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'message_id is required']);
    exit;
}
if (empty($status) || !in_array($status, ['sent', 'failed'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'status must be "sent" or "failed"']);
    exit;
}

// Update the queue item while holding the same lock used by poll.php and send.php.
$queuePath = $configDir . 'sms_queue.json';
$queueHandle = @fopen($queuePath, 'c+');
if (!$queueHandle || !flock($queueHandle, LOCK_EX)) {
    if (is_resource($queueHandle)) fclose($queueHandle);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to lock SMS queue']);
    exit;
}
rewind($queueHandle);
$smsQueue = json_decode(stream_get_contents($queueHandle), true);
if (!is_array($smsQueue)) $smsQueue = [];
$queue = $smsQueue['queue'] ?? [];
$itemFound = false;
$wrongDevice = false;

foreach ($queue as $idx => $item) {
    if (($item['id'] ?? '') !== $messageId) continue;
    if (!empty($item['assigned_device']) && $item['assigned_device'] !== $deviceId) {
        $wrongDevice = true;
        break;
    }
    $queue[$idx]['status'] = $status;
    if ($status === 'sent') $queue[$idx]['sent_at'] = date('Y-m-d H:i:s');
    if ($error !== null && $error !== '') $queue[$idx]['error'] = $error;
    $itemFound = true;
    break;
}

if (!$itemFound) {
    flock($queueHandle, LOCK_UN);
    fclose($queueHandle);
    if ($wrongDevice) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Message is assigned to another device']);
    } else {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Message not found in queue']);
    }
    exit;
}

$smsQueue['queue'] = $queue;
$encodedQueue = json_encode($smsQueue, JSON_PRETTY_PRINT);
if ($encodedQueue === false) {
    flock($queueHandle, LOCK_UN);
    fclose($queueHandle);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to encode SMS queue']);
    exit;
}
rewind($queueHandle);
if (!ftruncate($queueHandle, 0) || fwrite($queueHandle, $encodedQueue) === false) {
    flock($queueHandle, LOCK_UN);
    fclose($queueHandle);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to save SMS queue']);
    exit;
}
fflush($queueHandle);
flock($queueHandle, LOCK_UN);
fclose($queueHandle);

// Log activity
if ($status === 'sent') {
    logActivity('sms_sent', 'SMS delivered: ' . $messageId . ' (device: ' . $deviceId . ')');
} else {
    logActivity('sms_failed', 'SMS failed: ' . $messageId . ' - ' . ($error ?: 'Unknown error') . ' (device: ' . $deviceId . ')');
}

// Update device sent count
$deviceTokens = loadJson('device_tokens.json');
if (isset($deviceTokens['devices'])) {
    foreach ($deviceTokens['devices'] as $idx => $device) {
        if (($device['device_id'] ?? '') === $deviceId) {
            $deviceTokens['devices'][$idx]['last_seen'] = date('Y-m-d H:i:s');
            $deviceTokens['devices'][$idx]['status'] = 'online';
            if ($status === 'sent') {
                $deviceTokens['devices'][$idx]['sms_sent_count'] = ($deviceTokens['devices'][$idx]['sms_sent_count'] ?? 0) + 1;
            }
            break;
        }
    }
    saveJson('device_tokens.json', $deviceTokens);
}

echo json_encode(['ok' => true]);

}

if ($endpoint === 'schedule') {
header('Content-Type: application/json');

$configDir = __DIR__ . '/config/';

function loadJson($file) {
    global $configDir;
    $path = $configDir . $file;
    if (!file_exists($path)) return [];
    return json_decode(file_get_contents($path), true) ?: [];
}

function saveJson($file, $data) {
    global $configDir;
    $path = $configDir . $file;
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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

$token = null;
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (!empty($authHeader) && preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
    $token = $matches[1];
}
if (empty($token)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Authentication required.']);
    exit;
}

$db = loadJson('database.json');
$apiKeys = $db['api_keys'] ?? [];
$tokenHash = hash('sha256', $token);
$keyData = null;
foreach ($apiKeys as $key) {
    if (($key['hash'] ?? '') === $tokenHash) {
        $keyData = $key;
        break;
    }
}
if ($keyData === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Invalid API key.']);
    exit;
}

$permissions = $keyData['permissions'] ?? [];
if (!in_array('schedule', $permissions) && !in_array('send', $permissions)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'API key lacks schedule permission.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed. Use POST.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$numbers = $input['numbers'] ?? [];
$message = trim($input['message'] ?? '');
$scheduleTime = $input['schedule_time'] ?? '';
$recurring = $input['recurring'] ?? 'none';

if (empty($numbers) || !is_array($numbers)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'numbers is required.']);
    exit;
}
if (empty($message)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'message is required.']);
    exit;
}
if (empty($scheduleTime)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'schedule_time is required.']);
    exit;
}

$schedId = 'sched_' . uniqid();
$schedulerLock = @fopen($configDir . 'scheduler.lock', 'c');
if (!$schedulerLock || !flock($schedulerLock, LOCK_EX)) {
    if (is_resource($schedulerLock)) fclose($schedulerLock);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to lock scheduler.']);
    exit;
}

// Read the latest database only after taking the same lock used by poll.php.
$db = loadJson('database.json');
if (!isset($db['scheduled_sms']) || !is_array($db['scheduled_sms'])) $db['scheduled_sms'] = [];
$db['scheduled_sms'][] = [
    'id' => $schedId,
    'numbers' => $numbers,
    'message' => $message,
    'schedule_time' => $scheduleTime,
    'recurring' => $recurring,
    'status' => 'pending',
    'api_key_id' => $keyData['id'] ?? null,
    'created_at' => date('Y-m-d H:i:s')
];
saveJson('database.json', $db);
flock($schedulerLock, LOCK_UN);
fclose($schedulerLock);

echo json_encode([
    'ok' => true,
    'sched_id' => $schedId,
    'numbers_count' => count($numbers),
    'schedule_time' => $scheduleTime
]);
}

if ($endpoint === 'send') {
header('Content-Type: application/json');

$configDir = __DIR__ . '/config/';

function loadJson($file) {
    global $configDir;
    $path = $configDir . $file;
    if (!file_exists($path)) return [];
    $content = file_get_contents($path);
    return json_decode($content, true) ?: [];
}

function saveJson($file, $data) {
    global $configDir;
    $path = $configDir . $file;
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
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

function sendError($code, $message) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

// Extract token from Authorization header or query parameter
$token = null;

// Check Authorization header
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (empty($authHeader)) {
    $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
}

if (!empty($authHeader)) {
    if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
        $token = $matches[1];
    }
}

// Fallback to query parameter
if (empty($token)) {
    $token = $_GET['api_key'] ?? null;
}

if (empty($token)) {
    sendError(401, 'Authentication required. Provide API key via Authorization: Bearer header or ?api_key= parameter.');
}

// Look up key
$db = loadJson('database.json');
$apiKeys = $db['api_keys'] ?? [];
$tokenHash = hash('sha256', $token);

$keyData = null;
foreach ($apiKeys as $key) {
    if (($key['hash'] ?? '') === $tokenHash) {
        $keyData = $key;
        break;
    }
}

if ($keyData === null) {
    sendError(401, 'Invalid API key.');
}

// Rate limit check
$rateLimit = $keyData['rate_limit'] ?? 100;
$activity = loadJson('activity.json');
$logs = $activity['logs'] ?? [];
$windowStart = time() - 3600;
$callCount = 0;

foreach ($logs as $log) {
    if (($log['type'] ?? '') === 'api_call' && ($log['api_key_id'] ?? '') === ($keyData['id'] ?? '')) {
        $ts = strtotime($log['timestamp'] ?? '0');
        if ($ts && $ts > $windowStart) {
            $callCount++;
        }
    }
}

if ($callCount >= $rateLimit) {
    sendError(429, 'Rate limit exceeded. Maximum ' . $rateLimit . ' requests per hour.');
}

// Update usage
foreach ($apiKeys as $idx => $key) {
    if (($key['id'] ?? '') === ($keyData['id'] ?? '')) {
        $apiKeys[$idx]['last_used'] = date('Y-m-d H:i:s');
        $apiKeys[$idx]['usage_count'] = ($apiKeys[$idx]['usage_count'] ?? 0) + 1;
        break;
    }
}
$db['api_keys'] = $apiKeys;
saveJson('database.json', $db);

// Log API call
$activity['logs'] = $logs;
if (!isset($activity['logs'])) $activity['logs'] = [];
array_unshift($activity['logs'], [
    'type' => 'api_call',
    'api_key_id' => $keyData['id'] ?? '',
    'detail' => $_SERVER['REQUEST_METHOD'] . ' ' . ($_SERVER['REQUEST_URI'] ?? ''),
    'timestamp' => date('Y-m-d H:i:s')
]);
$activity['logs'] = array_slice($activity['logs'], 0, 5000);
saveJson('activity.json', $activity);

// Make key data available to the including endpoint
$__keyData = $keyData;



if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError(405, 'Method not allowed. Use POST.');
}


$input = json_decode(file_get_contents('php://input'), true);

if ($input === null && json_last_error() !== JSON_ERROR_NONE) {
   
    $input = $_POST;
}

$numbers = $input['numbers'] ?? [];
$message = trim($input['message'] ?? '');

if (empty($numbers) || !is_array($numbers)) {
    sendError(400, 'numbers is required and must be a non-empty array.');
}


foreach ($numbers as $number) {
    $number = trim($number);
    if (empty($number)) {
        sendError(400, 'Empty phone number found in numbers array.');
    }
    if (strpos($number, '+') !== 0) {
        sendError(400, 'Phone number must start with +: ' . $number);
    }
    $digitsOnly = preg_replace('/[^0-9]/', '', $number);
    $digitCount = strlen($digitsOnly);
    if ($digitCount < 7 || $digitCount > 15) {
        sendError(400, 'Phone number must have 7-15 digits after the +: ' . $number);
    }
}

if (empty($message)) {
    sendError(400, 'message is required and cannot be empty.');
}
if (strlen($message) > 1600) {
    sendError(400, 'Message exceeds maximum length of 1600 characters.');
}

$msgId = 'msg_' . uniqid();
$queuePath = $configDir . 'sms_queue.json';
$queueHandle = @fopen($queuePath, 'c+');
if (!$queueHandle || !flock($queueHandle, LOCK_EX)) {
    if (is_resource($queueHandle)) fclose($queueHandle);
    sendError(500, 'Unable to lock SMS queue.');
}
rewind($queueHandle);
$queue = json_decode(stream_get_contents($queueHandle), true);
if (!is_array($queue)) $queue = [];
if (!isset($queue['queue']) || !is_array($queue['queue'])) $queue['queue'] = [];

$sentCount = 0;
foreach ($numbers as $number) {
    $number = trim($number);
    $queue['queue'][] = [
        'id' => $msgId . '_' . mt_rand(1000, 9999),
        'message_id' => $msgId,
        'to' => $number,
        'message' => $message,
        'status' => 'queued',
        'created_at' => date('Y-m-d H:i:s'),
        'sent_at' => null,
        'source' => 'api',
        'api_key_id' => $__keyData['id'] ?? null,
        'error' => null
    ];
    $sentCount++;
}

$encodedQueue = json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($encodedQueue === false) {
    flock($queueHandle, LOCK_UN);
    fclose($queueHandle);
    sendError(500, 'Unable to encode SMS queue.');
}
rewind($queueHandle);
if (!ftruncate($queueHandle, 0) || fwrite($queueHandle, $encodedQueue) === false) {
    flock($queueHandle, LOCK_UN);
    fclose($queueHandle);
    sendError(500, 'Unable to save SMS queue.');
}
fflush($queueHandle);
flock($queueHandle, LOCK_UN);
fclose($queueHandle);

$numberList = implode(', ', $numbers);
logActivity('api_sms_sent', 'API sent SMS to ' . $sentCount . ' number(s): ' . $numberList);

echo json_encode([
    'ok' => true,
    'message_id' => $msgId,
    'sent_count' => $sentCount,
    'queued' => true
]);

}

if ($endpoint === 'statistics') {
header('Content-Type: application/json');

$configDir = __DIR__ . '/config/';

function loadJson($file) {
    global $configDir;
    $path = $configDir . $file;
    if (!file_exists($path)) return [];
    $content = file_get_contents($path);
    return json_decode($content, true) ?: [];
}

function saveJson($file, $data) {
    global $configDir;
    $path = $configDir . $file;
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
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

function sendError($code, $message) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

// Extract token from Authorization header or query parameter
$token = null;

// Check Authorization header
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (empty($authHeader)) {
    $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
}

if (!empty($authHeader)) {
    if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
        $token = $matches[1];
    }
}

// Fallback to query parameter
if (empty($token)) {
    $token = $_GET['api_key'] ?? null;
}

if (empty($token)) {
    sendError(401, 'Authentication required. Provide API key via Authorization: Bearer header or ?api_key= parameter.');
}

// Look up key
$db = loadJson('database.json');
$apiKeys = $db['api_keys'] ?? [];
$tokenHash = hash('sha256', $token);

$keyData = null;
foreach ($apiKeys as $key) {
    if (($key['hash'] ?? '') === $tokenHash) {
        $keyData = $key;
        break;
    }
}

if ($keyData === null) {
    sendError(401, 'Invalid API key.');
}

// Rate limit check
$rateLimit = $keyData['rate_limit'] ?? 100;
$activity = loadJson('activity.json');
$logs = $activity['logs'] ?? [];
$windowStart = time() - 3600;
$callCount = 0;

foreach ($logs as $log) {
    if (($log['type'] ?? '') === 'api_call' && ($log['api_key_id'] ?? '') === ($keyData['id'] ?? '')) {
        $ts = strtotime($log['timestamp'] ?? '0');
        if ($ts && $ts > $windowStart) {
            $callCount++;
        }
    }
}

if ($callCount >= $rateLimit) {
    sendError(429, 'Rate limit exceeded. Maximum ' . $rateLimit . ' requests per hour.');
}

// Update usage
foreach ($apiKeys as $idx => $key) {
    if (($key['id'] ?? '') === ($keyData['id'] ?? '')) {
        $apiKeys[$idx]['last_used'] = date('Y-m-d H:i:s');
        $apiKeys[$idx]['usage_count'] = ($apiKeys[$idx]['usage_count'] ?? 0) + 1;
        break;
    }
}
$db['api_keys'] = $apiKeys;
saveJson('database.json', $db);

// Log API call
$activity['logs'] = $logs;
if (!isset($activity['logs'])) $activity['logs'] = [];
array_unshift($activity['logs'], [
    'type' => 'api_call',
    'api_key_id' => $keyData['id'] ?? '',
    'detail' => $_SERVER['REQUEST_METHOD'] . ' ' . ($_SERVER['REQUEST_URI'] ?? ''),
    'timestamp' => date('Y-m-d H:i:s')
]);
$activity['logs'] = array_slice($activity['logs'], 0, 5000);
saveJson('activity.json', $activity);

// Make key data available to the including endpoint
$__keyData = $keyData;


if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError(405, 'Method not allowed. Use GET.');
}

$activity = loadJson('activity.json');
$logs = $activity['logs'] ?? [];
$now = time();

$totalSent = 0;
$todaySent = 0;
$weekSent = 0;
$monthSent = 0;

foreach ($logs as $log) {
    if (($log['type'] ?? '') === 'sms_sent') {
        $totalSent++;
        $ts = strtotime($log['timestamp'] ?? '0');
        if ($ts === false) continue;
        $daysDiff = floor(($now - $ts) / 86400);
        if ($daysDiff < 1) $todaySent++;
        if ($daysDiff < 7) $weekSent++;
        if ($daysDiff < 30) $monthSent++;
    }
}

$db = loadJson('database.json');
$apiKeysCount = count($db['api_keys'] ?? []);

$deviceTokens = loadJson('device_tokens.json');
$devices = $deviceTokens['devices'] ?? [];
$onlineCount = 0;
foreach ($devices as $device) {
    $seen = strtotime($device['last_seen'] ?? '0');
    if ($seen && (time() - $seen) < 300) {
        $onlineCount++;
    }
}

echo json_encode([
    'ok' => true,
    'total_sent' => $totalSent,
    'today_sent' => $todaySent,
    'week_sent' => $weekSent,
    'month_sent' => $monthSent,
    'api_keys_count' => $apiKeysCount,
    'devices_online_count' => $onlineCount
]);

}

if ($endpoint === 'status') {
header('Content-Type: application/json');

$configDir = __DIR__ . '/config/';

function loadJson($file) {
    global $configDir;
    $path = $configDir . $file;
    if (!file_exists($path)) return [];
    $content = file_get_contents($path);
    return json_decode($content, true) ?: [];
}

function saveJson($file, $data) {
    global $configDir;
    $path = $configDir . $file;
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
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

function sendError($code, $message) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

// Extract token from Authorization header or query parameter
$token = null;

// Check Authorization header
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (empty($authHeader)) {
    $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
}

if (!empty($authHeader)) {
    if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
        $token = $matches[1];
    }
}

// Fallback to query parameter
if (empty($token)) {
    $token = $_GET['api_key'] ?? null;
}

if (empty($token)) {
    sendError(401, 'Authentication required. Provide API key via Authorization: Bearer header or ?api_key= parameter.');
}

// Look up key
$db = loadJson('database.json');
$apiKeys = $db['api_keys'] ?? [];
$tokenHash = hash('sha256', $token);

$keyData = null;
foreach ($apiKeys as $key) {
    if (($key['hash'] ?? '') === $tokenHash) {
        $keyData = $key;
        break;
    }
}

if ($keyData === null) {
    sendError(401, 'Invalid API key.');
}

// Rate limit check
$rateLimit = $keyData['rate_limit'] ?? 100;
$activity = loadJson('activity.json');
$logs = $activity['logs'] ?? [];
$windowStart = time() - 3600;
$callCount = 0;

foreach ($logs as $log) {
    if (($log['type'] ?? '') === 'api_call' && ($log['api_key_id'] ?? '') === ($keyData['id'] ?? '')) {
        $ts = strtotime($log['timestamp'] ?? '0');
        if ($ts && $ts > $windowStart) {
            $callCount++;
        }
    }
}

if ($callCount >= $rateLimit) {
    sendError(429, 'Rate limit exceeded. Maximum ' . $rateLimit . ' requests per hour.');
}

// Update usage
foreach ($apiKeys as $idx => $key) {
    if (($key['id'] ?? '') === ($keyData['id'] ?? '')) {
        $apiKeys[$idx]['last_used'] = date('Y-m-d H:i:s');
        $apiKeys[$idx]['usage_count'] = ($apiKeys[$idx]['usage_count'] ?? 0) + 1;
        break;
    }
}
$db['api_keys'] = $apiKeys;
saveJson('database.json', $db);

// Log API call
$activity['logs'] = $logs;
if (!isset($activity['logs'])) $activity['logs'] = [];
array_unshift($activity['logs'], [
    'type' => 'api_call',
    'api_key_id' => $keyData['id'] ?? '',
    'detail' => $_SERVER['REQUEST_METHOD'] . ' ' . ($_SERVER['REQUEST_URI'] ?? ''),
    'timestamp' => date('Y-m-d H:i:s')
]);
$activity['logs'] = array_slice($activity['logs'], 0, 5000);
saveJson('activity.json', $activity);

// Make key data available to the including endpoint
$__keyData = $keyData;


if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError(405, 'Method not allowed. Use GET.');
}

$messageId = $_GET['id'] ?? '';

if (empty($messageId)) {
    sendError(400, 'id parameter is required');
}

$smsQueue = loadJson('sms_queue.json');
$queue = $smsQueue['queue'] ?? [];

$items = [];
$sentCount = 0;
$failedCount = 0;
$createdAt = null;
$worstStatus = 'queued';

foreach ($queue as $item) {
    if (($item['message_id'] ?? '') === $messageId) {
        $items[] = $item;
        $status = $item['status'] ?? 'queued';
        if ($createdAt === null && isset($item['created_at'])) {
            $createdAt = $item['created_at'];
        }
        if ($status === 'sent') {
            $sentCount++;
        } elseif ($status === 'failed') {
            $failedCount++;
            $worstStatus = 'failed';
        } elseif ($status === 'queued' && $worstStatus !== 'failed') {
            $worstStatus = 'queued';
        }
    }
}

if (empty($items)) {
    sendError(404, 'Message not found');
}


$totalItems = count($items);
if ($sentCount === $totalItems) {
    $overallStatus = 'sent';
} elseif ($failedCount === $totalItems) {
    $overallStatus = 'failed';
} elseif ($sentCount > 0 || $failedCount > 0) {
    $overallStatus = 'partial';
} else {
    $overallStatus = 'queued';
}

echo json_encode([
    'ok' => true,
    'message_id' => $messageId,
    'status' => $overallStatus,
    'total_count' => $totalItems,
    'sent_count' => $sentCount,
    'failed_count' => $failedCount,
    'queued_count' => $totalItems - $sentCount - $failedCount,
    'created_at' => $createdAt,
    'items' => $items
]);

}

if ($endpoint === 'validate') {
header('Content-Type: application/json');

$configDir = __DIR__ . '/config/';

function loadJson($file) {
    global $configDir;
    $path = $configDir . $file;
    if (!file_exists($path)) return [];
    $content = file_get_contents($path);
    return json_decode($content, true) ?: [];
}

function saveJson($file, $data) {
    global $configDir;
    $path = $configDir . $file;
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
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

function sendError($code, $message) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

// Extract token from Authorization header or query parameter
$token = null;

// Check Authorization header
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (empty($authHeader)) {
    $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
}

if (!empty($authHeader)) {
    if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
        $token = $matches[1];
    }
}

// Fallback to query parameter
if (empty($token)) {
    $token = $_GET['api_key'] ?? null;
}

if (empty($token)) {
    sendError(401, 'Authentication required. Provide API key via Authorization: Bearer header or ?api_key= parameter.');
}

// Look up key
$db = loadJson('database.json');
$apiKeys = $db['api_keys'] ?? [];
$tokenHash = hash('sha256', $token);

$keyData = null;
foreach ($apiKeys as $key) {
    if (($key['hash'] ?? '') === $tokenHash) {
        $keyData = $key;
        break;
    }
}

if ($keyData === null) {
    sendError(401, 'Invalid API key.');
}

// Rate limit check
$rateLimit = $keyData['rate_limit'] ?? 100;
$activity = loadJson('activity.json');
$logs = $activity['logs'] ?? [];
$windowStart = time() - 3600;
$callCount = 0;

foreach ($logs as $log) {
    if (($log['type'] ?? '') === 'api_call' && ($log['api_key_id'] ?? '') === ($keyData['id'] ?? '')) {
        $ts = strtotime($log['timestamp'] ?? '0');
        if ($ts && $ts > $windowStart) {
            $callCount++;
        }
    }
}

if ($callCount >= $rateLimit) {
    sendError(429, 'Rate limit exceeded. Maximum ' . $rateLimit . ' requests per hour.');
}

// Update usage
foreach ($apiKeys as $idx => $key) {
    if (($key['id'] ?? '') === ($keyData['id'] ?? '')) {
        $apiKeys[$idx]['last_used'] = date('Y-m-d H:i:s');
        $apiKeys[$idx]['usage_count'] = ($apiKeys[$idx]['usage_count'] ?? 0) + 1;
        break;
    }
}
$db['api_keys'] = $apiKeys;
saveJson('database.json', $db);

// Log API call
$activity['logs'] = $logs;
if (!isset($activity['logs'])) $activity['logs'] = [];
array_unshift($activity['logs'], [
    'type' => 'api_call',
    'api_key_id' => $keyData['id'] ?? '',
    'detail' => $_SERVER['REQUEST_METHOD'] . ' ' . ($_SERVER['REQUEST_URI'] ?? ''),
    'timestamp' => date('Y-m-d H:i:s')
]);
$activity['logs'] = array_slice($activity['logs'], 0, 5000);
saveJson('activity.json', $activity);

// Make key data available to the including endpoint
$__keyData = $keyData;


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError(405, 'Method not allowed. Use POST.');
}

$input = json_decode(file_get_contents('php://input'), true);

if ($input === null && json_last_error() !== JSON_ERROR_NONE) {
    $input = $_POST;
}

$number = trim($input['number'] ?? '');

if (empty($number)) {
    sendError(400, 'number is required');
}


if (!strpos($number, '+') !== 0) {
    echo json_encode([
        'ok' => true,
        'valid' => false,
        'number' => $number,
        'country' => null,
        'country_code' => null,
        'reason' => 'Number must start with +'
    ]);
    exit;
}


$digitsOnly = preg_replace('/[^0-9]/', '', $number);
$digitCount = strlen($digitsOnly);

if ($digitCount < 8 || $digitCount > 16) {
    echo json_encode([
        'ok' => true,
        'valid' => false,
        'number' => $number,
        'country' => null,
        'country_code' => null,
        'reason' => 'Number must have 8-16 digits after the +' . $digitCount
    ]);
    exit;
}


$countryMap = [
    '+966' => ['country' => 'Saudi Arabia', 'code' => '+966'],
    '+971' => ['country' => 'UAE', 'code' => '+971'],
    '+880' => ['country' => 'Bangladesh', 'code' => '+880'],
    '+234' => ['country' => 'Nigeria', 'code' => '+234'],
    '+27'  => ['country' => 'South Africa', 'code' => '+27'],
    '+34'  => ['country' => 'Spain', 'code' => '+34'],
    '+39'  => ['country' => 'Italy', 'code' => '+39'],
    '+44'  => ['country' => 'UK', 'code' => '+44'],
    '+49'  => ['country' => 'Germany', 'code' => '+49'],
    '+55'  => ['country' => 'Brazil', 'code' => '+55'],
    '+61'  => ['country' => 'Australia', 'code' => '+61'],
    '+62'  => ['country' => 'Indonesia', 'code' => '+62'],
    '+7'   => ['country' => 'Russia', 'code' => '+7'],
    '+81'  => ['country' => 'Japan', 'code' => '+81'],
    '+82'  => ['country' => 'South Korea', 'code' => '+82'],
    '+86'  => ['country' => 'China', 'code' => '+86'],
    '+91'  => ['country' => 'India', 'code' => '+91'],
    '+1'   => ['country' => 'USA/Canada', 'code' => '+1'],
    '+20'  => ['country' => 'Egypt', 'code' => '+20'],
    '+33'  => ['country' => 'France', 'code' => '+33'],
];

$detectedCountry = null;
$detectedCode = null;


$prefixes = array_keys($countryMap);
usort($prefixes, function($a, $b) {
    return strlen($b) - strlen($a);
});

foreach ($prefixes as $prefix) {
    if (strpos($number, $prefix) === 0) {
        $detectedCountry = $countryMap[$prefix]['country'];
        $detectedCode = $countryMap[$prefix]['code'];
        break;
    }
}

$afterPlus = substr($number, 1);
if (!ctype_digit($afterPlus)) {
    echo json_encode([
        'ok' => true,
        'valid' => false,
        'number' => $number,
        'country' => $detectedCountry,
        'country_code' => $detectedCode,
        'reason' => 'Number contains invalid characters after the +' . $afterPlus
    ]);
    exit;
}

echo json_encode([
    'ok' => true,
    'valid' => true,
    'number' => $number,
    'country' => $detectedCountry,
    'country_code' => $detectedCode
]);

}

http_response_code(404);
echo json_encode(['ok' => false, 'error' => 'API endpoint not found']);
