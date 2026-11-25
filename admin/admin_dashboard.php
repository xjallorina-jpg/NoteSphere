<?php

// =========================================================
// NoteSphere - Admin Dashboard
// =========================================================

session_start();

require_once __DIR__ . '/../includes/db_config.php';

/** @var PDO $conn */

// =========================================================
// ADMIN SECURITY CHECK
// =========================================================

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'admin'
) {
    header("Location: ../auth/login.php");
    exit();
}

// =========================================================
// LOGIN SECURITY ALERTS + DASHBOARD POLLING ENDPOINT
// =========================================================

try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS login_security_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email_attempted VARCHAR(255) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            user_agent VARCHAR(500) NULL,
            attempt_status VARCHAR(50) NOT NULL,
            details VARCHAR(500) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_login_security_created_at (created_at),
            INDEX idx_login_security_email (email_attempted),
            INDEX idx_login_security_status (attempt_status)
        )
    " );
} catch (PDOException $e) {
    error_log('NoteSphere dashboard security log setup error: ' . $e->getMessage());
}

// Persist login lock state and IP blocks across sessions.
try {
    $conn->exec("CREATE TABLE IF NOT EXISTS login_security_control (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        login_locked TINYINT(1) NOT NULL DEFAULT 0,
        lock_reason VARCHAR(500) NULL,
        updated_by INT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    $conn->exec("INSERT INTO login_security_control (id, login_locked, lock_reason) VALUES (1, 0, NULL) ON DUPLICATE KEY UPDATE id = id");
    $conn->exec("CREATE TABLE IF NOT EXISTS login_security_blocklist (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        blocked_ip VARCHAR(45) NOT NULL,
        email_attempted VARCHAR(255) NULL,
        alert_id BIGINT UNSIGNED NULL,
        blocked_by INT NULL,
        blocked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_login_security_blocked_ip (blocked_ip)
    )");
} catch (PDOException $e) {
    error_log('NoteSphere security control table setup error: ' . $e->getMessage());
}

// This lightweight endpoint lets an already-open admin dashboard check for new alerts.
if (isset($_GET['security_alerts_json']) && $_GET['security_alerts_json'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    try {
        $afterId = max(0, (int) ($_GET['after_id'] ?? 0));
        $alertStmt = $conn->prepare("
            SELECT id, email_attempted, ip_address, created_at, details
            FROM login_security_logs
            WHERE attempt_status = 'unknown_account_alert'
              AND id > ?
            ORDER BY id ASC
            LIMIT 30
        " );
        $alertStmt->execute([$afterId]);
        echo json_encode(['ok' => true, 'alerts' => $alertStmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (PDOException $e) {
        error_log('NoteSphere security alert polling error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'alerts' => []]);
    }
    exit();
}

if (isset($_GET['security_status_json']) && $_GET['security_status_json'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    try {
        $lockStmt = $conn->query("SELECT login_locked, lock_reason FROM login_security_control WHERE id = 1 LIMIT 1");
        $lockRow = $lockStmt->fetch(PDO::FETCH_ASSOC) ?: ['login_locked' => 0, 'lock_reason' => null];
        echo json_encode(['ok' => true, 'login_locked' => (bool)$lockRow['login_locked'], 'lock_reason' => $lockRow['lock_reason']]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['ok' => false]);
    }
    exit();
}

$security_alerts = [];
$security_alert_count = 0;
$latest_security_alert_id = 0;
try {
    $securityCountStmt = $conn->query("
        SELECT COUNT(*)
        FROM login_security_logs
        WHERE attempt_status = 'unknown_account_alert'
          AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    " );
    $security_alert_count = (int) $securityCountStmt->fetchColumn();

    $securityAlertsStmt = $conn->query("
        SELECT id, email_attempted, ip_address, created_at, details
        FROM login_security_logs
        WHERE attempt_status = 'unknown_account_alert'
        ORDER BY id DESC
        LIMIT 5
    " );
    $security_alerts = $securityAlertsStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($security_alerts)) {
        $latest_security_alert_id = (int) $security_alerts[0]['id'];
    }
} catch (PDOException $e) {
    error_log('NoteSphere dashboard security alert query error: ' . $e->getMessage());
}

// =========================================================
// DEFAULT SETTINGS
// =========================================================

$default_system_storage_tb = 10.00;
$default_student_storage_gb = 500.00;
$default_professor_storage_gb = 1000.00;
$default_max_users = 1000;

// =========================================================
// CSRF TOKEN
// =========================================================

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];

// =========================================================
// SECURITY ADMIN ACTIONS: LOCK LOGIN, BLOCK IP, UNBLOCK IP, DELETE ALERT
// =========================================================
$security_action_notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['security_action'])) {
    $actionToken = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrf_token, $actionToken)) {
        $security_action_notice = 'Invalid security token. Please refresh and try again.';
    } else {
        $securityAction = (string)$_POST['security_action'];
        try {
            if ($securityAction === 'lock_login' || $securityAction === 'unlock_login') {
                $shouldLock = $securityAction === 'lock_login' ? 1 : 0;
                $reason = $shouldLock
                    ? 'Login access has been temporarily paused by the administrator because of repeated suspicious attempts using unregistered accounts.'
                    : null;
                $controlStmt = $conn->prepare("UPDATE login_security_control SET login_locked = ?, lock_reason = ?, updated_by = ? WHERE id = 1");
                $controlStmt->execute([$shouldLock, $reason, (int)$_SESSION['user_id']]);
                $security_action_notice = $shouldLock ? 'login_locked' : 'login_unlocked';
            } elseif ($securityAction === 'block_ip') {
                $ipToBlock = trim((string)($_POST['blocked_ip'] ?? ''));
                $emailForBlock = trim((string)($_POST['email_attempted'] ?? ''));
                $alertIdForBlock = max(0, (int)($_POST['alert_id'] ?? 0));
                if (!filter_var($ipToBlock, FILTER_VALIDATE_IP)) {
                    throw new RuntimeException('The IP address is not valid, so it was not blocked.');
                }
                $blockStmt = $conn->prepare("INSERT INTO login_security_blocklist (blocked_ip, email_attempted, alert_id, blocked_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE email_attempted = VALUES(email_attempted), alert_id = VALUES(alert_id), blocked_by = VALUES(blocked_by), blocked_at = CURRENT_TIMESTAMP");
                $blockStmt->execute([$ipToBlock, $emailForBlock, $alertIdForBlock ?: null, (int)$_SESSION['user_id']]);
                $security_action_notice = 'ip_blocked';
            } elseif ($securityAction === 'unblock_ip') {
                $ipToUnblock = trim((string)($_POST['blocked_ip'] ?? ''));
                $unblockStmt = $conn->prepare("DELETE FROM login_security_blocklist WHERE blocked_ip = ?");
                $unblockStmt->execute([$ipToUnblock]);
                $security_action_notice = 'ip_unblocked';
            } elseif ($securityAction === 'delete_alert') {
                $alertId = max(0, (int)($_POST['alert_id'] ?? 0));
                $deleteStmt = $conn->prepare("DELETE FROM login_security_logs WHERE id = ? AND attempt_status = 'unknown_account_alert'");
                $deleteStmt->execute([$alertId]);
                $security_action_notice = 'alert_deleted';
            }
        } catch (Throwable $e) {
            error_log('NoteSphere security action failed: ' . $e->getMessage());
            $security_action_notice = 'security_action_error';
        }
    }
    if ($security_action_notice !== '') {
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?security_notice=' . rawurlencode($security_action_notice));
        exit();
    }
}

$login_lock_active = false;
$login_lock_reason = '';
$security_blocked_ips = [];
try {
    $lockStateStmt = $conn->query("SELECT login_locked, lock_reason FROM login_security_control WHERE id = 1 LIMIT 1");
    $lockState = $lockStateStmt->fetch(PDO::FETCH_ASSOC);
    $login_lock_active = (bool)($lockState['login_locked'] ?? false);
    $login_lock_reason = (string)($lockState['lock_reason'] ?? '');
    $blockedIpsStmt = $conn->query("SELECT blocked_ip, email_attempted, blocked_at FROM login_security_blocklist ORDER BY blocked_at DESC");
    $security_blocked_ips = $blockedIpsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('NoteSphere security controls read error: ' . $e->getMessage());
}

// =========================================================
// HELPER
// =========================================================

function e($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

// =========================================================
// ADMIN NAME
// ACTUAL USERS TABLE:
// user_id
// full_name
// email
// role
// =========================================================

$admin_name = 'Administrator';

try {

    $admin_stmt = $conn->prepare("
        SELECT full_name
        FROM users
        WHERE user_id = ?
        LIMIT 1
    ");

    $admin_stmt->execute([
        (int) $_SESSION['user_id']
    ]);

    $admin_data = $admin_stmt->fetch(PDO::FETCH_ASSOC);

    if (
        $admin_data &&
        !empty($admin_data['full_name'])
    ) {
        $admin_name = $admin_data['full_name'];
    }

} catch (PDOException $e) {

    // Use default administrator name
}

// =========================================================
// INITIAL VALUES
// =========================================================

$total_users = 0;
$total_announcements = 0;
$approved_notes = 0;
$pending_notes = 0;

$total_storage_tb = $default_system_storage_tb;
$student_storage_gb = $default_student_storage_gb;
$professor_storage_gb = $default_professor_storage_gb;
$max_users = $default_max_users;

$storage_error = '';
$storage_success = '';

// =========================================================
// CREATE SYSTEM STORAGE TABLE IF NEEDED
// =========================================================

try {

    $conn->exec("
        CREATE TABLE IF NOT EXISTS system_storage (

            id INT NOT NULL PRIMARY KEY,

            total_storage_tb
                DECIMAL(10,2)
                NOT NULL
                DEFAULT 10.00,

            student_storage_gb
                DECIMAL(10,2)
                NOT NULL
                DEFAULT 500.00,

            professor_storage_gb
                DECIMAL(10,2)
                NOT NULL
                DEFAULT 1000.00,

            updated_at
                TIMESTAMP
                DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP
        )
    ");

    // =====================================================
    // CHECK EXISTING COLUMNS
    // =====================================================

    $columns_stmt = $conn->query(
        "SHOW COLUMNS FROM system_storage"
    );

    $columns = $columns_stmt->fetchAll(
        PDO::FETCH_COLUMN
    );

    // =====================================================
    // TOTAL STORAGE
    // =====================================================

    if (!in_array(
        'total_storage_tb',
        $columns,
        true
    )) {

        $conn->exec("
            ALTER TABLE system_storage
            ADD COLUMN total_storage_tb
            DECIMAL(10,2)
            NOT NULL
            DEFAULT 10.00
        ");
    }

    // =====================================================
    // STUDENT STORAGE
    // =====================================================

    if (!in_array(
        'student_storage_gb',
        $columns,
        true
    )) {

        $conn->exec("
            ALTER TABLE system_storage
            ADD COLUMN student_storage_gb
            DECIMAL(10,2)
            NOT NULL
            DEFAULT 500.00
        ");
    }

    // =====================================================
    // PROFESSOR STORAGE
    // =====================================================

    if (!in_array(
        'professor_storage_gb',
        $columns,
        true
    )) {

        $conn->exec("
            ALTER TABLE system_storage
            ADD COLUMN professor_storage_gb
            DECIMAL(10,2)
            NOT NULL
            DEFAULT 1000.00
        ");
    }

    // =====================================================
    // DEFAULT STORAGE ROW
    // =====================================================

    $storage_insert = $conn->prepare("
        INSERT INTO system_storage (
            id,
            total_storage_tb,
            student_storage_gb,
            professor_storage_gb
        )
        VALUES (
            1,
            ?,
            ?,
            ?
        )
        ON DUPLICATE KEY UPDATE
            id = id
    ");

    $storage_insert->execute([
        $default_system_storage_tb,
        $default_student_storage_gb,
        $default_professor_storage_gb
    ]);

} catch (PDOException $e) {

    $storage_error =
        'System storage settings are not available.';
}

// =========================================================
// CREATE SYSTEM LIMITS TABLE
// =========================================================

try {

    $conn->exec("
        CREATE TABLE IF NOT EXISTS system_limits (

            id INT NOT NULL PRIMARY KEY,

            max_users
                INT
                NOT NULL
                DEFAULT 1000,

            updated_at
                TIMESTAMP
                DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP
        )
    ");

    $limit_insert = $conn->prepare("
        INSERT INTO system_limits (
            id,
            max_users
        )
        VALUES (
            1,
            ?
        )
        ON DUPLICATE KEY UPDATE
            id = id
    ");

    $limit_insert->execute([
        $default_max_users
    ]);

} catch (PDOException $e) {

    // Use default maximum users
}

// =========================================================
// GET TOTAL USERS
// =========================================================

try {

    $user_count_stmt = $conn->query("
        SELECT COUNT(*)
        FROM users
    ");

    $total_users = (int) $user_count_stmt->fetchColumn();

} catch (PDOException $e) {

    $total_users = 0;
}

// =========================================================
// MANAGE STORAGE / SYSTEM SETTINGS
// =========================================================

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['storage_action']) &&
    $_POST['storage_action'] === 'manage_storage'
) {

    // =====================================================
    // CSRF CHECK
    // =====================================================

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals(
            $_SESSION['csrf_token'],
            $_POST['csrf_token']
        )
    ) {

        $storage_error =
            'Invalid security token. Please try again.';

    } else {

        $add_system_storage_tb =
            isset($_POST['add_system_storage_tb'])
                ? (float) $_POST['add_system_storage_tb']
                : 0;

        $new_student_storage_gb =
            isset($_POST['student_storage_gb'])
                ? (float) $_POST['student_storage_gb']
                : 0;

        $new_professor_storage_gb =
            isset($_POST['professor_storage_gb'])
                ? (float) $_POST['professor_storage_gb']
                : 0;

        $new_max_users =
            isset($_POST['max_users'])
                ? (int) $_POST['max_users']
                : 0;

        // =================================================
        // VALIDATION
        // =================================================

        if ($add_system_storage_tb < 0) {

            $storage_error =
                'Additional system storage cannot be negative.';

        } elseif ($new_student_storage_gb <= 0) {

            $storage_error =
                'Student storage must be greater than 0 GB.';

        } elseif ($new_professor_storage_gb <= 0) {

            $storage_error =
                'Professor storage must be greater than 0 GB.';

        } elseif ($new_max_users <= 0) {

            $storage_error =
                'Maximum users must be greater than 0.';

        } elseif ($new_max_users > 1000000) {

            $storage_error =
                'Maximum users cannot be more than 1,000,000.';

        } elseif ($new_max_users < $total_users) {

            $storage_error =
                'Maximum users cannot be lower than the current number of users.';

        } else {

            try {

                $conn->beginTransaction();

                // =========================================
                // GET CURRENT STORAGE
                // =========================================

                $current_storage_stmt = $conn->query("
                    SELECT total_storage_tb
                    FROM system_storage
                    WHERE id = 1
                    LIMIT 1
                    FOR UPDATE
                ");

                $current_storage =
                    $current_storage_stmt->fetch(
                        PDO::FETCH_ASSOC
                    );

                if (!$current_storage) {

                    $insert_storage = $conn->prepare("
                        INSERT INTO system_storage (
                            id,
                            total_storage_tb,
                            student_storage_gb,
                            professor_storage_gb
                        )
                        VALUES (
                            1,
                            ?,
                            ?,
                            ?
                        )
                    ");

                    $insert_storage->execute([
                        $default_system_storage_tb,
                        $new_student_storage_gb,
                        $new_professor_storage_gb
                    ]);

                } else {

                    $current_total_storage =
                        (float) $current_storage[
                            'total_storage_tb'
                        ];

                    $new_total_storage =
                        $current_total_storage +
                        $add_system_storage_tb;

                    $update_storage = $conn->prepare("
                        UPDATE system_storage
                        SET
                            total_storage_tb = ?,
                            student_storage_gb = ?,
                            professor_storage_gb = ?
                        WHERE id = 1
                    ");

                    $update_storage->execute([
                        $new_total_storage,
                        $new_student_storage_gb,
                        $new_professor_storage_gb
                    ]);
                }

                // =========================================
                // MAX USERS
                // =========================================

                $update_limits = $conn->prepare("
                    INSERT INTO system_limits (
                        id,
                        max_users
                    )
                    VALUES (
                        1,
                        ?
                    )
                    ON DUPLICATE KEY UPDATE
                        max_users = VALUES(max_users)
                ");

                $update_limits->execute([
                    $new_max_users
                ]);

                // =========================================
                // COMMIT
                // =========================================

                $conn->commit();

                $storage_success =
                    'System settings updated successfully.';

            } catch (PDOException $e) {

                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }

                $storage_error =
                    'Unable to update system settings. Please try again.';
            }
        }
    }
}

// =========================================================
// GET CURRENT STORAGE SETTINGS
// =========================================================

try {

    $storage_stmt = $conn->query("
        SELECT
            total_storage_tb,
            student_storage_gb,
            professor_storage_gb
        FROM system_storage
        WHERE id = 1
        LIMIT 1
    ");

    $storage_data =
        $storage_stmt->fetch(PDO::FETCH_ASSOC);

    if ($storage_data) {

        $total_storage_tb =
            (float) $storage_data['total_storage_tb'];

        $student_storage_gb =
            (float) $storage_data['student_storage_gb'];

        $professor_storage_gb =
            (float) $storage_data['professor_storage_gb'];
    }

} catch (PDOException $e) {

    // Keep default values
}

// =========================================================
// GET MAXIMUM USERS
// =========================================================

try {

    $limit_stmt = $conn->query("
        SELECT max_users
        FROM system_limits
        WHERE id = 1
        LIMIT 1
    ");

    $limit_data =
        $limit_stmt->fetch(PDO::FETCH_ASSOC);

    if ($limit_data) {

        $max_users =
            (int) $limit_data['max_users'];
    }

} catch (PDOException $e) {

    $max_users =
        $default_max_users;
}

// =========================================================
// USER CAPACITY
// =========================================================

$remaining_user_slots =
    max(
        0,
        $max_users - $total_users
    );

$user_capacity_percentage = 0;

if ($max_users > 0) {

    $user_capacity_percentage =
        (
            $total_users /
            $max_users
        ) * 100;

    $user_capacity_percentage =
        min(
            100,
            max(
                0,
                $user_capacity_percentage
            )
        );
}

// =========================================================
// ANNOUNCEMENTS COUNT
// =========================================================

try {

    $announcement_stmt = $conn->query("
        SELECT COUNT(*)
        FROM announcements
    ");

    $total_announcements =
        (int) $announcement_stmt->fetchColumn();

} catch (PDOException $e) {

    $total_announcements = 0;
}

// =========================================================
// APPROVED NOTES
// =========================================================

try {

    $approved_notes_stmt = $conn->query("
        SELECT COUNT(*)
        FROM notes
        WHERE status = 'approved'
    ");

    $approved_notes =
        (int) $approved_notes_stmt->fetchColumn();

} catch (PDOException $e) {

    $approved_notes = 0;
}

// =========================================================
// PENDING NOTES
// =========================================================

try {

    $pending_notes_stmt = $conn->query("
        SELECT COUNT(*)
        FROM notes
        WHERE status = 'pending'
    ");

    $pending_notes =
        (int) $pending_notes_stmt->fetchColumn();

} catch (PDOException $e) {

    $pending_notes = 0;
}

// =========================================================
// STORAGE SCANNING
// =========================================================

$total_used_bytes = 0;

$known_files = [];

// =========================================================
// STORAGE DIRECTORIES
// =========================================================

$storage_directories = [

    __DIR__ . '/../uploads',

    __DIR__ . '/../assets/uploads',

    __DIR__ . '/../storage',

    __DIR__ . '/../files'

];

// =========================================================
// SCAN ACTUAL FILES
// =========================================================

foreach ($storage_directories as $directory) {

    if (!is_dir($directory)) {
        continue;
    }

    try {

        $iterator =
            new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $directory,
                    FilesystemIterator::SKIP_DOTS
                )
            );

        foreach ($iterator as $file) {

            if (!$file->isFile()) {
                continue;
            }

            $real_path =
                $file->getRealPath();

            if (!$real_path) {
                continue;
            }

            if (isset($known_files[$real_path])) {
                continue;
            }

            $known_files[$real_path] = true;

            $file_size =
                $file->getSize();

            if (
                $file_size !== false &&
                $file_size > 0
            ) {

                $total_used_bytes +=
                    $file_size;
            }
        }

    } catch (Exception $e) {

        // Ignore inaccessible folders
    }
}

// =========================================================
// CHECK FILES REFERENCED BY NOTES
// =========================================================

$note_file_columns = [

    'file_path',
    'filepath',
    'file',
    'file_url',
    'filename',
    'file_name'

];

try {

    $note_columns_stmt =
        $conn->query(
            "SHOW COLUMNS FROM notes"
        );

    $available_note_columns =
        $note_columns_stmt->fetchAll(
            PDO::FETCH_COLUMN
        );

    foreach (
        $note_file_columns as $file_column
    ) {

        if (!in_array(
            $file_column,
            $available_note_columns,
            true
        )) {
            continue;
        }

        $safe_column =
            str_replace(
                '`',
                '',
                $file_column
            );

        $file_stmt = $conn->query("
            SELECT `$safe_column`
            FROM notes
            WHERE `$safe_column` IS NOT NULL
            AND `$safe_column` <> ''
        ");

        $file_paths =
            $file_stmt->fetchAll(
                PDO::FETCH_COLUMN
            );

        foreach ($file_paths as $file_path) {

            if (
                !is_string($file_path) ||
                trim($file_path) === ''
            ) {
                continue;
            }

            $clean_path =
                ltrim(
                    trim($file_path),
                    '/\\'
                );

            $possible_paths = [

                __DIR__ .
                '/../' .
                $clean_path,

                __DIR__ .
                '/../uploads/' .
                basename($clean_path),

                __DIR__ .
                '/../assets/uploads/' .
                basename($clean_path),

                __DIR__ .
                '/../storage/' .
                basename($clean_path),

                __DIR__ .
                '/../files/' .
                basename($clean_path)

            ];

            foreach ($possible_paths as $possible_path) {

                if (!is_file($possible_path)) {
                    continue;
                }

                $real_path =
                    realpath($possible_path);

                if (!$real_path) {
                    continue;
                }

                if (isset($known_files[$real_path])) {
                    break;
                }

                $known_files[$real_path] = true;

                $file_size =
                    filesize($real_path);

                if (
                    $file_size !== false &&
                    $file_size > 0
                ) {

                    $total_used_bytes +=
                        $file_size;
                }

                break;
            }
        }
    }

} catch (PDOException $e) {

    // Ignore notes file errors
}

// =========================================================
// STORAGE UNITS
// =========================================================

$kb_in_bytes = 1024;

$mb_in_bytes =
    1024 * 1024;

$gb_in_bytes =
    1024 * 1024 * 1024;

$tb_in_bytes =
    1024 * 1024 * 1024 * 1024;

// =========================================================
// TOTAL CAPACITY
// =========================================================

$total_capacity_bytes =
    $total_storage_tb *
    $tb_in_bytes;

// =========================================================
// USED STORAGE
// =========================================================

$used_storage_tb =
    $total_used_bytes /
    $tb_in_bytes;

$used_storage_gb =
    $total_used_bytes /
    $gb_in_bytes;

$used_storage_mb =
    $total_used_bytes /
    $mb_in_bytes;

$used_storage_kb =
    $total_used_bytes /
    $kb_in_bytes;

// =========================================================
// AVAILABLE STORAGE
// =========================================================

$available_storage_tb =
    max(
        0,
        $total_storage_tb -
        $used_storage_tb
    );

// =========================================================
// USED STORAGE DISPLAY
// =========================================================

if ($total_used_bytes >= $tb_in_bytes) {

    $used_storage_display =
        number_format(
            $used_storage_tb,
            2
        ) . ' TB';

} elseif ($total_used_bytes >= $gb_in_bytes) {

    $used_storage_display =
        number_format(
            $used_storage_gb,
            2
        ) . ' GB';

} elseif ($total_used_bytes >= $mb_in_bytes) {

    $used_storage_display =
        number_format(
            $used_storage_mb,
            2
        ) . ' MB';

} elseif ($total_used_bytes >= $kb_in_bytes) {

    $used_storage_display =
        number_format(
            $used_storage_kb,
            2
        ) . ' KB';

} elseif ($total_used_bytes > 0) {

    $used_storage_display =
        number_format(
            $total_used_bytes,
            0
        ) . ' Bytes';

} else {

    $used_storage_display =
        '0 Bytes';
}

// =========================================================
// AVAILABLE STORAGE DISPLAY
// =========================================================

if ($available_storage_tb >= 1) {

    $available_storage_display =
        number_format(
            $available_storage_tb,
            2
        ) . ' TB';

} else {

    $available_storage_gb =
        $available_storage_tb * 1024;

    $available_storage_display =
        number_format(
            $available_storage_gb,
            2
        ) . ' GB';
}

// =========================================================
// STORAGE PERCENTAGE
// =========================================================

$storage_percentage = 0;

if ($total_capacity_bytes > 0) {

    $storage_percentage =
        (
            $total_used_bytes /
            $total_capacity_bytes
        ) * 100;

    $storage_percentage =
        min(
            100,
            max(
                0,
                $storage_percentage
            )
        );
}

// =========================================================
// STORAGE BAR
// =========================================================

$storage_bar_percentage =
    $storage_percentage;

if (
    $storage_bar_percentage > 0 &&
    $storage_bar_percentage < 0.08
) {

    $storage_bar_percentage = 0.08;
}

$storage_fill_width =
    number_format(
        $storage_bar_percentage,
        2,
        '.',
        ''
    );

$storage_fill_min_width =
    $storage_percentage > 0
        ? '4px'
        : '0';

// =========================================================
// STORAGE STATUS
// =========================================================

$storage_status = 'Available';

if ($storage_percentage >= 90) {

    $storage_status = 'Almost Full';

} elseif ($storage_percentage >= 75) {

    $storage_status = 'High Usage';

} elseif ($storage_percentage >= 50) {

    $storage_status = 'Moderate Usage';
}

// =========================================================
// USER CAPACITY BAR
// =========================================================

$user_capacity_width =
    number_format(
        $user_capacity_percentage,
        2,
        '.',
        ''
    );

// =========================================================
// ADMIN INITIAL
// =========================================================

$admin_initial = 'A';

$clean_admin_name =
    trim($admin_name);

if ($clean_admin_name !== '') {

    $admin_initial =
        strtoupper(
            substr(
                $clean_admin_name,
                0,
                1
            )
        );
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        NoteSphere - Admin Dashboard
    </title>

    <script>
        (function () {

            const savedTheme =
                localStorage.getItem(
                    'notesphere_admin_theme'
                );

            if (savedTheme === 'dark') {

                document.documentElement.classList.add(
                    'dark-mode'
                );
            }

        })();
    </script>

    <script
        src="https://cdn.jsdelivr.net/npm/sweetalert2@11"
    ></script>

    <style>

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {

            --primary: #233961;
            --primary-light: #3D8BFF;

            --background: #f5f7fb;
            --card: #ffffff;

            --text: #1f2937;
            --muted: #6b7280;

            --border: #e5e7eb;
            --sidebar: #ffffff;

            --success: #16a34a;
            --warning: #f59e0b;
            --danger: #dc2626;

            --shadow:
                0 8px 25px rgba(35, 57, 97, 0.08);
        }

        html.dark-mode {

            --background: #111827;
            --card: #1f2937;

            --text: #f9fafb;
            --muted: #9ca3af;

            --border: #374151;
            --sidebar: #182235;

            --shadow:
                0 8px 25px rgba(0, 0, 0, 0.25);
        }

        body {

            font-family:
                Arial,
                Helvetica,
                sans-serif;

            background: var(--background);
            background-image: url('../assets/Untitled design.png');
            background-size: cover;
            background-attachment: fixed;
            color: var(--text);

            min-height: 100vh;
        }

        button,
        input {
            font-family: inherit;
        }

        .dashboard-wrapper {

            display: flex;
            min-height: 100vh;
        }

        /* =====================================================
           SIDEBAR
        ====================================================== */

        .sidebar {

            width: 250px;

            background: var(--sidebar);

            border-right:
                1px solid var(--border);

            padding: 25px 18px;

            position: fixed;

            top: 0;
            bottom: 0;
            left: 0;

            z-index: 100;
        }

        .logo-section {

            display: flex;

            align-items: center;

            gap: 12px;

            padding:
                5px 10px 25px;

            border-bottom:
                1px solid var(--border);

            margin-bottom: 25px;
        }

        .logo-icon {

            width: 42px;
            height: 42px;

            border-radius: 12px;

            background: var(--primary);

            color: #ffffff;

            display: flex;

            align-items: center;
            justify-content: center;

            font-size: 20px;
            font-weight: bold;
        }

        .cdm-logo {

            font-size: 11px;

            font-weight: 800;

            letter-spacing: 0.5px;

            text-transform: uppercase;
        }

        .logo-text {

            font-size: 21px;
            font-weight: 700;

            color: var(--primary);
        }

        html.dark-mode .logo-text {
            color: #ffffff;
        }

        .admin-label {

            font-size: 13px;
            font-weight: 700;

            color: var(--text);

            padding:
                0 10px 4px;
        }

        .admin-role-label {

            font-size: 11px;

            color: var(--muted);

            padding:
                0 10px 18px;
        }

        .sidebar-menu {

            display: flex;

            flex-direction: column;

            gap: 7px;
        }

        .sidebar-link {

            display: flex;

            align-items: center;

            gap: 12px;

            padding:
                12px 14px;

            border-radius: 10px;

            color: var(--muted);

            text-decoration: none;

            font-size: 14px;

            transition: 0.2s ease;
        }

        .sidebar-link:hover {

            background:
                rgba(61, 139, 255, 0.08);

            color: var(--primary-light);
        }

        .sidebar-link.active {

            background: var(--primary);

            color: #ffffff;
        }

        .sidebar-icon {

            width: 22px;
            height: 22px;

            display: flex;

            align-items: center;
            justify-content: center;

            flex-shrink: 0;
        }

        .sidebar-icon svg {

            width: 19px;
            height: 19px;

            display: block;

            stroke: currentColor;
        }

        /* =====================================================
           MAIN CONTENT
        ====================================================== */

        .main-content {

            flex: 1;

            margin-left: 250px;

            min-width: 0;
        }

        /* =====================================================
           TOPBAR
        ====================================================== */

        .topbar {

            height: 76px;

            background: var(--card);

            border-bottom:
                1px solid var(--border);

            display: flex;

            align-items: center;

            justify-content: space-between;

            padding:
                0 30px;

            position: sticky;

            top: 0;

            z-index: 50;
        }

        .page-title h1 {

            font-size: 22px;

            color: var(--text);

            margin-bottom: 4px;
        }

        .page-title p {

            font-size: 12px;

            color: var(--muted);
        }

        .topbar-right {

            display: flex;

            align-items: center;

            gap: 18px;
        }

        .theme-button {

            width: 38px;
            height: 38px;

            border:
                1px solid var(--border);

            background: var(--card);

            color: var(--text);

            border-radius: 10px;

            cursor: pointer;

            font-size: 17px;

            display: flex;

            align-items: center;
            justify-content: center;
        }

        .theme-button:hover {

            background:
                rgba(61, 139, 255, 0.08);
        }

        .admin-profile {

            display: flex;

            align-items: center;

            gap: 10px;
        }

        .admin-avatar {

            width: 40px;
            height: 40px;

            border-radius: 50%;

            background: var(--primary);

            color: #ffffff;

            display: flex;

            align-items: center;
            justify-content: center;

            font-weight: bold;
            font-size: 15px;
        }

        .admin-profile-name {

            font-size: 13px;

            font-weight: 700;

            color: var(--text);

            max-width: 180px;

            white-space: nowrap;

            overflow: hidden;

            text-overflow: ellipsis;
        }

        .admin-profile-role {

            font-size: 11px;

            color: var(--muted);

            margin-top: 2px;
        }

        /* =====================================================
           CONTENT
        ====================================================== */

        .content {
            padding: 30px;
        }

        /* =====================================================
           STATS
        ====================================================== */

        .stats-grid {

            display: grid;

            grid-template-columns:
                repeat(4, 1fr);

            gap: 18px;

            margin-bottom: 22px;
        }

        .stat-card {

            background: var(--card);

            border:
                1px solid var(--border);

            border-radius: 15px;

            padding: 20px;

            box-shadow: var(--shadow);
        }

        .stat-top {

            display: flex;

            align-items: center;

            justify-content: space-between;

            margin-bottom: 14px;
        }

        .stat-title {

            font-size: 12px;

            color: var(--muted);

            font-weight: 600;
        }

        .stat-icon {

            width: 38px;
            height: 38px;

            border-radius: 10px;

            background:
                rgba(61, 139, 255, 0.1);

            color: var(--primary-light);

            display: flex;

            align-items: center;
            justify-content: center;

            font-size: 17px;
        }

        .stat-number {

            font-size: 28px;

            font-weight: 700;

            color: var(--text);

            margin-bottom: 5px;
        }

        .stat-limit {

            font-size: 11px;

            color: var(--muted);
        }

        /* =====================================================
           DASHBOARD GRID
        ====================================================== */

        .dashboard-grid {

            display: grid;

            grid-template-columns:
                1.4fr 1fr;

            gap: 22px;
        }

        .dashboard-card {

            background: var(--card);

            border:
                1px solid var(--border);

            border-radius: 15px;

            box-shadow: var(--shadow);

            overflow: hidden;
        }

        /* Login security alert card */
        .security-alert-card {
            border-color: rgba(239, 68, 68, .45);
            position: relative;
        }
        .security-alert-card .card-header {
            background: rgba(239, 68, 68, .07);
        }
        .security-alert-heading {
            display: flex;
            align-items: center;
            gap: 9px;
        }
        .security-alert-symbol {
            width: 35px;
            height: 35px;
            display: grid;
            place-items: center;
            flex: 0 0 auto;
            border-radius: 10px;
            color: #fff;
            background: #dc2626;
            font-size: 18px;
        }
        .security-alert-count {
            display: inline-flex;
            min-width: 24px;
            height: 24px;
            align-items: center;
            justify-content: center;
            padding: 0 7px;
            border-radius: 999px;
            background: #dc2626;
            color: #fff;
            font-size: 12px;
            font-weight: 800;
        }
        .security-alert-list { display: flex; flex-direction: column; gap: 10px; }
        .security-alert-item {
            padding: 11px 12px;
            border: 1px solid var(--border);
            border-left: 3px solid #dc2626;
            border-radius: 9px;
            background: var(--card);
            overflow-wrap: anywhere;
        }
        .security-alert-email { color: var(--text); font-weight: 800; font-size: 12px; }
        .security-alert-meta { color: var(--muted); font-size: 10px; margin-top: 5px; line-height: 1.5; }
        .security-alert-empty { color: var(--muted); font-size: 12px; line-height: 1.6; }
        .security-notification-button {
            border: 1px solid rgba(239, 68, 68, .45);
            border-radius: 8px;
            padding: 8px 10px;
            background: transparent;
            color: var(--text);
            font: inherit;
            font-size: 10px;
            font-weight: 800;
            cursor: pointer;
            white-space: nowrap;
        }
        .security-notification-button:hover { background: rgba(239, 68, 68, .1); }
        .login-lock-panel { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:12px; border-radius:10px; border:1px solid var(--border); }
        .login-lock-panel.is-locked { border-color:rgba(220,38,38,.55); background:rgba(220,38,38,.07); }
        .login-lock-panel.is-open { border-color:rgba(16,185,129,.4); background:rgba(16,185,129,.06); }
        .login-lock-copy { display:flex; flex-direction:column; gap:5px; min-width:0; }
        .login-lock-copy strong { color:var(--text); font-size:12px; }
        .login-lock-copy span { color:var(--muted); font-size:10px; line-height:1.5; }
        .security-lock-button,.security-action-button { border:0; border-radius:7px; padding:8px 10px; font:inherit; font-size:10px; font-weight:800; cursor:pointer; white-space:nowrap; }
        .security-lock-button.is-lock,.security-action-button.is-block { color:#fff; background:#dc2626; }
        .security-lock-button.is-unlock,.security-action-button.is-unblock { color:#fff; background:#059669; }
        .security-action-button.is-delete { color:#fff; background:#64748b; }
        .security-alert-actions { display:flex; flex-wrap:wrap; gap:7px; margin-top:10px; }
        .security-action-form { display:inline-flex; margin:0; }
        .security-blocked-section { margin-top:14px; padding-top:12px; border-top:1px solid var(--border); }
        .security-blocked-section h3 { display:flex; align-items:center; gap:7px; font-size:11px; margin-bottom:10px; color:var(--text); }
        .security-blocked-section h3 span { border-radius:999px; padding:2px 7px; background:rgba(220,38,38,.12); color:#dc2626; }
        .security-blocked-row { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:9px 0; border-bottom:1px solid var(--border); }
        .security-blocked-row strong { display:block; color:var(--text); font-size:11px; }
        .security-blocked-row small { display:block; color:var(--muted); font-size:9px; margin-top:3px; overflow-wrap:anywhere; }
        @media (max-width: 520px) { .login-lock-panel { align-items:stretch; flex-direction:column; } .security-lock-button { width:100%; } }

        .card-header {

            padding: 20px;

            border-bottom:
                1px solid var(--border);

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 15px;
        }

        .card-header h2 {

            font-size: 15px;

            color: var(--text);
        }

        .card-header p {

            font-size: 11px;

            color: var(--muted);

            margin-top: 4px;
        }

        .card-body {
            padding: 20px;
        }

        /* =====================================================
           STORAGE
        ====================================================== */

        .storage-main {

            display: flex;

            justify-content: space-between;

            align-items: flex-end;

            margin-bottom: 14px;
        }

        .storage-used {

            font-size: 26px;

            font-weight: 700;

            color: var(--text);

            margin-bottom: 3px;
        }

        .storage-total {

            font-size: 12px;

            color: var(--muted);
        }

        .storage-bar {

            width: 100%;

            height: 10px;

            background:
                rgba(107, 114, 128, 0.14);

            border-radius: 20px;

            overflow: hidden;

            margin-bottom: 10px;
        }

        .storage-fill {

            height: 100%;

            background: var(--primary);

            border-radius: inherit;

            transition:
                width 0.5s ease;
        }

        .storage-info {

            display: flex;

            justify-content: space-between;

            gap: 10px;

            font-size: 11px;

            color: var(--muted);
        }

        .storage-status {

            font-weight: 700;

            color: var(--success);
        }

        .storage-details {

            display: grid;

            grid-template-columns:
                repeat(3, 1fr);

            gap: 10px;

            margin-top: 20px;
        }

        .storage-detail {

            border:
                1px solid var(--border);

            border-radius: 10px;

            padding: 12px;
        }

        .storage-detail-label {

            font-size: 10px;

            color: var(--muted);

            margin-bottom: 5px;
        }

        .storage-detail-value {

            font-size: 15px;

            font-weight: 700;

            color: var(--text);
        }

        /* =====================================================
           SYSTEM INFORMATION
        ====================================================== */

        .info-row {

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 15px;

            padding: 3px 0;

            border-bottom:
                1px solid var(--border);
        }

        .info-row:last-of-type {
            border-bottom: none;
        }

        .info-label {

            font-size: 12px;

            color: var(--muted);
        }

        .info-value {

            font-size: 13px;

            font-weight: 700;

            color: var(--text);

            text-align: right;
        }

        /* =====================================================
           USER CAPACITY
        ====================================================== */

        .capacity-bar {

            width: 100%;

            height: 8px;

            background:
                rgba(107, 114, 128, 0.14);

            border-radius: 20px;

            overflow: hidden;

            margin-top: 15px;
        }

        .capacity-fill {

            height: 100%;

            background: var(--primary);

            border-radius: inherit;

            transition:
                width 0.5s ease;
        }

        .capacity-labels {

            display: flex;

            justify-content: space-between;

            margin-top: 8px;

            font-size: 10px;

            color: var(--muted);
        }

        /* =====================================================
           BUTTONS
        ====================================================== */

        .primary-button {

            border: none;

            background: var(--primary);

            color: #ffffff;

            padding:
                10px 15px;

            border-radius: 9px;

            font-size: 12px;

            font-weight: 600;

            cursor: pointer;

            transition: 0.2s ease;
        }

        .primary-button:hover {

            background: var(--primary-light);

            transform:
                translateY(-1px);
        }

        .secondary-button {

            border:
                1px solid var(--border);

            background: var(--card);

            color: var(--text);

            padding:
                10px 15px;

            border-radius: 9px;

            font-size: 12px;

            cursor: pointer;
        }

        /* =====================================================
           MODAL
        ====================================================== */

        .modal-overlay {

            position: fixed;

            inset: 0;

            background:
                rgba(0, 0, 0, 0.55);

            display: none;

            align-items: center;

            justify-content: center;

            padding: 20px;

            z-index: 999;
        }

        .modal-overlay.show {
            display: flex;
        }

        .modal {

            width: 100%;

            max-width: 560px;

            max-height: 90vh;

            overflow-y: auto;

            background: var(--card);

            border-radius: 16px;

            box-shadow:
                0 20px 60px
                rgba(0, 0, 0, 0.25);
        }

        .modal-header {

            display: flex;

            align-items: center;

            justify-content: space-between;

            padding: 20px;

            border-bottom:
                1px solid var(--border);
        }

        .modal-header h2 {

            font-size: 17px;

            color: var(--text);
        }

        .modal-close {

            width: 32px;
            height: 32px;

            border: none;

            border-radius: 8px;

            background:
                rgba(107, 114, 128, 0.1);

            color: var(--text);

            cursor: pointer;

            font-size: 17px;
        }

        .modal-body {
            padding: 20px;
        }

        .form-group {
            margin-bottom: 17px;
        }

        .form-group label {

            display: block;

            font-size: 12px;

            font-weight: 600;

            color: var(--text);

            margin-bottom: 7px;
        }

        .form-group input {

            width: 100%;

            padding:
                11px 12px;

            border:
                1px solid var(--border);

            border-radius: 9px;

            background: var(--background);

            color: var(--text);

            outline: none;

            font-size: 13px;
        }

        .form-group input:focus {

            border-color:
                var(--primary-light);
        }

        .form-help {

            font-size: 10px;

            color: var(--muted);

            margin-top: 5px;

            line-height: 1.5;
        }

        .modal-footer {

            padding:
                15px 20px 20px;

            display: flex;

            justify-content: flex-end;

            gap: 10px;
        }

        /* =====================================================
           MOBILE
        ====================================================== */

        .mobile-header {

            display: none;

            height: 65px;

            background: var(--card);

            border-bottom:
                1px solid var(--border);

            padding:
                0 18px;

            align-items: center;

            justify-content: space-between;

            position: sticky;

            top: 0;

            z-index: 80;
        }

        .mobile-logo {

            font-weight: 700;

            color: var(--primary);

            font-size: 19px;
        }

        html.dark-mode .mobile-logo {
            color: #ffffff;
        }

        /* =====================================================
           RESPONSIVE
        ====================================================== */

        @media (max-width: 1100px) {

            .stats-grid {

                grid-template-columns:
                    repeat(2, 1fr);
            }

            .dashboard-grid {

                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {

            .sidebar {
                display: none;
            }

            .main-content {
                margin-left: 0;
            }

            .topbar {
                display: none;
            }

            .mobile-header {
                display: flex;
            }

            .content {
                padding: 18px;
            }

            .stats-grid {

                grid-template-columns:
                    1fr 1fr;

                gap: 12px;
            }

            .stat-card {
                padding: 15px;
            }

            .stat-number {
                font-size: 23px;
            }

            .storage-details {
                grid-template-columns: 1fr;
            }

            .storage-info {

                flex-direction: column;

                gap: 5px;
            }
        }

        @media (max-width: 480px) {

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .card-header {

                align-items: flex-start;

                flex-direction: column;
            }

            .modal {
                max-height: 95vh;
            }
        }

    </style>

</head>

<body>

<div class="dashboard-wrapper">

    <!-- =====================================================
         SIDEBAR
    ====================================================== -->

    <aside class="sidebar">

        <div class="logo-section">

            <img src="../assets/cdmlogo.png" alt="CDM Logo" style="width:48px;height:auto;">

            <div class="logo-text">
                NoteSphere
            </div>

        </div>

        <div class="admin-label">
            <?php echo e($admin_name); ?>
        </div>

        <div class="admin-role-label">
            Administrator
        </div>

        <nav class="sidebar-menu">

            <!-- DASHBOARD -->

            <a
                href="admin_dashboard.php"
                class="sidebar-link active"
            >

                <span class="sidebar-icon">

                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <path d="M3 10.5L12 3l9 7.5"></path>
                        <path d="M5 9.5V21h14V9.5"></path>
                        <path d="M9 21v-7h6v7"></path>
                    </svg>

                </span>

                <span>
                    Dashboard
                </span>

            </a>

            <!-- MANAGE USERS -->

            <a
                href="manage_users.php"
                class="sidebar-link"
            >

                <span class="sidebar-icon">

                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                        <circle cx="9" cy="7" r="4"></circle>
                        <path d="M22 21v-2a4 4 0 0 0-3-3.87"></path>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                    </svg>

                </span>

                <span>
                    Manage Users
                </span>

            </a>

            <!-- MANAGE NOTES -->

            <a
                href="manage_notes.php"
                class="sidebar-link"
            >

                <span class="sidebar-icon">

                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <path d="M4 4h16v16H4z"></path>
                        <path d="M8 8h8"></path>
                        <path d="M8 12h8"></path>
                        <path d="M8 16h5"></path>
                    </svg>

                </span>

                <span>
                    Manage Notes
                </span>

            </a>

            <!-- HELPBOARD -->

            <a
                href="manage_helpboard.php"
                class="sidebar-link"
            >

                <span class="sidebar-icon">

                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <circle cx="12" cy="12" r="9"></circle>
                        <path d="M9.5 9a2.5 2.5 0 1 1 4.2 1.8c-.9.8-1.7 1.3-1.7 2.7"></path>
                        <path d="M12 17h.01"></path>
                    </svg>

                </span>

                <span>
                    Helpboard
                </span>

            </a>

            <!-- ANNOUNCEMENTS -->

            <a
                href="manage_announcements.php"
                class="sidebar-link"
            >

                <span class="sidebar-icon">

                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <path d="M4 11h4l9-5v12l-9-5H4z"></path>
                        <path d="M8 13v5"></path>
                        <path d="M20 9v6"></path>
                    </svg>

                </span>

                <span>
                    Announcements
                </span>

            </a>

<!-- USER SATISFACTION moved to card near warning alerts -->
            <!-- STORAGE -->

            <a
                href="manage_storage.php"
                class="sidebar-link"
            >

                <span class="sidebar-icon">

                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <ellipse cx="12" cy="5" rx="8" ry="3"></ellipse>
                        <path d="M4 5v7c0 1.7 3.6 3 8 3s8-1.3 8-3V5"></path>
                        <path d="M4 12v7c0 1.7 3.6 3 8 3s8-1.3 8-3v-7"></path>
                    </svg>

                </span>

                <span>
                    Academic Settings
                </span>

            </a>



            <!-- LOGOUT -->

            <a
                href="../auth/logout.php"
                class="sidebar-link"
                id="logoutLink"
            >

                <span class="sidebar-icon">

                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <path d="M10 17l5-5-5-5"></path>
                        <path d="M15 12H3"></path>
                        <path d="M21 3v18"></path>
                    </svg>

                </span>

                <span>
                    Logout
                </span>

            </a>

        </nav>

    </aside>

    <!-- =====================================================
         MAIN
    ====================================================== -->

    <main class="main-content">

        <!-- MOBILE HEADER -->

        <div class="mobile-header">

            <div class="mobile-logo">
                NoteSphere
            </div>

            <button
                type="button"
                class="theme-button"
                id="mobileThemeButton"
                title="Toggle theme"
            >
                ☾
            </button>

        </div>

        <!-- TOPBAR -->

        <header class="topbar">

            <div class="page-title">

                <h1>
                    Admin Dashboard
                </h1>

                <p>
                    Manage and monitor your NoteSphere system
                </p>

            </div>

            <div class="topbar-right">

                <button
                    type="button"
                    class="theme-button"
                    id="themeButton"
                    title="Toggle theme"
                >
                    ☾
                </button>



            </div>

        </header>

        <!-- CONTENT -->

        <section class="content">

            <!-- =================================================
                 STAT CARDS
            ================================================== -->

            <div class="stats-grid">

                <!-- USERS -->

                <div class="stat-card">

                    <div class="stat-top">

                        <div class="stat-title">
                            Total Users
                        </div>

                        <div class="stat-icon">
                            👥
                        </div>

                    </div>

                    <div class="stat-number">

                        <?php
                        echo number_format(
                            $total_users
                        );
                        ?>

                    </div>

                    <div class="stat-limit">

                        Maximum:
                        <?php
                        echo number_format(
                            $max_users
                        );
                        ?>
                        users

                    </div>

                </div>

                <!-- ANNOUNCEMENTS -->

                <div class="stat-card">

                    <div class="stat-top">

                        <div class="stat-title">
                            Announcements
                        </div>

                        <div class="stat-icon">
                            📢
                        </div>

                    </div>

                    <div class="stat-number">

                        <?php
                        echo number_format(
                            $total_announcements
                        );
                        ?>

                    </div>

                    <div class="stat-limit">
                        From professors
                    </div>

                </div>

                <!-- APPROVED NOTES -->

                <div class="stat-card">

                    <div class="stat-top">

                        <div class="stat-title">
                            Approved Notes
                        </div>

                        <div class="stat-icon">
                            ✓
                        </div>

                    </div>

                    <div class="stat-number">

                        <?php
                        echo number_format(
                            $approved_notes
                        );
                        ?>

                    </div>

                    <div class="stat-limit">
                        Available to users
                    </div>

                </div>

                <!-- PENDING NOTES -->

                <div class="stat-card">

                    <div class="stat-top">

                        <div class="stat-title">
                            Pending Notes
                        </div>

                        <div class="stat-icon">
                            ⏳
                        </div>

                    </div>

                    <div class="stat-number">

                        <?php
                        echo number_format(
                            $pending_notes
                        );
                        ?>

                    </div>

                    <div class="stat-limit">
                        Waiting for approval
                    </div>

                </div>

            </div>

            <!-- =================================================
                 DASHBOARD GRID
            ================================================== -->

            <div class="dashboard-grid">

                <!-- LOGIN SECURITY ALERTS (FIRST CARD FOR QUICK VISIBILITY) -->
                <div class="dashboard-card security-alert-card" id="securityAlertCard" data-latest-alert-id="<?php echo (int) $latest_security_alert_id; ?>">
                    <div class="card-header">
                        <div class="security-alert-heading">
                            <div class="security-alert-symbol" aria-hidden="true">⚠</div>
                            <div>
                                <h2>Security Warning Alerts</h2>
                                <p>Repeated attempts using unregistered emails</p>
                            </div>
                            <span class="security-alert-count" id="securityAlertCount"><?php echo number_format($security_alert_count); ?></span>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="login-lock-panel <?php echo $login_lock_active ? 'is-locked' : 'is-open'; ?>">
                            <div class="login-lock-copy">
                                <strong><?php echo $login_lock_active ? 'Login Page Temporarily Locked' : 'Login Page Is Open'; ?></strong>
                                <span><?php echo $login_lock_active ? 'Students and professors cannot sign in until you unlock access. Admin sign-in remains available to prevent admin lockout.' : 'Lock sign-in temporarily if repeated suspicious attempts are detected.'; ?></span>
                            </div>
                            <form method="post" id="loginLockForm">
                                <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                                <button type="submit" name="security_action" value="<?php echo $login_lock_active ? 'unlock_login' : 'lock_login'; ?>" class="security-lock-button <?php echo $login_lock_active ? 'is-unlock' : 'is-lock'; ?>" id="loginLockButton"><?php echo $login_lock_active ? 'Unlock Login Page' : 'Lock Login Page'; ?></button>
                            </form>
                        </div>
                        <div style="display:flex; justify-content:flex-end; margin:12px 0;">
                            <button type="button" class="security-notification-button" id="enableSecurityNotifications">Enable Device Notifications</button>
                        </div>
                        <div class="security-alert-list" id="securityAlertList" aria-live="polite">
                            <?php if (empty($security_alerts)): ?>
                                <div class="security-alert-empty" id="securityAlertEmpty">No third-attempt security alerts recorded in the log yet. New alerts will appear here automatically while this dashboard is open.</div>
                            <?php else: ?>
                                <?php foreach ($security_alerts as $security_alert): ?>
                                    <div class="security-alert-item" data-alert-id="<?php echo (int) $security_alert['id']; ?>">
                                        <div class="security-alert-email"><?php echo e($security_alert['email_attempted']); ?></div>
                                        <div class="security-alert-meta">
                                            IP: <?php echo e($security_alert['ip_address']); ?><br>
                                            <?php echo e($security_alert['created_at']); ?>
                                        </div>
                                        <div class="security-alert-actions">
                                            <?php $alertIpBlocked = false; foreach ($security_blocked_ips as $blockedEntry) { if ($blockedEntry['blocked_ip'] === $security_alert['ip_address']) { $alertIpBlocked = true; break; } } ?>
                                            <form method="post" class="security-action-form" data-confirm="<?php echo $alertIpBlocked ? 'unblock' : 'block'; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                                                <input type="hidden" name="alert_id" value="<?php echo (int)$security_alert['id']; ?>">
                                                <input type="hidden" name="blocked_ip" value="<?php echo e($security_alert['ip_address']); ?>">
                                                <input type="hidden" name="email_attempted" value="<?php echo e($security_alert['email_attempted']); ?>">
                                                <button type="submit" name="security_action" value="<?php echo $alertIpBlocked ? 'unblock_ip' : 'block_ip'; ?>" class="security-action-button <?php echo $alertIpBlocked ? 'is-unblock' : 'is-block'; ?>"><?php echo $alertIpBlocked ? 'Unblock IP' : 'Block IP'; ?></button>
                                            </form>
                                            <form method="post" class="security-action-form" data-confirm="delete">
                                                <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                                                <input type="hidden" name="alert_id" value="<?php echo (int)$security_alert['id']; ?>">
                                                <button type="submit" name="security_action" value="delete_alert" class="security-action-button is-delete">Delete Alert</button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="security-blocked-section">
                            <h3>Blocked IP Addresses <span><?php echo count($security_blocked_ips); ?></span></h3>
                            <?php if (empty($security_blocked_ips)): ?>
                                <p class="security-alert-empty">No IP addresses are currently blocked.</p>
                            <?php else: ?>
                                <?php foreach ($security_blocked_ips as $blockedEntry): ?>
                                    <div class="security-blocked-row">
                                        <div><strong><?php echo e($blockedEntry['blocked_ip']); ?></strong><small><?php echo e($blockedEntry['email_attempted'] ?? ''); ?> · <?php echo e($blockedEntry['blocked_at']); ?></small></div>
                                        <form method="post" class="security-action-form" data-confirm="unblock">
                                            <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                                            <input type="hidden" name="blocked_ip" value="<?php echo e($blockedEntry['blocked_ip']); ?>">
                                            <button type="submit" name="security_action" value="unblock_ip" class="security-action-button is-unblock">Unblock</button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- USER SATISFACTION RESULTS CARD -->
                <div class="dashboard-card">
                    <div class="card-header"><h2>User Satisfaction Level</h2></div>
                    <div class="card-body">
                        <?php
                        $satTotal=0; $satMean=0.0;
                        try{
                            $rs=$conn->query("SELECT COUNT(*) AS c, AVG(rating) AS m FROM user_satisfaction_ratings");
                            $r=$rs->fetch(PDO::FETCH_ASSOC);
                            $satTotal=(int)($r['c']??0);
                            $satMean=(float)($r['m']??0);
                        }catch(Throwable $e){}
                        $level = 'No ratings yet'; $levelColor = '#94a3b8';
                        if ($satTotal > 0) {
                            if ($satMean >= 4.5) { $level='Excellent'; $levelColor='#22c55e'; }
                            elseif ($satMean >= 3.5) { $level='Good'; $levelColor='#3b82f6'; }
                            elseif ($satMean >= 2.5) { $level='Fair'; $levelColor='#f59e0b'; }
                            else { $level='Poor'; $levelColor='#ef4444'; }
                        }
                        ?>
                        <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
                            <div style="font-size:2.2rem;font-weight:800;color:<?= $levelColor ?>;"><?= $satTotal?number_format($satMean,2):'—' ?></div>
                            <div style="text-align:left;">
                                <div style="font-size:1rem;font-weight:700;color:<?= $levelColor ?>;"><?= $level ?></div>
                                <div style="color:var(--muted);font-size:.85rem;"><?= $satTotal?$satTotal.' respondent(s)':'No ratings yet' ?></div>
                            </div>
                        </div>
                        <?php if ($satTotal > 0): ?>
                        <div style="display:flex;gap:4px;justify-content:center;margin-bottom:10px;">
                            <?php for ($i=1;$i<=5;$i++): ?>
                                <span style="font-size:1.4rem;color:<?= $i <= round($satMean) ? '#fbbf24' : '#334155' ?>;">★</span>
                            <?php endfor; ?>
                        </div>
                        <?php endif; ?>
                        <div style="color:var(--muted);margin-top:6px;"><?= $satTotal?$satTotal.' respondent(s) (ratings at logout)':'No ratings yet' ?></div>
                        <!-- removed link -->
                        <?php
                        try{
                            $cs=$conn->query("SELECT comments,user_role,submitted_at FROM user_satisfaction_ratings WHERE comments IS NOT NULL AND TRIM(comments)<>'' ORDER BY submitted_at DESC LIMIT 10");
                            $coms=$cs->fetchAll(PDO::FETCH_ASSOC);
                            if($coms){
                                echo '<div style="margin-top:12px;max-height:200px;overflow:auto;">';
                                foreach($coms as $c){
                                    echo '<div style="border-bottom:1px solid var(--border,#eee);padding:8px 0;font-size:.9rem;">';
                                    echo '<strong>'.htmlspecialchars($c['user_role']).'</strong>: '.htmlspecialchars($c['comments']);
                                    echo '<br><small style="color:var(--muted);">'.htmlspecialchars($c['submitted_at']).'</small>';
                                    echo '</div>';
                                }
                                echo '</div>';
                            }
                        }catch(Throwable $e){}
                        ?>
                    </div>
                </div>

                <!-- STORAGE -->

                <div class="dashboard-card">

                    <div class="card-header">

                        <div>

                            <h2>
                                System Storage
                            </h2>

                            <p>
                                Overall storage usage
                            </p>

                        </div>

                        <button
                            type="button"
                            class="primary-button"
                            id="openStorageModal"
                        >
                            Manage Storage
                        </button>

                    </div>

                    <div class="card-body">

                        <div class="storage-main">

                            <div>

                                <div class="storage-used">

                                    <?php
                                    echo e(
                                        $used_storage_display
                                    );
                                    ?>

                                </div>

                                <div class="storage-total">

                                    of
                                    <?php
                                    echo number_format(
                                        $total_storage_tb,
                                        2
                                    );
                                    ?>
                                    TB used

                                </div>

                            </div>

                        </div>

                        <div class="storage-info">

                            <span>

                                Used:
                                <?php
                                echo e(
                                    $used_storage_display
                                );
                                ?>

                            </span>

                            <span class="storage-status">

                                <?php
                                echo e(
                                    $storage_status
                                );
                                ?>

                            </span>


                        </div>

                        <div class="storage-details">

                            <div class="storage-detail">

                                <div class="storage-detail-label">
                                    System Storage
                                </div>

                                <div class="storage-detail-value">

                                    <?php
                                    echo number_format(
                                        $total_storage_tb,
                                        2
                                    );
                                    ?>
                                    TB

                                </div>

                            </div>

                            <div class="storage-detail">

                                <div class="storage-detail-label">
                                    Student Quota
                                </div>

                                <div class="storage-detail-value">

                                    <?php
                                    echo number_format(
                                        $student_storage_gb,
                                        2
                                    );
                                    ?>
                                    GB

                                </div>

                            </div>

                            <div class="storage-detail">

                                <div class="storage-detail-label">
                                    Professor Quota
                                </div>

                                <div class="storage-detail-value">

                                    <?php
                                    echo number_format(
                                        $professor_storage_gb,
                                        2
                                    );
                                    ?>
                                    GB

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

                <!-- SYSTEM INFORMATION -->

                <div class="dashboard-card">

                    <div class="card-header">

                        <div>

                            <h2>
                                System Information
                            </h2>

                            <p>
                                Current NoteSphere settings
                            </p>

                        </div>

                    </div>

                    <div class="card-body">

                        <div class="info-row">

                            <span class="info-label">
                                Administrator
                            </span>

                            <span class="info-value">
                                <?php
                                echo e(
                                    $admin_name
                                );
                                ?>
                            </span>

                        </div>

                        <div class="info-row">

                            <span class="info-label">
                                Maximum Users
                            </span>

                            <span class="info-value">

                                <?php
                                echo number_format(
                                    $max_users
                                );
                                ?>

                            </span>

                        </div>

                        <div class="info-row">

                            <span class="info-label">
                                Current Users
                            </span>

                            <span class="info-value">

                                <?php
                                echo number_format(
                                    $total_users
                                );
                                ?>

                            </span>

                        </div>

                        <div class="info-row">

                            <span class="info-label">
                                Remaining Slots
                            </span>

                            <span class="info-value">

                                <?php
                                echo number_format(
                                    $remaining_user_slots
                                );
                                ?>

                            </span>

                        </div>

                        </div>

                        <div class="capacity-labels">

                            <span>
                                <?php
                                echo number_format(
                                    $total_users
                                );
                                ?>
                            <span>

                                <?php
                                echo number_format(
                                    $max_users
                                );
                                ?>

                                maximum

                            </span>

                        </div>

                    </div>

                </div>

            </div>

        </section>

    </main>

</div>

<!-- =========================================================
     STORAGE MODAL
========================================================== -->

<div
    class="modal-overlay"
    id="storageModal"
>

    <div class="modal">

        <div class="modal-header">

            <h2>
                Manage System Settings
            </h2>

            <button
                type="button"
                class="modal-close"
                id="closeStorageModal"
            >
                ×
            </button>

        </div>

        <form
            method="POST"
            id="storageForm"
        >

            <input
                type="hidden"
                name="storage_action"
                value="manage_storage"
            >

            <input
                type="hidden"
                name="csrf_token"
                value="<?php echo e($csrf_token); ?>"
            >

            <div class="modal-body">

                <!-- SYSTEM STORAGE -->

                <div class="form-group">

                    <label for="add_system_storage_tb">
                        Add System Storage (TB)
                    </label>

                    <input
                        type="number"
                        id="add_system_storage_tb"
                        name="add_system_storage_tb"
                        min="0"
                        step="0.01"
                        value="0"
                    >

                    <div class="form-help">

                        Current system storage:
                        <?php
                        echo number_format(
                            $total_storage_tb,
                            2
                        );
                        ?>
                        TB.

                        Add additional storage if the
                        system is getting full.

                    </div>

                </div>

                <!-- STUDENT STORAGE -->

                <div class="form-group">

                    <label for="student_storage_gb">
                        Student Storage Quota (GB)
                    </label>

                    <input
                        type="number"
                        id="student_storage_gb"
                        name="student_storage_gb"
                        min="0.01"
                        step="0.01"
                        value="<?php
                            echo e(
                                number_format(
                                    $student_storage_gb,
                                    2,
                                    '.',
                                    ''
                                )
                            );
                        ?>"
                        required
                    >

                    <div class="form-help">

                        Maximum storage assigned to
                        each student.

                    </div>

                </div>

                <!-- PROFESSOR STORAGE -->

                <div class="form-group">

                    <label for="professor_storage_gb">
                        Professor Storage Quota (GB)
                    </label>

                    <input
                        type="number"
                        id="professor_storage_gb"
                        name="professor_storage_gb"
                        min="0.01"
                        step="0.01"
                        value="<?php
                            echo e(
                                number_format(
                                    $professor_storage_gb,
                                    2,
                                    '.',
                                    ''
                                )
                            );
                        ?>"
                        required
                    >

                    <div class="form-help">

                        Maximum storage assigned to
                        each professor.

                    </div>

                </div>

                <!-- MAX USERS -->

                <div class="form-group">

                    <label for="max_users">
                        Maximum System Users
                    </label>

                    <input
                        type="number"
                        id="max_users"
                        name="max_users"
                        min="<?php
                            echo max(
                                1,
                                $total_users
                            );
                        ?>"
                        max="1000000"
                        step="1"
                        value="<?php
                            echo e(
                                $max_users
                            );
                        ?>"
                        required
                    >

                    <div class="form-help">

                        Maximum number of accounts that
                        can exist in the NoteSphere system.

                        Current users:
                        <?php
                        echo number_format(
                            $total_users
                        );
                        ?>.

                    </div>

                </div>

                <!-- DEVICE NOTIFICATIONS -->

                <div class="form-group">

                    <label>
                        Device Notifications
                    </label>

                    <button
                        type="button"
                        class="security-notification-button"
                        id="storageEnableNotifications"
                    >
                        Enable Device Notifications
                    </button>

                    <div class="form-help">

                        Allow browser/device notifications for
                        security alerts and push updates.

                    </div>

                </div>

            </div>

            <div class="modal-footer">

                <button
                    type="button"
                    class="secondary-button"
                    id="cancelStorageModal"
                >
                    Cancel
                </button>

                <button
                    type="submit"
                    class="primary-button"
                >
                    Save Changes
                </button>

            </div>

        </form>

    </div>

</div>

<script>

    // ========================================================
    // THEME
    // ========================================================

    const themeButton =
        document.getElementById(
            'themeButton'
        );

    const mobileThemeButton =
        document.getElementById(
            'mobileThemeButton'
        );

    function updateThemeIcons() {

        const isDark =
            document.documentElement.classList.contains(
                'dark-mode'
            );

        if (themeButton) {

            themeButton.textContent =
                isDark ? '☀' : '☾';
        }

        if (mobileThemeButton) {

            mobileThemeButton.textContent =
                isDark ? '☀' : '☾';
        }
    }

    function toggleTheme() {

        const html =
            document.documentElement;

        const isDark =
            html.classList.toggle(
                'dark-mode'
            );

        localStorage.setItem(
            'notesphere_admin_theme',
            isDark ? 'dark' : 'light'
        );

        updateThemeIcons();
    }

    if (themeButton) {

        themeButton.addEventListener(
            'click',
            toggleTheme
        );
    }

    if (mobileThemeButton) {

        mobileThemeButton.addEventListener(
            'click',
            toggleTheme
        );
    }

    updateThemeIcons();

    // ========================================================
    // STORAGE MODAL
    // ========================================================

    const storageModal =
        document.getElementById(
            'storageModal'
        );

    const openStorageModal =
        document.getElementById(
            'openStorageModal'
        );

    const closeStorageModal =
        document.getElementById(
            'closeStorageModal'
        );

    const cancelStorageModal =
        document.getElementById(
            'cancelStorageModal'
        );

    function openModal() {

        if (storageModal) {

            storageModal.classList.add(
                'show'
            );
        }
    }

    function closeModal() {

        if (storageModal) {

            storageModal.classList.remove(
                'show'
            );
        }
    }

    if (openStorageModal) {

        openStorageModal.addEventListener(
            'click',
            openModal
        );
    }

    if (closeStorageModal) {

        closeStorageModal.addEventListener(
            'click',
            closeModal
        );
    }

    if (cancelStorageModal) {

        cancelStorageModal.addEventListener(
            'click',
            closeModal
        );
    }

    if (storageModal) {

        storageModal.addEventListener(
            'click',
            function (event) {

                if (
                    event.target ===
                    storageModal
                ) {

                    closeModal();
                }

            }
        );
    }

    // ========================================================
    // SAVE CONFIRMATION
    // ========================================================

    const storageForm =
        document.getElementById(
            'storageForm'
        );

    if (storageForm) {

        storageForm.addEventListener(
            'submit',
            function (event) {

                const addStorageInput =
                    document.getElementById(
                        'add_system_storage_tb'
                    );

                const studentStorageInput =
                    document.getElementById(
                        'student_storage_gb'
                    );

                const professorStorageInput =
                    document.getElementById(
                        'professor_storage_gb'
                    );

                const maxUsersInput =
                    document.getElementById(
                        'max_users'
                    );

                const addStorage =
                    parseFloat(
                        addStorageInput.value
                    ) || 0;

                const studentStorage =
                    parseFloat(
                        studentStorageInput.value
                    ) || 0;

                const professorStorage =
                    parseFloat(
                        professorStorageInput.value
                    ) || 0;

                const maxUsers =
                    parseInt(
                        maxUsersInput.value,
                        10
                    ) || 0;

                if (addStorage < 0) {

                    event.preventDefault();

                    Swal.fire({

                        icon: 'error',

                        title: 'Invalid Storage',

                        text:
                            'Additional system storage cannot be negative.'

                    });

                    return;
                }

                if (studentStorage <= 0) {

                    event.preventDefault();

                    Swal.fire({

                        icon: 'error',

                        title:
                            'Invalid Student Storage',

                        text:
                            'Student storage must be greater than 0 GB.'

                    });

                    return;
                }

                if (professorStorage <= 0) {

                    event.preventDefault();

                    Swal.fire({

                        icon: 'error',

                        title:
                            'Invalid Professor Storage',

                        text:
                            'Professor storage must be greater than 0 GB.'

                    });

                    return;
                }

                if (maxUsers <= 0) {

                    event.preventDefault();

                    Swal.fire({

                        icon: 'error',

                        title:
                            'Invalid User Limit',

                        text:
                            'Maximum users must be greater than 0.'

                    });

                    return;
                }

                if (maxUsers > 1000000) {

                    event.preventDefault();

                    Swal.fire({

                        icon: 'error',

                        title:
                            'Invalid User Limit',

                        text:
                            'Maximum users cannot be more than 1,000,000.'

                    });

                    return;
                }

                if (
                    maxUsers <
                    <?php echo (int) $total_users; ?>
                ) {

                    event.preventDefault();

                    Swal.fire({

                        icon: 'error',

                        title:
                            'Invalid User Limit',

                        text:
                            'Maximum users cannot be lower than the current number of users.'

                    });

                    return;
                }

                event.preventDefault();

                Swal.fire({

                    icon: 'question',

                    title:
                        'Save Changes?',

                    text:
                        'The storage settings and maximum user limit will be updated.',

                    showCancelButton: true,

                    confirmButtonText:
                        'Yes, Save Changes',

                    cancelButtonText:
                        'Cancel',

                    reverseButtons: true

                }).then(
                    function (result) {

                        if (
                            result.isConfirmed
                        ) {

                            storageForm.submit();
                        }

                    }
                );
            }
        );
    }

    // ========================================================
    // LOGOUT CONFIRMATION
    // ========================================================

    const logoutLink =
        document.getElementById(
            'logoutLink'
        );

    if (logoutLink) {

        logoutLink.addEventListener(
            'click',
            function (event) {

                event.preventDefault();

                const logoutUrl =
                    this.getAttribute(
                        'href'
                    );

                Swal.fire({

                    icon: 'question',

                    title:
                        'Logout?',

                    text:
                        'Are you sure you want to logout?',

                    showCancelButton: true,

                    confirmButtonText:
                        'Yes, Logout',

                    cancelButtonText:
                        'Cancel',

                    reverseButtons: true

                }).then(
                    function (result) {

                        if (
                            result.isConfirmed
                        ) {

                            window.location.href =
                                logoutUrl;
                        }

                    }
                );
            }
        );
    }

</script>

<?php if ($storage_success !== ''): ?>

<script>

    Swal.fire({

        icon: 'success',

        title: 'Success',

        text:
            <?php
            echo json_encode(
                $storage_success
            );
            ?>,

        confirmButtonText:
            'OK'

    });

</script>

<?php endif; ?>

<?php if ($storage_error !== ''): ?>

<script>

    Swal.fire({

        icon: 'error',

        title:
            'Something went wrong',

        text:
            <?php
            echo json_encode(
                $storage_error
            );
            ?>,

        confirmButtonText:
            'OK'

    });

</script>

<?php endif; ?>


<script>
(function () {
    'use strict';

    const alertCard = document.getElementById('securityAlertCard');
    const alertList = document.getElementById('securityAlertList');
    const alertCount = document.getElementById('securityAlertCount');
    const enableButton = document.getElementById('enableSecurityNotifications');
    const storageEnableButton = document.getElementById('storageEnableNotifications');
    if (!alertCard || !alertList || !alertCount || !enableButton) return;

    let latestAlertId = parseInt(alertCard.dataset.latestAlertId || '0', 10) || 0;
    let displayedCount = parseInt((alertCount.textContent || '0').replace(/,/g, ''), 10) || 0;
    let notificationEnabled = ('Notification' in window && Notification.permission === 'granted');

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (char) {
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[char];
        });
    }

    function setNotificationButtonText(button, text) {
        if (button) button.textContent = text;
    }

    function updateNotificationButton() {
        if (!('Notification' in window)) {
            setNotificationButtonText(enableButton, 'Notifications Not Supported');
            setNotificationButtonText(storageEnableButton, 'Notifications Not Supported');
            if (enableButton) enableButton.disabled = true;
            if (storageEnableButton) storageEnableButton.disabled = true;
            return;
        }
        if (Notification.permission === 'granted' && notificationEnabled) {
            setNotificationButtonText(enableButton, 'Device Notifications Enabled');
            setNotificationButtonText(storageEnableButton, 'Device Notifications Enabled');
        } else if (Notification.permission === 'denied') {
            setNotificationButtonText(enableButton, 'Notifications Blocked in Browser');
            setNotificationButtonText(storageEnableButton, 'Notifications Blocked in Browser');
        } else {
            setNotificationButtonText(enableButton, 'Enable Device Notifications');
            setNotificationButtonText(storageEnableButton, 'Enable Device Notifications');
        }
    }

    function bindSecurityActionForms(root) {
        const scope = root || document;
        scope.querySelectorAll('.security-action-form:not([data-bound="1"])').forEach(function (actionForm) {
            actionForm.dataset.bound = '1';
            actionForm.addEventListener('submit', function (event) {
                event.preventDefault();
                const submitter = event.submitter;
                const action = submitter ? submitter.value : '';
                const label = action === 'block_ip' ? 'Block this IP address?' : action === 'unblock_ip' ? 'Unblock this IP address?' : action === 'delete_alert' ? 'Delete this security alert? This only removes the alert record.' : 'Change login access?';
                const text = action === 'block_ip' ? 'This IP will be prevented from signing in to student/professor accounts. Admin sign-in is preserved.' : action === 'unblock_ip' ? 'This IP will be allowed to try signing in again.' : 'This action cannot be undone.';
                const submitAction = function () {
                    const hiddenAction = document.createElement('input');
                    hiddenAction.type = 'hidden'; hiddenAction.name = 'security_action'; hiddenAction.value = action;
                    actionForm.appendChild(hiddenAction);
                    HTMLFormElement.prototype.submit.call(actionForm);
                };
                if (window.Swal) {
                    Swal.fire({icon: action === 'delete_alert' ? 'warning' : 'question', title: label, text: text, showCancelButton: true, confirmButtonText: 'Continue', cancelButtonText: 'Cancel', confirmButtonColor: action === 'delete_alert' ? '#64748b' : '#dc2626', background: getComputedStyle(document.body).getPropertyValue('--card') || '#fff', color: getComputedStyle(document.body).getPropertyValue('--text') || '#111'}).then(function(result) { if (result.isConfirmed) submitAction(); });
                } else if (window.confirm(label + '\n' + text)) {
                    submitAction();
                }
            });
        });
    }
    bindSecurityActionForms(document);

    const lockForm = document.getElementById('loginLockForm');
    if (lockForm) {
        lockForm.addEventListener('submit', function(event) {
            event.preventDefault();
            const button = event.submitter;
            const action = button ? button.value : '';
            const isLocking = action === 'lock_login';
            Swal.fire({icon: isLocking ? 'warning' : 'question', title: isLocking ? 'Temporarily lock login page?' : 'Unlock login page?', text: isLocking ? 'Students and professors will see a temporary lock notice and cannot sign in until you unlock access. Admin sign-in remains available.' : 'Students and professors will be able to sign in again.', showCancelButton:true, confirmButtonText:isLocking ? 'Lock Login' : 'Unlock Login', cancelButtonText:'Cancel', confirmButtonColor:isLocking ? '#dc2626' : '#059669', background:'#fff', color:'#111'}).then(function(result) {
                if (result.isConfirmed) {
                    const hiddenAction = document.createElement('input'); hiddenAction.type='hidden'; hiddenAction.name='security_action'; hiddenAction.value=action; lockForm.appendChild(hiddenAction);
                    HTMLFormElement.prototype.submit.call(lockForm);
                }
            });
        });
    }

    const noticeParams = new URLSearchParams(window.location.search);
    const notice = noticeParams.get('security_notice');
    const notices = {
        login_locked: ['warning','Login Temporarily Locked','Student and professor sign-in has been paused. A notice will appear on the login page.'],
        login_unlocked: ['success','Login Page Unlocked','Students and professors can sign in again.'],
        ip_blocked: ['success','IP Address Blocked','Sign-in attempts from this IP are blocked for student and professor accounts.'],
        ip_unblocked: ['success','IP Address Unblocked','This IP can attempt to sign in again.'],
        alert_deleted: ['success','Alert Deleted','The selected security alert record was deleted.'],
        security_action_error: ['error','Action Failed','The security action could not be completed. Check the PHP error log.']
    };
    if (notice && notices[notice] && window.Swal) {
        Swal.fire({icon:notices[notice][0], title:notices[notice][1], text:notices[notice][2], confirmButtonColor:'#233961'});
        window.history.replaceState({}, document.title, window.location.pathname);
    }

    function urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);
        for (let i = 0; i < rawData.length; i++) outputArray[i] = rawData.charCodeAt(i);
        return outputArray;
    }

    const currentUserId = <?php echo (int)$user_id; ?>;

    async function requestDeviceNotifications() {
        if (!('Notification' in window)) {
            if (window.Swal) Swal.fire({icon:'info', title:'Not Supported', text:'This browser does not support device notifications.'});
            return;
        }
        try {
            const permission = await Notification.requestPermission();
            notificationEnabled = permission === 'granted';
            updateNotificationButton();
            if (permission === 'granted') {
                try {
                    const registration = await navigator.serviceWorker.ready;
                    const vapidResponse = await fetch('../api/push_proxy.php?endpoint=vapid-public', { cache: 'no-store' });
                    const vapid = await vapidResponse.json();
                    if (vapid && vapid.publicKey) {
                        const subscription = await registration.pushManager.subscribe({
                            userVisibleOnly: true,
                            applicationServerKey: urlBase64ToUint8Array(vapid.publicKey)
                        });
                        await fetch('../api/push_proxy.php?endpoint=subscribe', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                            body: JSON.stringify({ subscription: subscription, user: currentUserId })
                        });
                    }
                } catch (pushError) {
                    console.warn('Push subscription could not be completed. Browser notifications still work while NoteSphere is open.', pushError);
                }
                if (window.Swal) Swal.fire({icon:'success', title:'Notifications Enabled', text:'Device notifications are enabled. Keep the push server running for alerts while NoteSphere is closed.'});
            } else if (permission === 'denied') {
                if (window.Swal) Swal.fire({icon:'warning', title:'Permission Blocked', text:'Allow notifications for NoteSphere in your browser or device settings.'});
            }
        } catch (error) {
            console.error('Unable to request notification permission:', error);
        }
    }

    if (enableButton) enableButton.addEventListener('click', requestDeviceNotifications);
    if (storageEnableButton) storageEnableButton.addEventListener('click', requestDeviceNotifications);

    function addAlert(alert) {
        const empty = document.getElementById('securityAlertEmpty');
        if (empty) empty.remove();
        const item = document.createElement('div');
        item.className = 'security-alert-item';
        item.dataset.alertId = String(alert.id);
        item.innerHTML = '<div class="security-alert-email">' + escapeHtml(alert.email_attempted) + '</div>' +
            '<div class="security-alert-meta">IP: ' + escapeHtml(alert.ip_address) + '<br>' + escapeHtml(alert.created_at) + '</div>' +
            '<div class="security-alert-actions">' +
            '<form method="post" class="security-action-form" data-confirm="block">' +
            '<input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">' +
            '<input type="hidden" name="alert_id" value="' + escapeHtml(alert.id) + '">' +
            '<input type="hidden" name="blocked_ip" value="' + escapeHtml(alert.ip_address) + '">' +
            '<input type="hidden" name="email_attempted" value="' + escapeHtml(alert.email_attempted) + '">' +
            '<button type="submit" name="security_action" value="block_ip" class="security-action-button is-block">Block IP</button></form>' +
            '<form method="post" class="security-action-form" data-confirm="delete">' +
            '<input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">' +
            '<input type="hidden" name="alert_id" value="' + escapeHtml(alert.id) + '">' +
            '<button type="submit" name="security_action" value="delete_alert" class="security-action-button is-delete">Delete Alert</button></form></div>';
        bindSecurityActionForms(item);
        alertList.prepend(item);
        while (alertList.querySelectorAll('.security-alert-item').length > 5) {
            const items = alertList.querySelectorAll('.security-alert-item');
            items[items.length - 1].remove();
        }
        latestAlertId = Math.max(latestAlertId, parseInt(alert.id, 10) || 0);
        alertCard.dataset.latestAlertId = String(latestAlertId);
        displayedCount += 1;
        alertCount.textContent = displayedCount.toLocaleString();

        if ('Notification' in window && Notification.permission === 'granted' && notificationEnabled) {
            try {
                const notice = new Notification('NoteSphere Security Warning', {
                    body: 'Third failed attempt with unregistered email: ' + alert.email_attempted,
                    tag: 'notesphere-security-' + alert.id,
                    icon: '../assets/logo.png'
                });
                notice.onclick = function () {
                    window.focus();
                    notice.close();
                };
            } catch (error) {
                console.error('Could not display security notification:', error);
            }
        }
    }

    async function pollSecurityAlerts() {
        try {
            const response = await fetch(window.location.pathname + '?security_alerts_json=1&after_id=' + encodeURIComponent(latestAlertId), {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {'Accept': 'application/json'}
            });
            if (!response.ok) return;
            const data = await response.json();
            if (!data || !data.ok || !Array.isArray(data.alerts)) return;
            data.alerts.forEach(addAlert);
        } catch (error) {
            console.debug('Security alert polling is temporarily unavailable.', error);
        }
    }

    async function pollLoginLockStatus() {
        try {
            const response = await fetch(window.location.pathname + '?security_status_json=1', {credentials:'same-origin', cache:'no-store', headers:{'Accept':'application/json'}});
            if (!response.ok) return;
            const data = await response.json();
            if (!data || !data.ok) return;
            const wasLocked = alertCard.dataset.loginLocked === '1';
            alertCard.dataset.loginLocked = data.login_locked ? '1' : '0';
            if (wasLocked !== data.login_locked) {
                const title = data.login_locked ? 'Login Page Locked' : 'Login Page Unlocked';
                const body = data.login_locked ? 'Student and professor sign-in is temporarily paused.' : 'Student and professor sign-in has been restored.';
                if ('Notification' in window && Notification.permission === 'granted' && notificationEnabled) {
                    try { new Notification('NoteSphere Security Control', {body:body, tag:'notesphere-login-lock-state'}); } catch (e) {}
                }
                if (window.Swal) Swal.fire({icon:data.login_locked ? 'warning' : 'success', title:title, text:body, confirmButtonColor:'#233961'});
                window.location.reload();
            }
        } catch (error) { console.debug('Login lock status check unavailable.', error); }
    }

    alertCard.dataset.loginLocked = alertCard.dataset.loginLocked || '<?php echo $login_lock_active ? '1' : '0'; ?>';
    updateNotificationButton();
    // Poll while this admin dashboard is open so repeated suspicious login
    // attempts are shown without requiring a manual page refresh.
    window.setInterval(pollSecurityAlerts, 15000);
    window.setInterval(pollLoginLockStatus, 10000);
})();
</script>

<script>
(function () {
    var path = window.location.pathname.split('/').pop() || 'admin_dashboard.php';
    document.querySelectorAll('.sidebar-link').forEach(function (link) {
        var href = (link.getAttribute('href') || '').split('/').pop().split('?')[0];
        if (href && href === path) {
            link.classList.add('active');
        } else {
            link.classList.remove('active');
        }
    });
})();
</script>
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
        navigator.serviceWorker.register('../sw.js', { scope: '../' }).catch(function (error) {
            console.warn('Service worker registration failed:', error);
        });
    });
}
</script>
</body>
</html>