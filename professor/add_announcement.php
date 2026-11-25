<?php

session_start();

require_once __DIR__ . '/../includes/db_config.php';

/** @var PDO $conn */

// =========================================================
// PROFESSOR SECURITY CHECK
// =========================================================

if (
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'professor'
) {
    header("Location: ../auth/login.php");
    exit();
}

$prof_id = (int) ($_SESSION['user_id'] ?? 0);

if ($prof_id <= 0) {
    header("Location: ../auth/login.php");
    exit();
}

$current_page = basename($_SERVER['PHP_SELF']);

$success_msg = '';
$error_msg = '';


// =========================================================
// FLASH MESSAGE HELPER
// =========================================================

function setFlash($type, $message)
{
    $_SESSION['announcement_flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

if (isset($_SESSION['announcement_flash'])) {

    $flash = $_SESSION['announcement_flash'];

    if (($flash['type'] ?? '') === 'success') {
        $success_msg = $flash['message'] ?? '';
    } else {
        $error_msg = $flash['message'] ?? '';
    }

    unset($_SESSION['announcement_flash']);
}

// PHP discards the entire POST body when Content-Length exceeds post_max_size.
// Detect that case so the page can show an actionable message instead of silently doing nothing.
function ns_parse_ini_bytes($value)
{
    $value = trim((string) $value);
    if ($value === '') return 0;
    $unit = strtolower(substr($value, -1));
    $number = (float) $value;
    if ($unit === 'g') $number *= 1024;
    if ($unit === 'g' || $unit === 'm') $number *= 1024;
    if (in_array($unit, ['g', 'm', 'k'], true)) {
        if ($unit === 'k') $number *= 1024;
        return (int) $number;
    }
    return (int) $number;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postLimit = ns_parse_ini_bytes(ini_get('post_max_size'));
    if ($contentLength > 0 && $postLimit > 0 && $contentLength > $postLimit) {
        $error_msg = 'The upload request (' . ns_format_bytes($contentLength) .
            ') exceeds the PHP post_max_size limit (' . e(ini_get('post_max_size')) .
            '). Increase post_max_size in the active XAMPP php.ini, make it larger than upload_max_filesize, then restart Apache and retry.';
    }
}


// =========================================================
// UPLOAD SETTINGS
// =========================================================

$upload_dir = __DIR__ . '/../uploads/announcements/';

if (!is_dir($upload_dir)) {
    @mkdir($upload_dir, 0777, true);
}


// =========================================================
// HELPER FUNCTIONS
// =========================================================

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Render safe, browser-supported previews for common image/video/audio attachments. */
function ns_render_announcement_attachment(array $attachment): string
{
    $storedPath = trim((string) ($attachment['file_path'] ?? ''));
    if ($storedPath === '') return '';

    $url = '../' . ltrim(str_replace('\\', '/', $storedPath), '/');
    $url = e($url);
    $name = (string) ($attachment['original_name'] ?? basename($storedPath));
    $safeName = e($name);
    $extension = strtolower(pathinfo($storedPath, PATHINFO_EXTENSION));
    $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'avif'];
    $videoExtensions = ['mp4', 'webm', 'ogv', 'ogg', 'mov', 'm4v'];
    $audioExtensions = ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac'];

    if (in_array($extension, $imageExtensions, true)) {
        return '<div class="attachment-preview-media"><a href="' . $url . '" target="_blank" rel="noopener noreferrer"><img src="' . $url . '" alt="' . $safeName . '" loading="lazy"></a><div class="attachment-preview-name">📷 ' . $safeName . '</div></div>';
    }
    if (in_array($extension, $videoExtensions, true)) {
        return '<div class="attachment-preview-media"><video controls preload="metadata"><source src="' . $url . '">Your browser does not support embedded video playback.</video><div class="attachment-preview-name">🎬 ' . $safeName . ' · <a href="' . $url . '" target="_blank" rel="noopener noreferrer">Open file</a></div></div>';
    }
    if (in_array($extension, $audioExtensions, true)) {
        return '<div class="attachment-preview-media"><audio controls preload="metadata"><source src="' . $url . '">Your browser does not support audio playback.</audio><div class="attachment-preview-name">🎵 ' . $safeName . '</div></div>';
    }

    return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer" class="attachment-badge">📎 ' . $safeName . '</a>';
}

function deleteAnnouncementFile($relativePath)
{
    if (empty($relativePath)) {
        return;
    }

    $relativePath = str_replace('\\', '/', trim($relativePath));

    $filename = basename($relativePath);

    if ($filename === '' || $filename === '.' || $filename === '..') {
        return;
    }

    $baseDir = realpath(__DIR__ . '/../uploads/announcements');

    if (!$baseDir) {
        return;
    }

    $filePath = realpath(
        $baseDir . DIRECTORY_SEPARATOR . $filename
    );

    if (
        $filePath &&
        is_file($filePath) &&
        strpos(
            $filePath,
            $baseDir . DIRECTORY_SEPARATOR
        ) === 0
    ) {
        @unlink($filePath);
    }
}


// =========================================================
// DYNAMIC STORAGE HELPERS
// Uses Admin settings from system_storage.
// Announcement attachments are included in the professor quota.
// =========================================================

function ns_storage_settings(PDO $conn)
{
    $stmt = $conn->query("\n        SELECT\n            total_storage_tb,\n            student_storage_gb,\n            professor_storage_gb\n        FROM system_storage\n        WHERE id = 1\n        LIMIT 1\n    ");

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new Exception(
            "System storage settings are not available. Please ask the administrator to configure storage first."
        );
    }

    $total_tb = (float) $row['total_storage_tb'];
    $professor_gb = (float) $row['professor_storage_gb'];

    if ($total_tb <= 0 || $professor_gb <= 0) {
        throw new Exception(
            "The configured storage quota is invalid. Please ask the administrator to check the storage settings."
        );
    }

    return [
        'total_storage_tb' => $total_tb,
        'professor_storage_gb' => $professor_gb,
        'system_limit_bytes' => $total_tb * 1024 * 1024 * 1024 * 1024,
        'professor_limit_bytes' => $professor_gb * 1024 * 1024 * 1024
    ];
}

function ns_resolve_storage_file($storedPath)
{
    if (empty($storedPath)) {
        return null;
    }

    $path = str_replace('\\', '/', trim((string) $storedPath));

    if ($path === '' || preg_match('/^(https?:\\/\\/|www\\.)/i', $path)) {
        return null;
    }

    // Windows absolute path.
    if (preg_match('/^[A-Za-z]:\\//', $path)) {
        $real = realpath($path);
        return ($real && is_file($real)) ? $real : null;
    }

    $relative = ltrim($path, '/');

    $candidates = [
        __DIR__ . '/../' . $relative,
        __DIR__ . '/../uploads/' . basename($relative),
        __DIR__ . '/../storage/' . basename($relative),
        __DIR__ . '/../files/' . basename($relative),
        __DIR__ . '/../assets/uploads/' . basename($relative)
    ];

    foreach ($candidates as $candidate) {
        $real = realpath($candidate);

        if ($real && is_file($real)) {
            return $real;
        }
    }

    return null;
}

function ns_add_physical_file(&$known, &$total, $path)
{
    if (!$path || !is_file($path)) {
        return;
    }

    $real = realpath($path);

    if (!$real || isset($known[$real])) {
        return;
    }

    $size = @filesize($real);

    if ($size === false || $size < 0) {
        return;
    }

    $known[$real] = true;
    $total += (int) $size;
}

function ns_get_professor_used_bytes(PDO $conn, $prof_id)
{
    $total = 0;
    $known = [];

    // Professor note files + thumbnails.
    try {
        $stmt = $conn->prepare("\n            SELECT file_path, thumbnail\n            FROM notes\n            WHERE user_id = ?\n        ");

        $stmt->execute([(int) $prof_id]);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            ns_add_physical_file(
                $known,
                $total,
                ns_resolve_storage_file($row['file_path'] ?? '')
            );

            ns_add_physical_file(
                $known,
                $total,
                ns_resolve_storage_file($row['thumbnail'] ?? '')
            );
        }
    } catch (Exception $e) {
        // Notes are optional for this calculation. Announcements below
        // are still counted.
    }

    // Professor announcement attachments.
    $stmt = $conn->prepare("\n        SELECT attachment\n        FROM announcements\n        WHERE professor_id = ?\n          AND attachment IS NOT NULL\n          AND attachment <> ''\n    ");

    $stmt->execute([(int) $prof_id]);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        ns_add_physical_file(
            $known,
            $total,
            ns_resolve_storage_file($row['attachment'] ?? '')
        );
    }

    // Additional files attached to announcements.
    try {
        $stmt = $conn->prepare("SELECT aa.file_path FROM announcement_attachments aa INNER JOIN announcements a ON a.id = aa.announcement_id WHERE a.professor_id = ?");
        $stmt->execute([(int) $prof_id]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            ns_add_physical_file($known, $total, ns_resolve_storage_file($row['file_path'] ?? ''));
        }
    } catch (Exception $e) {
        // The additional attachment table may not exist until SQL setup is run.
    }

    return $total;
}

function ns_get_system_used_bytes()
{
    $total = 0;
    $known = [];

    $directories = [
        __DIR__ . '/../uploads',
        __DIR__ . '/../assets/uploads',
        __DIR__ . '/../storage',
        __DIR__ . '/../files'
    ];

    foreach ($directories as $directory) {
        if (!is_dir($directory)) {
            continue;
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $directory,
                    FilesystemIterator::SKIP_DOTS
                )
            );

            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                ns_add_physical_file(
                    $known,
                    $total,
                    $file->getPathname()
                );
            }
        } catch (Exception $e) {
            // Ignore an inaccessible storage directory and continue scanning.
        }
    }

    return $total;
}

function ns_format_bytes($bytes)
{
    $bytes = max(0, (float) $bytes);

    if ($bytes >= 1024 * 1024 * 1024) {
        return number_format($bytes / (1024 * 1024 * 1024), 2) . ' GB';
    }

    if ($bytes >= 1024 * 1024) {
        return number_format($bytes / (1024 * 1024), 2) . ' MB';
    }

    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    }

    return number_format($bytes, 0) . ' B';
}

function ns_validate_announcement_upload($file)
{
    if (!is_array($file) || !isset($file['tmp_name'], $file['name'])) {
        throw new Exception("Invalid attachment upload.");
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $code = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
            throw new Exception("The file exceeds the server's upload limit. Increase upload_max_filesize and post_max_size in PHP settings.");
        }
        if ($code === UPLOAD_ERR_PARTIAL) {
            throw new Exception("The file was only partially uploaded. Please try again.");
        }
        throw new Exception("There was a problem uploading the attachment. PHP upload error: " . $code);
    }

    $file_size = (int) ($file['size'] ?? 0);
    if ($file_size <= 0 || !is_uploaded_file($file['tmp_name'])) {
        throw new Exception("The attachment is empty or invalid.");
    }
    if ($file_size > 1024 * 1024 * 1024) {
        throw new Exception("File too large. Max 1 GB per file.");
    }
    $originalName = basename(str_replace('\\', '/', (string) $file['name']));
    if ($originalName === '' || $originalName === '.' || $originalName === '..') {
        $originalName = 'attachment';
    }

    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $extension = preg_replace('/[^a-z0-9]/', '', $extension ?? '');

    // Allow broad document/media/archive types, but never permit uploaded server scripts or executables.
    $blockedExtensions = ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'cgi', 'pl', 'py', 'sh', 'bash', 'bat', 'cmd', 'com', 'exe', 'dll', 'msi', 'js', 'mjs', 'html', 'htm', 'shtml'];
    if ($extension !== '' && in_array($extension, $blockedExtensions, true)) {
        throw new Exception("This file extension cannot be uploaded for security reasons. Please upload it as a ZIP archive if it is needed as a resource.");
    }

    return [
        'size' => $file_size,
        'extension' => $extension,
        'original_name' => $originalName
    ];
}

