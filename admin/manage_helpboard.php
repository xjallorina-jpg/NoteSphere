
<?php
// =========================================================
// NoteSphere - Admin Help Board Management
// Help Board Only - No Post Display Limit
// =========================================================

session_start();
require_once __DIR__ . '/../includes/db_config.php';

if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'admin'
) {
    header('Location: ../auth/login.php');
    exit();
}

if (!isset($conn) || !($conn instanceof PDO)) {
    http_response_code(500);
    exit('Database connection is unavailable. Check includes/db_config.php.');
}

if (empty($_SESSION['manage_helpboard_csrf'])) {
    $_SESSION['manage_helpboard_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['manage_helpboard_csrf'];
$flash = $_SESSION['manage_helpboard_flash'] ?? null;
unset($_SESSION['manage_helpboard_flash']);

// ---------------------------------------------------------
// Helper functions
// ---------------------------------------------------------

function hb_e($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function hb_qi(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function hb_columns(PDO $conn, string $table): array
{
    $columns = [];

    $stmt = $conn->query(
        'SHOW COLUMNS FROM ' . hb_qi($table)
    );

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $columns[strtolower($row['Field'])] = $row['Field'];
    }

    return $columns;
}

function hb_choose(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (isset($columns[strtolower($candidate)])) {
            return $columns[strtolower($candidate)];
        }
    }

    return null;
}

function hb_status(string $value): string
{
    $value = strtolower(trim($value));

    return str_replace(['_', '-'], ' ', $value);
}

function hb_is_pending(string $value): bool
{
    $value = hb_status($value);

    return in_array(
        $value,
        [
            '0',
            'pending',
            'for approval',
            'awaiting approval',
            'under review',
            'for review',
            'awaiting review',
            'waiting for approval',
            'submitted',
            'unverified',
            'unapproved'
        ],
        true
    ) || str_starts_with($value, 'pending ');
}

function hb_is_approved(string $value): bool
{
    return in_array(
        hb_status($value),
        ['1', 'approved', 'published', 'active', 'visible'],
        true
    );
}

function hb_is_rejected(string $value): bool
{
    return in_array(
        hb_status($value),
        ['rejected', 'declined', 'disapproved'],
        true
    );
}

function hb_redirect(
    string $type,
    string $message,
    string $filter,
    string $search
): void {
    $_SESSION['manage_helpboard_flash'] = [
        'type' => $type,
        'message' => $message
    ];

    $params = [];

    if ($filter !== 'all') {
        $params['status'] = $filter;
    }

    if ($search !== '') {
        $params['search'] = $search;
    }

    $url = basename($_SERVER['PHP_SELF']);

    if ($params) {
        $url .= '?' . http_build_query($params);
    }

    header('Location: ' . $url);
    exit();
}

// ---------------------------------------------------------
// Search and filters
// ---------------------------------------------------------

$filter = (string)($_GET['status'] ?? 'all');

if (!in_array($filter, ['all', 'pending', 'approved', 'rejected'], true)) {
    $filter = 'all';
}

$search = trim((string)($_GET['search'] ?? ''));

if (function_exists('mb_substr')) {
    $search = mb_substr($search, 0, 120);
} else {
    $search = substr($search, 0, 120);
}

$posts = [];

$counts = [
    'total' => 0,
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0
];

$errorMessage = '';
$hasStatus = false;

try {
    $qCols = hb_columns($conn, 'help_questions');
    $uCols = hb_columns($conn, 'users');

    $idCol = hb_choose($qCols, [
        'id',
        'question_id',
        'post_id'
    ]);

    $studentCol = hb_choose($qCols, [
        'student_id',
        'user_id',
        'created_by'
    ]);

    $titleCol = hb_choose($qCols, [
        'title',
        'question_title',
        'post_title'
    ]);

    $detailsCol = hb_choose($qCols, [
        'details',
        'content',
        'question',
        'body',
        'description'
    ]);

    $anonymousCol = hb_choose($qCols, [
        'is_anonymous',
        'anonymous'
    ]);

    $dateCol = hb_choose($qCols, [
        'created_at',
        'posted_at',
        'date_created'
    ]);

    $statusCol = hb_choose($qCols, [
        'moderation_status',
        'status',
        'approval_status',
        'post_status'
    ]);

    $nameIdCol = hb_choose($uCols, [
        'user_id',
        'id'
    ]);

    $nameCol = hb_choose($uCols, [
        'full_name',
        'name',
        'username',
        'student_name'
    ]);

    $hasStatus = (bool)$statusCol;

    if (!$idCol || !$titleCol || !$detailsCol) {
        throw new RuntimeException(
            'The help_questions table must contain an ID, title, and details/content column.'
        );
    }

    $idExpr = 'hq.' . hb_qi($idCol);
    $titleExpr = 'hq.' . hb_qi($titleCol);
    $detailsExpr = 'hq.' . hb_qi($detailsCol);

    $studentExpr = $studentCol
        ? 'hq.' . hb_qi($studentCol)
        : 'NULL';

    $anonymousExpr = $anonymousCol
        ? 'hq.' . hb_qi($anonymousCol)
        : '0';

    $dateExpr = $dateCol
        ? 'hq.' . hb_qi($dateCol)
        : 'NULL';

    $statusExpr = $statusCol
        ? 'hq.' . hb_qi($statusCol)
        : "'approved'";

    $nameExpr = ($studentCol && $nameIdCol && $nameCol)
        ? 'u.' . hb_qi($nameCol)
        : "CONCAT('Student #', " .
            ($studentCol ? 'hq.' . hb_qi($studentCol) : $idExpr) .
            ')';

    $join = ($studentCol && $nameIdCol && $nameCol)
        ? ' LEFT JOIN users u ON hq.' .
            hb_qi($studentCol) .
            ' = u.' .
            hb_qi($nameIdCol)
        : '';

    // -----------------------------------------------------
    // Handle Help Board actions
    // -----------------------------------------------------

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $postedToken = (string)($_POST['csrf_token'] ?? '');

        if (!hash_equals((string)$csrfToken, $postedToken)) {
            hb_redirect(
                'error',
                'Security token expired. Please refresh and try again.',
                $filter,
                $search
            );
        }

        $action = (string)($_POST['action'] ?? '');

        $postId = filter_var(
            $_POST['question_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if (!$postId) {
            hb_redirect(
                'error',
                'Please select a valid Help Board post.',
                $filter,
                $search
            );
        }

        try {
            $find = $conn->prepare(
                'SELECT ' . hb_qi($idCol) .
                ' FROM help_questions WHERE ' .
                hb_qi($idCol) . ' = ? LIMIT 1'
            );

            $find->execute([(int)$postId]);

            if (!$find->fetchColumn()) {
                hb_redirect(
                    'error',
                    'The Help Board post no longer exists.',
                    $filter,
                    $search
                );
            }

            // Approve or reject
            if (
                $action === 'help_approve' ||
                $action === 'help_reject'
            ) {
                if (!$statusCol) {
                    hb_redirect(
                        'error',
                        'Approval controls require a moderation status column in help_questions.',
                        $filter,
                        $search
                    );
                }

                $newStatus = $action === 'help_approve'
                    ? 'approved'
                    : 'rejected';

                $set = [
                    hb_qi($statusCol) . ' = ?'
                ];

                $values = [$newStatus];

                if (isset($qCols['moderation_note'])) {
                    $set[] = hb_qi($qCols['moderation_note']) . ' = ?';

                    $values[] = $action === 'help_reject'
                        ? 'Rejected by administrator.'
                        : null;
                }

                if (isset($qCols['moderated_at'])) {
                    $set[] = hb_qi($qCols['moderated_at']) . ' = NOW()';
                }

                if (isset($qCols['moderated_by'])) {
                    $set[] = hb_qi($qCols['moderated_by']) . ' = ?';
                    $values[] = (int)$_SESSION['user_id'];
                }

                $values[] = (int)$postId;

                $update = $conn->prepare(
                    'UPDATE help_questions SET ' .
                    implode(', ', $set) .
                    ' WHERE ' . hb_qi($idCol) . ' = ?'
                );

                $update->execute($values);

                hb_redirect(
                    'success',
                    $action === 'help_approve'
                        ? 'Help Board post approved successfully.'
                        : 'Help Board post rejected.',
                    $filter,
                    $search
                );
            }

            // Delete post and related records
            if ($action === 'help_delete') {
                $conn->beginTransaction();

                foreach ([
                    'help_comments',
                    'help_board_comments'
                ] as $commentsTable) {
                    try {
                        $commentCols = hb_columns(
                            $conn,
                            $commentsTable
                        );

                        $commentPostCol = hb_choose(
                            $commentCols,
                            [
                                'question_id',
                                'post_id',
                                'help_question_id'
                            ]
                        );

                        if ($commentPostCol) {
                            $deleteComments = $conn->prepare(
                                'DELETE FROM ' .
                                hb_qi($commentsTable) .
                                ' WHERE ' .
                                hb_qi($commentPostCol) .
                                ' = ?'
                            );

                            $deleteComments->execute([(int)$postId]);
                        }
                    } catch (Throwable $ignored) {
                        // Optional comments table may not exist.
                    }
                }

                try {
                    $hiddenCols = hb_columns(
                        $conn,
                        'help_hidden_posts'
                    );

                    $hiddenPostCol = hb_choose(
                        $hiddenCols,
                        [
                            'question_id',
                            'post_id',
                            'help_question_id'
                        ]
                    );

                    if ($hiddenPostCol) {
                        $deleteHidden = $conn->prepare(
                            'DELETE FROM help_hidden_posts WHERE ' .
                            hb_qi($hiddenPostCol) . ' = ?'
                        );

                        $deleteHidden->execute([(int)$postId]);
                    }
                } catch (Throwable $ignored) {
                    // Optional hidden-post table may not exist.
                }

                $deletePost = $conn->prepare(
                    'DELETE FROM help_questions WHERE ' .
                    hb_qi($idCol) . ' = ?'
                );

                $deletePost->execute([(int)$postId]);

                $conn->commit();

                hb_redirect(
                    'success',
                    'Help Board post and related records were deleted.',
                    $filter,
                    $search
                );
            }

            hb_redirect(
                'error',
                'Unknown Help Board action.',
                $filter,
                $search
            );
        } catch (Throwable $actionError) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            error_log(
                'Help Board admin action failed: ' .
                $actionError->getMessage()
            );

            hb_redirect(
                'error',
                'The action could not be completed. Check the database and try again.',
                $filter,
                $search
            );
        }
    }

    // -----------------------------------------------------
    // Count all Help Board posts
    // -----------------------------------------------------

    $allRows = $conn->query(
        'SELECT ' . $statusExpr .
        ' AS post_status FROM help_questions hq'
    )->fetchAll(PDO::FETCH_COLUMN);

    $counts['total'] = count($allRows);

    foreach ($allRows as $rawStatus) {
        $rawStatus = (string)$rawStatus;

        if (hb_is_pending($rawStatus)) {
            $counts['pending']++;
        } elseif (hb_is_rejected($rawStatus)) {
            $counts['rejected']++;
        } elseif (hb_is_approved($rawStatus)) {
            $counts['approved']++;
        }
    }

    // -----------------------------------------------------
    // Build filters
    // -----------------------------------------------------

    $where = [];
    $params = [];

    if ($filter !== 'all' && $hasStatus) {
        $normalizedStatusExpr =
            "REPLACE(REPLACE(LOWER(TRIM(CAST(" .
            $statusExpr .
            " AS CHAR))), '_', ' '), '-', ' ')";

        if ($filter === 'pending') {
            $where[] =
                "(" . $normalizedStatusExpr .
                " IN ('0','pending','for approval','awaiting approval'," .
                "'under review','for review','awaiting review'," .
                "'waiting for approval','submitted','unverified','unapproved')" .
                " OR " . $normalizedStatusExpr . " LIKE 'pending %')";

        } elseif ($filter === 'approved') {
            $where[] =
                "LOWER(TRIM(CAST(" . $statusExpr .
                " AS CHAR))) IN ('1','approved','published','active','visible')";

        } else {
            $where[] =
                "LOWER(TRIM(CAST(" . $statusExpr .
                " AS CHAR))) IN ('rejected','declined','disapproved')";
        }
    }

    if ($search !== '') {
        $searchExprs = [
            $titleExpr,
            $detailsExpr
        ];

        if ($studentCol && $nameIdCol && $nameCol) {
            $searchExprs[] = $nameExpr;
        }

        $likeParts = [];

        foreach ($searchExprs as $expr) {
            $likeParts[] = $expr . ' LIKE ?';
            $params[] = '%' . $search . '%';
        }

        $where[] = '(' . implode(' OR ', $likeParts) . ')';
    }

    $whereSql = $where
        ? ' WHERE ' . implode(' AND ', $where)
        : '';

    // -----------------------------------------------------
    // Detect comments table for comment counts
    // -----------------------------------------------------

    $commentCountExpr = '0';

    foreach ([
        'help_comments',
        'help_board_comments'
    ] as $commentsTable) {
        try {
            $commentCols = hb_columns(
                $conn,
                $commentsTable
            );

            $commentPostCol = hb_choose(
                $commentCols,
                [
                    'question_id',
                    'post_id',
                    'help_question_id'
                ]
            );

            if ($commentPostCol) {
                $commentCountExpr =
                    '(SELECT COUNT(*) FROM ' .
                    hb_qi($commentsTable) .
                    ' hc WHERE hc.' .
                    hb_qi($commentPostCol) .
                    ' = ' . $idExpr . ')';

                break;
            }
        } catch (Throwable $ignored) {
            // Optional comments table may not exist.
        }
    }

    // -----------------------------------------------------
    // Fetch ALL matching posts - no LIMIT 100
    // -----------------------------------------------------

    $sql =
        'SELECT ' .
        $idExpr . ' AS id, ' .
        $studentExpr . ' AS student_id, ' .
        $titleExpr . ' AS title, ' .
        $detailsExpr . ' AS details, ' .
        $anonymousExpr . ' AS is_anonymous, ' .
        $dateExpr . ' AS created_at, ' .
        $statusExpr . ' AS status, ' .
        $nameExpr . ' AS student_name, ' .
        $commentCountExpr . ' AS comment_count ' .
        'FROM help_questions hq' .
        $join .
        $whereSql .
        ' ORDER BY ' .
        ($dateCol ? $dateExpr : $idExpr) .
        ' DESC';

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $ex) {
    error_log(
        'Help Board admin page failed: ' .
        $ex->getMessage()
    );

    $errorMessage = 'Unable to load Help Board data. ' .
        'Please check the database structure and try again.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Help Board Management | NoteSphere</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
:root {
    --navy: #233961;
    --blue: #3D8BFF;
    --bg: #f4f7fb;
    --card: #ffffff;
    --text: #000000;
    --muted: #000000;
    --border: #e3eaf3;
    --green: #12804a;
    --green-bg: #e7f8ee;
    --amber: #986000;
    --amber-bg: #fff4d7;
    --red: #c73535;
    --red-bg: #ffebeb;
    --shadow: 0 10px 28px rgba(35,57,97,.07);
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: var(--bg);
    background-image: url('../assets/Untitled design.png');
    background-size: cover;
    background-attachment: fixed;
    color: var(--text);
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 14px;
}

body.dark {
    --bg: #101827;
    --card: #182337;
    --text: #eef4ff;
    --muted: #a2b0c5;
    --border: #2a3950;
    --shadow: 0 10px 28px rgba(0,0,0,.18);
    --green-bg: #153b2b;
    --amber-bg: #49391a;
    --red-bg: #482626;
}

.back-x {
    position: fixed;
    top: 16px;
    left: 18px;
    z-index: 1000;
    width: 42px;
    height: 42px;
    border: 1px solid var(--border);
    border-radius: 12px;
    display: grid;
    place-items: center;
    color: var(--navy);
    font-size: 28px;
    line-height: 1;
    text-decoration: none;
    background: var(--card);
    box-shadow: var(--shadow);
    transition: .2s ease;
}

.back-x:hover {
    background: var(--navy);
    color: #fff;
    transform: translateY(-1px);
}

body.dark .back-x {
    color: var(--text);
}

body.dark .back-x:hover {
    color: #fff;
}

.container {
    max-width: 1440px;
    margin: 0 auto;
    padding: 82px 28px 45px;
}

.heading-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    margin-bottom: 24px;
}

.eyebrow {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    color: var(--blue);
    font-weight: 800;
    margin-bottom: 7px;
}

.page-title {
    font-size: 28px;
    letter-spacing: -1px;
    margin: 0 0 7px;
    font-weight: 800;
}

.subtitle {
    color: var(--muted);
    margin: 0;
    line-height: 1.6;
}

.stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 22px;
}

