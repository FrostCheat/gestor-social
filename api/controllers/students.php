<?php
$db = getDB();

if ($resource === 'students') {

    // GET /api/students  (admin only)
    if ($method === 'GET' && $id === null && $action === null) {
        requireAdmin();
        try {
            $where  = "WHERE 1=1";
            $params = [];

            if (!empty($_GET['q'])) {
                $q      = '%' . $_GET['q'] . '%';
                $where .= " AND (u.full_name LIKE ? OR u.email LIKE ? OR s.enrollment_code LIKE ? OR s.career LIKE ?)";
                $params = array_merge($params, [$q, $q, $q, $q]);
            }
            if (!empty($_GET['status'])) {
                $where .= " AND s.status = ?";
                $params[] = $_GET['status'];
            }

            $stmt = $db->prepare("
                SELECT u.id as user_id, u.full_name, u.email, u.blocked, u.created_at,
                       s.id as student_id, s.enrollment_code, s.career, s.semester,
                       s.required_hours, s.status, s.start_date, s.end_date,
                       COALESCE(SUM(sh.hours), 0) as completed_hours,
                       z.name as zone_name
                FROM students s
                JOIN users u ON u.id = s.user_id
                LEFT JOIN service_hours sh ON sh.student_id = s.id
                LEFT JOIN student_zones sz ON sz.student_id = s.id AND sz.is_current = 1
                LEFT JOIN zones z ON z.id = sz.zone_id
                $where
                GROUP BY s.id, u.id, z.name
                ORDER BY u.full_name ASC
            ");
            $stmt->execute($params);
            jsonResponse($stmt->fetchAll());
        } catch (PDOException $e) {
            logDatabaseError('students/get_all', $e);
            jsonError('Error al obtener estudiantes', 500);
        }
    }

    // GET /api/students/me  (student own profile)
    if ($method === 'GET' && $action === 'me') {
        $auth = requireAuth();
        try {
            $stmt = $db->prepare("
                SELECT u.id as user_id, u.full_name, u.email,
                       s.id as student_id, s.enrollment_code, s.career, s.semester,
                       s.required_hours, s.status, s.start_date, s.end_date,
                       COALESCE(SUM(sh.hours), 0) as completed_hours
                FROM students s
                JOIN users u ON u.id = s.user_id
                LEFT JOIN service_hours sh ON sh.student_id = s.id
                WHERE u.id = ?
                GROUP BY s.id, u.id
            ");
            $stmt->execute([$auth['id']]);
            $student = $stmt->fetch();
            if (!$student) jsonError('Perfil de estudiante no encontrado', 404);

            $student['remaining_hours']  = max(0, $student['required_hours'] - $student['completed_hours']);
            $student['completion_pct']   = $student['required_hours'] > 0
                ? round(($student['completed_hours'] / $student['required_hours']) * 100, 1)
                : 0;

            jsonResponse($student);
        } catch (PDOException $e) {
            logDatabaseError('students/me', $e);
            jsonError('Error al obtener perfil', 500);
        }
    }

    // GET /api/students/{id}
    if ($method === 'GET' && $id !== null) {
        $auth = requireAuth();
        try {
            $stmt = $db->prepare("
                SELECT u.id as user_id, u.full_name, u.email, u.blocked, u.created_at,
                       s.id as student_id, s.enrollment_code, s.career, s.semester,
                       s.required_hours, s.status, s.start_date, s.end_date,
                       COALESCE(SUM(sh.hours), 0) as completed_hours
                FROM students s
                JOIN users u ON u.id = s.user_id
                LEFT JOIN service_hours sh ON sh.student_id = s.id
                WHERE s.id = ?
                GROUP BY s.id, u.id
            ");
            $stmt->execute([$id]);
            $student = $stmt->fetch();
            if (!$student) jsonError('Estudiante no encontrado', 404);

            // Students can only see themselves
            if ($auth['role'] === 'student' && (int)$student['user_id'] !== (int)$auth['id']) {
                jsonError('Acceso denegado', 403);
            }

            $student['remaining_hours'] = max(0, $student['required_hours'] - $student['completed_hours']);
            $student['completion_pct']  = $student['required_hours'] > 0
                ? round(($student['completed_hours'] / $student['required_hours']) * 100, 1)
                : 0;

            // Current zone
            $zoneStmt = $db->prepare("
                SELECT sz.*, z.name as zone_name, z.description, z.supervisor, z.address
                FROM student_zones sz
                JOIN zones z ON z.id = sz.zone_id
                WHERE sz.student_id = ? AND sz.is_current = 1
                LIMIT 1
            ");
            $zoneStmt->execute([$id]);
            $student['current_zone'] = $zoneStmt->fetch() ?: null;

            jsonResponse($student);
        } catch (PDOException $e) {
            logDatabaseError('students/get_by_id', $e);
            jsonError('Error al obtener estudiante', 500);
        }
    }

    // POST /api/students  (admin only)
    if ($method === 'POST') {
        requireAdmin();
        $required = ['full_name', 'email', 'password', 'enrollment_code', 'career'];
        foreach ($required as $f) {
            if (empty($body[$f])) jsonError("El campo $f es requerido");
        }

        $email = strtolower(trim($body['email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonError('Email inválido');
        if (strlen($body['password']) < 6) jsonError('La contraseña debe tener mínimo 6 caracteres');

        try {
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) jsonError('El email ya está registrado');

            $db->beginTransaction();
            $hash = password_hash($body['password'], PASSWORD_BCRYPT);
            $db->prepare("INSERT INTO users (full_name, email, password, role) VALUES (?,?,?,'student')")
               ->execute([sanitizeString($body['full_name']), $email, $hash]);
            $userId = (int)$db->lastInsertId();

            $db->prepare("
                INSERT INTO students (user_id, enrollment_code, career, semester, required_hours, start_date)
                VALUES (?,?,?,?,?,?)
            ")->execute([
                $userId,
                strtoupper(trim($body['enrollment_code'])),
                sanitizeString($body['career']),
                (int)($body['semester'] ?? 1),
                (int)($body['required_hours'] ?? 480),
                $body['start_date'] ?? date('Y-m-d'),
            ]);
            $studentId = (int)$db->lastInsertId();

            auditLog($db, $auth['id'] ?? null, 'create', 'student', $studentId, null, $body);
            emitEvent($db, 'student_created', ['student_id' => $studentId, 'user_id' => $userId]);
            $db->commit();

            $stmt = $db->prepare("SELECT u.id as user_id, u.full_name, u.email, s.* FROM students s JOIN users u ON u.id=s.user_id WHERE s.id=?");
            $stmt->execute([$studentId]);
            jsonResponse($stmt->fetch(), 201);
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            logDatabaseError('students/create', $e);
            jsonError('Error al crear estudiante', 500);
        }
    }

    // PUT /api/students/{id}  (admin only)
    if ($method === 'PUT' && $id !== null && $action === null) {
        $auth = requireAdmin();
        try {
            $stmt = $db->prepare("SELECT s.id, u.id as user_id FROM students s JOIN users u ON u.id=s.user_id WHERE s.id=?");
            $stmt->execute([$id]);
            $student = $stmt->fetch();
            if (!$student) jsonError('Estudiante no encontrado', 404);

            $userFields = []; $userParams = [];
            foreach (['full_name', 'email'] as $f) {
                if (array_key_exists($f, $body)) { $userFields[] = "$f=?"; $userParams[] = sanitizeString($body[$f]); }
            }
            if (!empty($body['password'])) {
                $userFields[] = "password=?";
                $userParams[] = password_hash($body['password'], PASSWORD_BCRYPT);
            }
            if ($userFields) {
                $userParams[] = $student['user_id'];
                $db->prepare("UPDATE users SET " . implode(',', $userFields) . " WHERE id=?")->execute($userParams);
            }

            $stuFields = []; $stuParams = [];
            foreach (['enrollment_code', 'career', 'semester', 'required_hours', 'status', 'start_date', 'end_date'] as $f) {
                if (array_key_exists($f, $body)) { $stuFields[] = "$f=?"; $stuParams[] = $body[$f]; }
            }
            if ($stuFields) {
                $stuParams[] = $id;
                $db->prepare("UPDATE students SET " . implode(',', $stuFields) . " WHERE id=?")->execute($stuParams);
            }

            auditLog($db, $auth['id'], 'update', 'student', $id, null, $body);
            emitEvent($db, 'student_updated', ['student_id' => $id]);

            $stmt = $db->prepare("SELECT u.id as user_id, u.full_name, u.email, s.* FROM students s JOIN users u ON u.id=s.user_id WHERE s.id=?");
            $stmt->execute([$id]);
            jsonResponse($stmt->fetch());
        } catch (PDOException $e) {
            logDatabaseError('students/update', $e);
            jsonError('Error al actualizar estudiante', 500);
        }
    }

    // DELETE /api/students/{id}  (admin only)
    if ($method === 'DELETE' && $id !== null) {
        $auth = requireAdmin();
        try {
            $stmt = $db->prepare("SELECT user_id FROM students WHERE id=?");
            $stmt->execute([$id]);
            $student = $stmt->fetch();
            if (!$student) jsonError('Estudiante no encontrado', 404);

            auditLog($db, $auth['id'], 'delete', 'student', $id);
            $db->prepare("DELETE FROM users WHERE id=?")->execute([$student['user_id']]);
            jsonResponse(['message' => 'Estudiante eliminado correctamente']);
        } catch (PDOException $e) {
            logDatabaseError('students/delete', $e);
            jsonError('Error al eliminar estudiante', 500);
        }
    }

    jsonError('Ruta students no encontrada', 404);
}
