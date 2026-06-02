<?php
function emitEvent(PDO $db, string $type, array $payload = []): void {
    try {
        $db->prepare("INSERT INTO events (type, payload) VALUES (?, ?)")
           ->execute([$type, json_encode($payload)]);
    } catch (PDOException $e) {}
}

function jsonResponse($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError(string $message, int $code = 400): void {
    jsonResponse(['error' => $message], $code);
}

function generateToken(int $length = 32): string {
    return bin2hex(random_bytes($length));
}

function sanitizeString(string $input): string {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

function createNotification(PDO $db, int $userId, string $type, string $title, string $message): void {
    try {
        $db->prepare("INSERT INTO notifications (user_id, type, title, message) VALUES (?,?,?,?)")
           ->execute([$userId, $type, $title, $message]);
    } catch (PDOException $e) {}
}

function getStudentTotalHours(PDO $db, int $studentId): float {
    $stmt = $db->prepare("SELECT COALESCE(SUM(hours), 0) FROM service_hours WHERE student_id = ?");
    $stmt->execute([$studentId]);
    return (float)$stmt->fetchColumn();
}

function auditLog(PDO $db, ?int $userId, string $action, string $entity, ?int $entityId = null, $oldData = null, $newData = null): void {
    try {
        $db->prepare("
            INSERT INTO audit_logs (user_id, action, entity, entity_id, old_data, new_data, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $userId, $action, $entity, $entityId,
            $oldData !== null ? json_encode($oldData) : null,
            $newData !== null ? json_encode($newData) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (PDOException $e) {}
}

function checkStudentCompletion(PDO $db, int $studentId): void {
    $stmt = $db->prepare("SELECT required_hours, status, user_id FROM students WHERE id = ?");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch();
    if (!$student || $student['status'] === 'completed') return;

    $totalHours = getStudentTotalHours($db, $studentId);
    if ($totalHours >= $student['required_hours']) {
        $db->prepare("UPDATE students SET status='completed', end_date=CURDATE() WHERE id=?")
           ->execute([$studentId]);
        createNotification(
            $db,
            $student['user_id'],
            'completion',
            '¡Servicio Social Completado!',
            "¡Felicitaciones! Has completado {$totalHours} horas de servicio social. Ya puedes solicitar tu certificado."
        );
        emitEvent($db, 'student_completed', ['student_id' => $studentId, 'user_id' => $student['user_id']]);
    }
}
