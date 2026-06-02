<?php
$db = getDB();

if ($resource === 'hours') {

    // GET /api/hours?student_id=X  (admin: all; student: own)
    if ($method === 'GET' && $id === null) {
        $auth = requireAuth();
        try {
            $where  = "WHERE 1=1";
            $params = [];

            if ($auth['role'] === 'student') {
                // Force filter to own student profile
                $stmt = $db->prepare("SELECT id FROM students WHERE user_id = ?");
                $stmt->execute([$auth['id']]);
                $studentRow = $stmt->fetch();
                if (!$studentRow) jsonError('Perfil de estudiante no encontrado', 404);
                $where .= " AND sh.student_id = ?";
                $params[] = $studentRow['id'];
            } elseif (!empty($_GET['student_id'])) {
                $where .= " AND sh.student_id = ?";
                $params[] = (int)$_GET['student_id'];
            }

            if (!empty($_GET['from'])) { $where .= " AND sh.service_date >= ?"; $params[] = $_GET['from']; }
            if (!empty($_GET['to']))   { $where .= " AND sh.service_date <= ?"; $params[] = $_GET['to'];   }

            $stmt = $db->prepare("
                SELECT sh.id, sh.student_id, sh.hours, sh.service_date, sh.observation,
                       sh.registered_by, sh.created_at, sh.updated_at,
                       u.full_name as admin_name,
                       s.enrollment_code, su.full_name as student_name
                FROM service_hours sh
                JOIN users u ON u.id = sh.registered_by
                JOIN students s ON s.id = sh.student_id
                JOIN users su ON su.id = s.user_id
                $where
                ORDER BY sh.service_date DESC, sh.id DESC
            ");
            $stmt->execute($params);
            jsonResponse($stmt->fetchAll());
        } catch (PDOException $e) {
            logDatabaseError('hours/get_all', $e);
            jsonError('Error al obtener horas', 500);
        }
    }

    // POST /api/hours  (admin only)
    if ($method === 'POST') {
        $auth = requireAdmin();
        $required = ['student_id', 'hours', 'service_date'];
        foreach ($required as $f) {
            if (empty($body[$f])) jsonError("El campo $f es requerido");
        }

        $hours = (float)$body['hours'];
        if ($hours <= 0 || $hours > 24) jsonError('Las horas deben estar entre 0.5 y 24');

        try {
            $stmt = $db->prepare("SELECT s.id, u.id as user_id, u.full_name FROM students s JOIN users u ON u.id=s.user_id WHERE s.id=?");
            $stmt->execute([(int)$body['student_id']]);
            $student = $stmt->fetch();
            if (!$student) jsonError('Estudiante no encontrado', 404);

            $db->prepare("
                INSERT INTO service_hours (student_id, hours, service_date, observation, registered_by)
                VALUES (?, ?, ?, ?, ?)
            ")->execute([
                $student['id'],
                $hours,
                $body['service_date'],
                sanitizeString($body['observation'] ?? ''),
                $auth['id'],
            ]);
            $newId = (int)$db->lastInsertId();

            // Notify student
            createNotification(
                $db,
                $student['user_id'],
                'hours_added',
                'Nuevas horas registradas',
                "Se han registrado {$hours} hora(s) de servicio correspondientes al " . date('d/m/Y', strtotime($body['service_date'])) . '.'
            );

            // Check if student has completed total hours
            checkStudentCompletion($db, $student['id']);

            auditLog($db, $auth['id'], 'create', 'service_hours', $newId, null, $body);
            emitEvent($db, 'hours_added', ['student_id' => $student['id'], 'user_id' => $student['user_id'], 'hours' => $hours]);

            $stmt = $db->prepare("
                SELECT sh.*, u.full_name as admin_name
                FROM service_hours sh
                JOIN users u ON u.id = sh.registered_by
                WHERE sh.id = ?
            ");
            $stmt->execute([$newId]);
            jsonResponse($stmt->fetch(), 201);
        } catch (PDOException $e) {
            logDatabaseError('hours/create', $e);
            jsonError('Error al registrar horas', 500);
        }
    }

    // PUT /api/hours/{id}  (admin only)
    if ($method === 'PUT' && $id !== null) {
        $auth = requireAdmin();
        try {
            $stmt = $db->prepare("SELECT * FROM service_hours WHERE id=?");
            $stmt->execute([$id]);
            $old = $stmt->fetch();
            if (!$old) jsonError('Registro no encontrado', 404);

            $fields = []; $params = [];
            foreach (['hours', 'service_date', 'observation'] as $f) {
                if (array_key_exists($f, $body)) {
                    $fields[] = "$f=?";
                    $params[] = $f === 'observation' ? sanitizeString($body[$f]) : $body[$f];
                }
            }
            if (empty($fields)) jsonError('Sin campos para actualizar');

            if (isset($body['hours']) && ((float)$body['hours'] <= 0 || (float)$body['hours'] > 24)) {
                jsonError('Las horas deben estar entre 0.5 y 24');
            }

            $params[] = $id;
            $db->prepare("UPDATE service_hours SET " . implode(',', $fields) . " WHERE id=?")->execute($params);

            // Re-check completion status
            checkStudentCompletion($db, $old['student_id']);

            auditLog($db, $auth['id'], 'update', 'service_hours', $id, $old, $body);
            emitEvent($db, 'hours_updated', ['student_id' => $old['student_id']]);

            $stmt = $db->prepare("SELECT sh.*, u.full_name as admin_name FROM service_hours sh JOIN users u ON u.id=sh.registered_by WHERE sh.id=?");
            $stmt->execute([$id]);
            jsonResponse($stmt->fetch());
        } catch (PDOException $e) {
            logDatabaseError('hours/update', $e);
            jsonError('Error al actualizar horas', 500);
        }
    }

    // DELETE /api/hours/{id}  (admin only)
    if ($method === 'DELETE' && $id !== null) {
        $auth = requireAdmin();
        try {
            $stmt = $db->prepare("SELECT * FROM service_hours WHERE id=?");
            $stmt->execute([$id]);
            $hour = $stmt->fetch();
            if (!$hour) jsonError('Registro no encontrado', 404);

            $db->prepare("DELETE FROM service_hours WHERE id=?")->execute([$id]);

            // Re-evaluate student status if previously completed
            $stuStmt = $db->prepare("SELECT status FROM students WHERE id=?");
            $stuStmt->execute([$hour['student_id']]);
            $stu = $stuStmt->fetch();
            if ($stu && $stu['status'] === 'completed') {
                $total = getStudentTotalHours($db, $hour['student_id']);
                $reqStmt = $db->prepare("SELECT required_hours FROM students WHERE id=?");
                $reqStmt->execute([$hour['student_id']]);
                $req = (int)$reqStmt->fetchColumn();
                if ($total < $req) {
                    $db->prepare("UPDATE students SET status='active', end_date=NULL WHERE id=?")
                       ->execute([$hour['student_id']]);
                }
            }

            auditLog($db, $auth['id'], 'delete', 'service_hours', $id, $hour);
            emitEvent($db, 'hours_deleted', ['student_id' => $hour['student_id']]);
            jsonResponse(['message' => 'Registro eliminado correctamente']);
        } catch (PDOException $e) {
            logDatabaseError('hours/delete', $e);
            jsonError('Error al eliminar horas', 500);
        }
    }

    jsonError('Ruta hours no encontrada', 404);
}