/** Normalize both single-file and multiple-file HTML upload arrays. */
function ns_get_uploaded_announcement_files($fieldName = 'attachments')
{
    if (!isset($_FILES[$fieldName])) {
        // Backward compatibility with the old single-file input name.
        if ($fieldName === 'attachments' && isset($_FILES['attachment'])) {
            $fieldName = 'attachment';
        } else {
            return [];
        }
    }

    $f = $_FILES[$fieldName];
    if (!is_array($f) || !isset($f['name'])) return [];

    $files = [];
    if (is_array($f['name'])) {
        $count = count($f['name']);
        for ($i = 0; $i < $count; $i++) {
            if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            $files[] = [
                'name' => $f['name'][$i] ?? '',
                'type' => $f['type'][$i] ?? '',
                'tmp_name' => $f['tmp_name'][$i] ?? '',
                'error' => $f['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $f['size'][$i] ?? 0
            ];
        }
    } else {
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) $files[] = $f;
    }
    return $files;
}

function ns_get_announcement_attachments(PDO $conn, $announcementId, $legacyPath = null)
{
    $items = [];
    if (!empty($legacyPath)) {
        $items[] = ['file_path' => $legacyPath, 'original_name' => basename($legacyPath)];
    }
    try {
        $stmt = $conn->prepare("SELECT file_path, original_name FROM announcement_attachments WHERE announcement_id = ? ORDER BY id ASC");
        $stmt->execute([(int)$announcementId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $path = (string)($row['file_path'] ?? '');
            $already = false;
            foreach ($items as $it) if (($it['file_path'] ?? '') === $path) { $already = true; break; }
            if (!$already && $path !== '') $items[] = $row;
        }
    } catch (Exception $e) {
        // The additional-attachments table may not have been created yet.
    }
    return $items;
}

function ns_delete_announcement_attachments(PDO $conn, $announcementId, $legacyPath = null)
{
    $items = ns_get_announcement_attachments($conn, $announcementId, $legacyPath);
    foreach ($items as $item) {
        if (!empty($item['file_path'])) deleteAnnouncementFile($item['file_path']);
    }
    try {
        $stmt = $conn->prepare("DELETE FROM announcement_attachments WHERE announcement_id = ?");
        $stmt->execute([(int)$announcementId]);
    } catch (Exception $e) {
        // Legacy-only installations remain usable.
    }
}

/** Save uploaded files against the professor's live admin-configured quota. */
function ns_save_announcement_uploads(PDO $conn, int $profId, array $files, string $uploadDir): array
{
    $saved = [];
    if (!$files) return $saved;

    $storage = ns_storage_settings($conn);
    $professorUsed = ns_get_professor_used_bytes($conn, $profId);
    $systemUsed = ns_get_system_used_bytes();
    $batchProfessorBytes = 0;
    $batchSystemBytes = 0;
    try {
        foreach ($files as $file) {
            $info = ns_validate_announcement_upload($file);
            if ($professorUsed + $batchProfessorBytes + $info['size'] > $storage['professor_limit_bytes']) {
                throw new Exception("Professor storage quota exceeded. Remaining storage: " . ns_format_bytes(max(0, $storage['professor_limit_bytes'] - $professorUsed - $batchProfessorBytes)) . ". Ask the administrator to increase your professor storage quota.");
            }
            if ($systemUsed + $batchSystemBytes + $info['size'] > $storage['system_limit_bytes']) {
                throw new Exception("The system's configured storage capacity has been reached. The attachment was not uploaded.");
            }

            $safeExt = $info['extension'] !== '' ? '.' . $info['extension'] : '';
            $safeName = 'announcement_' . $profId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . $safeExt;
            $destination = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR . $safeName;
            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                throw new Exception("Failed to save uploaded file: " . $info['original_name']);
            }
            $saved[] = [
                'file_path' => 'uploads/announcements/' . $safeName,
                'physical_path' => $destination,
                'original_name' => $info['original_name'],
                'size' => $info['size']
            ];
            $batchProfessorBytes += $info['size'];
            $batchSystemBytes += $info['size'];
        }
    } catch (Exception $uploadException) {
        foreach ($saved as $savedFile) {
            if (!empty($savedFile['physical_path']) && is_file($savedFile['physical_path'])) @unlink($savedFile['physical_path']);
        }
        throw $uploadException;
    }
    return $saved;
}

// =========================================================
// GET ACTIVE SEMESTER / ACADEMIC YEAR
// =========================================================

$active_sem = '1st Semester';
$active_year = '2026-2027';