.stat-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 17px;
    padding: 20px;
    box-shadow: var(--shadow);
    display: flex;
    align-items: center;
    gap: 15px;
    min-width: 0;
}

.stat-icon {
    width: 46px;
    height: 46px;
    flex: 0 0 46px;
    border-radius: 14px;
    display: grid;
    place-items: center;
    font-size: 21px;
    background: #eaf2ff;
}

.stat-card:nth-child(2) .stat-icon {
    background: var(--amber-bg);
}

.stat-card:nth-child(3) .stat-icon {
    background: var(--green-bg);
}

.stat-card:nth-child(4) .stat-icon {
    background: var(--red-bg);
}

.stat-label {
    font-size: 12px;
    color: var(--muted);
    font-weight: 700;
    margin-bottom: 5px;
}

.stat-value {
    font-size: 25px;
    font-weight: 800;
    line-height: 1.1;
}

.panel {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 18px;
    box-shadow: var(--shadow);
    overflow: hidden;
}

.panel-head {
    padding: 20px 22px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}

.panel-title {
    margin: 0;
    font-size: 16px;
    font-weight: 800;
}

.panel-subtitle {
    font-size: 12px;
    color: var(--muted);
    margin-top: 5px;
}

.filters {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    padding: 16px 22px;
    border-bottom: 1px solid var(--border);
}

