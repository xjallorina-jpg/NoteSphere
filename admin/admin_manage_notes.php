<?php
/**
 * NoteSphere - Admin Manage Notes
 * Schema-aware version: detects common legacy column names without changing
 * the existing database structure or removing the approve/delete workflow.
 */
session_start();
require_once __DIR__ . '/../includes/db_config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../auth/login.php');
    exit();
}
if (!isset($conn) || !($conn instanceof PDO)) {
    http_response_code(500);
    exit('Database connection is unavailable. Check includes/db_config.php.');
}

if (empty($_SESSION['manage_notes_csrf'])) $_SESSION['manage_notes_csrf'] = bin2hex(random_bytes(32));
$csrfToken = $_SESSION['manage_notes_csrf'];
$flash = $_SESSION['manage_notes_flash'] ?? null;
unset($_SESSION['manage_notes_flash']);

function e($value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function qi(string $identifier): string { return '`' . str_replace('`', '``', $identifier) . '`'; }
function getColumns(PDO $conn, string $table): array {
    // Table names are fixed constants in this file, not user input.
    $out = [];
    foreach ($conn->query('SHOW COLUMNS FROM ' . qi($table))->fetchAll(PDO::FETCH_ASSOC) as $row) $out[strtolower($row['Field'])] = $row['Field'];
    return $out;
}
function chooseColumn(array $columns, array $candidates): ?string {
    foreach ($candidates as $candidate) if (isset($columns[strtolower($candidate)])) return $columns[strtolower($candidate)];
    return null;
}
function normalizeStatus($value): string {
    $value = strtolower(trim((string)($value ?? '')));
    return preg_replace('/[_-]+/', ' ', $value) ?? $value;
}
function isPendingNoteStatus($value): bool {
    $value = normalizeStatus($value);
    return $value === '0' || $value === 'pending' || str_starts_with($value, 'pending ') || in_array($value, ['for approval', 'awaiting approval', 'under review', 'under review approval', 'for review', 'awaiting review', 'waiting for approval', 'waiting', 'for checking', 'in review', 'unverified', 'submitted', 'not approved', 'unapproved'], true);
}
function isApprovedNoteStatus($value): bool {
    return in_array(normalizeStatus($value), ['1', 'approved', 'published', 'active', 'visible'], true);
}
function sqlPendingCondition(string $expression): string {
    $normalized = "REPLACE(REPLACE(LOWER(TRIM(CAST($expression AS CHAR))), '_', ' '), '-', ' ')";
    return "($normalized IN ('0','pending','for approval','awaiting approval','under review','under review approval','for review','awaiting review','waiting for approval','waiting','for checking','in review','unverified','submitted','not approved','unapproved') OR $normalized LIKE 'pending %')";
}
function sqlApprovedCondition(string $expression): string {
    return "LOWER(TRIM(CAST($expression AS CHAR))) IN ('1','approved','published','active','visible')";
}
function sqlBooleanFalseCondition(string $expression): string {
    return "LOWER(TRIM(CAST($expression AS CHAR))) IN ('0','false','no','pending','')";
}
function redirectWithFlash(string $type, string $message, string $status, string $search): void {
    $_SESSION['manage_notes_flash'] = ['type' => $type, 'message' => $message];
    $params = [];
    if ($status !== 'all') $params['status'] = $status;
    if ($search !== '') $params['q'] = $search;
    $url = basename($_SERVER['PHP_SELF']);
    if ($params) $url .= '?' . http_build_query($params);
    header('Location: ' . $url); exit();
}
function resolveNoteUpload(string $storedPath): ?string {
    $storedPath = trim(str_replace('\\', '/', $storedPath));
    if ($storedPath === '' || preg_match('~^(?:https?:)?//~i', $storedPath)) return null;
    $uploadsRoot = realpath(__DIR__ . '/../uploads');
    if ($uploadsRoot === false) return null;
    $relative = ltrim($storedPath, '/');
    if (stripos($relative, 'uploads/') === 0) $relative = substr($relative, 8);
    $candidate = realpath($uploadsRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    if ($candidate === false || !is_file($candidate)) return null;
    $prefix = rtrim($uploadsRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    return strpos($candidate, $prefix) === 0 ? $candidate : null;
}
function deleteNoteFileIfUnused(PDO $conn, string $storedPath, ?string $fileColumn): void {
    if ($storedPath === '' || !$fileColumn) return;
    try {
        $check = $conn->prepare('SELECT COUNT(*) FROM notes WHERE ' . qi($fileColumn) . ' = ?');
        $check->execute([$storedPath]);
        if ((int)$check->fetchColumn() > 0) return;
    } catch (Throwable $ignored) { return; }
    $resolved = resolveNoteUpload($storedPath);
    if ($resolved !== null) @unlink($resolved);
}
function pageUrl(int $page, string $status, string $search): string {
    $params = ['page' => $page];
    if ($status !== 'all') $params['status'] = $status;
    if ($search !== '') $params['q'] = $search;
    return basename($_SERVER['PHP_SELF']) . '?' . http_build_query($params);
}

$statusFilter = (string)($_GET['status'] ?? 'all');
if (!in_array($statusFilter, ['all', 'pending', 'approved'], true)) $statusFilter = 'all';
$search = trim((string)($_GET['q'] ?? ''));
if (function_exists('mb_strlen') && mb_strlen($search) > 120) $search = mb_substr($search, 0, 120);
elseif (strlen($search) > 120) $search = substr($search, 0, 120);

$counts = ['total' => 0, 'pending' => 0, 'approved' => 0];
$notes = []; $totalRows = 0; $totalPages = 1; $page = max(1, (int)($_GET['page'] ?? 1)); $perPage = 10;
$schemaError = '';
$helpPosts = []; $helpboardError = ''; $helpboardHasStatus = false; $helpboardPendingCount = 0;

try {
    $noteCols = getColumns($conn, 'notes');
    $userCols = getColumns($conn, 'users');

    $noteIdCol = chooseColumn($noteCols, ['id', 'note_id']);
    $noteUserCol = chooseColumn($noteCols, ['user_id', 'uploaded_by', 'uploader_id', 'created_by']);
    $titleCol = chooseColumn($noteCols, ['title', 'note_title', 'file_title']);
    $subjectCol = chooseColumn($noteCols, ['subject', 'subject_name', 'course_subject']);
    $descCol = chooseColumn($noteCols, ['description', 'note_description', 'details', 'content']);
    $fileCol = chooseColumn($noteCols, ['file_path', 'file', 'note_file', 'filename', 'file_name']);
    $statusCol = chooseColumn($noteCols, ['moderation_status', 'approval_status', 'note_status', 'review_status', 'status']);
    $approvalFlagCol = chooseColumn($noteCols, ['is_approved', 'approved', 'is_published', 'published']);
    $dateCol = chooseColumn($noteCols, ['created_at', 'uploaded_at', 'date_uploaded', 'upload_date', 'created_on']);
    $userIdCol = chooseColumn($userCols, ['user_id', 'id']);
    $nameCol = chooseColumn($userCols, ['full_name', 'name', 'username', 'student_name']);

    $missing = [];
    foreach (['notes primary key (id or note_id)' => $noteIdCol, 'notes uploader column (user_id or uploaded_by)' => $noteUserCol, 'notes title column (title or note_title)' => $titleCol, 'notes status/approval column' => ($statusCol ?: $approvalFlagCol), 'users primary key (user_id or id)' => $userIdCol] as $label => $col) if (!$col) $missing[] = $label;
    if ($missing) throw new RuntimeException('Missing required database columns: ' . implode('; ', $missing) . '. Please check the SQL instructions below the download link.');

    $N = 'n'; $U = 'u';
    $idExpr = "$N." . qi($noteIdCol);
    $ownerExpr = "$N." . qi($noteUserCol);
    $titleExpr = "$N." . qi($titleCol);
    $subjectExpr = $subjectCol ? "$N." . qi($subjectCol) : "''";
    $descExpr = $descCol ? "$N." . qi($descCol) : "''";
    $fileExpr = $fileCol ? "$N." . qi($fileCol) : "''";
    if ($statusCol) {
        $rawStatusExpr = "$N." . qi($statusCol);
        if ($approvalFlagCol) {
            // Some legacy rows store approval in a boolean flag while the text status is blank.
            $flagExpr = "$N." . qi($approvalFlagCol);
            $statusExpr = "CASE WHEN $rawStatusExpr IS NULL OR TRIM(CAST($rawStatusExpr AS CHAR)) = '' THEN CASE WHEN LOWER(TRIM(CAST($flagExpr AS CHAR))) IN ('1','true','yes','approved','published') THEN 'approved' ELSE 'pending' END ELSE $rawStatusExpr END";
            $approveUpdateSql = 'UPDATE notes SET ' . qi($statusCol) . " = 'approved', " . qi($approvalFlagCol) . ' = 1 WHERE ' . qi($noteIdCol) . ' = ? AND (' . sqlPendingCondition($rawStatusExpr) . " OR $rawStatusExpr IS NULL OR TRIM(CAST($rawStatusExpr AS CHAR)) = '' OR " . sqlBooleanFalseCondition($flagExpr) . ')';
        } else {
            $statusExpr = $rawStatusExpr;
            $approveUpdateSql = 'UPDATE notes SET ' . qi($statusCol) . " = 'approved' WHERE " . qi($noteIdCol) . ' = ? AND ' . sqlPendingCondition($rawStatusExpr);
        }
        $pendingCondition = sqlPendingCondition($statusExpr);
        $approvedCondition = sqlApprovedCondition($statusExpr);
    } else {
        $flagExpr = "$N." . qi($approvalFlagCol);
        $statusExpr = "CASE WHEN LOWER(TRIM(CAST($flagExpr AS CHAR))) IN ('1','true','yes','approved','published') THEN 'approved' ELSE 'pending' END";
        $pendingCondition = sqlBooleanFalseCondition($flagExpr);
        $approvedCondition = "LOWER(TRIM(CAST($flagExpr AS CHAR))) IN ('1','true','yes','approved','published')";
        $approveUpdateSql = 'UPDATE notes SET ' . qi($approvalFlagCol) . ' = 1 WHERE ' . qi($noteIdCol) . ' = ? AND ' . sqlBooleanFalseCondition($flagExpr);
    }
    $dateExpr = $dateCol ? "$N." . qi($dateCol) : 'NULL';
    $nameExpr = $nameCol ? "$U." . qi($nameCol) : "CONCAT('User #', $U." . qi($userIdCol) . ')';
    $join = ' LEFT JOIN users u ON ' . $ownerExpr . ' = u.' . qi($userIdCol);

    // POST actions: preserve the original approval/deletion behavior.
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $postedToken = (string)($_POST['csrf_token'] ?? '');
        if (!hash_equals((string)$_SESSION['manage_notes_csrf'], $postedToken)) redirectWithFlash('error', 'Security token expired. Please try again.', $statusFilter, $search);
        $action = (string)($_POST['action'] ?? '');
        $isHelpAction = in_array($action, ['help_approve', 'help_delete'], true);
        $selectedId = filter_var($_POST[$isHelpAction ? 'question_id' : 'note_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$selectedId) redirectWithFlash('error', $isHelpAction ? 'Invalid Help Board post selected.' : 'Invalid note selected.', $statusFilter, $search);
        try {
            if ($isHelpAction) {
                $helpColsAction = getColumns($conn, 'help_questions');
                $helpIdAction = chooseColumn($helpColsAction, ['id', 'question_id', 'post_id']);
                $helpStatusAction = chooseColumn($helpColsAction, ['moderation_status', 'status', 'approval_status', 'post_status']);
                if (!$helpIdAction) throw new RuntimeException('The help_questions table is missing its post ID column.');
                if ($action === 'help_approve') {
                    if (!$helpStatusAction) throw new RuntimeException('Help Board approval needs a status column in help_questions (status, approval_status, or post_status).');
                    $helpSetParts = [qi($helpStatusAction) . " = 'approved'"];
                    $helpSetParams = [];
                    if (isset($helpColsAction['moderation_note'])) {
                        $helpSetParts[] = '`moderation_note` = NULL';
                    }
                    if (isset($helpColsAction['moderated_at'])) {
                        $helpSetParts[] = '`moderated_at` = NOW()';
                    }
                    if (isset($helpColsAction['moderated_by'])) {
                        $helpSetParts[] = '`moderated_by` = ?';
                        $helpSetParams[] = (int)($_SESSION['user_id'] ?? 0) ?: null;
                    }
                    $helpSetParams[] = (int)$selectedId;
                    $approveHelp = $conn->prepare('UPDATE help_questions SET ' . implode(', ', $helpSetParts) . ' WHERE ' . qi($helpIdAction) . ' = ? AND LOWER(' . qi($helpStatusAction) . ") IN ('pending','rejected')");
                    $approveHelp->execute($helpSetParams);
                    if ($approveHelp->rowCount() > 0) redirectWithFlash('success', 'Help Board post approved. It can now appear to students.', $statusFilter, $search);
                    redirectWithFlash('error', 'This post was not found or is already approved.', $statusFilter, $search);
                }
                if ($action === 'help_delete') {
                    $conn->beginTransaction();
                    $commentsColsAction = [];
                    try { $commentsColsAction = getColumns($conn, 'help_comments'); } catch (Throwable $ignored) {}
                    $commentQuestionCol = chooseColumn($commentsColsAction, ['question_id', 'post_id', 'help_question_id']);
                    if ($commentQuestionCol) {
                        $delComments = $conn->prepare('DELETE FROM help_comments WHERE ' . qi($commentQuestionCol) . ' = ?');
                        $delComments->execute([(int)$selectedId]);
                    }
                    $hiddenColsAction = [];
                    try { $hiddenColsAction = getColumns($conn, 'help_hidden_posts'); } catch (Throwable $ignored) {}
                    $hiddenQuestionCol = chooseColumn($hiddenColsAction, ['question_id', 'post_id', 'help_question_id']);
                    if ($hiddenQuestionCol) {
                        $delHidden = $conn->prepare('DELETE FROM help_hidden_posts WHERE ' . qi($hiddenQuestionCol) . ' = ?');
                        $delHidden->execute([(int)$selectedId]);
                    }
                    $delHelp = $conn->prepare('DELETE FROM help_questions WHERE ' . qi($helpIdAction) . ' = ?');
                    $delHelp->execute([(int)$selectedId]);
                    $conn->commit();
                    redirectWithFlash('success', 'Help Board post and its related comments were deleted.', $statusFilter, $search);
                }
            }
            $noteId = $selectedId;
            if ($action === 'approve') {
                $approve = $conn->prepare($approveUpdateSql);
                $approve->execute([(int)$noteId]);
                if ($approve->rowCount() > 0) redirectWithFlash('success', 'Note approved successfully.', $statusFilter, $search);
                redirectWithFlash('error', 'This note was not found or is no longer pending.', $statusFilter, $search);
            }
            if ($action === 'delete') {
                $conn->beginTransaction();
                $select = $conn->prepare('SELECT ' . qi($titleCol) . ' AS title, ' . ($fileCol ? qi($fileCol) : "''") . ' AS file_path FROM notes WHERE ' . qi($noteIdCol) . ' = ? LIMIT 1');
                $select->execute([(int)$noteId]); $note = $select->fetch(PDO::FETCH_ASSOC);
                if (!$note) { $conn->rollBack(); redirectWithFlash('error', 'The selected note no longer exists.', $statusFilter, $search); }
                $delete = $conn->prepare('DELETE FROM notes WHERE ' . qi($noteIdCol) . ' = ?');
                $delete->execute([(int)$noteId]); $conn->commit();
                deleteNoteFileIfUnused($conn, (string)($note['file_path'] ?? ''), $fileCol);
                redirectWithFlash('success', 'Note deleted successfully.', $statusFilter, $search);
            }
            redirectWithFlash('error', 'Unknown action.', $statusFilter, $search);
        } catch (Throwable $actionError) {
            if ($conn->inTransaction()) $conn->rollBack();
            error_log('Manage Notes action failed: ' . $actionError->getMessage());
            redirectWithFlash('error', 'The action could not be completed. Please check the database and try again.', $statusFilter, $search);
        }
    }

    $countSql = 'SELECT COUNT(*) AS total, SUM(CASE WHEN ' . $pendingCondition . ' THEN 1 ELSE 0 END) AS pending, SUM(CASE WHEN ' . $approvedCondition . ' THEN 1 ELSE 0 END) AS approved FROM notes n';
    $cr = $conn->query($countSql)->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach ($counts as $key => $_) $counts[$key] = (int)($cr[$key] ?? 0);

    $where = []; $params = [];
    if ($statusFilter === 'pending') { $where[] = $pendingCondition; }
    elseif ($statusFilter === 'approved') { $where[] = $approvedCondition; }
    if ($search !== '') {
        $searchable = [$titleExpr];
        if ($subjectCol) $searchable[] = $subjectExpr;
        if ($descCol) $searchable[] = $descExpr;
        if ($nameCol) $searchable[] = $nameExpr;
        $where[] = '(' . implode(' OR ', array_map(static fn($expr) => $expr . ' LIKE ?', $searchable)) . ')';
        foreach ($searchable as $_) $params[] = '%' . $search . '%';
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $countQuery = 'SELECT COUNT(*) FROM notes n' . $join . $whereSql;
    $stmtCount = $conn->prepare($countQuery); $stmtCount->execute($params); $totalRows = (int)$stmtCount->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage)); if ($page > $totalPages) $page = $totalPages;
    $offset = ($page - 1) * $perPage;
    $dateOrder = $dateCol ? $dateExpr : $idExpr;
    $listSql = 'SELECT ' . $idExpr . ' AS id, ' . $ownerExpr . ' AS user_id, ' . $titleExpr . ' AS title, ' . $subjectExpr . ' AS subject, ' . $descExpr . ' AS description, ' . $fileExpr . ' AS file_path, ' . $statusExpr . ' AS status, ' . $dateExpr . ' AS created_at, ' . $nameExpr . ' AS uploader_name FROM notes n' . $join . $whereSql . ' ORDER BY CASE WHEN ' . $pendingCondition . ' THEN 0 ELSE 1 END, ' . $dateOrder . ' DESC LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset;
    $stmt = $conn->prepare($listSql); $stmt->execute($params); $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) {
    error_log('Manage Notes schema/list failed: ' . $ex->getMessage());
    $schemaError = $ex->getMessage();
    $flash = ['type' => 'error', 'message' => 'Unable to load notes. ' . $schemaError];
}

// Load student Help Board posts separately from learning notes. These records are stored in help_questions.
try {
    $helpCols = getColumns($conn, 'help_questions');
    $helpUsersCols = getColumns($conn, 'users');
    $helpIdCol = chooseColumn($helpCols, ['id', 'question_id', 'post_id']);
    $helpStudentCol = chooseColumn($helpCols, ['student_id', 'user_id', 'created_by']);
    $helpTitleCol = chooseColumn($helpCols, ['title', 'question_title', 'post_title']);
    $helpDetailsCol = chooseColumn($helpCols, ['details', 'content', 'question', 'body', 'description']);
    $helpAnonCol = chooseColumn($helpCols, ['is_anonymous', 'anonymous']);
    $helpDateCol = chooseColumn($helpCols, ['created_at', 'posted_at', 'date_created']);
    $helpStatusCol = chooseColumn($helpCols, ['moderation_status', 'status', 'approval_status', 'post_status']);
    $helpUserIdCol = chooseColumn($helpUsersCols, ['user_id', 'id']);
    $helpNameCol = chooseColumn($helpUsersCols, ['full_name', 'name', 'username', 'student_name']);
    $helpboardHasStatus = (bool)$helpStatusCol;
    if (!$helpIdCol || !$helpTitleCol || !$helpDetailsCol) {
        throw new RuntimeException('help_questions needs an ID, title, and details/content column. Detected columns: ' . implode(', ', array_values($helpCols)));
    }
    $hpTitle = 'hq.' . qi($helpTitleCol);
    $hpDetails = 'hq.' . qi($helpDetailsCol);
    $hpDate = $helpDateCol ? 'hq.' . qi($helpDateCol) : 'NULL';
    $hpAnon = $helpAnonCol ? 'hq.' . qi($helpAnonCol) : '0';
    $hpStatus = $helpStatusCol ? 'hq.' . qi($helpStatusCol) : "'published'";
    $hpStudent = $helpStudentCol ? 'hq.' . qi($helpStudentCol) : 'NULL';
    $hpName = ($helpStudentCol && $helpUserIdCol && $helpNameCol)
        ? 'u.' . qi($helpNameCol)
        : "CONCAT('Student #', " . ($helpStudentCol ? 'hq.' . qi($helpStudentCol) : 'hq.' . qi($helpIdCol)) . ')';
    $hpJoin = ($helpStudentCol && $helpUserIdCol)
        ? ' LEFT JOIN users u ON hq.' . qi($helpStudentCol) . ' = u.' . qi($helpUserIdCol)
        : '';
    $commentCountExpr = '0';
    try {
        $hcCols = getColumns($conn, 'help_comments');
        $hcQuestionCol = chooseColumn($hcCols, ['question_id', 'post_id', 'help_question_id']);
        if ($hcQuestionCol) $commentCountExpr = '(SELECT COUNT(*) FROM help_comments hc WHERE hc.' . qi($hcQuestionCol) . ' = hq.' . qi($helpIdCol) . ')';
    } catch (Throwable $ignored) {}
    $helpWhere = [];
    $helpParams = [];
    if ($search !== '') {
        $helpSearch = [$hpTitle, $hpDetails];
        if ($helpNameCol && $helpStudentCol && $helpUserIdCol) $helpSearch[] = $hpName;
        $helpWhere[] = '(' . implode(' OR ', array_map(static fn($expr) => $expr . ' LIKE ?', $helpSearch)) . ')';
        foreach ($helpSearch as $_) $helpParams[] = '%' . $search . '%';
    }
    $helpWhereSql = $helpWhere ? ' WHERE ' . implode(' AND ', $helpWhere) : '';
    $helpSql = 'SELECT hq.' . qi($helpIdCol) . ' AS id, ' . $hpStudent . ' AS student_id, ' . $hpTitle . ' AS title, ' . $hpDetails . ' AS details, ' . $hpAnon . ' AS is_anonymous, ' . $hpDate . ' AS created_at, ' . $hpStatus . ' AS status, ' . $hpName . ' AS student_name, ' . $commentCountExpr . ' AS comment_count FROM help_questions hq' . $hpJoin . $helpWhereSql . ' ORDER BY ' . ($helpDateCol ? $hpDate : 'hq.' . qi($helpIdCol)) . ' DESC LIMIT 30';
    $helpStmt = $conn->prepare($helpSql);
    $helpStmt->execute($helpParams);
    $helpPosts = $helpStmt->fetchAll(PDO::FETCH_ASSOC);
    if ($helpStatusCol) {
        $helpStatusExpr = qi($helpStatusCol);
        $pendingStmt = $conn->query('SELECT COUNT(*) FROM help_questions WHERE (' . sqlPendingCondition($helpStatusExpr) . ") OR LOWER(TRIM(CAST(" . $helpStatusExpr . " AS CHAR))) = 'rejected'");
        $helpboardPendingCount = (int)$pendingStmt->fetchColumn();
    }
} catch (Throwable $helpEx) {
    error_log('Manage Notes Help Board load failed: ' . $helpEx->getMessage());
    $helpboardError = $helpEx->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Notes | NoteSphere Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
:root{--navy:#233961;--blue:#3D8BFF;--blue-dark:#286bd1;--bg:#f4f7fb;--card:#fff;--text:#17243b;--muted:#6d7b91;--border:#e3eaf3;--green:#12804a;--green-bg:#e7f8ee;--amber:#986000;--amber-bg:#fff4d7;--red:#c73535;--red-bg:#ffebeb;--shadow:0 10px 28px rgba(35,57,97,.07)}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:'Plus Jakarta Sans',sans-serif;font-size:14px}body.dark{--bg:#101827;--card:#182337;--text:#eef4ff;--muted:#a2b0c5;--border:#2a3950;--shadow:0 10px 28px rgba(0,0,0,.18);--green-bg:#153b2b;--amber-bg:#49391a;--red-bg:#482626}
.topbar{height:72px;background:var(--card);border-bottom:1px solid var(--border);display:flex;align-items:center;gap:14px;padding:0 28px;position:sticky;top:0;z-index:5}.back-x{width:38px;height:38px;border:1px solid var(--border);border-radius:12px;display:grid;place-items:center;color:var(--navy);font-size:27px;line-height:1;text-decoration:none;background:var(--card)}body.dark .back-x{color:var(--text)}.brand{font-size:19px;font-weight:800;color:var(--navy);letter-spacing:-.5px}body.dark .brand{color:var(--text)}.brand span{color:var(--blue)}.top-spacer{flex:1}.admin-label{font-weight:700;color:var(--muted);font-size:12px}.theme-toggle{border:1px solid var(--border);background:var(--card);color:var(--text);border-radius:11px;padding:10px 12px;cursor:pointer;font:inherit;font-weight:700}
.container{max-width:1440px;margin:0 auto;padding:30px 28px 45px}.heading-row{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:24px}.eyebrow{font-size:11px;text-transform:uppercase;letter-spacing:1.5px;color:var(--blue);font-weight:800;margin-bottom:7px}.page-title{font-size:28px;letter-spacing:-1px;margin:0 0 7px;font-weight:800}.subtitle{color:var(--muted);margin:0;line-height:1.6}.stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-bottom:22px}.stat-card{background:var(--card);border:1px solid var(--border);border-radius:17px;padding:20px;box-shadow:var(--shadow);display:flex;align-items:center;gap:15px}.stat-icon{width:46px;height:46px;border-radius:14px;display:grid;place-items:center;font-size:21px;background:#eaf2ff}.stat-card:nth-child(2) .stat-icon{background:var(--amber-bg)}.stat-card:nth-child(3) .stat-icon{background:var(--green-bg)}.stat-label{font-size:12px;color:var(--muted);font-weight:700;margin-bottom:5px}.stat-value{font-size:25px;font-weight:800;line-height:1.1}.panel{background:var(--card);border:1px solid var(--border);border-radius:18px;box-shadow:var(--shadow);overflow:hidden}.panel-head{padding:20px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:16px}.panel-title{margin:0;font-size:16px;font-weight:800}.panel-subtitle{font-size:12px;color:var(--muted);margin-top:5px}.filters{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:16px 22px;border-bottom:1px solid var(--border)}.filter{border:1px solid var(--border);border-radius:10px;padding:9px 13px;color:var(--muted);text-decoration:none;font-weight:700;font-size:12px}.filter.active{background:var(--navy);border-color:var(--navy);color:#fff}.search-form{display:flex;gap:8px;margin-left:auto}.search-input{width:min(280px,40vw);padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg);color:var(--text);font:inherit;outline:none}.search-input:focus{border-color:var(--blue);box-shadow:0 0 0 3px #3d8bff20}.btn{border:0;border-radius:10px;padding:10px 13px;font:inherit;font-weight:800;font-size:12px;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:6px;white-space:nowrap}.btn-blue{background:var(--blue);color:white}.btn-blue:hover{background:var(--blue-dark)}.btn-approve{background:var(--green-bg);color:var(--green)}.btn-delete{background:var(--red-bg);color:var(--red)}.table-wrap{width:100%;overflow-x:auto}table{width:100%;border-collapse:collapse;min-width:900px}thead th{background:color-mix(in srgb,var(--bg) 80%,var(--card));padding:13px 16px;text-align:left;font-size:10px;letter-spacing:.8px;text-transform:uppercase;color:var(--muted);font-weight:800;white-space:nowrap}tbody td{padding:16px;border-top:1px solid var(--border);vertical-align:middle}tbody tr:hover{background:color-mix(in srgb,var(--blue) 3%,var(--card))}.note-title{font-weight:800;font-size:13px;max-width:280px;overflow-wrap:anywhere}.note-desc{font-size:11px;color:var(--muted);line-height:1.5;max-width:300px;margin-top:5px;overflow-wrap:anywhere}.subject{font-weight:700}.uploader{font-weight:700}.subtle{color:var(--muted);font-size:11px;margin-top:4px}.status{display:inline-flex;align-items:center;border-radius:999px;padding:6px 9px;font-size:10px;text-transform:capitalize;font-weight:800}.status.pending{color:var(--amber);background:var(--amber-bg)}.status.approved{color:var(--green);background:var(--green-bg)}.row-actions{display:flex;align-items:center;gap:7px;flex-wrap:wrap}.inline-form{display:inline;margin:0}.view-link{color:var(--blue);font-weight:800;text-decoration:none;font-size:12px}.view-link:hover{text-decoration:underline}.empty{padding:52px 20px;text-align:center;color:var(--muted)}.empty-icon{font-size:34px;margin-bottom:10px}.empty strong{display:block;color:var(--text);font-size:15px;margin-bottom:5px}.panel-foot{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:16px 22px;border-top:1px solid var(--border);color:var(--muted);font-size:12px}.pagination{display:flex;gap:6px;align-items:center}.page-link{min-width:34px;height:34px;display:grid;place-items:center;border:1px solid var(--border);border-radius:9px;text-decoration:none;color:var(--text);font-weight:700;padding:0 8px}.page-link.current{background:var(--navy);color:#fff;border-color:var(--navy)}.page-link.disabled{opacity:.4;pointer-events:none}.mobile-note{display:none}
@media(max-width:900px){.container{padding:24px 16px}.topbar{padding:0 16px}.stats{gap:10px}.stat-card{padding:15px}.stat-value{font-size:22px}.panel-head,.filters{padding-left:16px;padding-right:16px}.search-form{width:100%;margin:6px 0 0}.search-input{width:100%;flex:1}.filters{align-items:stretch}.panel-foot{padding:14px 16px}}
@media(max-width:600px){.topbar{height:64px}.brand{font-size:17px}.admin-label{display:none}.heading-row{display:block}.page-title{font-size:24px}.stats{grid-template-columns:1fr;gap:10px}.stat-card{padding:14px}.stat-icon{width:42px;height:42px}.filters .filter{flex:1;text-align:center}.search-form{display:flex}.panel-head{align-items:flex-start}.panel-foot{align-items:flex-start;flex-direction:column}.pagination{flex-wrap:wrap}.table-wrap{overflow:visible}table{min-width:0}thead{display:none}tbody tr{display:block;padding:14px 16px;border-top:1px solid var(--border)}tbody td{display:block;border:0;padding:5px 0;overflow-wrap:anywhere}tbody td:before{content:attr(data-label);display:block;color:var(--muted);font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.7px;margin-bottom:4px}.row-actions{padding-top:9px}.note-title,.note-desc{max-width:none}.btn{padding:9px 10px}}
</style>
</head>
<body>
<header class="topbar">
  <a class="back-x" href="admin_dashboard.php" title="Back to Admin Dashboard" aria-label="Back to Admin Dashboard">×</a>
  <div class="brand">Note<span>Sphere</span></div>
  <div class="top-spacer"></div>
  <div class="admin-label">ADMIN CONTROL PANEL</div>
  <button class="theme-toggle" id="themeToggle" type="button" aria-label="Toggle dark mode">🌙 Theme</button>
</header>
<main class="container">
  <div class="heading-row">
    <div><div class="eyebrow">Content management</div><h1 class="page-title">Manage Notes</h1><p class="subtitle">Review submitted learning materials, approve pending notes, or remove inappropriate and outdated uploads.</p></div>
  </div>

  <section class="stats" aria-label="Note statistics">
    <div class="stat-card"><div class="stat-icon">📚</div><div><div class="stat-label">Total Notes</div><div class="stat-value"><?= number_format($counts['total']) ?></div></div></div>
    <div class="stat-card"><div class="stat-icon">⏳</div><div><div class="stat-label">Pending Review</div><div class="stat-value"><?= number_format($counts['pending']) ?></div></div></div>
    <div class="stat-card"><div class="stat-icon">✓</div><div><div class="stat-label">Approved Notes</div><div class="stat-value"><?= number_format($counts['approved']) ?></div></div></div>
  </section>

  <section class="panel">
    <div class="panel-head"><div><h2 class="panel-title">Notes Library</h2><div class="panel-subtitle">Search, filter, preview, approve, or delete notes.</div></div></div>
    <div class="filters">
      <a class="filter <?= $statusFilter === 'all' ? 'active' : '' ?>" href="<?= e(pageUrl(1, 'all', $search)) ?>">All Notes (<?= number_format($counts['total']) ?>)</a>
      <a class="filter <?= $statusFilter === 'pending' ? 'active' : '' ?>" href="<?= e(pageUrl(1, 'pending', $search)) ?>">Pending (<?= number_format($counts['pending']) ?>)</a>
      <a class="filter <?= $statusFilter === 'approved' ? 'active' : '' ?>" href="<?= e(pageUrl(1, 'approved', $search)) ?>">Approved (<?= number_format($counts['approved']) ?>)</a>
      <form class="search-form" method="get" action="<?= e(basename($_SERVER['PHP_SELF'])) ?>">
        <?php if ($statusFilter !== 'all'): ?><input type="hidden" name="status" value="<?= e($statusFilter) ?>"><?php endif; ?>
        <input class="search-input" type="search" name="q" value="<?= e($search) ?>" maxlength="120" placeholder="Search title, subject, uploader..." aria-label="Search notes">
        <button class="btn btn-blue" type="submit">Search</button>
        <?php if ($search !== ''): ?><a class="btn" style="background:var(--bg);color:var(--text);border:1px solid var(--border)" href="<?= e(pageUrl(1, $statusFilter, '')) ?>">Clear</a><?php endif; ?>
      </form>
    </div>

    <?php if (!$notes): ?>
      <div class="empty"><div class="empty-icon">📂</div><strong><?= $schemaError !== '' ? 'Database schema needs attention' : 'No notes found' ?></strong><span><?= $schemaError !== '' ? e($schemaError) : ($search !== '' ? 'Try a different search keyword or clear the search.' : 'There are no notes in this category yet.') ?></span></div>
    <?php else: ?>
      <div class="table-wrap"><table>
        <thead><tr><th>Note details</th><th>Subject</th><th>Uploaded by</th><th>Status</th><th>Date added</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($notes as $note): ?>
          <tr>
            <td data-label="Note details"><div class="note-title"><?= e($note['title']) ?></div><div class="note-desc"><?= e((function_exists('mb_strimwidth') ? mb_strimwidth((string)($note['description'] ?? ''), 0, 150, '…', 'UTF-8') : substr((string)($note['description'] ?? ''), 0, 150))) ?></div><div style="margin-top:8px"><a class="view-link" href="note_view.php?note_id=<?= (int)$note['id'] ?>">↗ Preview note</a></div></td>
            <td data-label="Subject"><div class="subject"><?= e($note['subject'] ?: '—') ?></div></td>
            <td data-label="Uploaded by"><div class="uploader"><?= e($note['uploader_name'] ?: 'Unknown user') ?></div><div class="subtle">User ID: <?= (int)$note['user_id'] ?></div></td>
            <td data-label="Status"><span class="status <?= isPendingNoteStatus($note['status'] ?? '') ? 'pending' : (isApprovedNoteStatus($note['status'] ?? '') ? 'approved' : '') ?>"><?= e($note['status']) ?></span></td>
            <td data-label="Date added"><div><?= !empty($note['created_at']) ? e(date('M d, Y', strtotime((string)$note['created_at']))) : '—' ?></div><div class="subtle"><?= !empty($note['created_at']) ? e(date('h:i A', strtotime((string)$note['created_at']))) : '' ?></div></td>
            <td data-label="Actions"><div class="row-actions">
              <?php if (isPendingNoteStatus($note['status'] ?? '')): ?>
                <form class="inline-form action-form" method="post" data-action="approve" data-title="<?= e($note['title']) ?>"><input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>"><input type="hidden" name="action" value="approve"><input type="hidden" name="note_id" value="<?= (int)$note['id'] ?>"><button class="btn btn-approve" type="submit">✓ Approve</button></form>
              <?php endif; ?>
              <form class="inline-form action-form" method="post" data-action="delete" data-title="<?= e($note['title']) ?>"><input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="note_id" value="<?= (int)$note['id'] ?>"><button class="btn btn-delete" type="submit">🗑 Delete</button></form>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <div class="panel-foot"><div>Showing <?= number_format(($page - 1) * $perPage + 1) ?>–<?= number_format(min($page * $perPage, $totalRows)) ?> of <?= number_format($totalRows) ?> notes</div><nav class="pagination" aria-label="Pagination">
        <a class="page-link <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= e(pageUrl(max(1, $page - 1), $statusFilter, $search)) ?>" aria-label="Previous page">‹</a>
        <?php $startPage = max(1, $page - 2); $endPage = min($totalPages, $page + 2); for ($p = $startPage; $p <= $endPage; $p++): ?><a class="page-link <?= $p === $page ? 'current' : '' ?>" href="<?= e(pageUrl($p, $statusFilter, $search)) ?>" <?= $p === $page ? 'aria-current="page"' : '' ?>><?= $p ?></a><?php endfor; ?>
        <a class="page-link <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= e(pageUrl(min($totalPages, $page + 1), $statusFilter, $search)) ?>" aria-label="Next page">›</a>
      </nav></div>
    <?php endif; ?>
  </section>

  <section class="panel" style="margin-top:24px">
    <div class="panel-head">
      <div><h2 class="panel-title">Student Help Board Posts</h2><div class="panel-subtitle">Student questions are stored in <code>help_questions</code>, not in the notes table. The latest 30 posts are shown here.</div></div>
      <div class="status pending"><?= number_format($helpboardPendingCount) ?> pending review</div>
    </div>
    <?php if ($helpboardError !== ''): ?>
      <div class="empty"><div class="empty-icon">⚠️</div><strong>Unable to load Help Board posts</strong><span><?= e($helpboardError) ?></span><div class="subtle" style="margin-top:8px">Confirm that help_questions exists and contains the student post records.</div></div>
    <?php elseif (!$helpPosts): ?>
      <div class="empty"><div class="empty-icon">💬</div><strong>No Help Board posts found</strong><span><?= $search !== '' ? 'Try another search keyword or clear the search field.' : 'No student questions are currently stored in help_questions.' ?></span></div>
    <?php else: ?>
      <div class="table-wrap"><table>
        <thead><tr><th>Post</th><th>Student</th><th>Comments</th><th>Status</th><th>Date posted</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($helpPosts as $hp): $hpStatus = strtolower(trim((string)($hp['status'] ?? 'published'))); $hpPending = in_array($hpStatus, ['pending', 'rejected'], true); $hpAnonymous = in_array(strtolower((string)($hp['is_anonymous'] ?? '0')), ['1','yes','true'], true); ?>
          <tr>
            <td data-label="Post"><div class="note-title"><?= e($hp['title']) ?></div><div class="note-desc"><?= e(function_exists('mb_strimwidth') ? mb_strimwidth((string)$hp['details'], 0, 220, '…', 'UTF-8') : substr((string)$hp['details'], 0, 220)) ?></div></td>
            <td data-label="Student"><div class="uploader"><?= $hpAnonymous ? 'Anonymous Student' : e($hp['student_name'] ?: 'Unknown student') ?></div><?php if (!$hpAnonymous && $hp['student_id'] !== null): ?><div class="subtle">User ID: <?= (int)$hp['student_id'] ?></div><?php endif; ?></td>
            <td data-label="Comments"><?= number_format((int)$hp['comment_count']) ?></td>
            <td data-label="Status"><span class="status <?= $hpPending ? 'pending' : (in_array($hpStatus, ['approved','published','active','visible'], true) ? 'approved' : '') ?>"><?= e($hpStatus ?: 'published') ?></span></td>
            <td data-label="Date posted"><?= !empty($hp['created_at']) ? e(date('M d, Y h:i A', strtotime((string)$hp['created_at']))) : '—' ?></td>
            <td data-label="Actions"><div class="row-actions">
              <?php if ($hpPending && $helpboardHasStatus): ?><form class="inline-form action-form" method="post" data-action="help_approve" data-title="<?= e($hp['title']) ?>"><input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>"><input type="hidden" name="action" value="help_approve"><input type="hidden" name="question_id" value="<?= (int)$hp['id'] ?>"><button class="btn btn-approve" type="submit">✓ Approve post</button></form><?php endif; ?>
              <form class="inline-form action-form" method="post" data-action="help_delete" data-title="<?= e($hp['title']) ?>"><input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>"><input type="hidden" name="action" value="help_delete"><input type="hidden" name="question_id" value="<?= (int)$hp['id'] ?>"><button class="btn btn-delete" type="submit">🗑 Delete post</button></form>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php if (!$helpboardHasStatus): ?><div style="padding:12px 22px;color:var(--muted);font-size:12px;border-top:1px solid var(--border)">Note: No approval-status column was detected in <code>help_questions</code>. Posts are displayed as currently stored; approval controls require a moderation/status column.</div><?php endif; ?>
    <?php endif; ?>
  </section>
</main>
<script>
(function(){
  const body=document.body, toggle=document.getElementById('themeToggle');
  function applyTheme(theme){body.classList.toggle('dark',theme==='dark');toggle.textContent=theme==='dark'?'☀️ Light mode':'🌙 Dark mode';}
  let saved='light';try{saved=localStorage.getItem('notesphere_admin_theme')||'light';}catch(e){}
  applyTheme(saved);
  toggle.addEventListener('click',function(){const next=body.classList.contains('dark')?'light':'dark';applyTheme(next);try{localStorage.setItem('notesphere_admin_theme',next);}catch(e){}});

  document.querySelectorAll('.action-form').forEach(function(form){form.addEventListener('submit',function(ev){
    ev.preventDefault();
    const action=form.dataset.action, title=form.dataset.title||'this item';
    const approve=(action==='approve'||action==='help_approve');
    const helpAction=action.indexOf('help_')===0;
    const itemName=helpAction?'Help Board post':'note';
    const detail=approve?'Approve <strong>'+escapeHtml(title)+'</strong> so it becomes visible to students?':(helpAction?'Permanently delete Help Board post <strong>'+escapeHtml(title)+'</strong> and its related comments?':'Permanently delete <strong>'+escapeHtml(title)+'</strong>?<br><small>The note record will be removed. Its uploaded file will only be removed if no other note references it.</small>');
    Swal.fire({title:approve?'Approve this '+itemName+'?':'Delete this '+itemName+'?',html:detail,icon:approve?'question':'warning',showCancelButton:true,confirmButtonText:approve?'Yes, approve':'Yes, delete',cancelButtonText:'Cancel',confirmButtonColor:approve?'#12804a':'#c73535',reverseButtons:true}).then(function(result){if(result.isConfirmed)form.submit();});
  });});
  function escapeHtml(value){return String(value).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];});}
  <?php if (is_array($flash) && !empty($flash['message'])): ?>
  Swal.fire({icon:<?= json_encode(($flash['type'] ?? '') === 'success' ? 'success' : 'error') ?>,title:<?= json_encode(($flash['type'] ?? '') === 'success' ? 'Success' : 'Notice') ?>,text:<?= json_encode((string)$flash['message']) ?>,confirmButtonColor:'#3D8BFF'});
  <?php endif; ?>
})();
</script>
</body>
</html>