try {

    $stmt_settings = $conn->prepare("
        SELECT current_semester, current_academic_year
        FROM system_settings
        WHERE id = 1
        LIMIT 1
    ");

    $stmt_settings->execute();

    $settings = $stmt_settings->fetch(PDO::FETCH_ASSOC);

    if ($settings) {

        if (!empty($settings['current_semester'])) {
            $active_sem = $settings['current_semester'];
        }

        if (!empty($settings['current_academic_year'])) {
            $active_year = $settings['current_academic_year'];
        }
    }

} catch (Exception $e) {
    // Keep defaults.
}


// =========================================================
// HANDLE ADD / EDIT ANNOUNCEMENT
// =========================================================

if (isset($_POST['save_announcement'])) {

    $edit_id = isset($_POST['edit_id'])
        ? (int) $_POST['edit_id']
        : 0;

    $title = trim($_POST['title'] ?? '');
    $subject = strtoupper(trim($_POST['subject'] ?? ''));
    $target_course = trim($_POST['target_course'] ?? 'All Courses');
    $allowed_courses = ['All Courses', 'BSIT', 'BSCpE', 'BSBA', 'BSE', 'BECE', 'BEE', 'BTLE'];
    if (!in_array($target_course, $allowed_courses, true)) { $target_course = 'All Courses'; }
    $year_level = trim($_POST['year_level'] ?? 'All Years');
    $section = strtoupper(trim($_POST['section'] ?? 'All'));
    $content = trim($_POST['content'] ?? '');
    $category = trim($_POST['category'] ?? 'Announcement');
    $meet_link = trim($_POST['meet_link'] ?? '');
    $quiz_link = trim($_POST['quiz_link'] ?? '');
    $youtube_link = trim($_POST['youtube_link'] ?? '');

    $deadline = null;

    if (!empty($_POST['deadline'])) {

        $timestamp = strtotime($_POST['deadline']);

        if ($timestamp !== false) {
            $deadline = date(
                'Y-m-d H:i:s',
                $timestamp
            );
        }
    }

    // =====================================================
    // BASIC VALIDATION
    // =====================================================

    if (empty($title) || empty($content)) {

        $error_msg =
            "Please fill in both the Title and Message fields.";

    } elseif (in_array($category, ['Activity', 'Quiz', 'Exam'], true) && empty($deadline)) {

        $error_msg =
            "Please set a deadline for activities, quizzes, and exams.";

    } elseif (($meet_link !== '' && !filter_var($meet_link, FILTER_VALIDATE_URL)) || ($quiz_link !== '' && !filter_var($quiz_link, FILTER_VALIDATE_URL)) || ($youtube_link !== '' && !filter_var($youtube_link, FILTER_VALIDATE_URL))) {

        $error_msg =
            "Please enter a valid URL for the Google Meet, Google Form, and YouTube fields.";

    } else {

        try {

            // =================================================
            // EDIT EXISTING ANNOUNCEMENT
            // =================================================

            if ($edit_id > 0) {

                $stmt_existing = $conn->prepare("
                    SELECT *
                    FROM announcements
                    WHERE id = ?
                    AND professor_id = ?
                    LIMIT 1
                ");

                $stmt_existing->execute([
                    $edit_id,
                    $prof_id
                ]);

                $existing =
                    $stmt_existing->fetch(PDO::FETCH_ASSOC);

                if (!$existing) {

                    $error_msg =
                        "Announcement not found.";

                } else {

                    $old_attachment = $existing['attachment'] ?? null;
                    $new_attachment = $old_attachment;
                    $uploaded_files = [];
                    $saved_uploads = [];

                    $uploaded_files = ns_get_uploaded_announcement_files('attachments');
                    if (!$uploaded_files) $uploaded_files = ns_get_uploaded_announcement_files('attachment');
                    if ($uploaded_files) {
                        $saved_uploads = ns_save_announcement_uploads($conn, $prof_id, $uploaded_files, $upload_dir);
                        // Keep the first attachment in the legacy column if it is currently empty.
                        if (empty($new_attachment) && !empty($saved_uploads[0]['file_path'])) {
                            $new_attachment = $saved_uploads[0]['file_path'];
                            array_shift($saved_uploads);
                        }
                    }

                    // =========================================
                    // UPDATE DATABASE
                    // =========================================

                    $stmt = $conn->prepare("
                        UPDATE announcements
                        SET
                            title = ?,
                            subject = ?,
                            target_course = ?,
                            year_level = ?,
                            section = ?,
                            message = ?,
                            attachment = ?,
                            category = ?,
                            meet_link = ?,
                            quiz_link = ?,
                            youtube_link = ?,
                            deadline = ?
                        WHERE id = ?
                        AND professor_id = ?
                    ");

                    try {

                        $stmt->execute([
                            $title,
                            $subject,
                            $target_course,
                            $year_level,
                            $section,
                            $content,
                            $new_attachment,
                            $category,
                            $meet_link,
                            $quiz_link,
                            $youtube_link,
                            $deadline,
                            $edit_id,
                            $prof_id
                        ]);

                    } catch (Exception $dbException) {
                        foreach ($saved_uploads as $up) if (!empty($up['physical_path']) && is_file($up['physical_path'])) @unlink($up['physical_path']);
                        if ($new_attachment !== $old_attachment && !empty($new_attachment)) deleteAnnouncementFile($new_attachment);
                        throw $dbException;
                    }

                    // Save any additional attachments without replacing existing ones.
                    foreach ($saved_uploads as $up) {
                        $stmtAtt = $conn->prepare("INSERT INTO announcement_attachments (announcement_id, file_path, original_name, file_size, uploaded_at) VALUES (?, ?, ?, ?, NOW())");
                        $stmtAtt->execute([$edit_id, $up['file_path'], $up['original_name'], $up['size']]);
                    }

                    // PRG: prevents duplicate update on refresh
                    setFlash(
                        'success',
                        'The post was successfully updated.'
                    );

                    header(
                        "Location: " . $current_page
                    );
                    exit();
                }


            // =================================================
            // ADD NEW ANNOUNCEMENT
            // =================================================

            } else {

                $attachment_path = null;
                $saved_uploads = [];
                $uploaded_files = ns_get_uploaded_announcement_files('attachments');
                if (!$uploaded_files) $uploaded_files = ns_get_uploaded_announcement_files('attachment');
                if ($uploaded_files) {
                    $saved_uploads = ns_save_announcement_uploads($conn, $prof_id, $uploaded_files, $upload_dir);
                    if (!empty($saved_uploads[0]['file_path'])) {
                        $attachment_path = $saved_uploads[0]['file_path'];
                        array_shift($saved_uploads);
                    }
                }

                // =============================================
                // INSERT NEW ANNOUNCEMENT
                // =============================================

                $stmt = $conn->prepare("
                    INSERT INTO announcements (
                        professor_id,
                        title,
                        subject,
                        target_course,
                        year_level,
                        section,
                        message,
                        attachment,
                        category,
                        meet_link,
                        quiz_link,
                        youtube_link,
                        deadline,
                        semester,
                        academic_year,
                        status,
                        created_at
                    )
                    VALUES (
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW()
                    )
                ");

                try {

                    $stmt->execute([
                        $prof_id,
                        $title,
                        $subject,
                        $target_course,
                        $year_level,
                        $section,
                        $content,
                        $attachment_path,
                        $category,
                        $meet_link,
                        $quiz_link,
                        $youtube_link,
                        $deadline,
                        $active_sem,
                        $active_year
                    ]);

                } catch (Exception $dbException) {
                    foreach ($saved_uploads as $up) if (!empty($up['physical_path']) && is_file($up['physical_path'])) @unlink($up['physical_path']);
                    if (!empty($attachment_path)) deleteAnnouncementFile($attachment_path);
                    throw $dbException;
                }

                $newAnnouncementId = (int)$conn->lastInsertId();
                foreach ($saved_uploads as $up) {
                    $stmtAtt = $conn->prepare("INSERT INTO announcement_attachments (announcement_id, file_path, original_name, file_size, uploaded_at) VALUES (?, ?, ?, ?, NOW())");
                    $stmtAtt->execute([$newAnnouncementId, $up['file_path'], $up['original_name'], $up['size']]);
                }

                // Email notification to enrolled students
                try {
                    $where = "role='student' AND account_status='active' AND enrolled_subjects LIKE ?";
                    $params = ['%' . $subject . '%'];
                    if (!empty($target_course)) { $where .= " AND course = ?"; $params[] = $target_course; }
                    if (!empty($year_level)) { $where .= " AND year_level = ?"; $params[] = $year_level; }
                    if (!empty($section)) { $where .= " AND section = ?"; $params[] = $section; }
                    $studentsStmt = $conn->prepare("SELECT email, full_name FROM users WHERE $where");
                    $studentsStmt->execute($params);
                    $students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);
                    $mailCfg = require __DIR__ . '/../includes/mail_config.php';
                    require_once __DIR__ . '/../includes/PHPMailer-master/PHPMailer-master/src/PHPMailer.php';
                    require_once __DIR__ . '/../includes/PHPMailer-master/PHPMailer-master/src/SMTP.php';
                    require_once __DIR__ . '/../includes/PHPMailer-master/PHPMailer-master/src/Exception.php';
                    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                    $mail->isSMTP();
                    $mail->Host = 'smtp.gmail.com';
                    $mail->SMTPAuth = true;
                    $mail->Username = $mailCfg['username'];
                    $mail->Password = $mailCfg['app_password'];
                    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port = 587;
                    $mail->setFrom($mailCfg['username'], 'NoteSphere');
                    $mail->Subject = 'Bagong Announcement: ' . $title;
                    $mail->Body = $content;
                    foreach ($students as $st) {
                        if (!empty($st['email'])) {
                            $mail->clearAddresses();
                            $mail->addAddress($st['email'], $st['full_name']);
                            $mail->send();
                        }
                    }
                } catch (Exception $e) {
                    error_log('Email notif error: ' . $e->getMessage());
                }

                // PRG: prevents duplicate post on refresh
                setFlash(
                    'success',
                    'The ' .
                    $category .
                    ' was successfully published for ' .
                    $active_sem .
                    ' (' .
                    $active_year .
                    ').'
                );

                header(
                    "Location: " . $current_page
                );
                exit();
            }

        } catch (Exception $e) {

            $error_msg =
                "Error: " . $e->getMessage();
        }
    }
}


// =========================================================
// ARCHIVE ANNOUNCEMENT
// =========================================================

if (isset($_POST['archive_announcement'])) {

    $archive_id =
        (int) ($_POST['archive_id'] ?? 0);

    if ($archive_id > 0) {

        try {

            $stmt_archive = $conn->prepare("
                UPDATE announcements
                SET status = 'unactive'
                WHERE id = ?
                AND professor_id = ?
                LIMIT 1
            ");

            $stmt_archive->execute([
                $archive_id,
                $prof_id
            ]);

            if ($stmt_archive->rowCount() > 0) {

                setFlash(
                    'success',
                    'The post was archived. Its original semester and academic year were preserved.'
                );

            } else {

                setFlash(
                    'error',
                    'The post could not be archived.'
                );
            }

        } catch (Exception $e) {

            setFlash(
                'error',
                'Unable to archive the post.'
            );
        }
    }

    header(
        "Location: " . $current_page
    );
    exit();
}


// =========================================================
// RESTORE ARCHIVED ANNOUNCEMENT
// =========================================================

if (isset($_POST['restore_announcement'])) {

    $restore_id =
        (int) ($_POST['restore_id'] ?? 0);

    if ($restore_id > 0) {

        try {

            $stmt_restore = $conn->prepare("
                UPDATE announcements
                SET
                    status = 'active',
                    semester = ?,
                    academic_year = ?
                WHERE id = ?
                AND professor_id = ?
                AND status = 'unactive'
                LIMIT 1
            ");

            $stmt_restore->execute([
                $active_sem,
                $active_year,
                $restore_id,
                $prof_id
            ]);

            if ($stmt_restore->rowCount() > 0) {

                setFlash(
                    'success',
                    'The post was restored to the current academic period.'
                );

            } else {

                setFlash(
                    'error',
                    'The post could not be restored.'
                );
            }

        } catch (Exception $e) {

            setFlash(
                'error',
                'Unable to restore the post.'
            );
        }
    }

    header(
        "Location: " . $current_page . "?archive=1"
    );
    exit();
}


// =========================================================
// PERMANENT DELETE ANNOUNCEMENT
// =========================================================

if (isset($_POST['permanent_delete_announcement'])) {

    $delete_id =
        (int) ($_POST['permanent_delete_id'] ?? 0);

    if ($delete_id > 0) {

        try {

            $stmt_delete = $conn->prepare("
                SELECT attachment
                FROM announcements
                WHERE id = ?
                AND professor_id = ?
                LIMIT 1
            ");

            $stmt_delete->execute([
                $delete_id,
                $prof_id
            ]);

            $delete_data =
                $stmt_delete->fetch(PDO::FETCH_ASSOC);

            ns_delete_announcement_attachments($conn, $delete_id, $delete_data['attachment'] ?? null);

            $stmt_delete_record = $conn->prepare("
                DELETE FROM announcements
                WHERE id = ?
                AND professor_id = ?
                LIMIT 1
            ");

            $stmt_delete_record->execute([
                $delete_id,
                $prof_id
            ]);

            if (
                $delete_data &&
                !empty($delete_data['attachment'])
            ) {
                deleteAnnouncementFile(
                    $delete_data['attachment']
                );
            }

            setFlash(
                'success',
                'The post was permanently deleted.'
            );

        } catch (Exception $e) {

            setFlash(
                'error',
                'Unable to permanently delete the post.'
            );
        }
    }

    header(
        "Location: " . $current_page . "?archive=1"
    );
    exit();
}


// =========================================================
// DELETE ACTIVE ANNOUNCEMENT
// =========================================================

if (isset($_POST['delete_active_announcement'])) {

    $delete_id =
        (int) ($_POST['delete_active_id'] ?? 0);

    if ($delete_id > 0) {

        try {

            $stmt_delete = $conn->prepare("
                SELECT attachment
                FROM announcements
                WHERE id = ?
                AND professor_id = ?
                AND status = 'active'
                LIMIT 1
            ");

            $stmt_delete->execute([
                $delete_id,
                $prof_id
            ]);

            $delete_data =
                $stmt_delete->fetch(PDO::FETCH_ASSOC);

            if (!$delete_data) {

                setFlash(
                    'error',
                    'The post could not be found.'
                );

            } else {

                ns_delete_announcement_attachments($conn, $delete_id, $delete_data['attachment'] ?? null);

                $stmt_delete_record = $conn->prepare("
                    DELETE FROM announcements
                    WHERE id = ?
                    AND professor_id = ?
                    AND status = 'active'
                    LIMIT 1
                ");

                $stmt_delete_record->execute([
                    $delete_id,
                    $prof_id
                ]);

                if (
                    !empty($delete_data['attachment'])
                ) {
                    deleteAnnouncementFile(
                        $delete_data['attachment']
                    );
                }

                setFlash(
                    'success',
                    'The post was successfully deleted.'
                );
            }

        } catch (Exception $e) {

            setFlash(
                'error',
                'Unable to delete the post.'
            );
        }
    }

    header(
        "Location: " . $current_page
    );
    exit();
}


// =========================================================
// DELETE ALL ARCHIVED ANNOUNCEMENTS
// =========================================================

if (isset($_POST['delete_all_archived'])) {

    try {

        $stmt_archived_files = $conn->prepare("
            SELECT id, attachment
            FROM announcements
            WHERE professor_id = ?
            AND status = 'unactive'
        ");

        $stmt_archived_files->execute([
            $prof_id
        ]);

        $archived_files = $stmt_archived_files->fetchAll(PDO::FETCH_ASSOC);
        foreach ($archived_files as $archivedRow) {
            ns_delete_announcement_attachments($conn, (int)$archivedRow['id'], $archivedRow['attachment'] ?? null);
        }

        $stmt_delete_all = $conn->prepare("
            DELETE FROM announcements
            WHERE professor_id = ?
            AND status = 'unactive'
        ");

        $stmt_delete_all->execute([
            $prof_id
        ]);

        $deleted_count =
            $stmt_delete_all->rowCount();

        if ($deleted_count > 0) {

            setFlash(
                'success',
                $deleted_count === 1
                    ? 'The archived post was permanently deleted.'
                    : $deleted_count . ' archived posts were permanently deleted.'
            );

        } else {

            setFlash(
                'error',
                'There are no archived posts to delete.'
            );
        }

    } catch (Exception $e) {

        setFlash(
            'error',
            'Unable to delete the archived posts.'
        );
    }

    header(
        "Location: " . $current_page . "?archive=1"
    );
    exit();
}

// =========================================================
// ARCHIVE VIEW
// =========================================================

$show_archive =
    isset($_GET['archive']) &&
    $_GET['archive'] === '1';


// =========================================================
// AUTOMATICALLY ARCHIVE ANNOUNCEMENTS FROM OLD PERIODS
// This also repairs older records that were hidden from the
// current list but were never marked as archived.
// =========================================================

try {
    $stmt_auto_archive = $conn->prepare("
        UPDATE announcements
        SET status = 'unactive'
        WHERE professor_id = ?
          AND status = 'active'
          AND (
              COALESCE(semester, '') <> ?
              OR COALESCE(academic_year, '') <> ?
          )
    ");

    $stmt_auto_archive->execute([
        $prof_id,
        $active_sem,
        $active_year
    ]);
} catch (Exception $archiveException) {
    // Keep the page available; archive fetch below still loads
    // records that already have status = 'unactive'.
}


// =========================================================
// FETCH ACTIVE ANNOUNCEMENTS
// CURRENT SEMESTER + CURRENT ACADEMIC YEAR ONLY
// Old-period records are archived before this query runs.
// =========================================================

$announcements = [];

if (!$show_archive) {

    $my_announcements = $conn->prepare("
        SELECT *
        FROM announcements
        WHERE professor_id = ?
        AND status = 'active'
        AND semester = ?
        AND academic_year = ?
        ORDER BY
            CASE
                WHEN year_level = '1st Year' THEN 1
                WHEN year_level = '2nd Year' THEN 2
                WHEN year_level = '3rd Year' THEN 3
                WHEN year_level = '4th Year' THEN 4
                ELSE 5
            END,
            section ASC,
            subject ASC,
            created_at DESC
    ");

    $my_announcements->execute([
        $prof_id,
        $active_sem,
        $active_year
    ]);

    $announcements =
        $my_announcements->fetchAll(
            PDO::FETCH_ASSOC
        );
}


// =========================================================
// GROUP ACTIVE POSTS
// YEAR LEVEL → SECTION → SUBJECT
// =========================================================

$grouped_announcements = [];

foreach ($announcements as $a) {

    $year_key =
        !empty($a['year_level'])
        ? $a['year_level']
        : 'All Years';

    $section_key =
        !empty($a['section'])
        ? $a['section']
        : 'All';

    $subject_key =
        !empty(trim($a['subject'] ?? ''))
        ? trim($a['subject'])
        : 'General';

    if (!isset(
        $grouped_announcements[$year_key]
    )) {
        $grouped_announcements[$year_key] = [];
    }

    if (!isset(
        $grouped_announcements[$year_key][$section_key]
    )) {
        $grouped_announcements[$year_key][$section_key] = [];
    }

    if (!isset(
        $grouped_announcements
        [$year_key]
        [$section_key]
        [$subject_key]
    )) {
        $grouped_announcements
            [$year_key]
            [$section_key]
            [$subject_key] = [];
    }

    $grouped_announcements
        [$year_key]
        [$section_key]
        [$subject_key][] = $a;
}


// =========================================================
// FETCH ARCHIVED POSTS
// =========================================================

$archived_announcements = [];

if ($show_archive) {

    $stmt_archived = $conn->prepare("
        SELECT *
        FROM announcements
        WHERE professor_id = ?
          AND status = 'unactive'
        ORDER BY academic_year DESC, semester DESC, created_at DESC
    ");

    $stmt_archived->execute([
        $prof_id
    ]);

    $archived_announcements =
        $stmt_archived->fetchAll(
            PDO::FETCH_ASSOC
        );
}


// =========================================================
// GROUP ARCHIVED POSTS
// YEAR LEVEL → SECTION → SUBJECT
// =========================================================

$grouped_archived = [];

foreach ($archived_announcements as $a) {

    $year_key =
        !empty($a['year_level'])
        ? $a['year_level']
        : 'All Years';

    $section_key =
        !empty($a['section'])
        ? $a['section']
        : 'All';

    $subject_key =
        !empty(trim($a['subject'] ?? ''))
        ? trim($a['subject'])
        : 'General';

    if (!isset($grouped_archived[$year_key])) {
        $grouped_archived[$year_key] = [];
    }

    if (!isset($grouped_archived[$year_key][$section_key])) {
        $grouped_archived[$year_key][$section_key] = [];
    }

    if (!isset(
        $grouped_archived
        [$year_key]
        [$section_key]
        [$subject_key]
    )) {
        $grouped_archived
            [$year_key]
            [$section_key]
            [$subject_key] = [];
    }

    $grouped_archived
        [$year_key]
        [$section_key]
        [$subject_key][] = $a;
}


// =========================================================
// FETCH EDIT DATA
// =========================================================

$edit_data = null;

if (isset($_GET['edit'])) {

    $edit_id =
        (int) $_GET['edit'];

    if ($edit_id > 0) {

        $stmt_edit = $conn->prepare("
            SELECT *
            FROM announcements
            WHERE id = ?
            AND professor_id = ?
            LIMIT 1
        ");

        $stmt_edit->execute([
            $edit_id,
            $prof_id
        ]);

        $edit_data =
            $stmt_edit->fetch(
                PDO::FETCH_ASSOC
            );
    }
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
        Post Announcement & LMS Tools | NoteSphere
    </title>

    <link
        href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap"
        rel="stylesheet"
    >

    <link
        rel="manifest"
        href="../manifest.json"
    >

    <meta
        name="theme-color"
        content="#0f172a"
    >

    <!-- SweetAlert2 -->

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>

        :root {
            --neon: #39FF14;
            --neon-glow: rgba(57, 255, 20, 0.12);
            --card-bg: #0c0c0c;
            --text: #f8fafc;
            --sub-text: #94a3b8;
            --border: #1f293d;
            --sidebar-width: 260px;
        }

        body.light-mode {
            --card-bg: #ffffff;
            --text: #000000;
            --sidebar-bg: #ffffff;
            --border: #e2e8f0;
            --sub-text: #000000;
            --neon: #2ecc71;
            --neon-glow: rgba(46, 204, 113, 0.12);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        html {
            scroll-behavior: smooth;
        }

        html,
        body {
            width: 100%;
            max-width: 100vw;
            overflow-x: hidden;
        }

        body {
            position: relative;
            color: var(--text);
            display: flex;
            min-height: 100vh;
            transition: color 0.3s ease;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            z-index: -1;
            background:
                linear-gradient(
                    rgba(11, 11, 11, 0.85),
                    rgba(11, 11, 11, 0.92)
                ),
                none
                no-repeat center center;
            background-size: cover;
            pointer-events: none;
        }

        body.light-mode::before {
            background:
                none
                no-repeat center center;
            background-size: cover;
        }

        /* =====================================================
           SIDEBAR
        ===================================================== */

        .sidebar {
            width: var(--sidebar-width);
            height: 100vh;
            background: var(--card-bg);
            border-right: 1px solid var(--border);
            padding: 35px 20px;
            position: fixed;
            top: 0;
            left: 0;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            z-index: 1002;
            transition:
                transform 0.3s ease,
                background 0.3s ease,
                border-color 0.3s ease;
            overflow-y: auto;
        }

        .sidebar-top {
            display: flex;
            flex-direction: column;
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 30px;
            padding: 0 5px;
            background: transparent !important;
            border: none !important;
            box-shadow: none !important;
        }

        .sidebar-brand h2 {
            font-weight: 800;
            color: var(--neon);
            font-size: 1.4rem;
        }

        .sidebar-brand img {
            width: 35px;
            height: auto;
        }

        .menu {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .menu a {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 12px 16px;
            text-decoration: none;
            color: var(--sub-text);
            font-weight: 600;
            font-size: 0.92rem;
            border-radius: 12px;
            transition: all 0.3s ease;
        }

        .menu a svg {
            width: 20px;
            height: 20px;
            stroke: currentColor;
            stroke-width: 2;
            fill: none;
            flex-shrink: 0;
        }

        .menu a:hover,
        .menu a.active {
            background: var(--neon-glow);
            color: var(--neon);
        }

        .menu a.active {
            border: 1px solid rgba(57, 255, 20, 0.2);
        }

        .sidebar-footer {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: 20px;
        }

        .theme-toggle-btn {
            width: 100%;
            padding: 12px 16px;
            background: var(--card-bg);
            border: 1px solid var(--border);
            color: var(--text);
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: 0.3s;
            font-size: 0.9rem;
            text-decoration: none;
        }

        .theme-toggle-btn:hover {
            border-color: var(--neon);
            color: var(--neon);
        }

        /* =====================================================
           MOBILE HEADER
        ===================================================== */

        .mobile-header {
            display: none;
            align-items: center;
            justify-content: space-between;
            padding: 15px 20px;
            background: var(--card-bg);
            border-bottom: 1px solid var(--border);
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            z-index: 1000;
        }

        .sidebar-toggle-btn {
            background: var(--card-bg);
            border: 1px solid var(--border);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 10px;
            color: var(--text);
        }

        .sidebar-toggle-btn svg {
            width: 20px;
            height: 20px;
            stroke: currentColor;
            stroke-width: 2;
            fill: none;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(0, 0, 0, 0.6);
            z-index: 1001;
            backdrop-filter: blur(2px);
        }

        /* =====================================================
           MAIN
        ===================================================== */

        main {
            margin-left: var(--sidebar-width);
            flex: 1;
            padding: 40px;
            max-width: calc(100vw - var(--sidebar-width));
            width: 100%;
            box-sizing: border-box;
            overflow-x: hidden;
        }

        .grid-layout {
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            gap: 30px;
        }

        .card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            box-sizing: border-box;
            transition:
                background-color 0.3s ease,
                border-color 0.3s ease;
            min-width: 0;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            color: var(--text);
            font-weight: 600;
            margin-bottom: 6px;
            font-size: 0.85rem;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 15px;
            border-radius: 12px;
            border: 1px solid var(--border);
            background: var(--card-bg);
            color: var(--text);
            outline: none;
            font-size: 0.88rem;
        }

        .form-group textarea {
            resize: vertical;
            height: 110px;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: var(--neon);
            box-shadow: 0 0 10px var(--neon-glow);
        }

        /* =====================================================
           FILE INPUT
        ===================================================== */

        .file-upload-box {
            border: 1px dashed var(--border);
            border-radius: 12px;
            padding: 14px;
            background: rgba(128, 128, 128, 0.04);
        }

        .file-upload-box input[type="file"] {
            padding: 8px;
            border: none;
            background: transparent;
        }

        .file-help {
            margin-top: 7px;
            font-size: 0.72rem;
            color: var(--sub-text);
            line-height: 1.5;
        }

        .current-attachment {
            margin-top: 8px;
            padding: 9px 11px;
            border-radius: 9px;
            background: var(--neon-glow);
            color: var(--neon);
            font-size: 0.75rem;
            font-weight: 700;
            word-break: break-word;
        }

        /* =====================================================
           BUTTONS
        ===================================================== */

        .btn-submit {
            width: 100%;
            background: var(--neon);
            color: #0c0c0c;
            border: none;
            padding: 14px;
            border-radius: 12px;
            font-weight: 800;
            cursor: pointer;
            transition: 0.3s;
            margin-top: 5px;
        }

        .btn-submit:hover {
            opacity: 0.9;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px var(--neon-glow);
        }

        .btn-cancel-edit {
            display: inline-block;
            text-align: center;
            width: 100%;
            background: transparent;
            color: var(--sub-text);
            border: 1px solid var(--border);
            padding: 10px;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 8px;
            text-decoration: none;
            font-size: 0.85rem;
        }

        .btn-cancel-edit:hover {
            border-color: var(--text);
            color: var(--text);
        }

        .alert-success {
            background: rgba(46, 204, 113, 0.1);
            border: 1px solid #2ecc71;
            color: #2ecc71;
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            font-weight: 600;
        }

        .alert-error {
            background: rgba(255, 77, 77, 0.1);
            border: 1px solid #ff4d4d;
            color: #ff4d4d;
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            font-weight: 600;
        }

        /* =====================================================
           HISTORY
        ===================================================== */

        .history-scroll {
            max-height: 650px;
            overflow-y: auto;
            padding-right: 5px;
        }

        .history-scroll::-webkit-scrollbar {
            width: 6px;
        }

        .history-scroll::-webkit-scrollbar-thumb {
            background: var(--border);
            border-radius: 10px;
        }

        .history-group {
            margin-bottom: 22px;
        }

        .history-year {
            color: var(--neon);
            font-size: 0.95rem;
            font-weight: 800;
            padding: 10px 12px;
            background: var(--neon-glow);
            border: 1px solid rgba(57, 255, 20, 0.15);
            border-radius: 10px;
            margin-bottom: 10px;
        }

        .history-section {
            margin-left: 10px;
            margin-bottom: 14px;
        }

        .history-section-title {
            color: var(--text);
            font-size: 0.85rem;
            font-weight: 800;
            padding: 8px 10px;
            border-left: 3px solid var(--neon);
            margin-bottom: 8px;
        }

        .history-course {
            margin-left: 10px;
            margin-bottom: 14px;
        }

        .history-course-title {
            font-size: 0.8rem;
            font-weight: 800;
            color: var(--sub-text);
            padding: 7px 9px;
            background: rgba(128, 128, 128, 0.05);
            border-radius: 8px;
            margin-bottom: 8px;
        }

        .ann-list-item {
            background: var(--card-bg);
            border: 1px solid var(--border);
            padding: 18px;
            border-radius: 14px;
            margin-bottom: 12px;
        }

        .ann-title {
            font-weight: 700;
            font-size: 1.05rem;
            color: var(--text);
            margin-bottom: 4px;
            word-break: break-word;
        }

        .ann-meta {
            font-size: 0.78rem;
            color: var(--neon);
            font-weight: 600;
            margin-bottom: 8px;
        }

        .lms-badge,
        .attachment-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 700;
            margin-top: 6px;
            margin-right: 5px;
            text-decoration: none;
            transition: 0.2s;
        }

        .lms-badge {
            background: rgba(52, 152, 219, 0.15);
            color: #3498db;
            border: 1px solid rgba(52, 152, 219, 0.3);
        }

        .lms-badge:hover {
            background: rgba(52, 152, 219, 0.3);
        }

        .attachment-badge {
            background: rgba(155, 89, 182, 0.15);
            color: #9b59b6;
            border: 1px solid rgba(155, 89, 182, 0.3);
        }

        .attachment-badge:hover {
            background: rgba(155, 89, 182, 0.3);
        }

        .archive-toolbar {
            display: flex;
            gap: 8px;
            margin-bottom: 18px;
            flex-wrap: wrap;
        }

        .archive-btn,
        .back-history-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 9px 12px;
            border-radius: 10px;
            text-decoration: none;
            font-size: 0.75rem;
            font-weight: 800;
            border: 1px solid var(--border);
            cursor: pointer;
        }

        .archive-btn {
            background: rgba(52, 152, 219, 0.12);
            color: #3498db;
        }

        .back-history-btn {
            background: var(--neon-glow);
            color: var(--neon);
        }

        .archive-btn:hover,
        .back-history-btn:hover {
            opacity: 0.85;
        }

        .archive-item {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 18px;
            margin-bottom: 14px;
        }

        .archive-label {
            display: inline-block;
            font-size: 0.68rem;
            font-weight: 800;
            padding: 4px 8px;
            border-radius: 6px;
            background: rgba(243, 156, 18, 0.15);
            color: #f39c12;
            border: 1px solid rgba(243, 156, 18, 0.25);
            margin-bottom: 8px;
        }

        .archive-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 12px;
            padding-top: 10px;
            border-top: 1px solid var(--border);
        }

        .restore-btn,
        .permanent-delete-btn,
        .post-archive-btn {
            border: none;
            border-radius: 8px;
            padding: 7px 10px;
            font-size: 0.72rem;
            font-weight: 800;
            cursor: pointer;
        }

        .restore-btn {
            background: rgba(46, 204, 113, 0.15);
            color: #2ecc71;
            border: 1px solid rgba(46, 204, 113, 0.25);
        }

        .permanent-delete-btn,
        .post-archive-btn {
            background: rgba(255, 77, 77, 0.12);
            color: #ff4d4d;
            border: 1px solid rgba(255, 77, 77, 0.25);
        }

        .post-archive-btn {
            margin-top: 10px;
        }

        /* =====================================================
           COLLAPSIBLE HISTORY / ARCHIVE
        ===================================================== */

        .history-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 18px;
        }

        .history-heading {
            font-size: 1.15rem;
            color: var(--neon);
        }

        .history-period {
            margin-top: 4px;
            color: var(--sub-text);
            font-size: 0.72rem;
            line-height: 1.4;
        }

        .history-header-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
            flex-wrap: wrap;
        }

        .history-dropdown {
            margin-bottom: 10px;
        }

        .history-dropdown summary {
            list-style: none;
        }

        .history-dropdown summary::-webkit-details-marker {
            display: none;
        }

        .history-summary {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            cursor: pointer;
            user-select: none;
            transition: 0.2s ease;
            min-width: 0;
        }

        .summary-left {
            display: flex;
            align-items: center;
            gap: 9px;
            min-width: 0;
        }

        .summary-left > span:last-child {
            overflow-wrap: anywhere;
        }

        .summary-arrow {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 14px;
            font-size: 0.68rem;
            transition: transform 0.2s ease;
            flex-shrink: 0;
        }

        details[open] > summary .summary-arrow {
            transform: rotate(90deg);
        }

        .summary-count {
            flex-shrink: 0;
            font-size: 0.67rem;
            font-weight: 800;
            color: var(--sub-text);
            white-space: nowrap;
        }

        .year-summary {
            color: var(--neon);
            font-size: 0.95rem;
            font-weight: 800;
            padding: 11px 12px;
            background: var(--neon-glow);
            border: 1px solid rgba(57, 255, 20, 0.15);
            border-radius: 10px;
        }

        .year-summary:hover,
        .year-dropdown[open] > .year-summary {
            border-color: rgba(57, 255, 20, 0.35);
        }

        .dropdown-content {
            min-width: 0;
        }

        .year-content {
            padding: 10px 0 2px 10px;
        }

        .section-summary {
            color: var(--text);
            font-size: 0.85rem;
            font-weight: 800;
            padding: 9px 10px;
            border-left: 3px solid var(--neon);
            background: rgba(128, 128, 128, 0.035);
            border-radius: 0 8px 8px 0;
        }

        .section-summary:hover,
        .section-dropdown[open] > .section-summary {
            background: var(--neon-glow);
        }

        .section-content {
            padding: 8px 0 2px 10px;
        }

        .subject-summary {
            font-size: 0.8rem;
            font-weight: 800;
            color: var(--sub-text);
            padding: 8px 9px;
            background: rgba(128, 128, 128, 0.05);
            border: 1px solid transparent;
            border-radius: 8px;
        }

        .subject-summary:hover,
        .subject-dropdown[open] > .subject-summary {
            color: var(--neon);
            border-color: var(--border);
        }

        .subject-content {
            padding: 8px 0 2px 8px;
        }

        .empty-history {
            color: var(--sub-text);
            text-align: center;
            padding: 40px 20px;
        }

        .announcement-top-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            margin-bottom: 5px;
        }

        .announcement-category {
            font-size: 0.75rem;
            color: var(--neon);
            font-weight: 700;
        }

        .announcement-period {
            font-size: 0.68rem;
            background: var(--neon-glow);
            color: var(--neon);
            padding: 3px 7px;
            border-radius: 4px;
            white-space: nowrap;
        }

        .announcement-message {
            font-size: 0.85rem;
            color: var(--sub-text);
            margin-bottom: 8px;
            line-height: 1.5;
            word-break: break-word;
        }

        .announcement-deadline {
            font-size: 0.72rem;
            color: #ff4d4d;
            margin-top: 6px;
            font-weight: 700;
        }

        .announcement-footer {
            margin-top: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            border-top: 1px solid var(--border);
            padding-top: 8px;
            flex-wrap: wrap;
        }

        .announcement-date {
            font-size: 0.7rem;
            color: var(--sub-text);
        }

        .announcement-actions {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }

        .edit-action,
        .delete-action {
            background: none;
            border: none;
            padding: 0;
            font-weight: 700;
            font-size: 0.75rem;
            cursor: pointer;
            text-decoration: none;
        }

        .edit-action {
            color: #3498db;
        }

        .delete-action {
            color: #ff4d4d;
            min-width: 76px;
            padding: 6px 12px;
            border: 1px solid rgba(255, 77, 77, 0.30);
            border-radius: 6px;
            background: rgba(255, 77, 77, 0.10);
            text-align: center;
            transition: background 0.2s ease, border-color 0.2s ease;
        }

        .delete-action:hover {
            background: rgba(255, 77, 77, 0.18);
            border-color: rgba(255, 77, 77, 0.50);
        }

        .delete-all-archive-btn,
        .archive-btn,
        .back-history-btn {
            border-radius: 8px;
            padding: 8px 11px;
            font-size: 0.72rem;
            font-weight: 800;
            text-decoration: none;
            cursor: pointer;
            white-space: nowrap;
        }

        .delete-all-archive-btn {
            background: rgba(255, 77, 77, 0.12);
            color: #ff4d4d;
            border: 1px solid rgba(255, 77, 77, 0.25);
        }

        .delete-all-archive-btn:hover {
            background: rgba(255, 77, 77, 0.2);
        }

        .archive-btn {
            display: inline-block;
            background: rgba(52, 152, 219, 0.12);
            color: #3498db;
            border: 1px solid rgba(52, 152, 219, 0.25);
        }

        .back-history-btn {
            display: inline-block;
            background: var(--neon-glow);
            color: var(--neon);
            border: 1px solid rgba(57, 255, 20, 0.2);
        }

        .submissions-badge {
            background: rgba(46, 204, 113, 0.15) !important;
            color: #2ecc71 !important;
            border-color: rgba(46, 204, 113, 0.3) !important;
        }

        .post-archive-btn {
            width: 100%;
            margin-top: 10px;
        }

        /* =====================================================
           RESPONSIVE
        ===================================================== */

        @media (max-width: 1100px) {

            main {
                padding: 30px;
            }

            .grid-layout {
                grid-template-columns: 1fr;
            }

            .history-scroll {
                max-height: none;
            }
        }

        @media (max-width: 900px) {

            .mobile-header {
                display: flex;
            }

            main {
                margin-left: 0;
                padding:
                    90px
                    16px
                    25px
                    16px;
                max-width: 100vw;
            }

            .sidebar {
                transform: translateX(-100%);
            }

            body.sidebar-open .sidebar {
                transform: translateX(0);
            }

            body.sidebar-open .sidebar-overlay {
                display: block;
            }

            .grid-layout {
                grid-template-columns: 1fr;
                gap: 18px;
            }

            .card {
                padding: 20px;
                border-radius: 16px;
            }
        }

        @media (max-width: 600px) {

            main {
                padding:
                    82px
                    10px
                    20px
                    10px;
            }

            .mobile-header {
                padding: 12px 14px;
            }

            .mobile-header h2 {
                font-size: 1rem !important;
            }

            .sidebar-toggle-btn {
                width: 38px;
                height: 38px;
            }

            main > h1 {
                font-size: 1.35rem !important;
                line-height: 1.3;
                margin-bottom: 18px !important;
            }

            .card {
                padding: 16px;
                border-radius: 14px;
            }

            .form-group input,
            .form-group select,
            .form-group textarea {
                font-size: 0.84rem;
            }

            .form-group textarea {
                min-height: 120px;
            }

            .history-year {
                font-size: 0.86rem;
            }

            .history-section {
                margin-left: 5px;
            }

            .history-course {
                margin-left: 5px;
            }

            .ann-list-item,
            .archive-item {
                padding: 14px;
            }

            .ann-title {
                font-size: 0.95rem;
            }

            .ann-meta {
                font-size: 0.72rem;
                line-height: 1.5;
            }

            .archive-actions {
                flex-direction: column;
            }

            .restore-btn,
            .permanent-delete-btn {
                width: 100%;
            }

            .form-target-grid {
                grid-template-columns: 1fr !important;
            }
        }

    

        @media (max-width: 600px) {

            .history-header {
                align-items: stretch;
            }

            .history-header-actions {
                width: 100%;
                justify-content: stretch;
            }

            .history-header-actions > a,
            .history-header-actions > button {
                flex: 1 1 140px;
                text-align: center;
            }

            .year-content {
                padding-left: 4px;
            }

            .section-content {
                padding-left: 4px;
            }

            .subject-content {
                padding-left: 0;
            }

            .history-summary {
                align-items: flex-start;
            }

            .summary-count {
                font-size: 0.62rem;
            }

            .ann-list-item {
                padding: 14px;
            }

            .announcement-top-row {
                align-items: flex-start;
                flex-direction: column;
            }

            .announcement-period {
                white-space: normal;
            }

            .announcement-footer {
                align-items: flex-start;
                flex-direction: column;
            }

            .announcement-actions {
                width: 100%;
                justify-content: flex-end;
            }

            .post-archive-btn {
                min-height: 38px;
            }
        }

        .attachment-preview-media {
            display: block;
            width: min(100%, 560px);
            margin: 12px 0;
            padding: 10px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: rgba(127, 127, 127, 0.06);
            overflow: hidden;
        }
        .attachment-preview-media img,
        .attachment-preview-media video {
            display: block;
            width: 100%;
            max-height: 360px;
            border-radius: 8px;
            object-fit: contain;
            background: #000;
        }
        .attachment-preview-media audio {
            width: 100%;
        }
        .attachment-preview-name {
            margin-top: 8px;
            overflow-wrap: anywhere;
            font-size: 0.82rem;
            color: var(--sub-text);
        }
        .attachment-preview-name a {
            color: var(--neon);
        }
</style>

</head>

<body>

<!-- =========================================================
     MOBILE HEADER
========================================================= -->

<header class="mobile-header">

    <div
        style="
            display:flex;
            align-items:center;
            gap:10px;
        "
    >

        <img
            src="../assets/icon-192.png"
            alt="Logo"
            style="
                width:30px;
                height:auto;
            "
        >

        <h2
            style="
                font-size:1.2rem;
                color:var(--neon);
            "
        >
            NoteSphere
        </h2>

    </div>

    <button
        class="sidebar-toggle-btn"
        onclick="toggleMobileSidebar()"
        aria-label="Toggle Sidebar"
    >

        <svg viewBox="0 0 24 24">

            <line
                x1="3"
                y1="12"
                x2="21"
                y2="12"
            ></line>

            <line
                x1="3"
                y1="6"
                x2="21"
                y2="6"
            ></line>

            <line
                x1="3"
                y1="18"
                x2="21"
                y2="18"
            ></line>

        </svg>

    </button>

</header>


<div
    class="sidebar-overlay"
    onclick="toggleMobileSidebar()"
></div>


<!-- =========================================================
     SIDEBAR
========================================================= -->

<nav
    class="sidebar"
    id="sidebar"
>

    <div class="sidebar-top">

        <div class="sidebar-brand">

            <img
                src="../assets/icon-192.png"
                alt="Logo"
            >

            <h2>NoteSphere</h2>

        </div>

        <div class="menu">

            <a href="professor_dashboard.php">

                <svg viewBox="0 0 24 24">
                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                    <polyline points="9 22 9 12 15 12 15 22"></polyline>
                </svg>

                <span>Dashboard</span>

            </a>




         


            <a
                href="add_announcement.php"
                class="active"
            >

                <svg viewBox="0 0 24 24">
                    <path d="M12 5v14M5 12h14"></path>
                </svg>

                <span>Add Announcement</span>

            </a>

        </div>

    </div>


    <div class="sidebar-footer">

        <button
            onclick="toggleGlobalTheme()"
            class="theme-toggle-btn"
            id="globalThemeBtn"
        >

            <span id="themeIcon">
                🌞
            </span>

            <span id="themeText">
                Light Mode
            </span>

        </button>


        <a
            href="../auth/logout.php"
            class="theme-toggle-btn"
            style="
                color:#ff4d4d;
                border-color:
                    rgba(255,77,77,0.3);
            "
        >

            <svg
                viewBox="0 0 24 24"
                style="
                    width:18px;
                    height:18px;
                    stroke:currentColor;
                    stroke-width:2;
                    fill:none;
                "
            >

                <path
                    d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"
                ></path>

                <polyline
                    points="16 17 21 12 16 7"
                ></polyline>

            </svg>

            <span>Logout</span>

        </a>

    </div>

</nav>


<!-- =========================================================
     MAIN
========================================================= -->

<main>

    <h1
        style="
            font-size:1.8rem;
            font-weight:800;
            margin-bottom:25px;
            letter-spacing:-0.5px;
        "
    >
        Broadcast Announcement & LMS Tools
    </h1>


    <?php if (!empty($success_msg)): ?>

        <div class="alert-success">
            <?= e($success_msg) ?>
        </div>

    <?php endif; ?>


    <?php if (!empty($error_msg)): ?>

        <div class="alert-error">
            <?= e($error_msg) ?>
        </div>

    <?php endif; ?>


    <div class="grid-layout">


        <!-- =================================================
             FORM
        ================================================== -->

        <div
            class="card"
            id="editFormCard"
        >

            <h3
                style="
                    margin-bottom:10px;
                    font-size:1.15rem;
                    color:var(--neon);
                "
            >
                <?= $edit_data
                    ? '✏️ Edit Post / Task'
                    : 'Create Post / Task'
                ?>
            </h3>


            <p
                style="
                    font-size:0.8rem;
                    color:var(--sub-text);
                    margin-bottom:18px;
                "
            >
                Automatically saved to:

                <strong
                    style="
                        color:var(--text);
                    "
                >
                    <?= e($active_sem) ?>
                    (<?= e($active_year) ?>)
                </strong>
            </p>


            <form
                method="POST"
                action="<?= e($current_page) ?>"
                id="announcementForm"
                enctype="multipart/form-data"
            >

                <input
                    type="hidden"
                    name="edit_id"
                    value="<?= e($edit_data['id'] ?? '') ?>"
                >


                <div class="form-group">

                    <label>
                        Post Category
                    </label>

                    <select
                        name="category"
                        id="categorySelect"
                        onchange="toggleFields()"
                        required
                    >

                        <?php
                        $current_category =
                            $edit_data['category']
                            ?? 'Announcement';
                        ?>

                        <option
                            value="Announcement"
                            <?= $current_category ===
                                'Announcement'
                                ? 'selected'
                                : '' ?>
                        >
                            📢 General Announcement
                        </option>

                        <option
                            value="Activity"
                            <?= $current_category ===
                                'Activity'
                                ? 'selected'
                                : '' ?>
                        >
                            📁 Activity / Assignment
                        </option>

                        <option
                            value="Quiz"
                            <?= $current_category ===
                                'Quiz'
                                ? 'selected'
                                : '' ?>
                        >
                            📝 Quiz (Google Form)
                        </option>

                        <option
                            value="Exam"
                            <?= $current_category ===
                                'Exam'
                                ? 'selected'
                                : '' ?>
                        >
                            📋 Exam (Google Form)
                        </option>

                        <option
                            value="Online Class"
                            <?= $current_category ===
                                'Online Class'
                                ? 'selected'
                                : '' ?>
                        >
                            📹 Online Class (Google Meet)
                        </option>

                    </select>

                </div>


                <div class="form-group">

                    <label>
                        Title
                    </label>

                    <input
                        type="text"
                        name="title"
                        placeholder="e.g. Midterm Examination for IT 311"
                        value="<?= e(
                            $edit_data['title'] ?? ''
                        ) ?>"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>
                        Subject / Course Code
                    </label>

                    <input
                        type="text"
                        name="subject"
                        placeholder="e.g. ITELECT1"
                        style="text-transform: uppercase;"
                        oninput="this.value = this.value.toUpperCase();"
                        value="<?= e(
                            $edit_data['subject'] ?? ''
                        ) ?>"
                    >

                </div>


                <div
                    class="form-target-grid"
                    style="
                        display:grid;
                        grid-template-columns:
                            1fr 1fr;
                        gap:12px;
                    "
                >

                    <div class="form-group">
                        <label for="target_course">Target Course</label>
                        <select name="target_course" id="target_course" required>
                            <?php $selectedCourse = $edit_data['target_course'] ?? 'All Courses'; ?>
                            <option value="All Courses" <?= $selectedCourse === 'All Courses' ? 'selected' : '' ?>>All Courses</option>
                            <option value="BSIT" <?= $selectedCourse === 'BSIT' ? 'selected' : '' ?>>BSIT</option>
                            <option value="BSCpE" <?= $selectedCourse === 'BSCpE' ? 'selected' : '' ?>>BSCpE</option>
                            <option value="BSBA" <?= $selectedCourse === 'BSBA' ? 'selected' : '' ?>>BSBA</option>
                            <option value="BSE" <?= $selectedCourse === 'BSE' ? 'selected' : '' ?>>BSE</option>
                            <option value="BECE" <?= $selectedCourse === 'BECE' ? 'selected' : '' ?>>BECE</option>
                            <option value="BEE" <?= $selectedCourse === 'BEE' ? 'selected' : '' ?>>BEE</option>
                            <option value="BTLE" <?= $selectedCourse === 'BTLE' ? 'selected' : '' ?>>BTLE</option>
                        </select>
                        <div class="file-help">Choose which course can see this announcement.</div>
                    </div>

                    <div class="form-group">

                        <label>
                            Target Year Level
                        </label>

                        <?php
                        $yl =
                            $edit_data['year_level']
                            ?? 'All Years';
                        ?>

                        <select name="year_level">

                            <option
                                value="All Years"
                                <?= $yl === 'All Years'
                                    ? 'selected'
                                    : '' ?>
                            >
                                All Years
                            </option>

                            <option
                                value="1st Year"
                                <?= $yl === '1st Year'
                                    ? 'selected'
                                    : '' ?>
                            >
                                1st Year
                            </option>

                            <option
                                value="2nd Year"
                                <?= $yl === '2nd Year'
                                    ? 'selected'
                                    : '' ?>
                            >
                                2nd Year
                            </option>

                            <option
                                value="3rd Year"
                                <?= $yl === '3rd Year'
                                    ? 'selected'
                                    : '' ?>
                            >
                                3rd Year
                            </option>

                            <option
                                value="4th Year"
                                <?= $yl === '4th Year'
                                    ? 'selected'
                                    : '' ?>
                            >
                                4th Year
                            </option>

                        </select>

                    </div>


                    <div class="form-group">

                        <label>
                            Target Section
                        </label>

                        <input
                            type="text"
                            name="section"
                            placeholder="e.g. 3A or ALL"
                            style="text-transform: uppercase;"
                            oninput="this.value = this.value.toUpperCase();"
                            value="<?= e(
                                $edit_data['section']
                                ?? 'All'
                            ) ?>"
                        >

                    </div>

                </div>


                <div class="form-group">

                    <label>
                        Message / Instructions
                    </label>

                    <textarea
                        name="content"
                        placeholder="Type instructions or details here..."
                        required
                    ><?= e(
                        $edit_data['message'] ?? ''
                    ) ?></textarea>

                </div>


                <!-- FILE ATTACHMENT -->

                <div class="form-group">

                    <label>
                        📎 File Attachment
                    </label>

                    <div class="file-upload-box">

                        <input
                            type="file"
                            name="attachments[]"
                            multiple
                        >

                        <div class="file-help">

                            Optional. You may select multiple files, including documents, ZIP archives, videos, images, and other file types. Uploads use your available professor storage quota. PHP/Apache server limits must also be configured to allow large requests.<br>

                            <strong>
                                Google Drive / Google Forms /
                                Google Meet links do not use
                                NoteSphere storage.
                            </strong>

                        </div>


                        <?php if ($edit_data): ?>
                            <?php $existingAttachmentList = ns_get_announcement_attachments($conn, (int)$edit_data['id'], $edit_data['attachment'] ?? null); ?>
                            <?php if ($existingAttachmentList): ?>
                                <div class="current-attachment">
                                    <strong>📎 Current attachments:</strong><br>
                                    <?php foreach ($existingAttachmentList as $existingAttachment): ?>
                                        <?= e($existingAttachment['original_name'] ?? basename($existingAttachment['file_path'])) ?><br>
                                    <?php endforeach; ?>
                                    <span style="font-weight:500;opacity:0.8;">Select additional files above to attach more files to this announcement.</span>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>

                    </div>

                </div>


                <!-- GOOGLE MEET -->

                <div
                    class="form-group"
                    id="meetField"
                    style="display:block;"
                >

                    <label>
                        📹 Google Meet Link
                    </label>

                    <input
                        type="url"
                        name="meet_link"
                        placeholder="https://meet.google.com/xxx-xxxx-xxx"
                        value="<?= e(
                            $edit_data['meet_link']
                            ?? ''
                        ) ?>"
                    >

                </div>


                <!-- GOOGLE FORM -->

                <div
                    class="form-group"
                    id="quizField"
                    style="display:block;"
                >

                    <label>
                        📝 Google Form Quiz/Exam Link
                    </label>

                    <input
                        type="url"
                        name="quiz_link"
                        placeholder="https://forms.gle/xxxxxxxx"
                        value="<?= e(
                            $edit_data['quiz_link']
                            ?? ''
                        ) ?>"
                    >

                </div>



                <!-- YOUTUBE VIDEO LINK -->
                <div class="form-group" id="youtubeField">
                    <label>▶️ YouTube Video Link</label>
                    <input
                        type="url"
                        name="youtube_link"
                        placeholder="https://www.youtube.com/watch?v=..."
                        value="<?= e($edit_data['youtube_link'] ?? '') ?>"
                    >
                    <div class="file-help">Optional. YouTube links are external and do not use NoteSphere storage.</div>
                </div>

                <!-- DEADLINE -->

                <div
                    class="form-group"
                    id="deadlineField"
                    style="display:block;"
                >

                    <label>
                        ⏰ Submission Deadline
                    </label>

                    <?php

                    $dl_val = '';

                    if (
                        !empty(
                            $edit_data['deadline']
                        )
                    ) {

                        $dl_val = date(
                            'Y-m-d\TH:i',
                            strtotime(
                                $edit_data['deadline']
                            )
                        );
                    }

                    ?>

                    <input
                        type="datetime-local"
                        name="deadline"
                        value="<?= e($dl_val) ?>"
                    >

                </div>


                <button
                    type="submit"
                    name="save_announcement"
                    class="btn-submit"
                >
                    <?= $edit_data
                        ? 'Save Changes'
                        : 'Publish Post'
                    ?>
                </button>


                <?php if ($edit_data): ?>

                    <a
                        href="<?= e($current_page) ?>"
                        class="btn-cancel-edit"
                    >
                        Cancel Edit
                    </a>

                <?php endif; ?>

            </form>

        </div>


        <!-- =================================================
             HISTORY / ARCHIVE
        ================================================== -->

        <div class="card">

            <div class="history-header">

                <div>

                    <h3 class="history-heading">
                        <?= $show_archive
                            ? 'Archived Posts'
                            : 'Your Post History'
                        ?>
                    </h3>

                    <div class="history-period">
                        <?= $show_archive
                            ? 'Manage your archived announcements.'
                            : 'Current period: ' . e($active_sem) . ' (' . e($active_year) . ')'
                        ?>
                    </div>

                </div>

                <div class="history-header-actions">

                    <?php if ($show_archive): ?>

                        <?php if (!empty($archived_announcements)): ?>

                            <button
                                type="button"
                                class="delete-all-archive-btn"
                                onclick="confirmDeleteAllArchived()"
                            >
                                🗑️ Delete All
                            </button>

                        <?php endif; ?>

                        <a
                            href="<?= e($current_page) ?>"
                            class="back-history-btn"
                        >
                            ← Current Posts
                        </a>

                    <?php else: ?>

                        <a
                            href="<?= e($current_page) ?>?archive=1"
                            class="archive-btn"
                        >
                            🗃️ View Archive
                        </a>

                    <?php endif; ?>

                </div>

            </div>

            <div class="history-scroll">

                <?php if ($show_archive): ?>

                    <?php if (empty($grouped_archived)): ?>

                        <div class="empty-history">
                            No archived posts found.
                        </div>

                    <?php else: ?>

                        <?php foreach ($grouped_archived as $year_level => $sections): ?>

                            <?php
                            $year_count = 0;
                            foreach ($sections as $subjects) {
                                foreach ($subjects as $posts) {
                                    $year_count += count($posts);
                                }
                            }
                            ?>

                            <details class="history-dropdown year-dropdown">

                                <summary class="history-summary year-summary">
                                    <span class="summary-left">
                                        <span class="summary-arrow">▶</span>
                                        <span>🎓 <?= e($year_level) ?></span>
                                    </span>
                                    <span class="summary-count">
                                        <?= $year_count ?> <?= $year_count === 1 ? 'post' : 'posts' ?>
                                    </span>
                                </summary>

                                <div class="dropdown-content year-content">

                                    <?php foreach ($sections as $section => $subjects): ?>

                                        <?php
                                        $section_count = 0;
                                        foreach ($subjects as $posts) {
                                            $section_count += count($posts);
                                        }
                                        ?>

                                        <details class="history-dropdown section-dropdown">

                                            <summary class="history-summary section-summary">
                                                <span class="summary-left">
                                                    <span class="summary-arrow">▶</span>
                                                    <span>📂 Section <?= e($section) ?></span>
                                                </span>
                                                <span class="summary-count">
                                                    <?= $section_count ?> <?= $section_count === 1 ? 'post' : 'posts' ?>
                                                </span>
                                            </summary>

                                            <div class="dropdown-content section-content">

                                                <?php foreach ($subjects as $subject => $posts): ?>

                                                    <details class="history-dropdown subject-dropdown">

                                                        <summary class="history-summary subject-summary">
                                                            <span class="summary-left">
                                                                <span class="summary-arrow">▶</span>
                                                                <span>📚 <?= e($subject) ?></span>
                                                            </span>
                                                            <span class="summary-count">
                                                                <?= count($posts) ?> <?= count($posts) === 1 ? 'post' : 'posts' ?>
                                                            </span>
                                                        </summary>

                                                        <div class="dropdown-content subject-content">

                                                            <?php foreach ($posts as $a): ?>

                                                                <div class="ann-list-item">

                                                                    <div class="announcement-top-row">

                                                                        <span class="announcement-category">
                                                                            [<?= e($a['category']) ?>]
                                                                        </span>

                                                                        <span class="announcement-period">
                                                                            <?= e($a['semester'] ?? 'Semester not set') ?>
                                                                            (<?= e($a['academic_year'] ?? 'Academic year not set') ?>)
                                                                        </span>

                                                                    </div>

                                                                    <div class="ann-title">
                                                                        <?= e($a['title']) ?>
                                                                    </div>

                                                                    <div class="ann-meta">
                                                                        📚 <?= e($a['subject']) ?>
                                                                        &nbsp;|&nbsp;
                                                                        🎓 <?= e($a['target_course'] ?? 'All Courses') ?>
                                                                        &nbsp;|&nbsp;
                                                                        🎯 <?= e($a['year_level']) ?> - <?= e($a['section']) ?>
                                                                    </div>

                                                                    <div class="announcement-message">
                                                                        <?= nl2br(e($a['message'])) ?>
                                                                    </div>

                                                                    <?php foreach (ns_get_announcement_attachments($conn, (int)$a['id'], $a['attachment'] ?? null) as $att): ?>
                                                                        <?= ns_render_announcement_attachment($att) ?>
                                                                    <?php endforeach; ?>

                                                                    <?php if (!empty($a['meet_link'])): ?>
                                                                        <a
                                                                            href="<?= e($a['meet_link']) ?>"
                                                                            target="_blank"
                                                                            rel="noopener noreferrer"
                                                                            class="lms-badge"
                                                                        >
                                                                            📹 Join Meet
                                                                        </a>
                                                                    <?php endif; ?>

                                                                    <?php if (!empty($a['quiz_link'])): ?>
                                                                        <a
                                                                            href="<?= e($a['quiz_link']) ?>"
                                                                            target="_blank"
                                                                            rel="noopener noreferrer"
                                                                            class="lms-badge"
                                                                        >
                                                                            📝 Open Form
                                                                        </a>
                                                                    <?php endif; ?>
                                                                    <?php if (!empty($a['youtube_link'])): ?>
                                                                        <a
                                                                            href="<?= e($a['youtube_link']) ?>"
                                                                            target="_blank"
                                                                            rel="noopener noreferrer"
                                                                            class="lms-badge"
                                                                        >
                                                                            ▶️ Watch YouTube
                                                                        </a>
                                                                    <?php endif; ?>

                                                                    <?php if (
                                                                        in_array(
                                                                            $a['category'],
                                                                            ['Activity', 'Quiz', 'Exam'],
                                                                            true
                                                                        )
                                                                    ): ?>
                                                                        <a
                                                                            href="view_submissions.php?announcement_id=<?= (int) $a['id'] ?>"
                                                                            class="lms-badge submissions-badge"
                                                                        >
                                                                            📁 Open Submissions
                                                                        </a>
                                                                    <?php endif; ?>

                                                                    <?php if (!empty($a['deadline'])): ?>
                                                                        <div class="announcement-deadline">
                                                                            ⏳ Deadline: <?= e($a['deadline']) ?>
                                                                        </div>
                                                                    <?php endif; ?>

                                                                    <div class="announcement-footer">

                                                                        <span class="announcement-date">
                                                                            <?= e($a['created_at']) ?>
                                                                        </span>

                                                                        <div class="announcement-actions">

                                                                            <a
                                                                                href="<?= e($current_page) ?>?edit=<?= (int) $a['id'] ?>"
                                                                                class="edit-action"
                                                                            >
                                                                                EDIT
                                                                            </a>

                                                                            <button
                                                                                type="button"
                                                                                class="delete-action"
                                                                                onclick="confirmDelete(<?= (int) $a['id'] ?>, <?= json_encode($a['title'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)"
                                                                            >
                                                                                DELETE
                                                                            </button>

                                                                        </div>

                                                                    </div>

                                                                    <button
                                                                        type="button"
                                                                        class="post-archive-btn"
                                                                        onclick="confirmArchive(<?= (int) $a['id'] ?>, <?= json_encode($a['title'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)"
                                                                    >
                                                                        🗃️ Archive Post
                                                                    </button>

                                                                </div>

                                                            <?php endforeach; ?>

                                                        </div>

                                                    </details>

                                                <?php endforeach; ?>

                                            </div>

                                        </details>

                                    <?php endforeach; ?>

                                </div>

                            </details>

                        <?php endforeach; ?>

                    <?php endif; ?>

                <?php else: ?>

                    <?php if (empty($grouped_announcements)): ?>

                        <div class="empty-history">
                            No posts have been created for the current academic period.
                        </div>

                    <?php else: ?>

                        <?php foreach ($grouped_announcements as $year_level => $sections): ?>

                            <?php
                            $year_count = 0;
                            foreach ($sections as $subjects) {
                                foreach ($subjects as $posts) {
                                    $year_count += count($posts);
                                }
                            }
                            ?>

                            <details class="history-dropdown year-dropdown">

                                <summary class="history-summary year-summary">
                                    <span class="summary-left">
                                        <span class="summary-arrow">▶</span>
                                        <span>🎓 <?= e($year_level) ?></span>
                                    </span>
                                    <span class="summary-count">
                                        <?= $year_count ?> <?= $year_count === 1 ? 'post' : 'posts' ?>
                                    </span>
                                </summary>

                                <div class="dropdown-content year-content">

                                    <?php foreach ($sections as $section => $subjects): ?>

                                        <?php
                                        $section_count = 0;
                                        foreach ($subjects as $posts) {
                                            $section_count += count($posts);
                                        }
                                        ?>

                                        <details class="history-dropdown section-dropdown">

                                            <summary class="history-summary section-summary">
                                                <span class="summary-left">
                                                    <span class="summary-arrow">▶</span>
                                                    <span>📂 Section <?= e($section) ?></span>
                                                </span>
                                                <span class="summary-count">
                                                    <?= $section_count ?> <?= $section_count === 1 ? 'post' : 'posts' ?>
                                                </span>
                                            </summary>

                                            <div class="dropdown-content section-content">

                                                <?php foreach ($subjects as $subject => $posts): ?>

                                                    <details class="history-dropdown subject-dropdown">

                                                        <summary class="history-summary subject-summary">
                                                            <span class="summary-left">
                                                                <span class="summary-arrow">▶</span>
                                                                <span>📚 <?= e($subject) ?></span>
                                                            </span>
                                                            <span class="summary-count">
                                                                <?= count($posts) ?> <?= count($posts) === 1 ? 'post' : 'posts' ?>
                                                            </span>
                                                        </summary>

                                                        <div class="dropdown-content subject-content">

                                                            <?php foreach ($posts as $a): ?>

                                                                <div class="ann-list-item">

                                                                    <div class="announcement-top-row">
                                                                        <span class="announcement-category">
                                                                            [<?= e($a['category']) ?>]
                                                                        </span>

                                                                        <span class="announcement-period">
                                                                            <?= e($active_sem) ?>
                                                                            (<?= e($active_year) ?>)
                                                                        </span>
                                                                    </div>

                                                                    <div class="ann-title">
                                                                        <?= e($a['title']) ?>
                                                                    </div>

                                                                    <div class="ann-meta">
                                                                        📚 <?= e($a['subject']) ?>
                                                                        &nbsp;|&nbsp;
                                                                        🎓 <?= e($a['target_course'] ?? 'All Courses') ?>
                                                                        &nbsp;|&nbsp;
                                                                        🎯 <?= e($a['year_level']) ?> - <?= e($a['section']) ?>
                                                                    </div>

                                                                    <div class="announcement-message">
                                                                        <?= nl2br(e($a['message'])) ?>
                                                                    </div>

                                                                    <?php foreach (ns_get_announcement_attachments($conn, (int)$a['id'], $a['attachment'] ?? null) as $att): ?>
                                                                        <?= ns_render_announcement_attachment($att) ?>
                                                                    <?php endforeach; ?>

                                                                    <?php if (!empty($a['meet_link'])): ?>
                                                                        <a
                                                                            href="<?= e($a['meet_link']) ?>"
                                                                            target="_blank"
                                                                            rel="noopener noreferrer"
                                                                            class="lms-badge"
                                                                        >
                                                                            📹 Join Meet
                                                                        </a>
                                                                    <?php endif; ?>

                                                                    <?php if (!empty($a['quiz_link'])): ?>
                                                                        <a
                                                                            href="<?= e($a['quiz_link']) ?>"
                                                                            target="_blank"
                                                                            rel="noopener noreferrer"
                                                                            class="lms-badge"
                                                                        >
                                                                            📝 Open Form
                                                                        </a>
                                                                    <?php endif; ?>
                                                                    <?php if (!empty($a['youtube_link'])): ?>
                                                                        <a
                                                                            href="<?= e($a['youtube_link']) ?>"
                                                                            target="_blank"
                                                                            rel="noopener noreferrer"
                                                                            class="lms-badge"
                                                                        >
                                                                            ▶️ Watch YouTube
                                                                        </a>
                                                                    <?php endif; ?>

                                                                    <?php if (
                                                                        in_array(
                                                                            $a['category'],
                                                                            ['Activity', 'Quiz', 'Exam'],
                                                                            true
                                                                        )
                                                                    ): ?>
                                                                        <a
                                                                            href="view_submissions.php?announcement_id=<?= (int) $a['id'] ?>"
                                                                            class="lms-badge submissions-badge"
                                                                        >
                                                                            📁 Open Submissions
                                                                        </a>
                                                                    <?php endif; ?>

                                                                    <?php if (!empty($a['deadline'])): ?>
                                                                        <div class="announcement-deadline">
                                                                            ⏳ Deadline: <?= e($a['deadline']) ?>
                                                                        </div>
                                                                    <?php endif; ?>

                                                                    <div class="announcement-footer">

                                                                        <span class="announcement-date">
                                                                            <?= e($a['created_at']) ?>
                                                                        </span>

                                                                        <div class="announcement-actions">

                                                                            <a
                                                                                href="<?= e($current_page) ?>?edit=<?= (int) $a['id'] ?>"
                                                                                class="edit-action"
                                                                            >
                                                                                EDIT
                                                                            </a>

                                                                            <button
                                                                                type="button"
                                                                                class="delete-action"
                                                                                onclick="confirmDelete(<?= (int) $a['id'] ?>, <?= json_encode($a['title'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)"
                                                                            >
                                                                                DELETE
                                                                            </button>

                                                                        </div>

                                                                    </div>

                                                                    <button
                                                                        type="button"
                                                                        class="post-archive-btn"
                                                                        onclick="confirmArchive(<?= (int) $a['id'] ?>, <?= json_encode($a['title'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)"
                                                                    >
                                                                        🗃️ Archive Post
                                                                    </button>

                                                                </div>

                                                            <?php endforeach; ?>

                                                        </div>

                                                    </details>

                                                <?php endforeach; ?>

                                            </div>

                                        </details>

                                    <?php endforeach; ?>

                                </div>

                            </details>

                        <?php endforeach; ?>

                    <?php endif; ?>

                <?php endif; ?>

            </div>

        </div>

    </div>

</main>


<!-- =========================================================
     HIDDEN ACTION FORMS
========================================================= -->

<form method="POST" id="archiveForm" style="display:none;">
    <input type="hidden" name="archive_id" id="archiveId">
    <input type="hidden" name="archive_announcement" value="1">
</form>

<form method="POST" id="restoreForm" style="display:none;">
    <input type="hidden" name="restore_id" id="restoreId">
    <input type="hidden" name="restore_announcement" value="1">
</form>

<form method="POST" id="permanentDeleteForm" style="display:none;">
    <input type="hidden" name="permanent_delete_id" id="permanentDeleteId">
    <input type="hidden" name="permanent_delete_announcement" value="1">
</form>

<form method="POST" id="deleteActiveForm" style="display:none;">
    <input type="hidden" name="delete_active_id" id="deleteActiveId">
    <input type="hidden" name="delete_active_announcement" value="1">
</form>

<form method="POST" id="deleteAllArchivedForm" style="display:none;">
    <input type="hidden" name="delete_all_archived" value="1">
</form>

<script>

// =========================================================
// MOBILE SIDEBAR
// =========================================================

function toggleMobileSidebar() {

    document.body.classList.toggle(
        'sidebar-open'
    );
}


// =========================================================
// GLOBAL THEME
// =========================================================

function toggleGlobalTheme() {

    document.body.classList.toggle(
        'light-mode'
    );

    const isLight =
        document.body.classList.contains(
            'light-mode'
        );

    localStorage.setItem(
        'notesphere_theme',
        isLight ? 'light' : 'dark'
    );

    updateThemeButton(isLight);
}


function updateThemeButton(isLight) {

    document.getElementById(
        'themeIcon'
    ).textContent =
        isLight ? '🌙' : '🌞';

    document.getElementById(
        'themeText'
    ).textContent =
        isLight
            ? 'Dark Mode'
            : 'Light Mode';
}


if (
    localStorage.getItem(
        'notesphere_theme'
    ) === 'light'
) {

    document.body.classList.add(
        'light-mode'
    );

    updateThemeButton(true);
}


// =========================================================
// CONDITIONAL FIELDS
// =========================================================

function toggleFields() {

    const cat =
        document.getElementById(
            'categorySelect'
        ).value;

    const meetField =
        document.getElementById(
            'meetField'
        );

    const quizField =
        document.getElementById(
            'quizField'
        );

    const deadlineField =
        document.getElementById(
            'deadlineField'
        );

    // Keep both link fields and the deadline visible for every category.
    // Links remain optional; a deadline is required for activities, quizzes, and exams.
    meetField.style.display = 'block';
    quizField.style.display = 'block';
    deadlineField.style.display = 'block';

    const deadlineInput = deadlineField.querySelector('input[name="deadline"]');
    if (deadlineInput) {
        deadlineInput.required = ['Activity', 'Quiz', 'Exam'].includes(cat);
    }
}


toggleFields();


// =========================================================
// AUTO SCROLL WHEN EDITING
// =========================================================

<?php if ($edit_data): ?>

window.addEventListener(
    'DOMContentLoaded',
    () => {

        const formCard =
            document.getElementById(
                'editFormCard'
            );

        if (formCard) {

            formCard.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }
    }
);

<?php endif; ?>


// =========================================================
// ACTIVE DELETE CONFIRMATION
// =========================================================

function confirmDelete(id, title) {

    Swal.fire({
        title: 'Delete Post?',
        html:
            `Are you sure you want to delete <strong>"${escapeHtml(title)}"</strong>?<br><br><small>This action cannot be undone.</small>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, Delete',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#ff4d4d',
        cancelButtonColor: '#64748b',
        reverseButtons: true
    }).then((result) => {

        if (result.isConfirmed) {
            document.getElementById('deleteActiveId').value = id;
            document.getElementById('deleteActiveForm').submit();
        }
    });
}


// =========================================================
// ARCHIVE CONFIRMATION
// =========================================================

function confirmArchive(id, title) {

    Swal.fire({
        title: 'Archive Post?',
        html:
            `Move <strong>"${escapeHtml(title)}"</strong> to the archive?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Archive',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#3498db',
        cancelButtonColor: '#64748b',
        reverseButtons: true
    }).then((result) => {

        if (result.isConfirmed) {
            document.getElementById('archiveId').value = id;
            document.getElementById('archiveForm').submit();
        }
    });
}


// =========================================================
// RESTORE CONFIRMATION
// =========================================================

function confirmRestore(id, title) {

    Swal.fire({
        title: 'Restore Post?',
        html:
            `Restore <strong>"${escapeHtml(title)}"</strong> to the current academic period?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Restore',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#2ecc71',
        cancelButtonColor: '#64748b',
        reverseButtons: true
    }).then((result) => {

        if (result.isConfirmed) {
            document.getElementById('restoreId').value = id;
            document.getElementById('restoreForm').submit();
        }
    });
}


// =========================================================
// PERMANENT DELETE CONFIRMATION
// =========================================================

function confirmPermanentDelete(id, title) {

    Swal.fire({
        title: 'Delete Permanently?',
        html:
            `Are you sure you want to permanently delete <strong>"${escapeHtml(title)}"</strong>?<br><br><small>The post and its attachment will be permanently removed.</small>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, Delete Permanently',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#d33',
        cancelButtonColor: '#64748b',
        reverseButtons: true
    }).then((result) => {

        if (result.isConfirmed) {
            document.getElementById('permanentDeleteId').value = id;
            document.getElementById('permanentDeleteForm').submit();
        }
    });
}


// =========================================================
// DELETE ALL ARCHIVED CONFIRMATION
// =========================================================

function confirmDeleteAllArchived() {

    Swal.fire({
        title: 'Delete All Archived Posts?',
        html:
            'This will permanently delete <strong>all archived posts</strong> belonging to your account.<br><br><small>The posts and their attachments will be permanently removed. This action cannot be undone.</small>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, Delete All',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#d33',
        cancelButtonColor: '#64748b',
        reverseButtons: true,
        focusCancel: true
    }).then((result) => {

        if (result.isConfirmed) {
            document.getElementById('deleteAllArchivedForm').submit();
        }
    });
}


// =========================================================
// ESCAPE HTML
// =========================================================

function escapeHtml(value) {

    return String(value)

        .replace(
            /&/g,
            '&amp;'
        )

        .replace(
            /</g,
            '&lt;'
        )

        .replace(
            />/g,
            '&gt;'
        )

        .replace(
            /"/g,
            '&quot;'
        )

        .replace(
            /'/g,
            '&#039;'
        );
}


// =========================================================
// SWEETALERT FLASH MESSAGES
// =========================================================

<?php if (!empty($success_msg)): ?>

Swal.fire({

    icon: 'success',

    title: 'Success',

    text: <?= json_encode(
        $success_msg,
        JSON_HEX_TAG |
        JSON_HEX_APOS |
        JSON_HEX_QUOT |
        JSON_HEX_AMP
    ) ?>,

    confirmButtonColor:
        '#2ecc71',

    timer: 2200,

    timerProgressBar: true

});

<?php endif; ?>


<?php if (!empty($error_msg)): ?>

Swal.fire({

    icon: 'error',

    title: 'Error',

    text: <?= json_encode(
        $error_msg,
        JSON_HEX_TAG |
        JSON_HEX_APOS |
        JSON_HEX_QUOT |
        JSON_HEX_AMP
    ) ?>,

    confirmButtonColor:
        '#ff4d4d'

});

<?php endif; ?>

</script>
</body>
</html>