<?php
/**
 * SMSKIT automatic retention cleanup (PHP 7.4 compatible).
 *
 * - Activity logs older than 30 days are removed.
 * - Completed queue records (sent/failed) older than 30 days are removed.
 * - Queued and other non-completed messages are preserved.
 *
 * Runs when the dashboard or API is accessed; no cron job is required.
 */
function smskitCleanupExpiredRecords()
{
    $configDir = __DIR__ . '/config/';
    if (!is_dir($configDir)) {
        return;
    }

    // Use the gateway's configured timezone so timestamps are interpreted consistently.
    $settingsPath = $configDir . 'settings.json';
    if (is_file($settingsPath)) {
        $settings = json_decode((string) @file_get_contents($settingsPath), true);
        if (is_array($settings) && !empty($settings['timezone'])) {
            $timezone = stripslashes((string) $settings['timezone']);
            if (in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
                date_default_timezone_set($timezone);
            }
        }
    }

    // API devices poll frequently; run cleanup at most once per hour.
    $markerPath = $configDir . '.retention_cleanup';
    $marker = @fopen($markerPath, 'c+');
    if (!$marker || !flock($marker, LOCK_EX)) {
        if (is_resource($marker)) {
            fclose($marker);
        }
        return;
    }
    rewind($marker);
    $lastRun = (int) trim((string) stream_get_contents($marker));
    if ($lastRun > 0 && (time() - $lastRun) < 3600) {
        flock($marker, LOCK_UN);
        fclose($marker);
        return;
    }
    rewind($marker);
    ftruncate($marker, 0);
    fwrite($marker, (string) time());
    fflush($marker);
    flock($marker, LOCK_UN);
    fclose($marker);

    $cutoff = time() - (30 * 86400);

    $pruneJsonFile = function ($filename, $pruner) use ($configDir) {
        $path = $configDir . $filename;
        if (!is_file($path)) {
            return;
        }

        $handle = @fopen($path, 'c+');
        if (!$handle || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            return;
        }

        rewind($handle);
        $raw = stream_get_contents($handle);
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            flock($handle, LOCK_UN);
            fclose($handle);
            return;
        }

        $changed = $pruner($data);
        if ($changed) {
            $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($encoded !== false) {
                rewind($handle);
                if (ftruncate($handle, 0) && fwrite($handle, $encoded) !== false) {
                    fflush($handle);
                }
            }
        }

        flock($handle, LOCK_UN);
        fclose($handle);
    };

    $pruneJsonFile('activity.json', function (&$data) use ($cutoff) {
        if (!isset($data['logs']) || !is_array($data['logs'])) {
            return false;
        }

        $before = count($data['logs']);
        $data['logs'] = array_values(array_filter($data['logs'], function ($log) use ($cutoff) {
            $timestamp = isset($log['timestamp']) ? strtotime((string) $log['timestamp']) : false;
            // Keep records with missing/unreadable timestamps rather than risk deleting them.
            return $timestamp === false || $timestamp >= $cutoff;
        }));

        return count($data['logs']) !== $before;
    });

    $pruneJsonFile('sms_queue.json', function (&$data) use ($cutoff) {
        if (!isset($data['queue']) || !is_array($data['queue'])) {
            return false;
        }

        $before = count($data['queue']);
        $data['queue'] = array_values(array_filter($data['queue'], function ($message) use ($cutoff) {
            $status = strtolower((string) ($message['status'] ?? ''));
            if (!in_array($status, ['sent', 'failed'], true)) {
                return true;
            }

            $dateValue = $status === 'sent'
                ? ($message['sent_at'] ?? $message['created_at'] ?? '')
                : ($message['failed_at'] ?? $message['updated_at'] ?? $message['created_at'] ?? '');
            $timestamp = $dateValue !== '' ? strtotime((string) $dateValue) : false;

            // Keep records with missing/unreadable timestamps rather than risk deleting them.
            return $timestamp === false || $timestamp >= $cutoff;
        }));

        return count($data['queue']) !== $before;
    });
}

smskitCleanupExpiredRecords();
