<?php
define('DB_HOST',      getenv('DB_HOST')      ?: 'sql208.infinityfree.com');
define('DB_NAME',      getenv('DB_NAME')      ?: 'if0_41867189_gestor');
define('DB_USER',      getenv('DB_USER')      ?: 'if0_41867189');
define('DB_PASS',      getenv('DB_PASS')      ?: 'jbaRKTM4s8U');
define('JWT_SECRET',   getenv('JWT_SECRET')   ?: 'servicio_social_jwt_secret_2024_xZ7#kQ');
define('UPLOAD_PATH',  __DIR__ . '/../../uploads/certificates/');
define('BASE_URL',     getenv('BASE_URL')     ?: 'http://localhost:8080');

date_default_timezone_set('America/Bogota');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    try {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_PERSISTENT         => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone='-05:00'",
        ]);
        initDB($pdo);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'DB Connection failed']);
        exit;
    }
    return $pdo;
}

function initDB(PDO $pdo): void {
    // Roles
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `roles` (
            `id`          INT AUTO_INCREMENT PRIMARY KEY,
            `name`        VARCHAR(50) NOT NULL,
            `description` VARCHAR(255) NOT NULL DEFAULT '',
            `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `idx_name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Users
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `users` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `full_name`  VARCHAR(150) NOT NULL,
            `email`      VARCHAR(150) NOT NULL,
            `password`   VARCHAR(255) NOT NULL,
            `role`       VARCHAR(20) NOT NULL DEFAULT 'student',
            `blocked`    TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `idx_email` (`email`),
            KEY `idx_role` (`role`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Students
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `students` (
            `id`              INT AUTO_INCREMENT PRIMARY KEY,
            `user_id`         INT NOT NULL,
            `enrollment_code` VARCHAR(50) NOT NULL,
            `career`          VARCHAR(150) NOT NULL DEFAULT '',
            `semester`        TINYINT NOT NULL DEFAULT 1,
            `required_hours`  INT NOT NULL DEFAULT 480,
            `status`          ENUM('active','completed','suspended') NOT NULL DEFAULT 'active',
            `start_date`      DATE DEFAULT NULL,
            `end_date`        DATE DEFAULT NULL,
            `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `idx_user_id`         (`user_id`),
            UNIQUE KEY `idx_enrollment_code` (`enrollment_code`),
            KEY `idx_status` (`status`),
            CONSTRAINT `fk_students_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Zones
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `zones` (
            `id`          INT AUTO_INCREMENT PRIMARY KEY,
            `name`        VARCHAR(150) NOT NULL,
            `description` TEXT,
            `supervisor`  VARCHAR(150) NOT NULL DEFAULT '',
            `address`     VARCHAR(255) NOT NULL DEFAULT '',
            `active`      TINYINT(1) NOT NULL DEFAULT 1,
            `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_active` (`active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Student zones
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `student_zones` (
            `id`          INT AUTO_INCREMENT PRIMARY KEY,
            `student_id`  INT NOT NULL,
            `zone_id`     INT NOT NULL,
            `assigned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `assigned_by` INT NOT NULL,
            `is_current`  TINYINT(1) NOT NULL DEFAULT 1,
            `notes`       TEXT,
            KEY `idx_student_id` (`student_id`),
            KEY `idx_zone_id`    (`zone_id`),
            KEY `idx_is_current` (`is_current`),
            CONSTRAINT `fk_sz_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_sz_zone`    FOREIGN KEY (`zone_id`)    REFERENCES `zones`(`id`)    ON DELETE RESTRICT ON UPDATE CASCADE,
            CONSTRAINT `fk_sz_admin`   FOREIGN KEY (`assigned_by`) REFERENCES `users`(`id`)   ON DELETE RESTRICT ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Service hours
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `service_hours` (
            `id`            INT AUTO_INCREMENT PRIMARY KEY,
            `student_id`    INT NOT NULL,
            `hours`         DECIMAL(5,2) NOT NULL,
            `service_date`  DATE NOT NULL,
            `observation`   TEXT,
            `registered_by` INT NOT NULL,
            `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_student_id`    (`student_id`),
            KEY `idx_service_date`  (`service_date`),
            KEY `idx_registered_by` (`registered_by`),
            CONSTRAINT `fk_sh_student` FOREIGN KEY (`student_id`)    REFERENCES `students`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_sh_admin`   FOREIGN KEY (`registered_by`) REFERENCES `users`(`id`)    ON DELETE RESTRICT ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Certificate requests
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `certificate_requests` (
            `id`           INT AUTO_INCREMENT PRIMARY KEY,
            `student_id`   INT NOT NULL,
            `status`       ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            `requested_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `reviewed_at`  TIMESTAMP NULL DEFAULT NULL,
            `reviewed_by`  INT NULL DEFAULT NULL,
            `notes`        TEXT,
            KEY `idx_student_id` (`student_id`),
            KEY `idx_status`     (`status`),
            CONSTRAINT `fk_cr_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_cr_admin`   FOREIGN KEY (`reviewed_by`) REFERENCES `users`(`id`)   ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Certificates
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `certificates` (
            `id`          INT AUTO_INCREMENT PRIMARY KEY,
            `student_id`  INT NOT NULL,
            `request_id`  INT NULL DEFAULT NULL,
            `file_path`   VARCHAR(500) NOT NULL,
            `uploaded_by` INT NOT NULL,
            `uploaded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `notes`       TEXT,
            UNIQUE KEY `idx_student_id` (`student_id`),
            KEY `idx_request_id` (`request_id`),
            CONSTRAINT `fk_cert_student`  FOREIGN KEY (`student_id`)  REFERENCES `students`(`id`)             ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_cert_request`  FOREIGN KEY (`request_id`)  REFERENCES `certificate_requests`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT `fk_cert_admin`    FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`)                ON DELETE RESTRICT ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Notifications
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `notifications` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `user_id`    INT NOT NULL,
            `type`       VARCHAR(50) NOT NULL,
            `title`      VARCHAR(255) NOT NULL,
            `message`    TEXT NOT NULL,
            `read_at`    TIMESTAMP NULL DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_user_id`    (`user_id`),
            KEY `idx_read_at`    (`read_at`),
            KEY `idx_created_at` (`created_at`),
            CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Audit logs
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `audit_logs` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `user_id`    INT NULL DEFAULT NULL,
            `action`     VARCHAR(100) NOT NULL,
            `entity`     VARCHAR(50) NOT NULL,
            `entity_id`  INT NULL DEFAULT NULL,
            `old_data`   JSON,
            `new_data`   JSON,
            `ip_address` VARCHAR(45) DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_user_id`    (`user_id`),
            KEY `idx_entity`     (`entity`, `entity_id`),
            KEY `idx_created_at` (`created_at`),
            CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Events (SSE)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `events` (
            `id`         INT AUTO_INCREMENT PRIMARY KEY,
            `type`       VARCHAR(50) NOT NULL,
            `payload`    JSON,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_id`      (`id`),
            KEY `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Seed admin
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute(['admin@universidad.edu.co']);
    if (!$stmt->fetch()) {
        $hash = password_hash('admin123', PASSWORD_BCRYPT);
        $pdo->prepare("
            INSERT INTO users (full_name, email, password, role)
            VALUES ('Administrador Principal', 'admin@universidad.edu.co', ?, 'admin')
        ")->execute([$hash]);
    }

    // Seed default zones
    $zoneCount = (int)$pdo->query("SELECT COUNT(*) FROM zones")->fetchColumn();
    if ($zoneCount === 0) {
        $pdo->exec("
            INSERT INTO zones (name, description, supervisor, address) VALUES
            ('Biblioteca Municipal', 'Apoyo en organización de libros y atención al usuario', 'Lcda. María Torres', 'Calle 10 # 5-20, Centro'),
            ('Hospital San José', 'Apoyo administrativo en recepción y archivo', 'Dr. Carlos Mendoza', 'Av. Salud # 30-10'),
            ('Jardín Infantil Los Sueños', 'Apoyo pedagógico y actividades recreativas', 'Lcda. Ana Ruiz', 'Carrera 15 # 8-45'),
            ('Alcaldía Municipal', 'Apoyo en sistemas y atención al ciudadano', 'Ing. Pedro Gómez', 'Plaza principal s/n')
        ");
    }

    // Clean old events
    try {
        $pdo->exec("DELETE FROM events WHERE created_at < NOW() - INTERVAL 2 HOUR");
    } catch (PDOException $e) {}
}