.filter {
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 9px 13px;
    color: var(--muted);
    text-decoration: none;
    font-weight: 700;
    font-size: 12px;
}

.filter.active {
    background: var(--navy);
    border-color: var(--navy);
    color: #fff;
}

.search-form {
    display: flex;
    gap: 8px;
    margin-left: auto;
}

.search-input {
    width: min(280px, 40vw);
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--bg);
    color: var(--text);
    font: inherit;
    outline: none;
}

.search-input:focus {
    border-color: var(--blue);
    box-shadow: 0 0 0 3px #3d8bff20;
}

.btn {
    border: 0;
    border-radius: 10px;
    padding: 10px 13px;
    font: inherit;
    font-size: 12px;
    font-weight: 800;
    cursor: pointer;
    white-space: nowrap;
}

.btn-search {
    background: var(--navy);
    color: #fff;
}

.btn-approve {
    background: var(--green-bg);
    color: var(--green);
}

.btn-reject {
    background: var(--amber-bg);
    color: var(--amber);
}

.btn-delete {
    background: var(--red-bg);
    color: var(--red);
}

.table-wrap {
    width: 100%;
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 900px;
}

th {
    text-align: left;
    padding: 13px 18px;
    background: var(--bg);
    color: var(--muted);
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 1px;
    font-weight: 800;
    border-bottom: 1px solid var(--border);
}

