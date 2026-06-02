<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/middleware/auth.php';

ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', false);
while (ob_get_level() > 0) ob_end_clean();

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Connection: keep-alive');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('X-Accel-Buffering: no');

date_default_timezone_set('America/Bogota');

$token = $_GET['token'] ?? null;
$user  = $token ? jwtDecode($token) : getAuthUser();

if (!$user) {
    echo "event: error\ndata: {\"error\":\"unauthorized\"}\n\n";
    flush();
    exit;
}

$db = getDB();

function sseFlush(string $event, array $data): void {
    echo "event: {$event}\n";
    echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level() > 0) ob_flush();
    flush();
}

function getStudentStats(PDO $db, int $userId): array {
    $stmt = $db->prepare("
        SELECT s.id as student_id, s.required_hours, s.status,
               COALESCE(SUM(sh.hours), 0) as completed_hours
        FROM students s
        LEFT JOIN service_hours sh ON sh.student_id = s.id
        WHERE s.user_id = ?
        GROUP BY s.id
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) return [];
    $row['remaining_hours'] = max(0, $row['required_hours'] - $row['completed_hours']);
    $row['completion_pct']  = $row['required_hours'] > 0
        ? round(($row['completed_hours'] / $row['required_hours']) * 100, 1) : 0;
    return $row;
}

function getAdminStats(PDO $db): array {
    $total     = (int)$db->query("SELECT COUNT(*) FROM students")->fetchColumn();
    $active    = (int)$db->query("SELECT COUNT(*) FROM students WHERE status='active'")->fetchColumn();
    $completed = (int)$db->query("SELECT COUNT(*) FROM students WHERE status='completed'")->fetchColumn();
    $pending   = (int)$db->query("SELECT COUNT(*) FROM certificate_requests WHERE status='pending'")->fetchColumn();
    $hours     = (float)$db->query("SELECT COALESCE(SUM(hours),0) FROM service_hours")->fetchColumn();
    return [
        'total_students'       => $total,
        'active_students'      => $active,
        'completed_students'   => $completed,
        'pending_certificates' => $pending,
        'total_hours'          => $hours,
    ];
}

function getUnreadCount(PDO $db, int $userId): int {
    $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL");
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

$isAdmin = $user['role'] === 'admin';
$userId  = (int)$user['id'];

$lastEventId = (int)($db->query("SELECT COALESCE(MAX(id),0) FROM events")->fetchColumn());

set_time_limit(45);
$start        = time();
$maxTime      = 40;
$pollInterval = 5;

sseFlush('connected', ['status' => 'ok', 'role' => $user['role'], 'user_id' => $userId]);

if ($isAdmin) {
    sseFlush('stats_updated', getAdminStats($db));
} else {
    sseFlush('student_stats', getStudentStats($db, $userId));
    sseFlush('unread_notifications', ['count' => getUnreadCount($db, $userId)]);
}

while ((time() - $start) < $maxTime) {
    if (connection_aborted()) break;
    sleep($pollInterval);
    if (connection_aborted()) break;

    try {
        $stmt = $db->prepare("SELECT id, type, payload FROM events WHERE id > ? ORDER BY id ASC LIMIT 20");
        $stmt->execute([$lastEventId]);
        $newEvents = $stmt->fetchAll();

        if ($newEvents) {
            $lastEventId = (int)end($newEvents)['id'];
            $types       = array_unique(array_column($newEvents, 'type'));

            $needsStats  = (bool)array_intersect(['student_completed', 'student_created', 'student_updated',
                                                   'hours_added', 'hours_updated', 'hours_deleted',
                                                   'certificate_requested', 'certificate_uploaded',
                                                   'certificate_request_updated'], $types);

            if ($isAdmin && $needsStats) {
                sseFlush('stats_updated', getAdminStats($db));
            }

            if (!$isAdmin) {
                $myEvents = array_filter($newEvents, function ($e) use ($userId) {
                    $payload = json_decode($e['payload'] ?? '{}', true);
                    return ($payload['user_id'] ?? null) === $userId;
                });
                if ($myEvents || $needsStats) {
                    sseFlush('student_stats', getStudentStats($db, $userId));
                    sseFlush('unread_notifications', ['count' => getUnreadCount($db, $userId)]);
                }
            }
        }

        echo ": heartbeat\n\n";
        if (ob_get_level() > 0) ob_flush();
        flush();

    } catch (Exception $e) {
        break;
    }
}

sseFlush('reconnect', ['message' => 'session_end']);
