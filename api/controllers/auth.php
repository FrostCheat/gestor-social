<?php
$db = getDB();

if ($resource === 'auth') {

    // POST /api/auth/register
    if ($method === 'POST' && $action === 'register') {
        $required = ['full_name', 'email', 'password', 'enrollment_code', 'career'];
        foreach ($required as $f) {
            if (empty($body[$f])) jsonError("El campo $f es requerido");
        }

        $fullName = sanitizeString($body['full_name']);
        if (str_word_count($fullName) < 2) jsonError('El nombre completo debe tener al menos 2 palabras');

        $email = strtolower(trim($body['email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonError('Email inválido');

        if (strlen($body['password']) < 6) jsonError('La contraseña debe tener mínimo 6 caracteres');

        $enrollmentCode = strtoupper(trim($body['enrollment_code']));

        try {
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) jsonError('El email ya está registrado');

            $stmt = $db->prepare("SELECT id FROM students WHERE enrollment_code = ?");
            $stmt->execute([$enrollmentCode]);
            if ($stmt->fetch()) jsonError('El código de matrícula ya está registrado');

            $db->beginTransaction();

            $hash = password_hash($body['password'], PASSWORD_BCRYPT);
            $db->prepare("INSERT INTO users (full_name, email, password, role) VALUES (?,?,?,'student')")
               ->execute([$fullName, $email, $hash]);
            $userId = (int)$db->lastInsertId();

            $db->prepare("
                INSERT INTO students (user_id, enrollment_code, career, semester, required_hours, start_date)
                VALUES (?, ?, ?, ?, ?, CURDATE())
            ")->execute([
                $userId,
                $enrollmentCode,
                sanitizeString($body['career']),
                (int)($body['semester'] ?? 1),
                (int)($body['required_hours'] ?? 480),
            ]);

            $db->commit();

            $stmt = $db->prepare("
                SELECT u.id, u.full_name, u.email, u.role,
                       s.id as student_id, s.enrollment_code, s.career, s.semester, s.required_hours, s.status
                FROM users u
                JOIN students s ON s.user_id = u.id
                WHERE u.id = ?
            ");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();

            $token = jwtEncode([
                'id'    => $user['id'],
                'email' => $user['email'],
                'role'  => $user['role'],
                'name'  => $user['full_name'],
                'student_id' => $user['student_id'],
            ]);

            jsonResponse(['token' => $token, 'user' => $user], 201);
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            logDatabaseError('auth/register', $e);
            jsonError('Error al registrar usuario', 500);
        }
    }

    // POST /api/auth/login
    if ($method === 'POST' && $action === 'login') {
        if (empty($body['email']) || empty($body['password'])) jsonError('Email y contraseña requeridos');

        $email = strtolower(trim($body['email']));

        try {
            $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($body['password'], $user['password'])) {
                jsonError('Credenciales inválidas');
            }
            if ($user['blocked']) jsonError('Cuenta bloqueada. Contacta al administrador.');

            $extraData = [];
            if ($user['role'] === 'student') {
                $stmt = $db->prepare("SELECT id as student_id, enrollment_code, career, semester, required_hours, status FROM students WHERE user_id = ?");
                $stmt->execute([$user['id']]);
                $studentData = $stmt->fetch();
                if ($studentData) $extraData = $studentData;
            }

            $token = jwtEncode([
                'id'         => $user['id'],
                'email'      => $user['email'],
                'role'       => $user['role'],
                'name'       => $user['full_name'],
                'student_id' => $extraData['student_id'] ?? null,
            ]);

            unset($user['password']);
            jsonResponse(['token' => $token, 'user' => array_merge($user, $extraData)]);
        } catch (PDOException $e) {
            logDatabaseError('auth/login', $e);
            jsonError('Error al iniciar sesión', 500);
        }
    }

    // POST /api/auth/forgot-password
    if ($method === 'POST' && $action === 'forgot-password') {
        if (empty($body['email'])) jsonError('Email requerido');
        $email = strtolower(trim($body['email']));

        try {
            $stmt = $db->prepare("SELECT id, full_name FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            // Always return success to prevent email enumeration
            if ($user) {
                $resetToken = generateToken(32);
                $expires    = date('Y-m-d H:i:s', time() + 3600);
                // In production: store reset token and send email
                // For now we just log it (no email service configured)
                logInfo('Password reset requested', ['user_id' => $user['id'], 'token' => $resetToken, 'expires' => $expires]);
            }

            jsonResponse(['message' => 'Si el correo existe en nuestro sistema, recibirás las instrucciones.']);
        } catch (PDOException $e) {
            logDatabaseError('auth/forgot-password', $e);
            jsonError('Error al procesar solicitud', 500);
        }
    }

    // GET /api/auth/me
    if ($method === 'GET' && $action === 'me') {
        $auth = requireAuth();
        try {
            $stmt = $db->prepare("SELECT id, full_name, email, role, blocked, created_at FROM users WHERE id = ?");
            $stmt->execute([$auth['id']]);
            $user = $stmt->fetch();
            if (!$user) jsonError('Usuario no encontrado', 404);

            if ($user['role'] === 'student') {
                $stmt = $db->prepare("SELECT id as student_id, enrollment_code, career, semester, required_hours, status, start_date, end_date FROM students WHERE user_id = ?");
                $stmt->execute([$user['id']]);
                $studentData = $stmt->fetch();
                if ($studentData) $user = array_merge($user, $studentData);
            }

            jsonResponse($user);
        } catch (PDOException $e) {
            logDatabaseError('auth/me', $e);
            jsonError('Error al obtener información del usuario', 500);
        }
    }

    jsonError('Ruta auth no encontrada', 404);
}