td {
    padding: 17px 18px;
    border-bottom: 1px solid var(--border);
    vertical-align: top;
}

tbody tr:last-child td {
    border-bottom: 0;
}

.post-title {
    font-weight: 800;
    font-size: 13px;
    margin-bottom: 6px;
    overflow-wrap: anywhere;
}

.post-details {
    color: var(--muted);
    font-size: 12px;
    line-height: 1.6;
    max-width: 520px;
    overflow-wrap: anywhere;
}

.student-name {
    font-weight: 700;
}

.subtle {
    font-size: 11px;
    color: var(--muted);
    margin-top: 5px;
}

.status {
    display: inline-flex;
    align-items: center;
    padding: 6px 9px;
    border-radius: 999px;
    font-size: 10px;
    font-weight: 800;
    text-transform: capitalize;
    white-space: nowrap;
    background: var(--bg);
    color: var(--muted);
}

.status.pending {
    background: var(--amber-bg);
    color: var(--amber);
}

.status.approved {
    background: var(--green-bg);
    color: var(--green);
}

.status.rejected {
    background: var(--red-bg);
    color: var(--red);
}

.row-actions {
    display: flex;
    gap: 7px;
    flex-wrap: wrap;
}

.inline-form {
    display: inline-flex;
    margin: 0;
}

