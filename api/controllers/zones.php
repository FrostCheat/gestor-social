<?php
$db = getDB();

if ($resource === 'zones') {

    // GET /api/zones
    if ($method === 'GET' && $id === null && $action === null) {
        requireAuth();
        try {
            $showAll = !empty($_GET['all']) && $_GET['all'] === '1';
            $where   = $showAll ? "WHERE 1=1" : "WHERE active=1";
            $stmt    = $db->query("SELECT * FROM zones $where ORDER BY name ASC");
            jsonResponse($stmt->fetchAll());
        } catch (PDOException $e) {
            logDatabaseError('zones/get_all', $e);
            jsonError('Error al obtener zonas', 500);
        }
    }

    // GET /api/zones/{id}
    if ($method === 'GET' && $id !== null && $action === null) {
        requireAuth();
        try {
            $stmt = $db->prepare("SELECT * FROM zones WHERE id=?");
            $stmt->execute([$id]);
            $zone = $stmt->fetch();
            if (!$zone) jsonError('Zona no encontrada', 404);
            jsonResponse($zone);
        } catch (PDOException $e) {
            logDatabaseError('zones/get_by_id', $e);
            jsonError('Error al obtener zona', 500);
        }
    }

    // GET /api/zones/student/{studentId}/history
    if ($method === 'GET' && $action === 'history' && $id !== null) {
        requireAuth();
        try {
            $stmt = $db->prepare("
                SELECT sz.*, z.name as zone_name, z.description, z.supervisor, z.address,
                       u.full_name as assigned_by_name
                FROM student_zones sz
                JOIN zones z ON z.id = sz.zone_id
                JOIN users u ON u.id = sz.assigned_by
                WHERE sz.student_id = ?
                ORDER BY sz.assigned_at DESC
            ");
            $stmt->execute([$id]);
            jsonResponse($stmt->fetchAll());
        } catch (PDOException $e) {
            logDatabaseError('zones/history', $e);
            jsonError('Error al obtener historial de zonas', 500);
        }
    }

    // POST /api/zones  (admin only)
    if ($method === 'POST' && $action === null) {
        requireAdmin();
        if (empty($body['name'])) jsonError('El nombre de la zona es requerido');
        try {
            $db->prepare("INSERT INTO zones (name, description, supervisor, address) VALUES (?,?,?,?)")
               ->execute([
                   sanitizeString($body['name']),
                   sanitizeString($body['description'] ?? ''),
                   sanitizeString($body['supervisor'] ?? ''),
                   sanitizeString($body['address'] ?? ''),
               ]);
            $newId = (int)$db->lastInsertId();
            $stmt  = $db->prepare("SELECT * FROM zones WHERE id=?");
            $stmt->execute([$newId]);
            jsonResponse($stmt->fetch(), 201);
        } catch (PDOException $e) {
            logDatabaseError('zones/create', $e);
            jsonError('Error al crear zona', 500);
        }
    }

    // POST /api/zones/assign  (admin: assign zone to student)
    if ($method === 'POST' && $action === 'assign') {
        $auth = requireAdmin();
        if (empty($body['student_id']) || empty($body['zone_id'])) {
            jsonError('student_id y zone_id son requeridos');
        }
        try {
            $stmt = $db->prepare("SELECT id, user_id FROM students WHERE id=?");
            $stmt->execute([(int)$body['student_id']]);
            $student = $stmt->fetch();
            if (!$student) jsonError('Estudiante no encontrado', 404);

            $stmt = $db->prepare("SELECT id, name FROM zones WHERE id=? AND active=1");
            $stmt->execute([(int)$body['zone_id']]);
            $zone = $stmt->fetch();
            if (!$zone) jsonError('Zona no encontrada o inactiva', 404);

            $db->beginTransaction();
            // Deactivate all current zone assignments
            $db->prepare("UPDATE student_zones SET is_current=0 WHERE student_id=?")->execute([$student['id']]);
            // Insert new assignment
            $db->prepare("
                INSERT INTO student_zones (student_id, zone_id, assigned_by, is_current, notes)
                VALUES (?,?,?,1,?)
            ")->execute([
                $student['id'],
                $zone['id'],
                $auth['id'],
                sanitizeString($body['notes'] ?? ''),
            ]);
            $db->commit();

            // Notify student
            createNotification(
                $db,
                $student['user_id'],
                'zone_assigned',
                'Zona de servicio asignada',
                "Has sido asignado a la zona: {$zone['name']}."
            );
            emitEvent($db, 'zone_assigned', ['student_id' => $student['id'], 'zone_id' => $zone['id'], 'user_id' => $student['user_id']]);

            jsonResponse(['message' => 'Zona asignada correctamente', 'zone' => $zone]);
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            logDatabaseError('zones/assign', $e);
            jsonError('Error al asignar zona', 500);
        }
    }

    // PUT /api/zones/{id}  (admin only)
    if ($method === 'PUT' && $id !== null) {
        requireAdmin();
        try {
            $stmt = $db->prepare("SELECT id FROM zones WHERE id=?");
            $stmt->execute([$id]);
            if (!$stmt->fetch()) jsonError('Zona no encontrada', 404);

            $fields = []; $params = [];
            foreach (['name', 'description', 'supervisor', 'address', 'active'] as $f) {
                if (array_key_exists($f, $body)) {
                    $fields[] = "$f=?";
                    $params[] = $f === 'active' ? (int)$body[$f] : sanitizeString((string)$body[$f]);
                }
            }
            if (empty($fields)) jsonError('Sin campos para actualizar');
            $params[] = $id;
            $db->prepare("UPDATE zones SET " . implode(',', $fields) . " WHERE id=?")->execute($params);
            $stmt = $db->prepare("SELECT * FROM zones WHERE id=?");
            $stmt->execute([$id]);
            jsonResponse($stmt->fetch());
        } catch (PDOException $e) {
            logDatabaseError('zones/update', $e);
            jsonError('Error al actualizar zona', 500);
        }
    }

    jsonError('Ruta zones no encontrada', 404);
}
