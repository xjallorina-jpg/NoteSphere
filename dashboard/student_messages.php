<?php
/**
 * NoteSphere Messenger (existing `messages` table edition)
 * Place this file in: NoteSphere/dashboard/student_messages.php
 * Uses the existing messages table with sender_id, receiver_id, message,
 * file_path, file_type and created_at. No ns_conversation_messages tables are used.
 */
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../includes/db_config.php';
require_once __DIR__ . '/../includes/notification_counts.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}
if (!isset($conn) || !($conn instanceof PDO)) {
    http_response_code(500);
    exit('Database connection is unavailable. Check includes/db_config.php.');
}
$currentUserId = (int) $_SESSION['user_id'];
$projectRoot = dirname(__DIR__);
$ns_badge_counts = ns_notification_counts($conn, $currentUserId);

function nsMsgJson(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function nsMsgEsc(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function nsMsgTableColumns(PDO $db, string $table): array {
    // Only internal, hard-coded table names are passed here.
    $allowed = ['users', 'messages'];
    if (!in_array($table, $allowed, true)) throw new RuntimeException('Unsupported table lookup.');
    $result = $db->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
    return array_map(static fn(array $row): string => (string)$row['Field'], $result);
}
function nsMsgFirstColumn(array $columns, array $candidates): ?string {
    foreach ($candidates as $candidate) if (in_array($candidate, $columns, true)) return $candidate;
    return null;
}
function nsMsgSafeFilename(string $name): string {
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name) ?: 'attachment';
    if (function_exists('mb_substr')) return mb_substr($name, 0, 180);
    return substr($name, 0, 180);
}
function nsMsgLength(string $value): int {
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}
function nsMsgBytes(int|float|null $bytes): string {
    $n = max(0, (float)($bytes ?? 0));
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($n >= 1024 && $i < count($units) - 1) { $n /= 1024; $i++; }
    return number_format($n, $i === 0 ? 0 : 2) . ' ' . $units[$i];
}
function nsMsgAttachmentKind(string $mime, string $ext): ?string {
    $mime = strtolower(trim($mime));
    $ext = strtolower(trim($ext));
    $images = ['image/jpeg','image/png','image/gif','image/webp'];
    $audio = ['audio/webm','audio/ogg','audio/mpeg','audio/mp4','audio/aac','audio/wav','audio/x-wav','audio/3gpp','video/webm'];
    $video = ['video/mp4','video/webm','video/quicktime'];
    $docs = [
        'application/pdf','text/plain','application/zip','application/x-zip-compressed',
        'application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-powerpoint','application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.oasis.opendocument.text','application/vnd.oasis.opendocument.spreadsheet',
        'application/vnd.oasis.opendocument.presentation','application/octet-stream'
    ];
    if (in_array($mime, $images, true) && in_array($ext, ['jpg','jpeg','png','gif','webp'], true)) return 'image';
    if (in_array($mime, $audio, true) && in_array($ext, ['webm','ogg','mp3','m4a','mp4','aac','wav','3gp'], true)) return 'audio';
    if (in_array($mime, $video, true) && in_array($ext, ['mp4','webm','mov'], true)) return 'video';
    $allowedExts = ['pdf','txt','zip','doc','docx','ppt','pptx','xls','xlsx','odt','ods','odp'];
    if (in_array($ext, $allowedExts, true) && (in_array($mime, $docs, true) || $mime === 'application/octet-stream')) return 'file';
    return null;
}
function nsMsgResolveStoredPath(string $relativePath, string $projectRoot): ?string {
    $relativePath = str_replace('\\', '/', trim($relativePath));
    if ($relativePath === '' || str_starts_with($relativePath, '/') || preg_match('/^[A-Za-z]:/', $relativePath)) return null;
    foreach (explode('/', $relativePath) as $part) if ($part === '..' || $part === '.') return null;
    if (!str_starts_with($relativePath, 'uploads/')) return null;
    $uploadsRoot = realpath($projectRoot . DIRECTORY_SEPARATOR . 'uploads');
    $real = realpath($projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
    if ($uploadsRoot === false || $real === false || !is_file($real)) return null;
    $prefix = rtrim($uploadsRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (strncmp($real, $prefix, strlen($prefix)) !== 0) return null;
    return $real;
}

try {
    // Existing NoteSphere schema. This script does not create replacement conversation/message tables.
    $userCols = nsMsgTableColumns($conn, 'users');
    $messageCols = nsMsgTableColumns($conn, 'messages');
    $idCol = nsMsgFirstColumn($userCols, ['user_id', 'id']);
    $nameCol = nsMsgFirstColumn($userCols, ['full_name', 'name', 'username', 'student_name']);
    $studentNoCol = nsMsgFirstColumn($userCols, ['student_number', 'student_no', 'student_id', 'school_id', 'id_number', 'student_number_id']);
    $roleCol = nsMsgFirstColumn($userCols, ['role', 'user_role', 'account_type']);
    $picCol = nsMsgFirstColumn($userCols, ['profile_pic', 'profile_picture', 'avatar']);
    if (!$idCol || !$nameCol) throw new RuntimeException('The users table needs a user ID and a name column.');
    foreach (['id','sender_id','receiver_id','message','file_path','file_type','created_at'] as $requiredCol) {
        if (!in_array($requiredCol, $messageCols, true)) throw new RuntimeException('The messages table is missing the required column: ' . $requiredCol);
    }

    // Add only metadata columns missing from the existing messages table.
    $migrations = [
        'message_type' => "ALTER TABLE `messages` ADD COLUMN `message_type` VARCHAR(20) NOT NULL DEFAULT 'text'",
        'file_name' => 'ALTER TABLE `messages` ADD COLUMN `file_name` VARCHAR(255) NULL',
        'file_mime' => 'ALTER TABLE `messages` ADD COLUMN `file_mime` VARCHAR(150) NULL',
        'file_size' => 'ALTER TABLE `messages` ADD COLUMN `file_size` BIGINT UNSIGNED NULL'
    ];
    foreach ($migrations as $column => $sql) {
        if (!in_array($column, $messageCols, true)) $conn->exec($sql);
    }
    // Per-user settings for each one-to-one conversation. The existing messages table has no conversation_id.
    $conn->exec("CREATE TABLE IF NOT EXISTS `message_preferences` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` BIGINT NOT NULL,
        `other_user_id` BIGINT NOT NULL,
        `theme_name` VARCHAR(24) NOT NULL DEFAULT 'default',
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `unique_message_theme` (`user_id`,`other_user_id`),
        KEY `idx_message_preferences_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Group conversations are stored separately; existing one-to-one messages remain untouched.
    $conn->exec("CREATE TABLE IF NOT EXISTS `message_groups` (
        `group_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `group_name` VARCHAR(120) NOT NULL,
        `created_by` BIGINT NOT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`group_id`), KEY `idx_message_groups_creator` (`created_by`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $conn->exec("CREATE TABLE IF NOT EXISTS `message_group_members` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `group_id` BIGINT UNSIGNED NOT NULL,
        `user_id` BIGINT NOT NULL,
        `member_role` VARCHAR(16) NOT NULL DEFAULT 'member',
        `joined_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`), UNIQUE KEY `unique_group_member` (`group_id`,`user_id`),
        KEY `idx_group_members_user` (`user_id`), KEY `idx_group_members_group` (`group_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $conn->exec("CREATE TABLE IF NOT EXISTS `message_group_messages` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `group_id` BIGINT UNSIGNED NOT NULL,
        `sender_id` BIGINT NOT NULL,
        `message` TEXT NULL,
        `file_path` VARCHAR(500) NULL,
        `file_type` VARCHAR(30) NULL,
        `message_type` VARCHAR(20) NOT NULL DEFAULT 'text',
        `file_name` VARCHAR(255) NULL,
        `file_mime` VARCHAR(150) NULL,
        `file_size` BIGINT UNSIGNED NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`), KEY `idx_group_messages_group_id` (`group_id`,`id`), KEY `idx_group_messages_sender` (`sender_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $st = $conn->prepare('SELECT * FROM `users` WHERE `' . $idCol . '` = ? LIMIT 1');
    $st->execute([$currentUserId]);
    $currentUser = $st->fetch(PDO::FETCH_ASSOC);
    if (!$currentUser) { session_destroy(); header('Location: ../auth/login.php'); exit; }
    $currentName = (string)($currentUser[$nameCol] ?? 'User');
    $accountRole = $roleCol ? strtolower(trim((string)($currentUser[$roleCol] ?? 'student'))) : 'student';
    if (in_array($accountRole, ['admin', 'administrator', 'system administrator'], true)) {
        $dashboardUrl = '../admin/admin_dashboard.php';
    } elseif (in_array($accountRole, ['professor', 'teacher', 'faculty'], true)) {
        $dashboardUrl = '../professor/professor_dashboard.php';
    } else {
        $dashboardUrl = 'student_home.php';
    }
    // The central storage helper supports student/professor quotas. Keep its expected role values.
    $role = in_array($accountRole, ['student', 'professor'], true) ? $accountRole : 'student';
    $csrf = $_SESSION['notesphere_messages_csrf'] ??= bin2hex(random_bytes(32));

    // Secure authenticated download/preview. Only participants can access a message attachment.
    if (isset($_GET['download'])) {
        $messageId = max(0, (int)$_GET['download']);
        $isGroupDownload = (int)($_GET['group'] ?? 0) === 1;
        if ($isGroupDownload) {
            $st = $conn->prepare('SELECT gm.`id`,gm.`sender_id`,gm.`group_id`,gm.`file_path`,gm.`file_type`,gm.`file_name`,gm.`file_mime`,gm.`message_type` FROM `message_group_messages` gm INNER JOIN `message_group_members` mem ON mem.`group_id`=gm.`group_id` AND mem.`user_id`=? WHERE gm.`id`=? LIMIT 1');
            $st->execute([$currentUserId,$messageId]);
        } else {
            $st = $conn->prepare('SELECT `id`,`sender_id`,`receiver_id`,`file_path`,`file_type`,`file_name`,`file_mime`,`message_type` FROM `messages` WHERE `id` = ? AND (`sender_id` = ? OR `receiver_id` = ?) LIMIT 1');
            $st->execute([$messageId, $currentUserId, $currentUserId]);
        }
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['file_path'])) { http_response_code(404); exit('Attachment not found.'); }
        $absolutePath = nsMsgResolveStoredPath((string)$row['file_path'], $projectRoot);
        if (!$absolutePath) { http_response_code(404); exit('Attachment file is not available.'); }
        $mime = (string)($row['file_mime'] ?: 'application/octet-stream');
        $name = nsMsgSafeFilename((string)($row['file_name'] ?: basename((string)$row['file_path'])));
        $inline = isset($_GET['inline']) && $_GET['inline'] === '1' && in_array((string)($row['message_type'] ?: $row['file_type']), ['image','audio','video'], true);
        header('X-Content-Type-Options: nosniff');
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string)filesize($absolutePath));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . addcslashes($name, "\\\"") . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('Cache-Control: private, no-store');
        readfile($absolutePath);
        exit;
    }

    if (isset($_GET['api'])) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') nsMsgJson(['ok'=>false,'error'=>'Unsupported request method.'], 405);
        $contentType = (string)($_SERVER['CONTENT_TYPE'] ?? '');
        $input = stripos($contentType, 'multipart/form-data') !== false
            ? $_POST
            : (json_decode((string)file_get_contents('php://input'), true) ?: $_POST);
        if (!hash_equals($csrf, (string)($input['csrf'] ?? ''))) nsMsgJson(['ok'=>false,'error'=>'Your session token expired. Refresh and try again.'], 403);
        $action = (string)($input['action'] ?? '');

        if ($action === 'get_unread_count') {
            $counts = ns_notification_counts($conn, $currentUserId);
            nsMsgJson(['ok'=>true,'unread'=>$counts['messages'],'counts'=>$counts]);
        }

        if ($action === 'search_users') {
            $q = trim((string)($input['q'] ?? ''));
            if ($q === '') nsMsgJson(['ok'=>true,'users'=>[]]);
            $searchCols = [$nameCol];
            if ($studentNoCol) $searchCols[] = $studentNoCol;
            $where = []; $params = [];
            foreach ($searchCols as $col) { $where[] = 'CAST(u.`' . $col . '` AS CHAR) LIKE ?'; $params[] = '%' . $q . '%'; }
            $roleSelect = $roleCol ? 'u.`' . $roleCol . '` AS role' : "'user' AS role";
            $numberSelect = $studentNoCol ? 'u.`' . $studentNoCol . '` AS student_number' : "'' AS student_number";
            $sql = 'SELECT u.`' . $idCol . '` AS user_id,u.`' . $nameCol . '` AS full_name,' . $numberSelect . ',' . $roleSelect . ' FROM `users` u WHERE u.`' . $idCol . '` <> ? AND (' . implode(' OR ', $where) . ') ORDER BY u.`' . $nameCol . '` LIMIT 25';
            array_unshift($params, $currentUserId);
            $st = $conn->prepare($sql); $st->execute($params);
            nsMsgJson(['ok'=>true,'users'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
        }

        if ($action === 'create_conversation') {
            // A direct conversation is represented by a sender/receiver pair in the existing messages table.
            $peerId = (int)($input['peer_id'] ?? $input['user_id'] ?? 0);
            if ($peerId < 1 || $peerId === $currentUserId) nsMsgJson(['ok'=>false,'error'=>'Choose another registered user.'], 422);
            $st = $conn->prepare('SELECT 1 FROM `users` WHERE `' . $idCol . '` = ? LIMIT 1');
            $st->execute([$peerId]);
            if (!$st->fetchColumn()) nsMsgJson(['ok'=>false,'error'=>'That account was not found.'], 404);
            nsMsgJson(['ok'=>true,'peer_id'=>$peerId]);
        }

        if ($action === 'get_conversation_settings' || $action === 'save_conversation_settings') {
            $peerId = (int)($input['peer_id'] ?? $input['other_user_id'] ?? 0);
            if ($peerId < 1 || $peerId === $currentUserId) nsMsgJson(['ok'=>false,'error'=>'Choose a valid conversation.'], 422);
            $st = $conn->prepare('SELECT 1 FROM `users` WHERE `' . $idCol . '` = ? LIMIT 1'); $st->execute([$peerId]);
            if (!$st->fetchColumn()) nsMsgJson(['ok'=>false,'error'=>'User not found.'], 404);
            if ($action === 'get_conversation_settings') {
                $st = $conn->prepare('SELECT `theme_name` FROM `message_preferences` WHERE `user_id`=? AND `other_user_id`=? LIMIT 1');
                $st->execute([$currentUserId,$peerId]);
                nsMsgJson(['ok'=>true,'theme_name'=>$st->fetchColumn() ?: 'default']);
            }
            $theme = (string)($input['theme_name'] ?? 'default');
            $allowedThemes = ['default','ocean','sunset','forest','lavender','rose','sand'];
            if (!in_array($theme, $allowedThemes, true)) nsMsgJson(['ok'=>false,'error'=>'Choose a supported conversation theme.'], 422);
            $st = $conn->prepare('INSERT INTO `message_preferences` (`user_id`,`other_user_id`,`theme_name`) VALUES (?,?,?) ON DUPLICATE KEY UPDATE `theme_name`=VALUES(`theme_name`)');
            $st->execute([$currentUserId,$peerId,$theme]);
            nsMsgJson(['ok'=>true,'theme_name'=>$theme]);
        }

        if ($action === 'create_group') {
            $groupName = trim((string)($input['group_name'] ?? ''));
            $memberIds = $input['member_ids'] ?? [];
            if ($groupName === '' || nsMsgLength($groupName) > 120) nsMsgJson(['ok'=>false,'error'=>'Enter a group name (up to 120 characters).'], 422);
            if (!is_array($memberIds)) nsMsgJson(['ok'=>false,'error'=>'Choose group members.'], 422);
            $memberIds = array_values(array_unique(array_filter(array_map('intval',$memberIds),static fn($id)=>$id>0 && $id!==$currentUserId)));
            if (!$memberIds) nsMsgJson(['ok'=>false,'error'=>'Choose at least one other member.'], 422);
            $memberIds = array_slice($memberIds,0,99);
            $marks = implode(',',array_fill(0,count($memberIds),'?'));
            $st=$conn->prepare('SELECT COUNT(*) FROM `users` WHERE `'.$idCol.'` IN ('.$marks.')');$st->execute($memberIds);
            if ((int)$st->fetchColumn() !== count($memberIds)) nsMsgJson(['ok'=>false,'error'=>'One or more selected accounts could not be found.'],404);
            $conn->beginTransaction();
            try {
                $st=$conn->prepare('INSERT INTO `message_groups` (`group_name`,`created_by`) VALUES (?,?)');$st->execute([$groupName,$currentUserId]);$groupId=(int)$conn->lastInsertId();
                $st=$conn->prepare("INSERT INTO `message_group_members` (`group_id`,`user_id`,`member_role`) VALUES (?,?,?)");
                $st->execute([$groupId,$currentUserId,'admin']);
                foreach($memberIds as $memberId)$st->execute([$groupId,$memberId,'member']);
                $conn->commit();
            } catch(Throwable $e){if($conn->inTransaction())$conn->rollBack();throw $e;}
            nsMsgJson(['ok'=>true,'group_id'=>$groupId,'group_name'=>$groupName]);
        }
        if ($action === 'rename_group') {
            $groupId=(int)($input['group_id']??0);$groupName=trim((string)($input['group_name']??''));
            if($groupId<1||$groupName===''||nsMsgLength($groupName)>120)nsMsgJson(['ok'=>false,'error'=>'Enter a valid group name (up to 120 characters).'],422);
            $st=$conn->prepare("SELECT `member_role` FROM `message_group_members` WHERE `group_id`=? AND `user_id`=? LIMIT 1");$st->execute([$groupId,$currentUserId]);$memberRole=$st->fetchColumn();
            if(!$memberRole)nsMsgJson(['ok'=>false,'error'=>'You are not a member of this group.'],403);
            if($memberRole!=='admin')nsMsgJson(['ok'=>false,'error'=>'Only the group creator can rename this group.'],403);
            $st=$conn->prepare('UPDATE `message_groups` SET `group_name`=? WHERE `group_id`=?');$st->execute([$groupName,$groupId]);
            nsMsgJson(['ok'=>true,'group_name'=>$groupName]);
        }
        if ($action === 'add_group_members') {
            $groupId=(int)($input['group_id']??0);$memberIds=$input['member_ids']??[];
            if($groupId<1||!is_array($memberIds))nsMsgJson(['ok'=>false,'error'=>'Choose a valid group and members.'],422);
            $st=$conn->prepare("SELECT `member_role` FROM `message_group_members` WHERE `group_id`=? AND `user_id`=? LIMIT 1");$st->execute([$groupId,$currentUserId]);$memberRole=$st->fetchColumn();
            if(!$memberRole)nsMsgJson(['ok'=>false,'error'=>'You are not a member of this group.'],403);
            if($memberRole!=='admin')nsMsgJson(['ok'=>false,'error'=>'Only the group creator can add members.'],403);
            $memberIds=array_values(array_unique(array_filter(array_map('intval',$memberIds),static fn($id)=>$id>0&&$id!==$currentUserId)));
            if(!$memberIds)nsMsgJson(['ok'=>false,'error'=>'Choose at least one member to add.'],422);
            $memberIds=array_slice($memberIds,0,99);$marks=implode(',',array_fill(0,count($memberIds),'?'));
            $st=$conn->prepare('SELECT COUNT(*) FROM `users` WHERE `'.$idCol.'` IN ('.$marks.')');$st->execute($memberIds);
            if((int)$st->fetchColumn()!==count($memberIds))nsMsgJson(['ok'=>false,'error'=>'One or more selected accounts could not be found.'],404);
            $st=$conn->prepare("INSERT IGNORE INTO `message_group_members` (`group_id`,`user_id`,`member_role`) VALUES (?,?, 'member')");
            foreach($memberIds as $memberId)$st->execute([$groupId,$memberId]);
            nsMsgJson(['ok'=>true,'added'=>count($memberIds)]);
        }
        if ($action === 'send_group_message') {
            $groupId=(int)($input['group_id']??0);$message=trim((string)($input['message']??''));
            if($groupId<1||$message===''||nsMsgLength($message)>5000)nsMsgJson(['ok'=>false,'error'=>'Enter a message of up to 5,000 characters.'],422);
            $st=$conn->prepare('SELECT 1 FROM `message_group_members` WHERE `group_id`=? AND `user_id`=? LIMIT 1');$st->execute([$groupId,$currentUserId]);
            if(!$st->fetchColumn())nsMsgJson(['ok'=>false,'error'=>'You are not a member of this group.'],403);
            $st=$conn->prepare("INSERT INTO `message_group_messages` (`group_id`,`sender_id`,`message`,`message_type`) VALUES (?,?,?,'text')");$st->execute([$groupId,$currentUserId,$message]);
            $newGroupMessageId=(int)$conn->lastInsertId();
            $conn->prepare('UPDATE `message_groups` SET `updated_at`=CURRENT_TIMESTAMP WHERE `group_id`=?')->execute([$groupId]);
            if (function_exists('curl_init')) {
                $memberStmt = $conn->prepare('SELECT `user_id` FROM `message_group_members` WHERE `group_id`=? AND `user_id`<>?');
                $memberStmt->execute([$groupId, $currentUserId]);
                $groupMemberIds = array_map('intval', $memberStmt->fetchAll(PDO::FETCH_COLUMN));
                if ($groupMemberIds) {
                    $pushPayload = json_encode([
                        'title' => 'NoteSphere Group Message',
                        'body' => $currentName . ': ' . mb_substr($message, 0, 120),
                        'tag' => 'notesphere-group-' . $groupId,
                        'url' => '/NoteSphere/dashboard/student_messages.php',
                        'user_ids' => $groupMemberIds
                    ]);
                    $ch = curl_init('http://127.0.0.1:4000/notify');
                    if ($ch !== false) {
                        curl_setopt($ch, CURLOPT_POST, true);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $pushPayload);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
                        curl_exec($ch);
                        curl_close($ch);
                    }
                }
            }
            nsMsgJson(['ok'=>true,'message_id'=>$newGroupMessageId]);
        }
        if ($action === 'send_group_attachment') {
            $groupId=(int)($_POST['group_id']??0);$caption=trim((string)($_POST['message']??''));
            $st=$conn->prepare('SELECT 1 FROM `message_group_members` WHERE `group_id`=? AND `user_id`=? LIMIT 1');$st->execute([$groupId,$currentUserId]);
            if($groupId<1||!$st->fetchColumn())nsMsgJson(['ok'=>false,'error'=>'You are not a member of this group.'],403);
            if(!isset($_FILES['attachment'])||!is_array($_FILES['attachment']))nsMsgJson(['ok'=>false,'error'=>'Choose a photo, voice recording, or file first.'],422);
            $file=$_FILES['attachment'];$uploadError=(int)($file['error']??UPLOAD_ERR_NO_FILE);
            if($uploadError!==UPLOAD_ERR_OK){$errors=[UPLOAD_ERR_INI_SIZE=>'The file exceeds PHP upload_max_filesize. Increase upload_max_filesize and post_max_size in PHP settings.',UPLOAD_ERR_FORM_SIZE=>'The selected file is too large.',UPLOAD_ERR_PARTIAL=>'The upload was interrupted.',UPLOAD_ERR_NO_FILE=>'Choose a file first.',UPLOAD_ERR_NO_TMP_DIR=>'The server temporary folder is missing.',UPLOAD_ERR_CANT_WRITE=>'The server could not write the uploaded file.',UPLOAD_ERR_EXTENSION=>'PHP blocked this file upload.'];nsMsgJson(['ok'=>false,'error'=>$errors[$uploadError]??'Upload failed.'],422);}
            $size=(int)($file['size']??0);if($size<1||empty($file['tmp_name'])||!is_uploaded_file((string)$file['tmp_name']))nsMsgJson(['ok'=>false,'error'=>'The uploaded file is invalid or empty.'],422);
            if($size > 1024*1024*1024)nsMsgJson(['ok'=>false,'error'=>'File too large. Max 1 GB per file.'],413);
            if(nsMsgLength($caption)>5000)nsMsgJson(['ok'=>false,'error'=>'Captions must be 5,000 characters or fewer.'],422);
            $originalName=nsMsgSafeFilename((string)($file['name']??'attachment'));$ext=strtolower(pathinfo($originalName,PATHINFO_EXTENSION));$finfo=new finfo(FILEINFO_MIME_TYPE);$mime=(string)$finfo->file((string)$file['tmp_name']);$kind=nsMsgAttachmentKind($mime,$ext);
            if($kind===null)nsMsgJson(['ok'=>false,'error'=>'Unsupported file type. Allowed: images, audio/voice, video, PDF, Office documents, TXT, and ZIP.'],422);
            $storageHelper=$projectRoot.DIRECTORY_SEPARATOR.'storage_helper.php';if(is_file($storageHelper))require_once $storageHelper;
            if(!function_exists('ns_get_user_storage')||!function_exists('ns_get_system_storage'))nsMsgJson(['ok'=>false,'error'=>'Storage validation is not configured.'],500);
            try{$storage=ns_get_user_storage($conn,$currentUserId,$role);$used=(int)($storage['used_bytes']??0);$quota=(int)($storage['quota_bytes']??0);$system=ns_get_system_storage($conn);$total=(int)($system['total_bytes']??0);$systemUsed=(int)($system['used_bytes']??0);
                if($quota<1||$used+$size>$quota)nsMsgJson(['ok'=>false,'error'=>'Not enough personal storage. Used: '.nsMsgBytes($used).'; quota: '.nsMsgBytes($quota).'.'],413);
                if($total<1||$systemUsed+$size>$total)nsMsgJson(['ok'=>false,'error'=>'System storage is full or has no valid limit. Contact the Admin.'],413);
            }catch(Throwable $e){error_log('NoteSphere group attachment storage check: '.$e->getMessage());nsMsgJson(['ok'=>false,'error'=>'Could not verify storage limits. Upload stopped.'],500);}
            $uploadDir=$projectRoot.DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR.'messages'.DIRECTORY_SEPARATOR.$currentUserId;
            if(!is_dir($uploadDir)&&!mkdir($uploadDir,0750,true)&&!is_dir($uploadDir))nsMsgJson(['ok'=>false,'error'=>'Could not create the message upload folder.'],500);
            $storedName=bin2hex(random_bytes(18)).'.'.($ext!==''?$ext:'bin');$absolutePath=$uploadDir.DIRECTORY_SEPARATOR.$storedName;
            if(!move_uploaded_file((string)$file['tmp_name'],$absolutePath))nsMsgJson(['ok'=>false,'error'=>'Could not save the uploaded attachment.'],500);
            $relativePath='uploads/messages/'.$currentUserId.'/'.$storedName;
            try{$st=$conn->prepare('INSERT INTO `message_group_messages` (`group_id`,`sender_id`,`message`,`file_path`,`file_type`,`message_type`,`file_name`,`file_mime`,`file_size`) VALUES (?,?,?,?,?,?,?,?,?)');$st->execute([$groupId,$currentUserId,$caption,$relativePath,$kind,$kind,$originalName,$mime,$size]);$messageId=(int)$conn->lastInsertId();$conn->prepare('UPDATE `message_groups` SET `updated_at`=CURRENT_TIMESTAMP WHERE `group_id`=?')->execute([$groupId]);}catch(Throwable $e){@unlink($absolutePath);throw $e;}
            if (function_exists('curl_init')) {
                $memberStmt = $conn->prepare('SELECT `user_id` FROM `message_group_members` WHERE `group_id`=? AND `user_id`<>?');
                $memberStmt->execute([$groupId, $currentUserId]);
                $groupMemberIds = array_map('intval', $memberStmt->fetchAll(PDO::FETCH_COLUMN));
                if ($groupMemberIds) {
                    $pushPayload = json_encode([
                        'title' => 'NoteSphere Group Attachment',
                        'body' => $currentName . ': ' . ($caption !== '' ? mb_substr($caption, 0, 120) : 'Sent an attachment'),
                        'tag' => 'notesphere-group-' . $groupId,
                        'url' => '/NoteSphere/dashboard/student_messages.php',
                        'user_ids' => $groupMemberIds
                    ]);
                    $ch = curl_init('http://127.0.0.1:4000/notify');
                    if ($ch !== false) {
                        curl_setopt($ch, CURLOPT_POST, true);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $pushPayload);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
                        curl_exec($ch);
                        curl_close($ch);
                    }
                }
            }
            nsMsgJson(['ok'=>true,'message_id'=>$messageId,'message'=>'Group attachment sent.']);
        }
        if ($action === 'get_group_messages') {
            $groupId=(int)($input['group_id']??0);$after=max(0,(int)($input['after']??0));
            $st=$conn->prepare('SELECT 1 FROM `message_group_members` WHERE `group_id`=? AND `user_id`=? LIMIT 1');$st->execute([$groupId,$currentUserId]);if($groupId<1||!$st->fetchColumn())nsMsgJson(['ok'=>false,'error'=>'You are not a member of this group.'],403);
            $sql='SELECT gm.`id` AS message_id,gm.`sender_id`,0 AS receiver_id,gm.`message`,gm.`created_at`,CASE WHEN gm.`file_path` IS NOT NULL AND gm.`file_path`<>\'\' AND (gm.`message_type` IS NULL OR gm.`message_type`=\'\' OR gm.`message_type`=\'text\') THEN COALESCE(NULLIF(gm.`file_type`,\'\'),\'file\') ELSE COALESCE(NULLIF(gm.`message_type`,\'\'),\'text\') END AS message_type,gm.`file_type`,gm.`file_name`,gm.`file_mime`,gm.`file_size`,u.`'.$nameCol.'` AS sender_name FROM `message_group_messages` gm LEFT JOIN `users` u ON u.`'.$idCol.'`=gm.`sender_id` WHERE gm.`group_id`=?';
            if($after>0){$st=$conn->prepare($sql.' AND gm.`id`>? ORDER BY gm.`id` ASC LIMIT 100');$st->execute([$groupId,$after]);$messages=$st->fetchAll(PDO::FETCH_ASSOC);}else{$st=$conn->prepare($sql.' ORDER BY gm.`id` DESC LIMIT 100');$st->execute([$groupId]);$messages=array_reverse($st->fetchAll(PDO::FETCH_ASSOC));}
            foreach($messages as &$m){$m['message_id']=(int)$m['message_id'];$m['sender_id']=(int)$m['sender_id'];$m['receiver_id']=0;$m['file_size']=$m['file_size']!==null?(int)$m['file_size']:null;$m['is_group']=true;unset($m['file_path']);}unset($m);
            ns_mark_group_messages_read($conn, $currentUserId, $groupId);
            nsMsgJson(['ok'=>true,'messages'=>$messages]);
        }
        if ($action === 'get_group_members') {
            $groupId=(int)($input['group_id']??0);$st=$conn->prepare('SELECT 1 FROM `message_group_members` WHERE `group_id`=? AND `user_id`=? LIMIT 1');$st->execute([$groupId,$currentUserId]);if($groupId<1||!$st->fetchColumn())nsMsgJson(['ok'=>false,'error'=>'You are not a member of this group.'],403);
            $st=$conn->prepare('SELECT u.`'.$idCol.'` AS user_id,u.`'.$nameCol.'` AS full_name,gm.`member_role` FROM `message_group_members` gm INNER JOIN `users` u ON u.`'.$idCol.'`=gm.`user_id` WHERE gm.`group_id`=? ORDER BY gm.`member_role` DESC,u.`'.$nameCol.'`');$st->execute([$groupId]);nsMsgJson(['ok'=>true,'members'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
        }

        if ($action === 'send_message') {
            $peerId = (int)($input['peer_id'] ?? $input['receiver_id'] ?? 0);
            $message = trim((string)($input['message'] ?? ''));
            if ($peerId < 1 || $peerId === $currentUserId) nsMsgJson(['ok'=>false,'error'=>'Choose a valid recipient.'], 422);
            if ($message === '') nsMsgJson(['ok'=>false,'error'=>'Type a message first.'], 422);
            if (nsMsgLength($message) > 5000) nsMsgJson(['ok'=>false,'error'=>'Messages must be 5,000 characters or fewer.'], 422);
            $st = $conn->prepare('SELECT 1 FROM `users` WHERE `' . $idCol . '`=? LIMIT 1'); $st->execute([$peerId]);
            if (!$st->fetchColumn()) nsMsgJson(['ok'=>false,'error'=>'Recipient not found.'], 404);
            $st = $conn->prepare("INSERT INTO `messages` (`sender_id`,`receiver_id`,`message`,`file_path`,`file_type`,`message_type`) VALUES (?,?,?,NULL,NULL,'text')");
            $st->execute([$currentUserId,$peerId,$message]);
            $messageId = (int)$conn->lastInsertId();

            if (function_exists('curl_init')) {
                $pushPayload = json_encode([
                    'title' => 'NoteSphere Message',
                    'body' => $currentName . ': ' . mb_substr($message, 0, 120),
                    'tag' => 'notesphere-message-' . $peerId,
                    'url' => '/NoteSphere/dashboard/student_messages.php',
                    'user_ids' => [$peerId]
                ]);
                $ch = curl_init('http://127.0.0.1:4000/notify');
                if ($ch !== false) {
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $pushPayload);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
                    curl_exec($ch);
                    curl_close($ch);
                }
            }

            nsMsgJson(['ok'=>true,'message_id'=>$messageId]);
        }

        if ($action === 'send_attachment') {
            $peerId = (int)($_POST['peer_id'] ?? $_POST['receiver_id'] ?? 0);
            $caption = trim((string)($_POST['message'] ?? ''));
            if ($peerId < 1 || $peerId === $currentUserId) nsMsgJson(['ok'=>false,'error'=>'Choose a valid recipient.'], 422);
            $st = $conn->prepare('SELECT 1 FROM `users` WHERE `' . $idCol . '`=? LIMIT 1'); $st->execute([$peerId]);
            if (!$st->fetchColumn()) nsMsgJson(['ok'=>false,'error'=>'Recipient not found.'], 404);
            if (!isset($_FILES['attachment']) || !is_array($_FILES['attachment'])) nsMsgJson(['ok'=>false,'error'=>'Choose a photo, voice recording, or file first.'], 422);
            $file = $_FILES['attachment'];
            $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($uploadError !== UPLOAD_ERR_OK) {
                $errors = [
                    UPLOAD_ERR_INI_SIZE=>'The file exceeds PHP upload_max_filesize. Increase upload_max_filesize and post_max_size in PHP settings.',
                    UPLOAD_ERR_FORM_SIZE=>'The selected file is too large.', UPLOAD_ERR_PARTIAL=>'The upload was interrupted.',
                    UPLOAD_ERR_NO_FILE=>'Choose a file first.', UPLOAD_ERR_NO_TMP_DIR=>'The server temporary folder is missing.',
                    UPLOAD_ERR_CANT_WRITE=>'The server could not write the uploaded file.', UPLOAD_ERR_EXTENSION=>'PHP blocked this file upload.'
                ];
                nsMsgJson(['ok'=>false,'error'=>$errors[$uploadError] ?? 'Upload failed.'], 422);
            }
            $size = (int)($file['size'] ?? 0);
            if ($size < 1 || empty($file['tmp_name']) || !is_uploaded_file((string)$file['tmp_name'])) nsMsgJson(['ok'=>false,'error'=>'The uploaded file is invalid or empty.'], 422);
            if ($size > 1024 * 1024 * 1024) nsMsgJson(['ok'=>false,'error'=>'File too large. Max 1 GB per file.'], 413);
            if (nsMsgLength($caption) > 5000) nsMsgJson(['ok'=>false,'error'=>'Captions must be 5,000 characters or fewer.'], 422);
            $originalName = nsMsgSafeFilename((string)($file['name'] ?? 'attachment'));
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string)$finfo->file((string)$file['tmp_name']);
            $kind = nsMsgAttachmentKind($mime, $ext);
            if ($kind === null) nsMsgJson(['ok'=>false,'error'=>'Unsupported file type. Allowed: images, audio/voice, video, PDF, Office documents, TXT, and ZIP.'], 422);

            $storageHelper = $projectRoot . DIRECTORY_SEPARATOR . 'storage_helper.php';
            if (is_file($storageHelper)) require_once $storageHelper;
            if (!function_exists('ns_get_user_storage') || !function_exists('ns_get_storage_limits') || !function_exists('ns_get_system_storage')) {
                nsMsgJson(['ok'=>false,'error'=>'Storage validation is not configured. Save your central storage helper as storage_helper.php in the NoteSphere project root. Attachments are disabled until the Admin storage limits can be checked.'], 500);
            }
            try {
                $storage = ns_get_user_storage($conn, $currentUserId, $role);
                // ns_get_user_storage() already includes message attachments.
                // Do not add them again here, or the same files would be double-counted.
                $effectiveUsed = (int)($storage['used_bytes'] ?? 0);
                $quota = $storage['quota_bytes'] ?? null;
                if ($quota === null || (int)$quota < 1) {
                    nsMsgJson(['ok'=>false,'error'=>'Your message attachment quota is not configured. Ask the Admin to configure storage limits.'], 413);
                }
                if ($effectiveUsed + $size > (int)$quota) {
                    nsMsgJson(['ok'=>false,'error'=>'Not enough storage space. Used: ' . nsMsgBytes($effectiveUsed) . '; quota: ' . nsMsgBytes((int)$quota) . '. Remove uploaded files or ask the Admin to increase your storage limit.'], 413);
                }
                $limits = ns_get_storage_limits($conn); // Reads the current Admin-configured limits; no hard-coded quota.
                $system = ns_get_system_storage($conn);
                $totalBytes = (int)($system['total_bytes'] ?? 0);
                $systemUsed = (int)($system['used_bytes'] ?? 0);
                if ($totalBytes < 1) {
                    nsMsgJson(['ok'=>false,'error'=>'The Admin has not configured a valid total system storage limit.'], 413);
                }
                if ($systemUsed + $size > $totalBytes) {
                    nsMsgJson(['ok'=>false,'error'=>'System storage is full. Please contact the Admin.'], 413);
                }
            } catch (Throwable $storageError) {
                error_log('NoteSphere messaging storage check: ' . $storageError->getMessage());
                nsMsgJson(['ok'=>false,'error'=>'Could not verify the current storage limits. The upload was stopped. Check the Admin storage settings and storage_helper.php.'], 500);
            }

            $uploadDir = $projectRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'messages' . DIRECTORY_SEPARATOR . $currentUserId;
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0750, true) && !is_dir($uploadDir)) nsMsgJson(['ok'=>false,'error'=>'Could not create the message upload folder.'], 500);
            $storedName = bin2hex(random_bytes(18)) . '.' . ($ext !== '' ? $ext : 'bin');
            $absolutePath = $uploadDir . DIRECTORY_SEPARATOR . $storedName;
            if (!move_uploaded_file((string)$file['tmp_name'], $absolutePath)) nsMsgJson(['ok'=>false,'error'=>'Could not save the uploaded attachment.'], 500);
            $relativePath = 'uploads/messages/' . $currentUserId . '/' . $storedName;
            try {
                $st = $conn->prepare('INSERT INTO `messages` (`sender_id`,`receiver_id`,`message`,`file_path`,`file_type`,`message_type`,`file_name`,`file_mime`,`file_size`) VALUES (?,?,?,?,?,?,?,?,?)');
                $st->execute([$currentUserId,$peerId,$caption,$relativePath,$kind,$kind,$originalName,$mime,$size]);
                $messageId = (int)$conn->lastInsertId();
            } catch (Throwable $saveError) {
                @unlink($absolutePath);
                throw $saveError;
            }
            nsMsgJson(['ok'=>true,'message_id'=>$messageId,'message'=>'Attachment sent.']);
        }

        if ($action === 'get_messages') {
            $peerId = (int)($input['peer_id'] ?? $input['other_user_id'] ?? 0);
            $after = max(0, (int)($input['after'] ?? 0));
            if ($peerId < 1 || $peerId === $currentUserId) nsMsgJson(['ok'=>false,'error'=>'Choose a valid conversation.'], 422);
            $st = $conn->prepare('SELECT 1 FROM `users` WHERE `' . $idCol . '`=? LIMIT 1'); $st->execute([$peerId]);
            if (!$st->fetchColumn()) nsMsgJson(['ok'=>false,'error'=>'User not found.'], 404);
            $baseSelect = "SELECT m.`id` AS message_id,m.`sender_id`,m.`receiver_id`,m.`message`,m.`created_at`,CASE WHEN m.`file_path` IS NOT NULL AND m.`file_path`<>'' AND (m.`message_type` IS NULL OR m.`message_type`='' OR m.`message_type`='text') THEN COALESCE(NULLIF(m.`file_type`,''),'file') ELSE COALESCE(NULLIF(m.`message_type`,''),'text') END AS message_type,m.`file_path`,m.`file_type`,m.`file_name`,m.`file_mime`,m.`file_size`,u.`" . $nameCol . "` AS sender_name FROM `messages` m LEFT JOIN `users` u ON u.`" . $idCol . "`=m.`sender_id` WHERE ((m.`sender_id`=? AND m.`receiver_id`=?) OR (m.`sender_id`=? AND m.`receiver_id`=?))";
            if ($after > 0) {
                $st = $conn->prepare($baseSelect . ' AND m.`id`>? ORDER BY m.`id` ASC LIMIT 100');
                $st->execute([$currentUserId,$peerId,$peerId,$currentUserId,$after]);
                $messages = $st->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $st = $conn->prepare($baseSelect . ' ORDER BY m.`id` DESC LIMIT 100');
                $st->execute([$currentUserId,$peerId,$peerId,$currentUserId]);
                $messages = array_reverse($st->fetchAll(PDO::FETCH_ASSOC));
            }
            foreach ($messages as &$m) {
                $m['message_id'] = (int)$m['message_id'];
                $m['sender_id'] = (int)$m['sender_id'];
                $m['receiver_id'] = (int)$m['receiver_id'];
                $m['file_size'] = $m['file_size'] !== null ? (int)$m['file_size'] : null;
                unset($m['file_path']); // Attachments are served by the authenticated download handler below.
            }
            unset($m);
            // Mark as read
            $pdo = $conn;
            $pdo->prepare("UPDATE messages SET is_read=1 WHERE receiver_id=? AND sender_id=? AND is_read=0")->execute([$currentUserId, $peerId]);
            nsMsgJson(['ok'=>true,'messages'=>$messages]);
        }

        if ($action === 'get_conversations') {
            $st = $conn->prepare('SELECT CASE WHEN `sender_id`=? THEN `receiver_id` ELSE `sender_id` END AS peer_id,MAX(`id`) AS last_id FROM `messages` WHERE `sender_id`=? OR `receiver_id`=? GROUP BY peer_id ORDER BY last_id DESC LIMIT 100');
            $st->execute([$currentUserId,$currentUserId,$currentUserId]);
            $pairs = $st->fetchAll(PDO::FETCH_ASSOC);
            $conversations = [];
            $userStmt = $conn->prepare('SELECT `' . $nameCol . '` AS full_name' . ($studentNoCol ? ', `' . $studentNoCol . '` AS student_number' : ", '' AS student_number") . ($roleCol ? ', `' . $roleCol . '` AS role' : ", 'user' AS role") . ' FROM `users` WHERE `' . $idCol . '`=? LIMIT 1');
            $lastStmt = $conn->prepare('SELECT `id`,`message`,`file_path`,`file_type`,`message_type`,`created_at` FROM `messages` WHERE `id`=? LIMIT 1');
            $unreadStmt = $conn->prepare('SELECT COUNT(*) FROM `messages` WHERE `receiver_id`=? AND `sender_id`=? AND `is_read`=0');
            foreach ($pairs as $pair) {
                $peerId = (int)$pair['peer_id'];
                if ($peerId < 1) continue;
                $userStmt->execute([$peerId]); $person = $userStmt->fetch(PDO::FETCH_ASSOC);
                if (!$person) continue;
                $lastStmt->execute([(int)$pair['last_id']]); $last = $lastStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $unreadStmt->execute([$currentUserId, $peerId]);
                $unreadCount = (int)$unreadStmt->fetchColumn();
                $lastText = trim((string)($last['message'] ?? ''));
                $kind = !empty($last['file_path']) ? (string)($last['file_type'] ?: (($last['message_type'] ?? 'text') !== 'text' ? $last['message_type'] : 'file')) : (string)($last['message_type'] ?? 'text');
                if ($lastText === '') {
                    $lastText = match ($kind) {
                        'image' => '📷 Photo', 'audio' => '🎙️ Voice message', 'video' => '🎬 Video', 'file' => '📎 Attachment',
                        default => !empty($last['file_type']) ? '📎 Attachment' : 'Start the conversation'
                    };
                }
                $conversations[] = [
                    'peer_id'=>$peerId, 'display_name'=>(string)($person['full_name'] ?? 'User'),
                    'student_number'=>(string)($person['student_number'] ?? ''), 'role'=>(string)($person['role'] ?? 'user'),
                    'last_message'=>$lastText, 'last_message_at'=>(string)($last['created_at'] ?? ''), 'last_id'=>(int)$pair['last_id'],'unread_count'=>$unreadCount
                ];
            }
            // Add groups the current user belongs to, sorted alongside private chats by latest activity.
            $st=$conn->prepare('SELECT g.`group_id`,g.`group_name`,g.`updated_at`,(SELECT gm.`id` FROM `message_group_messages` gm WHERE gm.`group_id`=g.`group_id` ORDER BY gm.`id` DESC LIMIT 1) AS last_id FROM `message_groups` g INNER JOIN `message_group_members` mem ON mem.`group_id`=g.`group_id` WHERE mem.`user_id`=? ORDER BY g.`updated_at` DESC LIMIT 100');
            $st->execute([$currentUserId]);
            foreach($st->fetchAll(PDO::FETCH_ASSOC) as $group){
                $last=[];if(!empty($group['last_id'])){$q=$conn->prepare('SELECT `message`,`file_type`,`message_type`,`file_path`,`created_at` FROM `message_group_messages` WHERE `id`=? LIMIT 1');$q->execute([(int)$group['last_id']]);$last=$q->fetch(PDO::FETCH_ASSOC)?:[];}
                $lastText=trim((string)($last['message']??''));$kind=(string)($last['file_type']?:($last['message_type']??'text'));
                if($lastText==='')$lastText=match($kind){'image'=>'📷 Photo','audio'=>'🎙️ Voice message','video'=>'🎬 Video','file'=>'📎 Attachment',default=>'Group created'};
                $conversations[]=['peer_id'=>(int)$group['group_id'],'display_name'=>(string)$group['group_name'],'student_number'=>'','role'=>'Group chat','last_message'=>$lastText,'last_message_at'=>(string)($last['created_at']??$group['updated_at']),'last_id'=>(int)($group['last_id']??0),'is_group'=>true,'group_id'=>(int)$group['group_id'],'unread_count'=>ns_group_unread_count($conn,$currentUserId,(int)$group['group_id'])];
            }
            usort($conversations,static fn($a,$b)=>strcmp((string)($b['last_message_at']??''),(string)($a['last_message_at']??'')));
            nsMsgJson(['ok'=>true,'conversations'=>$conversations]);
        }
        nsMsgJson(['ok'=>false,'error'=>'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    error_log('NoteSphere Messenger error: ' . $e->getMessage());
    if (isset($_GET['api'])) nsMsgJson(['ok'=>false,'error'=>'Messaging setup failed. Check the existing messages/users table and database connection.','detail'=>$e->getMessage()], 500);
    http_response_code(500);
    exit('Messaging setup failed: ' . nsMsgEsc($e->getMessage()));
}

$profilePic = '';
if ($picCol && !empty($currentUser[$picCol])) {
    $rawPic = (string)$currentUser[$picCol];
    if (preg_match('~^https?://~i', $rawPic)) $profilePic = $rawPic;
    elseif (str_starts_with($rawPic, '../')) $profilePic = $rawPic;
    else $profilePic = ltrim($rawPic, '/');
}
$initial = static function(string $name): string {
    $name = trim($name) !== '' ? trim($name) : 'U';
    return function_exists('mb_substr') ? strtoupper(mb_substr($name, 0, 1, 'UTF-8')) : strtoupper(substr($name, 0, 1));
};
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0f172a">
<title>Messages</title>
<link rel="stylesheet" href="../assets/notification_badges.css">
<script src="../assets/notification_badges.js" data-csrf="<?= nsMsgEsc(ns_notification_csrf_token()) ?>" defer></script>
<style>
:root{--bg:#ffffff;--panel:#fff;--text:#172033;--muted:#718096;--line:#e5eaf2;--accent:#24a812;--accent-dark:#1a7a0e;--bubble-in:#fff;--bubble-out:#d9fbe9;--chat-bg:#f6f8fb;--shadow:0 18px 45px rgba(15,23,42,.08)}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:'Plus Jakarta Sans',Inter,system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;font-size:14px}button,input,textarea,select{font:inherit}button{cursor:pointer}.app{min-height:100vh;padding:22px;display:flex;align-items:stretch;justify-content:center}.messenger{width:min(1440px,100%);height:min(880px,calc(100vh - 44px));min-height:560px;display:grid;grid-template-columns:330px minmax(0,1fr);background:var(--panel);border:1px solid var(--line);border-radius:22px;overflow:hidden;box-shadow:var(--shadow)}.sidebar{display:flex;flex-direction:column;border-right:1px solid var(--line);min-width:0;background:#fff}.side-head{padding:23px 20px 16px;border-bottom:1px solid var(--line)}.brand-row{display:flex;align-items:center;justify-content:space-between;gap:11px;margin-bottom:19px}.brand-info{min-width:0}.brand-title{font-size:18px;font-weight:800;letter-spacing:-.5px}.brand-sub{font-size:11px;color:var(--muted);margin-top:2px}.side-title{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}.side-title strong{font-size:15px}.small-muted{color:var(--muted);font-size:12px}.search-wrap{position:relative}.search{width:100%;border:1px solid var(--line);border-radius:12px;padding:12px 13px 12px 38px;background:#f8fafc;outline:0}.search:focus,.composer textarea:focus{border-color:#7dd67a;box-shadow:0 0 0 3px #24a81214}.search-icon{position:absolute;left:13px;top:12px;color:#8995a8}.search-results{display:none;margin-top:8px;border:1px solid var(--line);border-radius:12px;background:#fff;max-height:220px;overflow:auto;box-shadow:0 12px 24px #0f172a12;position:relative;z-index:2}.search-results.show{display:block}.person-result{display:flex;align-items:center;gap:10px;padding:10px 12px;cursor:pointer}.person-result:hover{background:#f3fbf7}.person-meta{min-width:0;flex:1}.person-name{font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.person-sub{color:var(--muted);font-size:11px;margin-top:3px}.conversation-list{flex:1;overflow:auto;padding:8px}.conversation-item{display:flex;align-items:center;gap:11px;padding:12px;border-radius:14px;cursor:pointer;margin:2px 0}.conversation-item:hover{background:#f5faf8}.conversation-item.active{background:#e8fbf2}.avatar{height:42px;width:42px;min-width:42px;border-radius:50%;display:grid;place-items:center;background:#dff7ec;color:#1a7a0e;font-weight:800;font-size:14px;overflow:hidden}.avatar img{height:100%;width:100%;object-fit:cover}.conversation-text{min-width:0;flex:1}.conversation-top{display:flex;justify-content:space-between;gap:8px;align-items:center}.conversation-name{font-size:13px;font-weight:750;white-space:nowrap;text-overflow:ellipsis;overflow:hidden}.conversation-time{font-size:10px;color:var(--muted);white-space:nowrap}.conversation-preview{font-size:12px;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-top:5px}.side-foot{padding:13px 18px;border-top:1px solid var(--line);font-size:11px;color:var(--muted);display:flex;align-items:center;justify-content:space-between;gap:8px}.back-link{color:#1a7a0e;text-decoration:none;font-weight:700}.chat-panel{display:flex;flex-direction:column;min-width:0;background:var(--chat-bg)}.chat-head{background:#fff;padding:17px 22px;display:flex;align-items:center;justify-content:space-between;gap:14px;border-bottom:1px solid var(--line)}.chat-person{display:flex;align-items:center;gap:12px;min-width:0}.chat-name{font-size:15px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.chat-status{color:var(--muted);font-size:11px;margin-top:4px}.head-actions{display:flex;align-items:center;gap:8px}.icon-btn{border:1px solid var(--line);background:white;height:38px;min-width:38px;padding:0 10px;border-radius:11px;color:#46546a;display:inline-flex;align-items:center;justify-content:center;gap:7px}.icon-btn:hover{background:#f5faf8;border-color:#b8ead3}.icon-btn:disabled{opacity:.5;cursor:not-allowed}.theme-select{border:1px solid var(--line);border-radius:10px;padding:9px 8px;background:#fff;color:#475569;max-width:145px}.messages-area{flex:1;overflow-y:auto;padding:24px clamp(14px,4vw,48px);display:flex;flex-direction:column;gap:12px;background:var(--chat-bg);scroll-behavior:smooth}.empty-state{margin:auto;text-align:center;max-width:320px;padding:28px 15px;color:var(--muted)}.empty-icon{width:64px;height:64px;border-radius:21px;background:#dff7ec;display:grid;place-items:center;font-size:28px;margin:0 auto 15px}.empty-state h2{font-size:18px;color:var(--text);margin:0 0 7px}.empty-state p{line-height:1.6;margin:0}.message-row{display:flex;align-items:flex-end;gap:8px;max-width:82%;align-self:flex-start}.message-row.mine{align-self:flex-end;flex-direction:row-reverse}.mini-avatar{width:25px;height:25px;min-width:25px;border-radius:50%;background:#e2e8f0;display:grid;place-items:center;color:#475569;font-size:10px;font-weight:800}.message-stack{min-width:0}.sender-label{font-size:10px;color:var(--muted);margin:0 0 4px 4px}.bubble{padding:10px 13px;border-radius:15px 15px 15px 4px;background:var(--bubble-in);border:1px solid #e6ebf1;box-shadow:0 2px 4px #0f172a04;overflow-wrap:anywhere;white-space:pre-wrap;line-height:1.5}.message-row.mine .bubble{border-radius:15px 15px 4px 15px;background:var(--bubble-out);border-color:transparent}.message-time{font-size:10px;color:var(--muted);margin:4px 4px 0}.message-row.mine .message-time{text-align:right}.attachment-image{display:block;max-width:min(340px,65vw);max-height:330px;border-radius:10px;object-fit:contain;background:#e2e8f0;cursor:pointer}.attachment-audio{display:block;width:min(300px,60vw);max-width:100%;height:42px}.attachment-video{display:block;width:min(350px,65vw);max-height:280px;border-radius:10px}.file-card{display:flex;gap:11px;align-items:center;min-width:min(240px,55vw);max-width:350px;text-decoration:none;color:inherit;border:1px solid #dce4ec;border-radius:12px;padding:11px;background:#ffffffa8}.file-icon{height:37px;width:37px;display:grid;place-items:center;background:#e8f7ef;color:#1a7a0e;border-radius:10px;font-size:18px;flex-shrink:0}.file-name{font-weight:700;font-size:12px;overflow-wrap:anywhere}.file-caption{font-size:10px;color:var(--muted);margin-top:4px}.composer-area{background:#fff;border-top:1px solid var(--line);padding:14px 20px 17px}.composer-tools{display:flex;align-items:center;gap:8px;margin-bottom:10px}.tool-label{font-size:11px;color:var(--muted);margin-left:auto}.composer{display:flex;align-items:flex-end;gap:10px}.composer textarea{flex:1;min-width:0;resize:none;max-height:120px;min-height:46px;border:1px solid var(--line);border-radius:14px;padding:13px 14px;outline:0;background:#fbfdff;line-height:1.4}.send-btn{height:46px;padding:0 18px;border:0;border-radius:13px;background:#24a812;color:white;font-weight:800;display:flex;align-items:center;gap:8px;box-shadow:0 6px 15px #24a8122e}.send-btn:hover{background:#1a7a0e}.send-btn:disabled{opacity:.5;cursor:not-allowed}.notice{display:none;margin:0 0 10px;padding:9px 12px;border-radius:10px;font-size:12px}.notice.show{display:block}.notice.error{background:#fff1f2;color:#be123c}.notice.success{background:#ecfdf5;color:#1a7a0e}.recording{color:#dc2626!important;border-color:#fecaca!important;background:#fff1f2!important}.theme-default{--chat-bg:#f6f8fb;--bubble-out:#d9fbe9}.theme-ocean{--chat-bg:#eaf6ff;--bubble-out:#cfeaff}.theme-sunset{--chat-bg:#fff3ed;--bubble-out:#ffe0d2}.theme-forest{--chat-bg:#edf7ed;--bubble-out:#d6edd5}.theme-lavender{--chat-bg:#f5f0ff;--bubble-out:#e8ddff}.theme-rose{--chat-bg:#fff0f5;--bubble-out:#ffdce8}.theme-sand{--chat-bg:#f8f3e8;--bubble-out:#efe3c9}.mobile-back{display:none}.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}.close-dashboard{width:38px;height:38px;min-width:38px;display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--line);border-radius:12px;background:var(--panel);color:var(--text);text-decoration:none;font-size:25px;line-height:1;font-weight:500;box-shadow:0 4px 12px rgba(15,23,42,.06);transition:background .15s,border-color .15s,transform .15s}.close-dashboard:hover{background:var(--chat-bg);border-color:var(--accent);transform:translateY(-1px)}
@media(max-width:800px){.app{padding:0}.messenger{height:100vh;height:100dvh;min-height:0;border-radius:0;border:0;grid-template-columns:290px minmax(0,1fr)}.side-head{padding:18px 14px 13px}.chat-head{padding:13px}.messages-area{padding:16px 12px}.composer-area{padding:11px 12px calc(12px + env(safe-area-inset-bottom))}.message-row{max-width:94%}.mobile-back{display:inline-flex}.head-actions{gap:5px}.theme-select{max-width:115px}.send-btn{padding:0 12px}.tool-label{display:none}}
@media(max-width:620px){.messenger{display:block;position:relative}.sidebar,.chat-panel{height:100vh;height:100dvh;width:100%}.chat-panel{display:none}.messenger.chat-open .sidebar{display:none}.messenger.chat-open .chat-panel{display:flex}.side-head{padding:19px 17px 15px}.conversation-list{padding:8px 11px}.chat-head{gap:8px}.chat-person{gap:8px}.chat-head .avatar{height:36px;width:36px;min-width:36px}.chat-name{font-size:13px}.chat-status{font-size:10px}.head-actions .theme-select{max-width:100px;padding:8px 5px;font-size:11px}.head-actions .icon-btn{height:35px;min-width:35px;padding:0 8px}.composer-tools .icon-btn{height:35px;min-width:35px}.send-btn{height:44px;font-size:12px}.message-row{max-width:97%}.attachment-image{max-width:72vw}.attachment-video{max-width:72vw}.file-card{min-width:0;max-width:70vw}.brand-title{font-size:17px}}

/* Inherit NoteSphere's global light/dark choice. The conversation theme selector still controls chat tint. */
html.ns-messenger-dark{color-scheme:dark;--bg:#080808;--panel:#111827;--text:#e5eaf3;--muted:#9aa8bd;--line:#293548;--accent:#39ff14;--accent-dark:#24a812;--bubble-in:#1b2638;--bubble-out:#123b2f;--chat-bg:#0d1525;--shadow:0 18px 45px rgba(0,0,0,.28)}
html.ns-messenger-dark body{background:var(--bg);color:var(--text)}
html.ns-messenger-dark .messenger,html.ns-messenger-dark .sidebar,html.ns-messenger-dark .chat-panel{background:var(--panel);border-color:var(--line)}
html.ns-messenger-dark .sidebar,html.ns-messenger-dark .side-head,html.ns-messenger-dark .chat-head,html.ns-messenger-dark .composer-area{background:#111827;border-color:var(--line)}
html.ns-messenger-dark .search,html.ns-messenger-dark .theme-select,html.ns-messenger-dark .composer textarea{background:#0b1220;color:var(--text);border-color:#354258}
html.ns-messenger-dark .search::placeholder,html.ns-messenger-dark .composer textarea::placeholder{color:#8492a8}
html.ns-messenger-dark .search-results{background:#111827;border-color:var(--line)}
html.ns-messenger-dark .person-result:hover,html.ns-messenger-dark .conversation-item:hover{background:#1a2939}
html.ns-messenger-dark .conversation-item.active{background:#123b2f}
html.ns-messenger-dark .avatar{background:#173a32;color:#a7f3d0}
html.ns-messenger-dark .icon-btn,html.ns-messenger-dark .close-dashboard{background:#172235;color:#e2e8f0;border-color:#344258}
html.ns-messenger-dark .icon-btn:hover,html.ns-messenger-dark .close-dashboard:hover{background:#223149;border-color:#4b617d}
html.ns-messenger-dark .bubble{background:var(--bubble-in);border-color:#2d3b50;color:var(--text)}
html.ns-messenger-dark .message-row.mine .bubble{background:var(--bubble-out);color:#e5fff4}
html.ns-messenger-dark .file-card{background:#182438;border-color:#344258;color:var(--text)}
html.ns-messenger-dark .file-icon,html.ns-messenger-dark .empty-icon{background:#173a32;color:#a7f3d0}
html.ns-messenger-dark .mini-avatar{background:#29364a;color:#e2e8f0}
html.ns-messenger-dark .notice.error{background:#431b29;color:#fecdd3}
html.ns-messenger-dark .notice.success{background:#123b2f;color:#a7f3d0}
html.ns-messenger-dark .messages-area.theme-default{--chat-bg:#0d1525;--bubble-out:#123b2f}
html.ns-messenger-dark .messages-area.theme-ocean{--chat-bg:#0b2232;--bubble-out:#153f58}
html.ns-messenger-dark .messages-area.theme-sunset{--chat-bg:#2b1c1a;--bubble-out:#583128}
html.ns-messenger-dark .messages-area.theme-forest{--chat-bg:#10251c;--bubble-out:#1c4932}
html.ns-messenger-dark .messages-area.theme-lavender{--chat-bg:#211d32;--bubble-out:#3a315b}
html.ns-messenger-dark .messages-area.theme-rose{--chat-bg:#2d1b27;--bubble-out:#57283e}
html.ns-messenger-dark .messages-area.theme-sand{--chat-bg:#29251a;--bubble-out:#4b4027}
@media(max-width:620px){.close-dashboard{width:36px;height:36px;min-width:36px}}

</style>
<style>
.chat-panel { min-height: 0; overflow: hidden; }
.messages-area { min-height: 0; }
</style>
</head>

<div class="app">
  <div class="messenger" id="messenger">
    <aside class="sidebar">
      <div class="side-head">
        <div class="brand-row">
          <div class="brand-info"><div class="brand-title">NoteSphere</div><div class="brand-sub">Private academic messages</div></div>
          <div class="brand-actions" style="display:flex;align-items:center;gap:6px;flex-shrink:0;">
            <a href="student_notifications.php" class="ns-nav-link" title="Notifications" aria-label="Open notifications" style="width:36px;height:36px;min-width:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:rgba(127,127,127,.15);text-decoration:none;font-size:16px;">🔔<?= ns_notification_badge_html((int)($ns_badge_counts['help'] ?? 0), 'help') ?></a>
            <a class="close-dashboard" href="help_board.php" title="Back to Help Board" aria-label="Back to Help Board">×</a>
          </div>
        </div>
        <div class="side-title"><strong>Chats</strong><button class="icon-btn" id="createGroupBtn" type="button" title="Create a group chat">＋ Group</button><span class="small-muted" id="conversationCount">Loading…</span></div>
        <div class="search-wrap"><span class="search-icon">⌕</span><input class="search" id="searchUsers" type="search" autocomplete="off" placeholder="Find a student or professor"><div id="searchResults" class="search-results"></div></div>
      </div>
      <div class="conversation-list" id="conversationList"><div class="empty-state"><p>Loading your conversations…</p></div></div>
      <div class="side-foot"><span>Signed in as <?= nsMsgEsc($currentName) ?></span></div>
    </aside>

    <main class="chat-panel">
      <header class="chat-head">
        <div class="chat-person">
          <button class="icon-btn mobile-back" id="mobileBack" type="button" aria-label="Back to conversations">←</button>
          <div class="avatar" id="chatAvatar">N</div>
          <div style="min-width:0"><div class="chat-name" id="chatName">Choose a conversation</div><div class="chat-status" id="chatStatus">Select a person from the left</div></div>
        </div>
        <div class="head-actions">

          <label class="sr-only" for="themeSelect">Conversation background</label>
          <select class="theme-select" id="themeSelect" title="Change this chat's background" disabled>
            <option value="default">Theme: Default</option><option value="ocean">Ocean</option><option value="sunset">Sunset</option><option value="forest">Forest</option><option value="lavender">Lavender</option><option value="rose">Rose</option><option value="sand">Sand</option>
          </select>
        </div>
      </header>
      <div class="messages-area theme-default" id="messagesArea"><div class="empty-state"><div class="empty-icon">✉</div><h2>Your messages, in one place</h2><p>Choose an existing conversation or search for a registered user to start chatting.</p></div></div>
      <div class="composer-area">
        <div class="notice" id="notice" role="status"></div>
        <div class="composer-tools">
          <button class="icon-btn" id="attachBtn" type="button" title="Choose a photo, voice recording, or file" disabled>📎 <span>File / Photo</span></button>
          <button class="icon-btn" id="recordBtn" type="button" title="Record a voice message" disabled>🎙 <span>Voice</span></button>
          <span class="tool-label" id="uploadHint">Photos and files use your Admin-configured storage quota</span>
        </div>
        <form class="composer" id="messageForm">
          <textarea id="messageInput" rows="1" maxlength="5000" placeholder="Write a message…" disabled></textarea>
          <button class="send-btn" id="sendBtn" type="submit" disabled>Send <span aria-hidden="true">➤</span></button>
        </form>
        <input class="sr-only" type="file" id="attachInput" accept="image/*,audio/*,video/*,.pdf,.txt,.zip,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.odt,.ods,.odp">
      </div>
    </main>
  </div>
</div>
<div class="group-modal-backdrop" id="groupModal" hidden>
  <section class="group-modal" role="dialog" aria-modal="true" aria-labelledby="groupModalTitle">
    <div class="group-modal-head"><h2 id="groupModalTitle">Create group chat</h2><button type="button" class="icon-btn" id="closeGroupModal" aria-label="Close">×</button></div>
    <label class="group-label" for="groupNameInput">Group name</label><input id="groupNameInput" class="group-input" maxlength="120" placeholder="e.g. BSIT 3D Project Team">
    <label class="group-label" for="groupMemberSearch">Add members</label><input id="groupMemberSearch" class="group-input" type="search" placeholder="Search students or professors">
    <div id="groupMemberResults" class="group-member-results"><p class="small-muted">Search for people to add to this group.</p></div>
    <div class="group-modal-foot"><span id="selectedMemberCount" class="small-muted">0 members selected</span><button type="button" class="send-btn" id="saveGroupBtn">Create group</button></div>
  </section>
</div>
<style>
.group-modal-backdrop{position:fixed;inset:0;z-index:1000;background:rgba(2,6,23,.58);display:grid;place-items:center;padding:18px}.group-modal-backdrop[hidden]{display:none}.group-modal{width:min(520px,100%);max-height:min(720px,92vh);overflow:auto;background:var(--panel);color:var(--text);border:1px solid var(--line);border-radius:20px;padding:20px;box-shadow:0 24px 70px #02061755}.group-modal-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px}.group-modal-head h2{font-size:18px;margin:0}.group-label{display:block;font-size:12px;font-weight:800;margin:14px 0 7px}.group-input{width:100%;border:1px solid var(--line);border-radius:11px;padding:12px;background:var(--chat-bg);color:var(--text);outline:0}.group-member-results{max-height:260px;overflow:auto;border:1px solid var(--line);border-radius:12px;margin-top:9px}.group-member-row{display:flex;align-items:center;gap:10px;padding:11px 12px;border-bottom:1px solid var(--line);cursor:pointer}.group-member-row:last-child{border-bottom:0}.group-member-row:hover{background:var(--chat-bg)}.group-member-row input{accent-color:#24a812;width:17px;height:17px}.group-member-info{min-width:0;flex:1}.group-member-name{font-weight:750}.group-member-role{font-size:11px;color:var(--muted);margin-top:3px}.group-modal-foot{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:17px}.group-modal-foot .send-btn{flex-shrink:0}
        /* iOS: keep form fields at 16px so Safari does not zoom on focus. */
        @supports (-webkit-touch-callout: none) {
            input,
            select,
            textarea {
                font-size: 16px;
            }
        }
</style>
<script>
(() => {
  'use strict';
  const csrf = <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
  const currentUserId = <?= (int)$currentUserId ?>;
  const currentUserName = <?= json_encode($currentName, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
  // Build the API URL from the current page so it also works when NoteSphere is installed as a PWA or served from a subfolder.
  const apiEndpoint = new URL(window.location.href);
  apiEndpoint.search = 'api=1';
  apiEndpoint.hash = '';
  const apiUrl = apiEndpoint.href;
  const messenger = document.getElementById('messenger');
  const conversationList = document.getElementById('conversationList');
  const conversationCount = document.getElementById('conversationCount');
  const searchUsers = document.getElementById('searchUsers');
  const searchResults = document.getElementById('searchResults');
  const chatName = document.getElementById('chatName');
  const chatStatus = document.getElementById('chatStatus');
  const chatAvatar = document.getElementById('chatAvatar');
  const messagesArea = document.getElementById('messagesArea');
  const messageInput = document.getElementById('messageInput');
  const messageForm = document.getElementById('messageForm');
  const sendBtn = document.getElementById('sendBtn');
  const attachBtn = document.getElementById('attachBtn');
  const recordBtn = document.getElementById('recordBtn');
  const attachInput = document.getElementById('attachInput');
  const themeSelect = document.getElementById('themeSelect');
  const notice = document.getElementById('notice');
  const uploadHint = document.getElementById('uploadHint');
  const createGroupBtn=document.getElementById('createGroupBtn');
  const renameGroupBtn=document.getElementById('renameGroupBtn');
  const addGroupMembersBtn=document.getElementById('addGroupMembersBtn');
  const groupModal=document.getElementById('groupModal');
  const groupModalTitle=document.getElementById('groupModalTitle');
  const groupNameInput=document.getElementById('groupNameInput');
  const groupMemberSearch=document.getElementById('groupMemberSearch');
  const groupMemberResults=document.getElementById('groupMemberResults');
  const selectedMemberCount=document.getElementById('selectedMemberCount');
  const saveGroupBtn=document.getElementById('saveGroupBtn');
  let activeIsGroup=false;
  let groupModalMode='create';
  let selectedGroupMembers=new Map();

  // Mirror the system-wide NoteSphere theme without adding a theme switch to Messages.
  // Common class/data-theme/localStorage conventions are checked first; OS preference is the fallback.
  function detectGlobalDarkMode() {
    const nodes = [document.documentElement, document.body].filter(Boolean);
    for (const node of nodes) {
      const dataTheme = String(node.getAttribute('data-theme') || node.getAttribute('data-color-theme') || '').toLowerCase();
      if (dataTheme === 'dark' || dataTheme === 'dark-mode') return true;
      if (dataTheme === 'light' || dataTheme === 'light-mode') return false;
      const classes = Array.from(node.classList || []).filter(name => name !== 'ns-messenger-dark');
      if (classes.some(name => /^(dark|dark-mode|darkmode|dark-theme|theme-dark|is-dark)$/.test(name.toLowerCase()))) return true;
      if (classes.some(name => /^(light|light-mode|lightmode|light-theme|theme-light|is-light)$/.test(name.toLowerCase()))) return false;
    }
    try {
      const candidates = [];
      for (let i = 0; i < localStorage.length; i++) {
        const key = localStorage.key(i) || '';
        if (/(theme|dark.?mode|color.?mode|appearance)/i.test(key)) candidates.push([key.toLowerCase(), String(localStorage.getItem(key) || '').trim().toLowerCase()]);
      }
      // Prefer explicit theme-named settings over unrelated values.
      candidates.sort((a, b) => (/theme/.test(b[0]) ? 1 : 0) - (/theme/.test(a[0]) ? 1 : 0));
      for (const [key, value] of candidates) {
        if (/^(dark|dark-mode|darkmode|true|1|enabled|on)$/.test(value)) return true;
        if (/^(light|light-mode|lightmode|false|0|disabled|off)$/.test(value)) return false;
        if (/dark.?mode/.test(key) && /^(yes|true|1)$/.test(value)) return true;
        if (/dark.?mode/.test(key) && /^(no|false|0)$/.test(value)) return false;
      }
    } catch (_) { /* Storage can be unavailable in restricted browsers. */ }
    return !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
  }
  function syncGlobalTheme() {
    document.documentElement.classList.toggle('ns-messenger-dark', detectGlobalDarkMode());
  }
  syncGlobalTheme();
  if (window.matchMedia) {
    const systemTheme = window.matchMedia('(prefers-color-scheme: dark)');
    if (systemTheme.addEventListener) systemTheme.addEventListener('change', syncGlobalTheme);
    else if (systemTheme.addListener) systemTheme.addListener(syncGlobalTheme);
  }
  window.addEventListener('storage', syncGlobalTheme);
  const globalThemeObserver = new MutationObserver(syncGlobalTheme);
  globalThemeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class', 'data-theme', 'data-color-theme'] });
  if (document.body) globalThemeObserver.observe(document.body, { attributes: true, attributeFilter: ['class', 'data-theme', 'data-color-theme'] });
  let activePeer = null;
  let activePeerName = '';
  let lastMessageId = 0;
  let loadingMessages = false;
  let searchTimer = null;
  let pollTimer = null;
  let mediaRecorder = null;
  let recordedChunks = [];
  let recordingStream = null;
  let isRecording = false;

  async function api(action, payload = {}) {
    const controller = new AbortController();
    const timeoutId = window.setTimeout(() => controller.abort(), 20000);
    let response;
    try {
      response = await fetch(apiUrl, {
        method: 'POST',
        headers: {'Content-Type':'application/json','Accept':'application/json'},
        credentials: 'same-origin',
        cache: 'no-store',
        signal: controller.signal,
        body: JSON.stringify({csrf, action, ...payload})
      });
    } catch (error) {
      if (error && error.name === 'AbortError') throw new Error('The server took too long to respond. Check that XAMPP is running and your phone can reach the computer.');
      throw new Error('Cannot connect to NoteSphere. Check your network and the website address, then try again.');
    } finally {
      window.clearTimeout(timeoutId);
    }
    let data;
    try { data = await response.json(); } catch (_) { throw new Error('The server returned an invalid response. Check the PHP error log and confirm this page is served by Apache/PHP.'); }
    if (!response.ok || !data.ok) throw new Error(data.error || ('The request failed (' + response.status + ').'));
    return data;
  }
  function showNotice(message, type='error') {
    notice.textContent = message; notice.className = 'notice show ' + type;
    window.clearTimeout(showNotice.timer); showNotice.timer = window.setTimeout(() => { notice.className = 'notice'; }, 6000);
  }
  function initials(name) {
    const clean = String(name || 'U').trim();
    return Array.from(clean || 'U')[0].toUpperCase();
  }
  function escapeText(value) { return String(value ?? ''); }
  function formatTime(value) {
    if (!value) return '';
    const date = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return '';
    const today = new Date();
    if (date.toDateString() === today.toDateString()) return date.toLocaleTimeString([], {hour:'numeric',minute:'2-digit'});
    return date.toLocaleDateString([], {month:'short',day:'numeric'});
  }
  function setAvatar(el, name) { el.textContent = initials(name); }
  function enableComposer(enabled) {
    messageInput.disabled = !enabled; sendBtn.disabled = !enabled; attachBtn.disabled = !enabled; recordBtn.disabled = !enabled; themeSelect.disabled = !enabled;
    messageInput.placeholder = enabled ? 'Write a message…' : 'Choose someone to message first';
  }
  function showEmptyChat(title, text) {
    messagesArea.replaceChildren();
    const state = document.createElement('div'); state.className = 'empty-state';
    const icon = document.createElement('div'); icon.className = 'empty-icon'; icon.textContent = '✉';
    const heading = document.createElement('h2'); heading.textContent = title;
    const paragraph = document.createElement('p'); paragraph.textContent = text;
    state.append(icon, heading, paragraph); messagesArea.append(state);
  }
  function resetMessages() { messagesArea.replaceChildren(); lastMessageId = 0; }

  async function loadConversations() {
    try {
      const data = await api('get_conversations');
      const conversations = data.conversations || [];
      conversationCount.textContent = conversations.length + (conversations.length === 1 ? ' chat' : ' chats');
      conversationList.replaceChildren();
      if (!conversations.length) {
        const blank = document.createElement('div'); blank.className = 'empty-state';
        const p = document.createElement('p'); p.textContent = 'No conversations yet. Search for a registered user above to start a message.'; blank.append(p); conversationList.append(blank); return;
      }
      conversations.forEach(person => {
        const item = document.createElement('div'); item.className = 'conversation-item' + (activePeer === Number(person.peer_id) && activeIsGroup === Boolean(person.is_group) ? ' active' : '');
        item.tabIndex = 0; item.setAttribute('role','button');
        const avatar = document.createElement('div'); avatar.className = 'avatar'; setAvatar(avatar, person.display_name);
        const text = document.createElement('div'); text.className = 'conversation-text';
        const top = document.createElement('div'); top.className = 'conversation-top';
        const name = document.createElement('div'); name.className = 'conversation-name'; name.textContent = person.display_name;
        const time = document.createElement('div'); time.className = 'conversation-time'; time.textContent = formatTime(person.last_message_at);
        const preview = document.createElement('div'); preview.className = 'conversation-preview'; preview.textContent = person.last_message || 'Start the conversation';
        top.append(name,time); text.append(top,preview); item.append(avatar,text);
        // unread badge
        if (person.unread_count && person.unread_count > 0) {
            const badge = document.createElement('span');
            badge.style.cssText = 'background:#24a812;color:#fff;border-radius:10px;padding:2px 8px;font-size:11px;font-weight:700;margin-left:8px;';
            badge.textContent = person.unread_count;
            top.append(badge);
        }
        const open = () => openConversation({peer_id:Number(person.peer_id),full_name:person.display_name,display_name:person.display_name,student_number:person.student_number,role:person.role,is_group:Boolean(person.is_group),group_id:Number(person.group_id||0)});
        item.addEventListener('click',open); item.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();open();}});
        conversationList.append(item);
      });
    } catch (error) {
      conversationCount.textContent = 'Connection issue';
      conversationList.replaceChildren();
      const state = document.createElement('div');
      state.className = 'empty-state';
      const heading = document.createElement('h2');
      heading.textContent = 'Messages could not load';
      const paragraph = document.createElement('p');
      paragraph.textContent = error.message || 'Check your connection and try again.';
      paragraph.style.overflowWrap = 'anywhere';
      paragraph.style.marginBottom = '12px';
      const retry = document.createElement('button');
      retry.type = 'button';
      retry.className = 'icon-btn';
      retry.textContent = 'Try again';
      retry.addEventListener('click', () => loadConversations());
      state.append(heading, paragraph, retry);
      conversationList.append(state);
      showNotice(error.message);
    }
  }

  function showSearchResults(users) {
    searchResults.replaceChildren();
    if (!users.length) { const none = document.createElement('div'); none.className='person-result'; none.style.color='var(--muted)'; none.textContent='No matching users found'; searchResults.append(none); searchResults.classList.add('show'); return; }
    users.forEach(user => {
      const item = document.createElement('div'); item.className = 'person-result';
      const avatar = document.createElement('div'); avatar.className = 'avatar'; avatar.style.width='34px';avatar.style.height='34px';avatar.style.minWidth='34px';setAvatar(avatar,user.full_name);
      const meta = document.createElement('div'); meta.className='person-meta';
      const name = document.createElement('div'); name.className='person-name';name.textContent=user.full_name || 'User';
      const sub = document.createElement('div');sub.className='person-sub';sub.textContent=[user.student_number,user.role].filter(Boolean).join(' · ') || 'Registered user';
      meta.append(name,sub);item.append(avatar,meta);
      item.addEventListener('click',async()=>{searchResults.classList.remove('show');searchUsers.value='';try{await api('create_conversation',{peer_id:Number(user.user_id)});await openConversation({...user,is_group:false});}catch(error){showNotice(error.message);}});
      searchResults.append(item);
    });
    searchResults.classList.add('show');
  }
  searchUsers.addEventListener('input', () => {
    window.clearTimeout(searchTimer);
    const q = searchUsers.value.trim();
    if (!q) { searchResults.classList.remove('show');searchResults.replaceChildren();return; }
    searchTimer = window.setTimeout(async()=>{try{const data=await api('search_users',{q});showSearchResults(data.users||[]);}catch(error){showNotice(error.message);}},250);
  });
  document.addEventListener('click', e => { if (!searchResults.contains(e.target) && e.target !== searchUsers) searchResults.classList.remove('show'); });

  async function openConversation(person) {
    activePeer = Number(person.peer_id ?? person.user_id);
    activeIsGroup = Boolean(person.is_group);
    activePeerName = String(person.display_name ?? person.full_name ?? 'User');
    if (!activePeer || activePeer === currentUserId) { activePeer=null; return; }
    chatName.textContent = activePeerName;
    chatStatus.textContent = activeIsGroup ? 'Group chat · messages shared with all members' : ([person.student_number, person.role].filter(Boolean).join(' · ') || 'Registered user');
    setAvatar(chatAvatar, activePeerName);
    enableComposer(true); resetMessages();
    if (renameGroupBtn) renameGroupBtn.hidden=!activeIsGroup;
    if (addGroupMembersBtn) addGroupMembersBtn.hidden=!activeIsGroup;
    themeSelect.disabled=false;
    messenger.classList.add('chat-open');
    try {
      if(activeIsGroup){themeSelect.disabled=true;applyTheme('default');await loadMessages(true);}
      else {const settings = await api('get_conversation_settings',{peer_id:activePeer});themeSelect.value = settings.theme_name || 'default'; applyTheme(themeSelect.value);await loadMessages(true);}
      await loadConversations();
      messageInput.focus();
    } catch (error) { showNotice(error.message); }
    window.clearInterval(pollTimer);
    pollTimer = window.setInterval(()=>{ if(activePeer && !document.hidden) loadMessages(false); },2500);
  }
  function applyTheme(theme) {
    const valid = ['default','ocean','sunset','forest','lavender','rose','sand'];
    if (!valid.includes(theme)) theme='default';
    messagesArea.classList.remove(...valid.map(t=>'theme-'+t));
    messagesArea.classList.add('theme-'+theme);
  }
  themeSelect.addEventListener('change',async()=>{
    if(!activePeer)return;
    applyTheme(themeSelect.value);
    try { await api('save_conversation_settings',{peer_id:activePeer,theme_name:themeSelect.value});showNotice('Conversation theme saved.','success'); }
    catch(error){showNotice(error.message);}
  });
  document.getElementById('mobileBack').addEventListener('click',()=>{messenger.classList.remove('chat-open');window.clearInterval(pollTimer);});

  function appendAttachment(message, bubble) {
    const kind = String(message.message_type || message.file_type || 'file');
    const groupFlag=activeIsGroup?'&group=1':'';
    const url = window.location.pathname + '?download=' + encodeURIComponent(message.message_id) + groupFlag + '&inline=1';
    if (kind === 'image') {
      const img = document.createElement('img'); img.className='attachment-image';img.src=url;img.alt=message.file_name || 'Shared photo';img.loading='lazy';img.addEventListener('click',()=>window.open(url,'_blank','noopener'));bubble.append(img);
    } else if (kind === 'audio') {
      const audio = document.createElement('audio');audio.className='attachment-audio';audio.controls=true;audio.preload='metadata';audio.src=url;bubble.append(audio);
      const label=document.createElement('div');label.className='file-caption';label.textContent=message.file_name || 'Voice message';bubble.append(label);
    } else if (kind === 'video') {
      const video=document.createElement('video');video.className='attachment-video';video.controls=true;video.preload='metadata';video.src=url;bubble.append(video);
    } else {
      const link=document.createElement('a');link.className='file-card';link.href=window.location.pathname+'?download='+encodeURIComponent(message.message_id)+(activeIsGroup?'&group=1':'');
      const icon=document.createElement('span');icon.className='file-icon';icon.textContent='↧';
      const meta=document.createElement('span');meta.style.minWidth='0';
      const fname=document.createElement('div');fname.className='file-name';fname.textContent=message.file_name || 'Download attachment';
      const sub=document.createElement('div');sub.className='file-caption';sub.textContent=message.file_size ? formatBytes(message.file_size) : 'File attachment';
      meta.append(fname,sub);link.append(icon,meta);bubble.append(link);
    }
  }
  function formatBytes(input) { let n=Number(input)||0;const units=['B','KB','MB','GB'];let i=0;while(n>=1024&&i<units.length-1){n/=1024;i++;}return n.toFixed(i===0?0:1)+' '+units[i]; }
  function renderMessage(message) {
    const mine = Number(message.sender_id) === currentUserId;
    const row=document.createElement('div');row.className='message-row'+(mine?' mine':'');
    const avatar=document.createElement('div');avatar.className='mini-avatar';setAvatar(avatar,mine?currentUserName:(message.sender_name||activePeerName));
    const stack=document.createElement('div');stack.className='message-stack';
    if(activeIsGroup||!mine){const sender=document.createElement('div');sender.className='sender-label';sender.textContent=mine?'You':(message.sender_name||activePeerName);stack.append(sender);}
    const bubble=document.createElement('div');bubble.className='bubble';
    if(message.file_name || message.file_type || (message.message_type && message.message_type!=='text')) appendAttachment(message,bubble);
    if(message.message){const text=document.createElement('div');text.textContent=message.message;bubble.append(text);}
    if(!bubble.childNodes.length) { const text=document.createElement('div');text.textContent='Attachment';bubble.append(text); }
    const time=document.createElement('div');time.className='message-time';time.textContent=formatTime(message.created_at);
    stack.append(bubble,time);row.append(avatar,stack);messagesArea.append(row);
  }
  async function loadMessages(initialLoad=false) {
    if(!activePeer || loadingMessages)return;
    loadingMessages=true;
    const shouldStick = messagesArea.scrollHeight - messagesArea.scrollTop - messagesArea.clientHeight < 120 || initialLoad;
    try {
      const data=activeIsGroup?await api('get_group_messages',{group_id:activePeer,after:initialLoad?0:lastMessageId}):await api('get_messages',{peer_id:activePeer,after:initialLoad?0:lastMessageId});
      if(initialLoad)resetMessages();
      const msgs=data.messages||[];
      if (msgs.length && messagesArea.querySelector('.empty-state')) messagesArea.replaceChildren();
      msgs.forEach(message=>{renderMessage(message);lastMessageId=Math.max(lastMessageId,Number(message.message_id)||0);});
      if(initialLoad && !msgs.length) showEmptyChat('Say hello to '+activePeerName,'Send a message, photo, voice recording, or file to begin.');
      if(msgs.length && shouldStick)messagesArea.scrollTop=messagesArea.scrollHeight;
      if (window.NSRefreshNotificationBadges) window.NSRefreshNotificationBadges();
    } catch(error) { if(!initialLoad) console.error(error); else showNotice(error.message); }
    finally {loadingMessages=false;}
  }
  async function sendText() {
    if(!activePeer)return;
    const message=messageInput.value.trim();if(!message)return;
    sendBtn.disabled=true;
    try {
      if(activeIsGroup)await api('send_group_message',{group_id:activePeer,message});else await api('send_message',{peer_id:activePeer,message});messageInput.value='';messageInput.style.height='auto';
      await loadMessages(false);await loadConversations();
    } catch(error){showNotice(error.message);}
    finally{sendBtn.disabled=false;messageInput.focus();}
  }
  messageForm.addEventListener('submit',e=>{e.preventDefault();sendText();});
  messageInput.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();sendText();}});
  messageInput.addEventListener('input',()=>{messageInput.style.height='auto';messageInput.style.height=Math.min(messageInput.scrollHeight,120)+'px';});

  attachBtn.addEventListener('click',()=>{if(activePeer)attachInput.click();});
  attachInput.addEventListener('change',()=>{if(attachInput.files&&attachInput.files[0])uploadAttachment(attachInput.files[0]);attachInput.value='';});
  async function uploadAttachment(file) {
    if(!activePeer||!file)return;
    const form=new FormData();form.append('csrf',csrf);form.append('action',activeIsGroup?'send_group_attachment':'send_attachment');if(activeIsGroup)form.append('group_id',String(activePeer));else form.append('peer_id',String(activePeer));form.append('message',messageInput.value.trim());form.append('attachment',file,file.name||'attachment');
    const oldLabel=uploadHint.textContent;uploadHint.textContent='Uploading '+(file.name||'attachment')+'…';attachBtn.disabled=true;recordBtn.disabled=true;sendBtn.disabled=true;
    try {
      const response=await fetch(apiUrl,{method:'POST',body:form,credentials:'same-origin',headers:{'Accept':'application/json'}});
      const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.error||'Upload failed.');
      messageInput.value='';messageInput.style.height='auto';showNotice('Attachment sent.','success');await loadMessages(false);await loadConversations();
    } catch(error){showNotice(error.message);}
    finally{uploadHint.textContent=oldLabel;attachBtn.disabled=!activePeer;recordBtn.disabled=!activePeer;sendBtn.disabled=!activePeer;}
  }
  function audioExtension(mime) {
    if(mime.includes('ogg'))return 'ogg';if(mime.includes('mp4'))return 'm4a';if(mime.includes('mpeg'))return 'mp3';if(mime.includes('wav'))return 'wav';return 'webm';
  }
  recordBtn.addEventListener('click',async()=>{
    if(!activePeer)return;
    if(isRecording){try{mediaRecorder.stop();}catch(_){}return;}
    if(!navigator.mediaDevices||!navigator.mediaDevices.getUserMedia||!window.MediaRecorder){showNotice('Voice recording is not supported by this browser. Try an updated browser on localhost or HTTPS.');return;}
    try {
      recordingStream=await navigator.mediaDevices.getUserMedia({audio:true});recordedChunks=[];
      let options={};
      if(MediaRecorder.isTypeSupported('audio/webm;codecs=opus'))options.mimeType='audio/webm;codecs=opus';
      else if(MediaRecorder.isTypeSupported('audio/mp4'))options.mimeType='audio/mp4';
      else if(MediaRecorder.isTypeSupported('audio/ogg;codecs=opus'))options.mimeType='audio/ogg;codecs=opus';
      mediaRecorder=new MediaRecorder(recordingStream,options);
      mediaRecorder.ondataavailable=e=>{if(e.data&&e.data.size)recordedChunks.push(e.data);};
      mediaRecorder.onerror=()=>showNotice('The browser could not record audio.');
      mediaRecorder.onstop=async()=>{
        isRecording=false;recordBtn.classList.remove('recording');recordBtn.innerHTML='🎙 <span>Voice</span>';
        recordingStream.getTracks().forEach(track=>track.stop());recordingStream=null;
        const mime=mediaRecorder.mimeType||'audio/webm';const blob=new Blob(recordedChunks,{type:mime});
        if(blob.size>0){const file=new File([blob],'voice-message.'+audioExtension(mime),{type:mime});await uploadAttachment(file);}else showNotice('No audio was recorded. Try again.');
      };
      mediaRecorder.start();isRecording=true;recordBtn.classList.add('recording');recordBtn.innerHTML='■ <span>Stop & Send</span>';showNotice('Recording voice message… Press Stop & Send when finished.','success');
    } catch(error) { if(recordingStream)recordingStream.getTracks().forEach(track=>track.stop());recordingStream=null;showNotice('Microphone access was not granted. Allow microphone access and try again.'); }
  });
  window.addEventListener('beforeunload',()=>{if(recordingStream)recordingStream.getTracks().forEach(track=>track.stop());});
  function updateSelectedCount(){selectedMemberCount.textContent=selectedGroupMembers.size+' member'+(selectedGroupMembers.size===1?'':'s')+' selected';}
  function openGroupModal(mode='create'){
    groupModalMode=mode;selectedGroupMembers=new Map();groupMemberSearch.value='';groupMemberResults.replaceChildren();
    groupModalTitle.textContent=mode==='create'?'Create group chat':'Add members to '+activePeerName;
    groupNameInput.value=mode==='create'?'':activePeerName;groupNameInput.closest('label');groupNameInput.parentElement;
    groupNameInput.disabled=mode!=='create';groupNameInput.style.display=mode==='create'?'block':'none';
    const nameLabel=groupModal.querySelector('label[for="groupNameInput"]');if(nameLabel)nameLabel.style.display=mode==='create'?'block':'none';
    saveGroupBtn.textContent=mode==='create'?'Create group':'Add selected members';groupModal.hidden=false;updateSelectedCount();groupMemberSearch.focus();
  }
  function renderGroupMemberOptions(users){
    groupMemberResults.replaceChildren();
    if(!users.length){const p=document.createElement('p');p.className='small-muted';p.style.padding='12px';p.textContent='No matching users found.';groupMemberResults.append(p);return;}
    users.forEach(user=>{const id=Number(user.user_id);if(!id||id===currentUserId)return;const label=document.createElement('label');label.className='group-member-row';const check=document.createElement('input');check.type='checkbox';check.checked=selectedGroupMembers.has(id);check.addEventListener('change',()=>{if(check.checked)selectedGroupMembers.set(id,user);else selectedGroupMembers.delete(id);updateSelectedCount();});const info=document.createElement('span');info.className='group-member-info';const name=document.createElement('div');name.className='group-member-name';name.textContent=user.full_name||'User';const sub=document.createElement('div');sub.className='group-member-role';sub.textContent=[user.student_number,user.role].filter(Boolean).join(' · ')||'Registered user';info.append(name,sub);label.append(check,info);groupMemberResults.append(label);});
  }
  let groupSearchTimer=null;
  groupMemberSearch.addEventListener('input',()=>{window.clearTimeout(groupSearchTimer);const q=groupMemberSearch.value.trim();if(q.length<1){groupMemberResults.replaceChildren();const p=document.createElement('p');p.className='small-muted';p.style.padding='12px';p.textContent='Type a name or student number to search.';groupMemberResults.append(p);return;}groupSearchTimer=window.setTimeout(async()=>{try{const data=await api('search_users',{q});renderGroupMemberOptions(data.users||[]);}catch(e){showNotice(e.message);}},220);});
  createGroupBtn.addEventListener('click',()=>openGroupModal('create'));
  if (addGroupMembersBtn) addGroupMembersBtn.addEventListener('click',()=>{if(activeIsGroup)openGroupModal('add');});
  document.getElementById('closeGroupModal').addEventListener('click',()=>{groupModal.hidden=true;});
  groupModal.addEventListener('click',e=>{if(e.target===groupModal)groupModal.hidden=true;});
  saveGroupBtn.addEventListener('click',async()=>{
    const memberIds=Array.from(selectedGroupMembers.keys());
    try{
      saveGroupBtn.disabled=true;
      if(groupModalMode==='create'){
        const groupName=groupNameInput.value.trim();if(!groupName){showNotice('Enter a name for the group.');groupNameInput.focus();return;}if(!memberIds.length){showNotice('Search and select at least one member.');return;}
        const result=await api('create_group',{group_name:groupName,member_ids:memberIds});groupModal.hidden=true;await loadConversations();await openConversation({peer_id:result.group_id,display_name:result.group_name,full_name:result.group_name,role:'Group chat',is_group:true,group_id:result.group_id});showNotice('Group created successfully.','success');
      }else{
        if(!memberIds.length){showNotice('Select at least one member to add.');return;}await api('add_group_members',{group_id:activePeer,member_ids:memberIds});groupModal.hidden=true;showNotice('Group members updated.','success');
      }
    }catch(e){showNotice(e.message);}finally{saveGroupBtn.disabled=false;}
  });
  if (renameGroupBtn) renameGroupBtn.addEventListener('click',async()=>{if(!activeIsGroup)return;const proposed=window.prompt('Enter the new group name:',activePeerName);if(proposed===null)return;const groupName=proposed.trim();if(!groupName)return;try{await api('rename_group',{group_id:activePeer,group_name:groupName});activePeerName=groupName;chatName.textContent=groupName;await loadConversations();showNotice('Group name updated.','success');}catch(e){showNotice(e.message);}});

  enableComposer(false);
  loadConversations();
  window.setInterval(loadConversations,10000);
})();
</script>
</body>
</html>