.empty {
    padding: 55px 22px;
    text-align: center;
    display: flex;
    align-items: center;
    flex-direction: column;
    gap: 8px;
    color: var(--muted);
}

.empty-icon {
    font-size: 32px;
    margin-bottom: 4px;
}

.empty strong {
    color: var(--text);
    font-size: 15px;
}

.panel-foot {
    padding: 15px 22px;
    border-top: 1px solid var(--border);
    font-size: 12px;
    color: var(--muted);
}

@media (max-width: 900px) {
    .stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .container {
        padding: 76px 16px 30px;
    }
}

@media (max-width: 560px) {
    .stats {
        gap: 10px;
    }

    .stat-card {
        padding: 14px;
        gap: 10px;
    }

    .stat-icon {
        width: 38px;
        height: 38px;
        flex-basis: 38px;
        font-size: 18px;
    }

    .stat-value {
        font-size: 22px;
    }

    .page-title {
        font-size: 23px;
    }

    .filters {
        padding: 13px;
    }

    .search-form {
        width: 100%;
        margin: 5px 0 0;
    }

    .search-input {
        width: 100%;
        min-width: 0;
    }
}
</style>
</head>

<body>

<a
    class="back-x"
    href="admin_dashboard.php"
    aria-label="Back to Admin Dashboard"
    title="Back to Admin Dashboard"
