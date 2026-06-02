<?php
$db = getDB();

if ($resource === 'notifications') {

    // GET /api/notifications  (own notifications)
    if ($method === 'GET' && $id === null && $action === null) {
        $auth = requireAuth();
        try {
            $stmt = $db->prepare("
                SELECT * FROM notifications
                WHERE user_id = ?
                ORDER BY created_at DESC
                LIMIT 50
            ");
            $stmt->execute([$auth['id']]);
            $notifications = $stmt->fetchAll();

            $unreadCount = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL");
            $unreadCount->execute([$auth['id']]);

            jsonResponse([
                'items'        => $notifications,
                'unread_count' => (int)$unreadCount->fetchColumn(),
            ]);
        } catch (PDOException $e) {
            logDatabaseError('notifications/get', $e);
            jsonError('Error al obtener notificaciones', 500);
        }
    }

    // PUT /api/notifications/read-all  (mark all as read)
    if ($method === 'PUT' && $action === 'read-all') {
        $auth = requireAuth();
        try {
            $db->prepare("UPDATE notifications SET read_at=NOW() WHERE user_id=? AND read_at IS NULL")
               ->execute([$auth['id']]);
            jsonResponse(['message' => 'Notificaciones marcadas como leídas']);
        } catch (PDOException $e) {
            logDatabaseError('notifications/read-all', $e);
            jsonError('Error al marcar notificaciones', 500);
        }
    }

    // PUT /api/notifications/{id}/read  (mark one as read)
    if ($method === 'PUT' && $id !== null && $action === 'read') {
        $auth = requireAuth();
        try {
            $db->prepare("UPDATE notifications SET read_at=NOW() WHERE id=? AND user_id=?")
               ->execute([$id, $auth['id']]);
            jsonResponse(['message' => 'Notificación marcada como leída']);
        } catch (PDOException $e) {
            logDatabaseError('notifications/read', $e);
            jsonError('Error al marcar notificación', 500);
        }
    }

    jsonError('Ruta notifications no encontrada', 404);
}
