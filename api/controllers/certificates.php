<?php
$db = getDB();

if ($resource === 'certificates') {

    // GET /api/certificates  (admin: all; student: own)
    if ($method === 'GET' && $id === null && $action === null) {
        $auth = requireAuth();
        try {
            if ($auth['role'] === 'admin') {
                $stmt = $db->query("
                    SELECT c.*, u.full_name as student_name, s.enrollment_code,
                           ua.full_name as uploaded_by_name
                    FROM certificates c
                    JOIN students s ON s.id = c.student_id
                    JOIN users u ON u.id = s.user_id
                    JOIN users ua ON ua.id = c.uploaded_by
                    ORDER BY c.uploaded_at DESC
                ");
            } else {
                $stuStmt = $db->prepare("SELECT id FROM students WHERE user_id=?");
                $stuStmt->execute([$auth['id']]);
                $stu = $stuStmt->fetch();
                if (!$stu) jsonError('Perfil de estudiante no encontrado', 404);
                $stmt = $db->prepare("SELECT c.*, ua.full_name as uploaded_by_name FROM certificates c JOIN users ua ON ua.id=c.uploaded_by WHERE c.student_id=?");
                $stmt->execute([$stu['id']]);
            }
            jsonResponse($stmt->fetchAll());
        } catch (PDOException $e) {
            logDatabaseError('certificates/get_all', $e);
            jsonError('Error al obtener certificados', 500);
        }
    }

    // GET /api/certificates/requests  (admin: all; student: own)
    if ($method === 'GET' && $action === 'requests') {
        $auth = requireAuth();
        try {
            if ($auth['role'] === 'admin') {
                $stmt = $db->query("
                    SELECT cr.*, u.full_name as student_name, s.enrollment_code, s.status as student_status,
                           COALESCE(SUM(sh.hours),0) as completed_hours, s.required_hours,
                           ua.full_name as reviewed_by_name
                    FROM certificate_requests cr
                    JOIN students s ON s.id = cr.student_id
                    JOIN users u ON u.id = s.user_id
                    LEFT JOIN service_hours sh ON sh.student_id = s.id
                    LEFT JOIN users ua ON ua.id = cr.reviewed_by
                    GROUP BY cr.id, u.full_name, s.enrollment_code, s.status, s.required_hours, ua.full_name
                    ORDER BY cr.requested_at DESC
                ");
            } else {
                $stuStmt = $db->prepare("SELECT id FROM students WHERE user_id=?");
                $stuStmt->execute([$auth['id']]);
                $stu = $stuStmt->fetch();
                if (!$stu) jsonError('Perfil de estudiante no encontrado', 404);
                $stmt = $db->prepare("
                    SELECT cr.*, ua.full_name as reviewed_by_name
                    FROM certificate_requests cr
                    LEFT JOIN users ua ON ua.id = cr.reviewed_by
                    WHERE cr.student_id = ?
                    ORDER BY cr.requested_at DESC
                ");
                $stmt->execute([$stu['id']]);
            }
            jsonResponse($stmt->fetchAll());
        } catch (PDOException $e) {
            logDatabaseError('certificates/requests', $e);
            jsonError('Error al obtener solicitudes', 500);
        }
    }

    // POST /api/certificates/request  (student: request certificate)
    if ($method === 'POST' && $action === 'request') {
        $auth = requireAuth();
        try {
            $stuStmt = $db->prepare("SELECT id, required_hours, status FROM students WHERE user_id=?");
            $stuStmt->execute([$auth['id']]);
            $student = $stuStmt->fetch();
            if (!$student) jsonError('Perfil de estudiante no encontrado', 404);

            // Check hours completed
            $totalHours = getStudentTotalHours($db, $student['id']);
            if ($totalHours < $student['required_hours']) {
                jsonError("No puedes solicitar el certificado. Tienes {$totalHours} horas de {$student['required_hours']} requeridas.");
            }

            // Check for duplicate pending request
            $dupStmt = $db->prepare("SELECT id FROM certificate_requests WHERE student_id=? AND status='pending'");
            $dupStmt->execute([$student['id']]);
            if ($dupStmt->fetch()) jsonError('Ya tienes una solicitud pendiente. Espera a que sea procesada.');

            $db->prepare("INSERT INTO certificate_requests (student_id) VALUES (?)")
               ->execute([$student['id']]);
            $requestId = (int)$db->lastInsertId();

            emitEvent($db, 'certificate_requested', ['student_id' => $student['id'], 'request_id' => $requestId]);

            $stmt = $db->prepare("SELECT * FROM certificate_requests WHERE id=?");
            $stmt->execute([$requestId]);
            jsonResponse($stmt->fetch(), 201);
        } catch (PDOException $e) {
            logDatabaseError('certificates/request', $e);
            jsonError('Error al enviar solicitud', 500);
        }
    }

    // POST /api/certificates/upload  (admin: upload certificate file)
    if ($method === 'POST' && $action === 'upload') {
        $auth = requireAdmin();
        if (empty($body['student_id'])) jsonError('student_id es requerido');
        if (empty($body['file_base64']) && empty($_FILES['certificate'])) jsonError('Archivo de certificado requerido');

        try {
            $stuStmt = $db->prepare("SELECT id, user_id FROM students WHERE id=?");
            $stuStmt->execute([(int)$body['student_id']]);
            $student = $stuStmt->fetch();
            if (!$student) jsonError('Estudiante no encontrado', 404);

            $uploadDir = UPLOAD_PATH;
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

            $filename  = 'cert_student_' . $student['id'] . '_' . time() . '.pdf';
            $filepath  = $uploadDir . $filename;
            $storedPath = 'uploads/certificates/' . $filename;

            if (!empty($body['file_base64'])) {
                // Base64 encoded file
                $fileData = base64_decode(preg_replace('/^data:[^;]+;base64,/', '', $body['file_base64']));
                if ($fileData === false) jsonError('Archivo inválido');
                file_put_contents($filepath, $fileData);
            } elseif (isset($_FILES['certificate'])) {
                $allowed = ['application/pdf', 'image/jpeg', 'image/png'];
                if (!in_array($_FILES['certificate']['type'], $allowed)) jsonError('Solo se permiten PDF, JPG y PNG');
                if ($_FILES['certificate']['size'] > 10 * 1024 * 1024) jsonError('El archivo no puede superar 10MB');
                move_uploaded_file($_FILES['certificate']['tmp_name'], $filepath);
                $ext = pathinfo($_FILES['certificate']['name'], PATHINFO_EXTENSION);
                $filename = 'cert_student_' . $student['id'] . '_' . time() . '.' . $ext;
                rename($filepath, $uploadDir . $filename);
                $storedPath = 'uploads/certificates/' . $filename;
            }

            // Upsert certificate
            $existing = $db->prepare("SELECT id FROM certificates WHERE student_id=?");
            $existing->execute([$student['id']]);
            $cert = $existing->fetch();

            $requestId = !empty($body['request_id']) ? (int)$body['request_id'] : null;

            if ($cert) {
                $db->prepare("UPDATE certificates SET file_path=?, uploaded_by=?, request_id=?, notes=?, uploaded_at=NOW() WHERE student_id=?")
                   ->execute([$storedPath, $auth['id'], $requestId, sanitizeString($body['notes'] ?? ''), $student['id']]);
            } else {
                $db->prepare("INSERT INTO certificates (student_id, request_id, file_path, uploaded_by, notes) VALUES (?,?,?,?,?)")
                   ->execute([$student['id'], $requestId, $storedPath, $auth['id'], sanitizeString($body['notes'] ?? '')]);
            }

            // If tied to a request, auto-approve it
            if ($requestId) {
                $db->prepare("UPDATE certificate_requests SET status='approved', reviewed_by=?, reviewed_at=NOW() WHERE id=?")
                   ->execute([$auth['id'], $requestId]);
            }

            // Notify student
            createNotification(
                $db,
                $student['user_id'],
                'certificate_ready',
                'Certificado disponible',
                'Tu certificado de servicio social ya está disponible para descarga.'
            );
            emitEvent($db, 'certificate_uploaded', ['student_id' => $student['id'], 'user_id' => $student['user_id']]);

            jsonResponse(['message' => 'Certificado cargado correctamente', 'file_path' => $storedPath], 201);
        } catch (PDOException $e) {
            logDatabaseError('certificates/upload', $e);
            jsonError('Error al cargar certificado', 500);
        }
    }

    // PUT /api/certificates/approve  (admin)
    if ($method === 'PUT' && $action === 'approve') {
        $auth = requireAdmin();
        if (empty($body['request_id'])) jsonError('request_id es requerido');
        try {
            $stmt = $db->prepare("SELECT cr.*, s.user_id FROM certificate_requests cr JOIN students s ON s.id=cr.student_id WHERE cr.id=?");
            $stmt->execute([(int)$body['request_id']]);
            $req = $stmt->fetch();
            if (!$req) jsonError('Solicitud no encontrada', 404);
            if ($req['status'] !== 'pending') jsonError('La solicitud ya fue procesada');

            $db->prepare("UPDATE certificate_requests SET status='approved', reviewed_by=?, reviewed_at=NOW(), notes=? WHERE id=?")
               ->execute([$auth['id'], sanitizeString($body['notes'] ?? ''), $req['id']]);

            createNotification(
                $db, $req['user_id'], 'request_approved',
                'Solicitud de certificado aprobada',
                'Tu solicitud de certificado ha sido aprobada. Pronto recibirás tu certificado.'
            );
            emitEvent($db, 'certificate_request_updated', ['request_id' => $req['id'], 'status' => 'approved']);
            jsonResponse(['message' => 'Solicitud aprobada']);
        } catch (PDOException $e) {
            logDatabaseError('certificates/approve', $e);
            jsonError('Error al aprobar solicitud', 500);
        }
    }

    // PUT /api/certificates/reject  (admin)
    if ($method === 'PUT' && $action === 'reject') {
        $auth = requireAdmin();
        if (empty($body['request_id'])) jsonError('request_id es requerido');
        try {
            $stmt = $db->prepare("SELECT cr.*, s.user_id FROM certificate_requests cr JOIN students s ON s.id=cr.student_id WHERE cr.id=?");
            $stmt->execute([(int)$body['request_id']]);
            $req = $stmt->fetch();
            if (!$req) jsonError('Solicitud no encontrada', 404);
            if ($req['status'] !== 'pending') jsonError('La solicitud ya fue procesada');

            $db->prepare("UPDATE certificate_requests SET status='rejected', reviewed_by=?, reviewed_at=NOW(), notes=? WHERE id=?")
               ->execute([$auth['id'], sanitizeString($body['notes'] ?? ''), $req['id']]);

            createNotification(
                $db, $req['user_id'], 'request_rejected',
                'Solicitud de certificado rechazada',
                'Tu solicitud de certificado fue rechazada. ' . ($body['notes'] ?? 'Contacta al administrador para más información.')
            );
            emitEvent($db, 'certificate_request_updated', ['request_id' => $req['id'], 'status' => 'rejected']);
            jsonResponse(['message' => 'Solicitud rechazada']);
        } catch (PDOException $e) {
            logDatabaseError('certificates/reject', $e);
            jsonError('Error al rechazar solicitud', 500);
        }
    }

    jsonError('Ruta certificates no encontrada', 404);
}