>×</a>

<main class="container">

    <div class="heading-row">
        <div>
            <div class="eyebrow">Community moderation</div>
            <h1 class="page-title">Help Board Management</h1>
            <p class="subtitle">
                Review, approve, reject, search, and manage student Help Board questions.
            </p>
        </div>
    </div>

    <section class="stats" aria-label="Help Board post totals">

        <div class="stat-card">
            <div class="stat-icon">💬</div>
            <div>
                <div class="stat-label">Total Posts</div>
                <div class="stat-value">
                    <?= number_format($counts['total']) ?>
                </div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">⏳</div>
            <div>
                <div class="stat-label">Pending Review</div>
                <div class="stat-value">
                    <?= number_format($counts['pending']) ?>
                </div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">✓</div>
            <div>
                <div class="stat-label">Approved Posts</div>
                <div class="stat-value">
                    <?= number_format($counts['approved']) ?>
                </div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">!</div>
            <div>
                <div class="stat-label">Rejected Posts</div>
                <div class="stat-value">
                    <?= number_format($counts['rejected']) ?>
                </div>
            </div>
        </div>

    </section>

    <section class="panel">

        <div class="panel-head">
            <div>
                <h2 class="panel-title">Student Help Board Posts</h2>
                <div class="panel-subtitle">
                    All matching posts are displayed without a post-count limit.
                </div>
            </div>
        </div>

        <div class="filters">

            <a
                class="filter <?= $filter === 'all' ? 'active' : '' ?>"
                href="<?= hb_e(basename($_SERVER['PHP_SELF'])) ?>"
            >All posts</a>

            <a
                class="filter <?= $filter === 'pending' ? 'active' : '' ?>"
                href="?status=pending<?= $search !== '' ? '&search=' . rawurlencode($search) : '' ?>"
            >Pending</a>

            <a
                class="filter <?= $filter === 'approved' ? 'active' : '' ?>"
                href="?status=approved<?= $search !== '' ? '&search=' . rawurlencode($search) : '' ?>"
            >Approved</a>

            <a
                class="filter <?= $filter === 'rejected' ? 'active' : '' ?>"
                href="?status=rejected<?= $search !== '' ? '&search=' . rawurlencode($search) : '' ?>"
            >Rejected</a>

            <form class="search-form" method="get" action="">

                <?php if ($filter !== 'all'): ?>
                    <input
                        type="hidden"
                        name="status"
                        value="<?= hb_e($filter) ?>"
                    >
                <?php endif; ?>

                <input
                    class="search-input"
                    type="search"
                    name="search"
                    value="<?= hb_e($search) ?>"
                    placeholder="Search title, details, or student..."
                    aria-label="Search Help Board posts"
                >

                <button class="btn btn-search" type="submit">
                    Search
                </button>

                <?php if ($search !== ''): ?>
                    <a
                        class="filter"
                        href="<?= hb_e(
                            $filter === 'all'
                                ? basename($_SERVER['PHP_SELF'])
                                : '?status=' . rawurlencode($filter)
                        ) ?>"
                    >Clear</a>
                <?php endif; ?>

            </form>
        </div>

        <?php if ($errorMessage !== ''): ?>

            <div class="empty">
                <div class="empty-icon">⚠️</div>
                <strong>Unable to load Help Board posts</strong>
                <span><?= hb_e($errorMessage) ?></span>
                <span>
                    Check that the help_questions table contains the expected post fields.
                </span>
            </div>

        <?php elseif (!$posts): ?>

            <div class="empty">
                <div class="empty-icon">💬</div>
                <strong>No Help Board posts found</strong>
                <span>
                    <?= $search !== ''
                        ? 'Try another search keyword or clear the search.'
                        : 'There are no posts in this category yet.' ?>
                </span>
            </div>

        <?php else: ?>

            <div class="table-wrap">
                <table>

                    <thead>
                        <tr>
                            <th>Post</th>
                            <th>Student</th>
                            <th>Comments</th>
                            <th>Status</th>
                            <th>Date Posted</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($posts as $post): ?>

                        <?php
                        $rawStatus = (string)($post['status'] ?? 'approved');
                        $normalizedStatus = hb_status($rawStatus);

                        $pending = hb_is_pending($rawStatus);
                        $approved = hb_is_approved($rawStatus);
                        $rejected = hb_is_rejected($rawStatus);

                        $anonymous = in_array(
                            strtolower((string)($post['is_anonymous'] ?? '0')),
                            ['1', 'yes', 'true'],
                            true
                        );

                        $badgeClass = $pending
                            ? 'pending'
                            : ($approved ? 'approved' : ($rejected ? 'rejected' : ''));

                        $details = (string)($post['details'] ?? '');

                        if (function_exists('mb_strimwidth')) {
                            $shortDetails = mb_strimwidth(
                                $details,
                                0,
                                220,
                                '…',
                                'UTF-8'
                            );
                        } else {
                            $shortDetails = substr($details, 0, 220);
                        }
                        ?>

                        <tr>

                            <td>
                                <div class="post-title">
                                    <?= hb_e($post['title']) ?>
                                </div>

                                <div class="post-details">
                                    <?= hb_e($shortDetails) ?>
                                </div>
                            </td>

                            <td>
                                <div class="student-name">
                                    <?= $anonymous
                                        ? 'Anonymous Student'
                                        : hb_e($post['student_name'] ?: 'Unknown student') ?>
                                </div>

                                <?php if (
                                    !$anonymous &&
                                    $post['student_id'] !== null
                                ): ?>
                                    <div class="subtle">
                                        User ID:
                                        <?= (int)$post['student_id'] ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?= number_format((int)$post['comment_count']) ?>
                            </td>

                            <td>
                                <span class="status <?= hb_e($badgeClass) ?>">
                                    <?= hb_e($normalizedStatus ?: 'approved') ?>
                                </span>
                            </td>

                            <td>
                                <?php if (!empty($post['created_at'])): ?>
                                    <?php
                                    $timestamp = strtotime(
                                        (string)$post['created_at']
                                    );
                                    ?>
                                    <?= $timestamp !== false
                                        ? hb_e(date('M d, Y h:i A', $timestamp))
                                        : '—' ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>

                            <td>
                                <div class="row-actions">

                                    <?php if ($hasStatus && !$approved): ?>
                                        <form
                                            class="inline-form action-form"
                                            method="post"
                                            data-action="help_approve"
                                            data-title="<?= hb_e($post['title']) ?>"
                                        >
                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= hb_e($csrfToken) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="help_approve"
                                            >

                                            <input
                                                type="hidden"
                                                name="question_id"
                                                value="<?= (int)$post['id'] ?>"
                                            >

                                            <button
                                                class="btn btn-approve"
                                                type="submit"
                                            >✓ Approve</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($hasStatus && !$rejected): ?>
                                        <form
                                            class="inline-form action-form"
                                            method="post"
                                            data-action="help_reject"
                                            data-title="<?= hb_e($post['title']) ?>"
                                        >
                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= hb_e($csrfToken) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="help_reject"
                                            >

                                            <input
                                                type="hidden"
                                                name="question_id"
                                                value="<?= (int)$post['id'] ?>"
                                            >

                                            <button
                                                class="btn btn-reject"
                                                type="submit"
                                            >Reject</button>
                                        </form>
                                    <?php endif; ?>

                                    <form
                                        class="inline-form action-form"
                                        method="post"
                                        data-action="help_delete"
                                        data-title="<?= hb_e($post['title']) ?>"
                                    >
                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= hb_e($csrfToken) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="help_delete"
                                        >

                                        <input
                                            type="hidden"
                                            name="question_id"
                                            value="<?= (int)$post['id'] ?>"
                                        >

                                        <button
                                            class="btn btn-delete"
                                            type="submit"
                                        >🗑 Delete</button>
                                    </form>

                                </div>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>
                </table>
            </div>

            <div class="panel-foot">
                Showing <?= number_format(count($posts)) ?> post(s).
                All matching records are included.
            </div>

        <?php endif; ?>

        <?php if (!$hasStatus && $errorMessage === ''): ?>
            <div class="panel-foot">
                Approval and rejection controls are unavailable because
                no moderation status column was detected in
                <code>help_questions</code>.
            </div>
        <?php endif; ?>

    </section>
