<?php


header('Content-Type: application/json');

$configDir = __DIR__ . '/../../config/';

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