</main>

<script>
(function () {
    // Keep the saved theme preference without requiring a theme-toggle button.
    const body = document.body;

    try {
        const savedTheme =
            localStorage.getItem('notesphere_admin_theme') || 'light';

        body.classList.toggle('dark', savedTheme === 'dark');
    } catch (error) {
        body.classList.remove('dark');
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (character) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[character];
        });
    }

    // Confirmation dialogs for Help Board actions.
    document.querySelectorAll('.action-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();

            const action = form.dataset.action;
            const title = form.dataset.title || 'this post';

            const approve = action === 'help_approve';
            const reject = action === 'help_reject';

            const heading = approve
                ? 'Approve this post?'
                : (reject ? 'Reject this post?' : 'Delete this post?');

            const message = approve
                ? 'Approve <strong>' + escapeHtml(title) +
                    '</strong> so it can appear to students?'
                : (reject
                    ? 'Reject <strong>' + escapeHtml(title) + '</strong>?'
                    : 'Permanently delete <strong>' +
                        escapeHtml(title) +
                        '</strong> and its related comments?');

            Swal.fire({
                title: heading,
                html: message,
                icon: approve ? 'question' : 'warning',
                showCancelButton: true,
                confirmButtonText: approve
                    ? 'Yes, approve'
                    : (reject ? 'Yes, reject' : 'Yes, delete'),
                cancelButtonText: 'Cancel',
                confirmButtonColor: approve
                    ? '#12804a'
                    : (reject ? '#986000' : '#c73535'),
                reverseButtons: true
            }).then(function (result) {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        });
    });

    <?php if (is_array($flash) && !empty($flash['message'])): ?>
    Swal.fire({
        icon: <?= json_encode(
            ($flash['type'] ?? '') === 'success' ? 'success' : 'error'
        ) ?>,
        title: <?= json_encode(
            ($flash['type'] ?? '') === 'success' ? 'Success' : 'Notice'
        ) ?>,
        text: <?= json_encode((string)$flash['message']) ?>,
        confirmButtonColor: '#3D8BFF'
    });
    <?php endif; ?>
})();
</script>
</body>
</html>
