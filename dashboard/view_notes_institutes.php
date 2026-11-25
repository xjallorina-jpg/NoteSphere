
<?php
// =========================================================
// NoteSphere - Institute Notes
// File: dashboard/view_notes_institutes.php
// =========================================================

session_start();
require_once __DIR__ . '/../includes/db_config.php';

if (!isset($_SESSION['user_id'], $_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header('Location: ../auth/login.php');
    exit();
}

$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function e(string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function notesphere_status_class(string $status): string
{
    $status = strtolower(trim((string)$status));

    return $status === 'approved'
        ? 'status-approved'
        : ($status === 'rejected' ? 'status-rejected' : 'status-pending');
}

$studentId = (int)$_SESSION['user_id'];

$institutes = [
    'ICS' => [
        'name' => 'Institute of Computing Studies',
        'description' => 'Programming, computing, and information technology notes.'
    ],
    'IBE' => [
        'name' => 'Institute of Business Education',
        'description' => 'Business, management, and entrepreneurship learning materials.'
    ],
    'ITE' => [
        'name' => 'Institute of Technical Education',
        'description' => 'Technical subjects, practical lessons, and study materials.'
    ],
];

$selectedInstitute = strtoupper(trim($_GET['institute'] ?? ''));
$search = trim($_GET['search'] ?? '');
$tab = $_GET['tab'] ?? 'browse';

if ($selectedInstitute !== '' && !isset($institutes[$selectedInstitute])) {
    http_response_code(400);
    exit('Invalid institute selection.');
}

if (!in_array($tab, ['browse', 'history'], true)) {
    $tab = 'browse';
}

if (empty($_SESSION['notesphere_csrf'])) {
    $_SESSION['notesphere_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['notesphere_csrf'];
$flash = null;

// =========================================================
// SAVE OR UPDATE RATING
// Students cannot rate their own notes.
// =========================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rate') {
    $postedToken = (string)($_POST['csrf_token'] ?? '');
    $noteId = filter_var($_POST['note_id'] ?? null, FILTER_VALIDATE_INT);
    $rating = filter_var($_POST['rating'] ?? null, FILTER_VALIDATE_INT);
    $institute = strtoupper(trim($_POST['institute'] ?? ''));
    $postedSearch = trim($_POST['search'] ?? '');

    $redirect = 'view_notes_institutes.php?institute=' .
        urlencode($institute) . '&tab=browse';

    if ($postedSearch !== '') {
        $redirect .= '&search=' . urlencode($postedSearch);
    }

    if (!hash_equals($csrfToken, $postedToken)) {
        $_SESSION['notesphere_flash'] = [
            'type' => 'error',
            'message' => 'Your session token is invalid. Please refresh the page and try again.'
        ];
    } elseif (
        $noteId === false || !$noteId ||
        $rating === false || $rating < 1 || $rating > 5
    ) {
        $_SESSION['notesphere_flash'] = [
            'type' => 'error',
            'message' => 'Please click a star from 1 to 5 before submitting your rating.'
        ];
    } elseif (!isset($institutes[$institute])) {
        $_SESSION['notesphere_flash'] = [
            'type' => 'error',
            'message' => 'Invalid institute selection.'
        ];
    } else {
        try {
            $check = $conn->prepare(
                "SELECT id
                 FROM notes
                 WHERE id = ?
                   AND institute = ?
                   AND status = 'approved'
                   AND user_id <> ?
                 LIMIT 1"
            );

            $check->execute([$noteId, $institute, $studentId]);

            if (!$check->fetch()) {
                $_SESSION['notesphere_flash'] = [
                    'type' => 'error',
                    'message' => 'This note cannot be rated. You cannot rate your own note.'
                ];
            } else {
                $existing = $conn->prepare(
                    "SELECT id
                     FROM note_ratings
                     WHERE note_id = ? AND user_id = ?
                     LIMIT 1"
                );

                $existing->execute([$noteId, $studentId]);

                if ($existing->fetch()) {
                    $stmt = $conn->prepare(
                        "UPDATE note_ratings
                         SET rating = ?, updated_at = CURRENT_TIMESTAMP
                         WHERE note_id = ? AND user_id = ?"
                    );

                    $stmt->execute([$rating, $noteId, $studentId]);

                    $message = 'Your rating has been updated to ' .
                        $rating . ' star' . ($rating === 1 ? '' : 's') . '.';
                } else {
                    $stmt = $conn->prepare(
                        "INSERT INTO note_ratings
                            (note_id, user_id, rating, created_at, updated_at)
                         VALUES (?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
                    );

                    $stmt->execute([$noteId, $studentId, $rating]);

                    $message = 'Your ' . $rating .
                        '-star rating has been saved successfully.';
                }

                $_SESSION['notesphere_flash'] = [
                    'type' => 'success',
                    'message' => $message
                ];
            }
        } catch (PDOException $exception) {
            error_log('NoteSphere rating error: ' . $exception->getMessage());

            $_SESSION['notesphere_flash'] = [
                'type' => 'error',
                'message' => 'Unable to save your rating. Please check the PHP error log.'
            ];
        }
    }

    header('Location: ' . $redirect);
    exit();
}

if (!empty($_SESSION['notesphere_flash'])) {
    $flash = $_SESSION['notesphere_flash'];
    unset($_SESSION['notesphere_flash']);
}

// =========================================================
// LOAD NOTES AND UPLOAD HISTORY
// =========================================================

$notes = [];
$history = [];
$noteCount = 0;

if ($selectedInstitute !== '') {
    try {
        if ($tab === 'browse') {
            $sql = "
                SELECT
                    n.id,
                    n.user_id,
                    n.title,
                    n.description,
                    n.institute,
                    n.file_path,
                    n.thumbnail,
                    n.created_at,
                    u.full_name AS uploader_name,
                    COALESCE(AVG(r.rating), 0) AS average_rating,
                    COUNT(r.rating) AS rating_count,
                    MAX(
                        CASE
                            WHEN r.user_id = ? THEN r.rating
                            ELSE NULL
                        END
                    ) AS my_rating
                FROM notes n
                LEFT JOIN users u
                    ON u.user_id = n.user_id
                LEFT JOIN note_ratings r
                    ON r.note_id = n.id
                WHERE n.status = 'approved'
                  AND n.institute = ?
            ";

            $params = [$studentId, $selectedInstitute];

            if ($search !== '') {
                $sql .= " AND (n.title LIKE ? OR n.description LIKE ?)";
                $term = '%' . $search . '%';
                $params[] = $term;
                $params[] = $term;
            }

            $sql .= "
                GROUP BY
                    n.id,
                    n.user_id,
                    n.title,
                    n.description,
                    n.institute,
                    n.file_path,
                    n.thumbnail,
                    n.created_at,
                    u.full_name
                ORDER BY n.created_at DESC
            ";

            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $notes = $stmt->fetchAll();
            $noteCount = count($notes);
        }

        $historyStmt = $conn->prepare(
            "SELECT
                id,
                title,
                description,
                institute,
                status,
                created_at
             FROM notes
             WHERE user_id = ?
               AND institute = ?
             ORDER BY created_at DESC"
        );

        $historyStmt->execute([$studentId, $selectedInstitute]);
        $history = $historyStmt->fetchAll();

    } catch (PDOException $exception) {
        error_log('NoteSphere notes loading error: ' . $exception->getMessage());

        $flash = [
            'type' => 'error',
            'message' => 'Unable to load notes. Please check the database columns and PHP error log.'
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="dark-mode">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#0b0b0b" id="themeColor">

<title><?= $selectedInstitute ? e($selectedInstitute . ' Notes') : 'Choose Institute' ?> | NoteSphere</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
(function () {
    try {
        const light = localStorage.getItem('notesphere_theme') === 'light';
        document.documentElement.classList.toggle('light-mode', light);
        document.documentElement.classList.toggle('dark-mode', !light);
    } catch (error) {
        document.documentElement.classList.add('dark-mode');
    }
})();
</script>

<style>
:root {
    color-scheme: dark;
    --accent: #39FF14;
    --accent-soft: rgba(57,255,20,.11);
    --text: #eff3f8;
    --muted: #a8b2c1;
    --border: rgba(148,163,184,.18);
    --card: rgba(12,14,14,.88);
    --panel: rgba(12,14,14,.66);
    --field: rgba(7,9,9,.72);
    --shadow: 0 18px 48px rgba(0,0,0,.20);
    --close-bg: rgba(12,14,14,.90);
    --surface-hover: rgba(57,255,20,.07);
    --overlay-start: rgba(7,10,9,.82);
    --overlay-end: rgba(7,10,9,.91);
}

html.light-mode {
    color-scheme: light;
    --accent: #087f3e;
    --accent-soft: rgba(8,127,62,.09);
    --text: #000000;
    --muted: #000000;
    --border: rgba(15,23,42,.12);
    --card: rgba(255,255,255,.94);
    --panel: rgba(255,255,255,.80);
    --field: rgba(255,255,255,.94);
    --shadow: 0 18px 45px rgba(15,23,42,.075);
    --close-bg: rgba(255,255,255,.95);
    --surface-hover: rgba(8,127,62,.055);
    
    --overlay-end: rgba(255,255,255,.88);
}

* { box-sizing: border-box; }

html {
    min-height: 100%;
    background: #080808;
    scroll-behavior: smooth;
}

html.light-mode { background: #fff; }

body {
    min-height: 100vh;
    margin: 0;
    position: relative;
    isolation: isolate;
    overflow-x: hidden;
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 13px;
    color: var(--text);
    background: transparent;
    transition: color .22s ease;
}

body::before {
    content: none;
}

a { color: inherit; text-decoration: none; }
button, input, select { font: inherit; }
button, a { -webkit-tap-highlight-color: transparent; }

button:focus-visible,
a:focus-visible,
input:focus-visible,
select:focus-visible {
    outline: 2px solid var(--accent);
    outline-offset: 3px;
}

.close-button {
    position: fixed;
    top: 17px;
    right: 20px;
    z-index: 100;
    width: 41px;
    height: 41px;
    display: grid;
    place-items: center;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: var(--close-bg);
    color: var(--text);
    font: 27px/1 Arial, sans-serif;
    box-shadow: var(--shadow);
    backdrop-filter: blur(14px);
    transition: color .2s, border-color .2s, background .2s;
}

.close-button:hover {
    color: var(--accent);
    border-color: var(--accent);
    background: var(--accent-soft);
}

.page {
    width: min(1080px, calc(100% - 40px));
    margin: 0 auto;
    padding: 76px 0 32px;
}

.hero {
    max-width: 700px;
    margin: 0 auto 30px;
    text-align: center;
}

.eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 14px;
    padding: 7px 12px;
    border: 1px solid var(--border);
    border-radius: 999px;
    background: var(--accent-soft);
    color: var(--accent);
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 1.15px;
    text-transform: uppercase;
}

.eyebrow-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: var(--accent);
    box-shadow: 0 0 12px var(--accent);
}

h1 {
    margin: 0 0 12px;
    color: var(--text);
    font-size: clamp(30px, 4.2vw, 44px);
    font-weight: 800;
    letter-spacing: -1.7px;
    line-height: 1.15;
}

h1 span { color: var(--accent); }

.hero p {
    max-width: 580px;
    margin: 0 auto;
    color: var(--muted);
    font-size: 12.5px;
    line-height: 1.85;
}

.section-heading {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 14px;
    margin-bottom: 16px;
}

.section-heading h2 {
    margin: 0 0 5px;
    font-size: 19px;
    letter-spacing: -.45px;
    font-weight: 800;
}

.section-heading p {
    margin: 0;
    color: var(--muted);
    font-size: 11.5px;
    line-height: 1.7;
}

.institute-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 17px;
}

.institute-card {
    display: flex;
    flex-direction: column;
    min-width: 0;
    min-height: 240px;
    padding: 24px 22px 20px;
    border: 1px solid var(--border);
    border-radius: 20px;
    background: var(--card);
    color: var(--text);
    box-shadow: 0 8px 24px rgba(0,0,0,.08);
    backdrop-filter: blur(14px);
    transition: transform .22s, border-color .22s, background .22s, box-shadow .22s;
}
.institute-card:hover {
    transform: translateY(-6px);
    border-color: var(--accent);
    box-shadow: 0 14px 32px rgba(0,0,0,.12);
}

.institute-icon {
    display: grid;
    place-items: center;
    width: 44px;
    height: 44px;
    flex: 0 0 auto;
    margin-bottom: 16px;
    border: 1px solid var(--border);
    border-radius: 13px;
    background: var(--accent-soft);
    color: var(--accent);
}

.institute-icon svg {
    width: 23px;
    height: 23px;
    fill: none;
    stroke: currentColor;
    stroke-width: 1.8;
    stroke-linecap: round;
    stroke-linejoin: round;
}

.institute-code {
    margin-bottom: 6px;
    color: var(--accent);
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 1.6px;
}

.institute-name {
    margin-bottom: 8px;
    font-size: 14px;
    font-weight: 800;
    line-height: 1.5;
}

.institute-description {
    margin: 0 0 16px;
    color: var(--muted);
    font-size: 11.5px;
    line-height: 1.8;
}

.card-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-top: auto;
    padding-top: 13px;
    border-top: 1px solid var(--border);
}

.explore-label { font-size: 11px; font-weight: 800; }

.arrow {
    display: grid;
    place-items: center;
    width: 28px;
    height: 28px;
    border-radius: 9px;
    background: var(--accent-soft);
    color: var(--accent);
    font-size: 17px;
    transition: transform .2s;
}

.institute-card:hover .arrow { transform: translateX(3px); }

.content-panel {
    padding: 20px;
    border: 1px solid var(--border);
    border-radius: 17px;
    background: var(--panel);
    box-shadow: var(--shadow);
    backdrop-filter: blur(14px);
}

.toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 17px;
}

.toolbar-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    flex-wrap: wrap;
    gap: 8px;
}

.institute-label {
    display: flex;
    align-items: center;
    gap: 11px;
    min-width: 0;
}

.institute-label .institute-icon {
    width: 40px;
    height: 40px;
    margin: 0;
}

.institute-label h2 {
    margin: 0 0 3px;
    font-size: 15px;
    font-weight: 800;
}

.institute-label p {
    margin: 0;
    color: var(--muted);
    font-size: 10.5px;
    line-height: 1.6;
}

.text-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    min-height: 35px;
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: 9px;
    background: var(--card);
    color: var(--text);
    font-size: 10.5px;
    font-weight: 700;
    transition: color .2s, border-color .2s, background .2s;
}

.text-link:hover {
    color: var(--accent);
    border-color: var(--accent);
    background: var(--surface-hover);
}

.tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    width: fit-content;
    max-width: 100%;
    margin: 0 0 15px;
    padding: 4px;
    border: 1px solid var(--border);
    border-radius: 11px;
    background: var(--card);
}

.tab {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 9px 13px;
    border-radius: 8px;
    color: var(--muted);
    font-size: 10.5px;
    font-weight: 800;
    transition: background .2s, color .2s;
}

.tab:hover { color: var(--accent); }
.tab.active { background: var(--accent-soft); color: var(--accent); }

.search-form {
    display: flex;
    gap: 8px;
    margin-bottom: 19px;
}

.search-input {
    width: 100%;
    min-width: 0;
    height: 40px;
    padding: 0 13px;
    border: 1px solid var(--border);
    border-radius: 10px;
    outline: none;
    background: var(--field);
    color: var(--text);
    font-size: 11.5px;
    transition: border-color .2s, box-shadow .2s;
}

.search-input::placeholder { color: var(--muted); opacity: .8; }

.search-input:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--accent-soft);
}

.primary-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    min-height: 39px;
    padding: 0 15px;
    border: 1px solid var(--accent);
    border-radius: 9px;
    background: var(--accent);
    color: #07150a;
    font-size: 10.5px;
    font-weight: 800;
    white-space: nowrap;
    cursor: pointer;
    transition: filter .2s, transform .2s;
}

html.light-mode .primary-button { color: #fff; }
.primary-button:hover { filter: brightness(.94); }
.primary-button:active { transform: scale(.98); }

.notes-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 13px;
}

.note-card {
    display: flex;
    flex-direction: column;
    min-width: 0;
    padding: 16px;
    border: 1px solid var(--border);
    border-radius: 14px;
    background: var(--card);
    transition: border-color .2s, transform .2s, background .2s;
}

.note-card:hover {
    border-color: var(--accent);
    transform: translateY(-2px);
}

.note-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 8px;
}

.note-title {
    min-width: 0;
    margin: 0;
    overflow-wrap: anywhere;
    font-size: 13px;
    line-height: 1.6;
    font-weight: 800;
}

.file-badge {
    flex: 0 0 auto;
    padding: 5px 7px;
    border: 1px solid var(--border);
    border-radius: 7px;
    background: var(--accent-soft);
    color: var(--accent);
    font-size: 9px;
    font-weight: 800;
    text-transform: uppercase;
}

/* =========================================================
   THREE-DOT MENU
========================================================= */

.note-top-right {
    display: flex;
    align-items: flex-start;
    gap: 6px;
    flex: 0 0 auto;
}

.note-menu {
    position: relative;
    flex: 0 0 auto;
}

.note-menu-toggle {
    display: grid;
    place-items: center;
    width: 31px;
    height: 31px;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: 9px;
    background: var(--field);
    color: var(--text);
    font-size: 22px;
    line-height: 1;
    cursor: pointer;
    transition: background .18s, color .18s, border-color .18s;
}

.note-menu-toggle:hover,
.note-menu-toggle[aria-expanded="true"] {
    border-color: var(--accent);
    background: var(--accent-soft);
    color: var(--accent);
}

.note-menu-dropdown {
    position: absolute;
    top: calc(100% + 7px);
    right: 0;
    z-index: 50;
    display: none;
    width: 190px;
    padding: 5px;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: var(--card);
    box-shadow: 0 12px 32px rgba(0,0,0,.28);
    backdrop-filter: blur(18px);
}

.note-menu-dropdown.open {
    display: block;
    animation: menuAppear .14s ease-out;
}

@keyframes menuAppear {
    from { opacity: 0; transform: translateY(-4px); }
    to { opacity: 1; transform: translateY(0); }
}

.note-menu-item {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    min-height: 38px;
    padding: 9px 10px;
    border: 0;
    border-radius: 8px;
    background: transparent;
    color: var(--text);
    text-align: left;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
    transition: background .16s, color .16s;
}

.note-menu-item:hover {
    background: var(--accent-soft);
    color: var(--accent);
}

.note-menu-icon {
    display: inline-grid;
    place-items: center;
    width: 18px;
    font-size: 15px;
}

.note-description {
    margin: 0 0 12px;
    color: var(--muted);
    font-size: 11px;
    line-height: 1.8;
    overflow-wrap: anywhere;
    white-space: pre-line;
}

.note-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 6px 12px;
    margin-bottom: 11px;
    color: var(--muted);
    font-size: 10px;
}

.rating-line {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 7px;
    margin-top: auto;
    padding: 10px 0;
    border-top: 1px solid var(--border);
    color: var(--muted);
    font-size: 10.5px;
}

.rating-line strong { color: var(--text); }

.stars {
    color: #f5b82e;
    font-size: 14px;
    letter-spacing: 1px;
    white-space: nowrap;
}

/* =========================================================
   CLICKABLE STAR RATING
========================================================= */

.rating-form {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 9px;
    padding-top: 10px;
}

.rating-prompt {
    color: var(--text);
    font-size: 10.5px;
}

.star-picker {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    direction: ltr;
}

.star-choice {
    position: relative;
    display: inline-grid;
    place-items: center;
    width: 35px;
    height: 38px;
    border: 1px solid transparent;
    border-radius: 9px;
    cursor: pointer;
    user-select: none;
    transition: background .18s, transform .18s, border-color .18s;
}

.star-choice input {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

.star-choice span {
    display: block;
    color: #87909d;
    font-size: 27px;
    line-height: 1;
    pointer-events: none;
    transition: color .18s, transform .18s;
}

.star-choice:hover {
    background: rgba(245,184,46,.10);
    transform: translateY(-2px);
}

.star-choice:hover span,
.star-choice.selected span,
.star-choice.preview span {
    color: #f5b82e;
}

.star-choice input:focus-visible + span {
    outline: 2px solid var(--accent);
    outline-offset: 3px;
    border-radius: 3px;
}

.rating-hint {
    color: var(--muted);
    font-size: 10px;
    line-height: 1.6;
}

.rating-form .small-button {
    min-height: 34px;
    padding: 0 12px;
    font-size: 10px;
}

.note-actions {
    display: flex;
    gap: 8px;
    margin-top: 11px;
}

.note-actions .primary-button {
    width: 100%;
    min-height: 36px;
}

.empty-state {
    grid-column: 1 / -1;
    padding: 36px 18px;
    border: 1px dashed var(--border);
    border-radius: 13px;
    background: var(--card);
    text-align: center;
}

.empty-state strong {
    display: block;
    margin-bottom: 7px;
    font-size: 13px;
}

.empty-state p {
    margin: 0;
    color: var(--muted);
    font-size: 11px;
    line-height: 1.8;
}

.history-list { display: grid; gap: 9px; }

.history-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 13px;
    border: 1px solid var(--border);
    border-radius: 11px;
    background: var(--card);
}

.history-item h3 {
    margin: 0 0 5px;
    font-size: 11.5px;
    line-height: 1.6;
    overflow-wrap: anywhere;
}

.history-item p {
    margin: 0;
    color: var(--muted);
    font-size: 10px;
    line-height: 1.7;
}

.status {
    flex: 0 0 auto;
    padding: 5px 9px;
    border-radius: 999px;
    font-size: 9px;
    font-weight: 800;
    text-transform: capitalize;
}

.status-approved { background: rgba(16,185,129,.13); color: #10b981; }
.status-pending { background: rgba(245,158,11,.14); color: #d99b13; }
.status-rejected { background: rgba(239,68,68,.13); color: #ef4444; }

.bottom-note {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    margin-top: 19px;
    padding: 13px 16px;
    border: 1px solid var(--border);
    border-radius: 11px;
    background: var(--panel);
    color: var(--muted);
    font-size: 10.5px;
    line-height: 1.7;
    text-align: center;
    backdrop-filter: blur(12px);
}

.bottom-note span:first-child {
    color: var(--accent);
    font-size: 15px;
    font-weight: 800;
}

.swal2-popup {
    border: 1px solid var(--border) !important;
    border-radius: 16px !important;
    font-family: 'Plus Jakarta Sans', sans-serif !important;
}

.swal2-title { font-family: 'Plus Jakarta Sans', sans-serif !important; }
.swal2-confirm { box-shadow: none !important; }

@media (max-width: 760px) {
    .page {
        width: calc(100% - 30px);
        padding-top: 73px;
    }

    .institute-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }

    .institute-card { min-height: 218px; padding: 17px 15px 14px; }
    .notes-grid { grid-template-columns: 1fr; }
    .content-panel { padding: 15px; }
    .hero { margin-bottom: 25px; }
}

@media (max-width: 480px) {
    .close-button {
        top: 11px;
        right: 12px;
        width: 36px;
        height: 36px;
    }

    .page {
        width: calc(100% - 24px);
        padding-top: 63px;
        padding-bottom: 22px;
    }

    .hero h1 { font-size: 31px; letter-spacing: -1.2px; }
    .hero p { font-size: 11.5px; }
    .institute-grid { grid-template-columns: 1fr; }
    .institute-card { min-height: 0; }
    .section-heading h2 { font-size: 17px; }
    .content-panel { padding: 12px; }
    .toolbar { align-items: flex-start; }
    .toolbar-actions { width: 100%; justify-content: flex-start; }

    .search-form {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 7px;
    }

    .search-form .search-input { grid-column: 1 / -1; }
    .search-form .primary-button,
    .search-form .text-link { min-height: 37px; }

    .history-item { align-items: flex-start; }
    .rating-form { align-items: flex-start; }
    .star-choice { width: 34px; }
    .bottom-note { justify-content: flex-start; text-align: left; }

    .note-menu-dropdown { width: 180px; }
}

@media (prefers-reduced-motion: reduce) {
    *,
    *::before,
    *::after {
        scroll-behavior: auto !important;
        transition-duration: .01ms !important;
        animation-duration: .01ms !important;
    }
}
        /* iOS: keep form fields at 16px so Safari does not zoom on focus. */
        @supports (-webkit-touch-callout: none) {
            input,
            select,
            textarea {
                font-size: 16px;
            }
        }
</style>
</head>

<body>

<a class="close-button"
   href="view_notes.php"
   aria-label="Back to view notes"
   title="Back to Dashboard">&times;</a>

<main class="page">

<?php if ($selectedInstitute === ''): ?>

    <section class="hero">
        <div class="eyebrow">
            <span class="eyebrow-dot"></span>
            Your Academic Library
        </div>

        <h1>Find Notes.<br>Learn <span>Better.</span></h1>

        <p>
            Browse shared academic notes by institute.
            Choose your institute to find learning materials relevant to your subjects.
        </p>
    </section>

    <section>
        <div class="section-heading">
            <div>
                <h2>Choose Your Institute</h2>
                <p>Select an institute to explore its approved notes.</p>
            </div>
        </div>

        <div class="institute-grid">
        <?php foreach ($institutes as $code => $institute): ?>
            <a class="institute-card"
               href="view_notes_institutes.php?institute=<?= e($code) ?>">

                <div class="institute-icon">
                    <?php if ($code === 'ICS'): ?>
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <polyline points="8 17 3 12 8 7"></polyline>
                            <polyline points="16 7 21 12 16 17"></polyline>
                            <line x1="14" y1="4" x2="10" y2="20"></line>
                        </svg>
                    <?php elseif ($code === 'IBE'): ?>
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <rect x="3" y="7" width="18" height="13" rx="2"></rect>
                            <path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                            <path d="M3 12h18"></path>
                            <path d="M10 12v2h4v-2"></path>
                        </svg>
                    <?php else: ?>
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                            <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
                            <path d="M8 7h8"></path>
                            <path d="M8 11h8"></path>
                        </svg>
                    <?php endif; ?>
                </div>

                <div class="institute-code"><?= e($code) ?></div>
                <div class="institute-name"><?= e($institute['name']) ?></div>
                <p class="institute-description"><?= e($institute['description']) ?></p>

                <div class="card-footer">
                    <span class="explore-label">Explore Notes</span>
                    <span class="arrow" aria-hidden="true">→</span>
                </div>
            </a>
        <?php endforeach; ?>
        </div>
    </section>

<?php else: ?>

    <section class="hero">
        <div class="eyebrow">
            <span class="eyebrow-dot"></span>
            Academic Library
        </div>

        <h1><?= e($selectedInstitute) ?> <span>Notes</span></h1>
        <p><?= e($institutes[$selectedInstitute]['description']) ?></p>
    </section>

    <section class="content-panel">
        <div class="toolbar">
            <div class="institute-label">
                <div class="institute-icon">
                    <?php if ($selectedInstitute === 'ICS'): ?>
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <polyline points="8 17 3 12 8 7"></polyline>
                            <polyline points="16 7 21 12 16 17"></polyline>
                            <line x1="14" y1="4" x2="10" y2="20"></line>
                        </svg>
                    <?php elseif ($selectedInstitute === 'IBE'): ?>
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <rect x="3" y="7" width="18" height="13" rx="2"></rect>
                            <path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                            <path d="M3 12h18"></path>
                        </svg>
                    <?php else: ?>
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                            <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
                        </svg>
                    <?php endif; ?>
                </div>

                <div>
                    <h2><?= e($selectedInstitute) ?> Learning Materials</h2>
                    <p><?= e($institutes[$selectedInstitute]['name']) ?></p>
                </div>
            </div>

            <div class="toolbar-actions">
                <a class="primary-button"
                   href="upload_notes.php?institute=<?= e($selectedInstitute) ?>">
                    + Upload Note
                </a>
            </div>
        </div>

        <nav class="tabs" aria-label="Notes navigation">
            <a class="tab <?= $tab === 'browse' ? 'active' : '' ?>"
               href="?institute=<?= e($selectedInstitute) ?>&amp;tab=browse">
                Browse Notes
            </a>

            <a class="tab <?= $tab === 'history' ? 'active' : '' ?>"
               href="?institute=<?= e($selectedInstitute) ?>&amp;tab=history">
                My Upload History
            </a>
        </nav>

        <?php if ($tab === 'browse'): ?>

            <form class="search-form" method="GET" action="view_notes_institutes.php">
                <input type="hidden" name="institute" value="<?= e($selectedInstitute) ?>">
                <input type="hidden" name="tab" value="browse">

                <input class="search-input"
                       type="search"
                       name="search"
                       value="<?= e($search) ?>"
                       placeholder="Search by note title or description..."
                       aria-label="Search notes">

                <button class="primary-button" type="submit">Search</button>

                <?php if ($search !== ''): ?>
                    <a class="text-link"
                       href="?institute=<?= e($selectedInstitute) ?>&amp;tab=browse">
                        Clear
                    </a>
                <?php endif; ?>
            </form>

            <div class="section-heading">
                <div>
                    <h2>Available Notes</h2>
                    <p>
                        <?= (int)$noteCount ?>
                        <?= $noteCount === 1 ? 'note' : 'notes' ?> found
                        <?= $search !== '' ? ' for “' . e($search) . '”' : '' ?>.
                    </p>
                </div>
            </div>

            <div class="notes-grid">
            <?php if (!$notes): ?>
                <div class="empty-state">
                    <strong>No notes found</strong>
                    <p>
                        There are no approved notes matching this institute
                        <?= $search !== '' ? ' and your search' : '' ?> yet.
                        Try another keyword or check back later.
                    </p>
                </div>
            <?php else: ?>

                <?php foreach ($notes as $note): ?>
                    <?php
                    $extension = strtoupper(
                        pathinfo((string)$note['file_path'], PATHINFO_EXTENSION)
                    );

                    $average = (float)$note['average_rating'];
                    $rounded = (int)round($average);
                    $previewUrl = 'note_view.php?note_id=' . (int)$note['id'];
                    $isOwnNote = (int)$note['user_id'] === $studentId;
                    $myRating = isset($note['my_rating']) ? (int)$note['my_rating'] : 0;
                    ?>

                    <article class="note-card">
                        <div class="note-top">
                            <h3 class="note-title"><?= e($note['title']) ?></h3>

                            <div class="note-top-right">
                                <span class="file-badge"><?= e($extension ?: 'FILE') ?></span>

                                <!-- Three-dot menu for each note -->
                                <div class="note-menu" data-note-menu>
                                    <button
                                        type="button"
                                        class="note-menu-toggle"
                                        aria-label="More options for <?= e($note['title']) ?>"
                                        aria-haspopup="true"
                                        aria-expanded="false"
                                        title="More options"
                                        data-menu-toggle>
                                        &#8942;
                                    </button>

                                    <div class="note-menu-dropdown" role="menu">
                                        <button
                                            type="button"
                                            class="note-menu-item"
                                            role="menuitem"
                                            data-note-action="copy"
                                            data-note-title="<?= e($note['title']) ?>"
                                            data-note-url="<?= e($previewUrl) ?>">
                                            <span class="note-menu-icon">⧉</span>
                                            Copy Note Link
                                        </button>


                                      
                                    </div>
                                </div>
                            </div>
                        </div>

                        <p class="note-description">
                            <?= e(
                                function_exists('mb_strimwidth')
                                    ? mb_strimwidth(
                                        (string)($note['description'] ?? ''),
                                        0,
                                        210,
                                        '...'
                                    )
                                    : substr((string)($note['description'] ?? ''), 0, 210)
                            ) ?>
                        </p>

                        <div class="note-meta">
                            <span>
                                By <?= e($note['uploader_name'] ?: 'Student') ?>
                                <?= $isOwnNote ? '(You)' : '' ?>
                            </span>

                            <span>
                                <?= e(date('M d, Y', strtotime($note['created_at']))) ?>
                            </span>
                        </div>

                        <div class="rating-line">
                            <span class="stars"
                                  aria-label="<?= e(number_format($average, 1)) ?> out of 5 stars">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <?= $i <= $rounded ? '★' : '☆' ?>
                                <?php endfor; ?>
                            </span>

                            <strong><?= e(number_format($average, 1)) ?>/5</strong>
                            <span>(<?= (int)$note['rating_count'] ?> ratings)</span>
                        </div>

                        <?php if (!$isOwnNote): ?>

                            <form class="rating-form"
                                  method="POST"
                                  action="view_notes_institutes.php"
                                  data-rating-form>

                                <input type="hidden" name="action" value="rate">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="note_id" value="<?= (int)$note['id'] ?>">
                                <input type="hidden" name="institute" value="<?= e($selectedInstitute) ?>">
                                <input type="hidden" name="search" value="<?= e($search) ?>">

                                <input type="hidden"
                                       name="rating"
                                       value="<?= $myRating > 0 ? $myRating : '' ?>"
                                       data-rating-value>

                                <div class="rating-prompt">
                                    <strong><?= $myRating > 0 ? 'Your rating' : 'Rate this note' ?></strong>
                                </div>

                                <div class="star-picker"
                                     role="radiogroup"
                                     aria-label="Select a rating from 1 to 5 stars"
                                     data-star-picker>

                                    <?php for ($r = 1; $r <= 5; $r++): ?>
                                        <label class="star-choice <?= $r <= $myRating ? 'selected' : '' ?>">
                                            <input type="radio"
                                                   name="rating_choice"
                                                   value="<?= $r ?>"
                                                   data-star="<?= $r ?>"
                                                   <?= $myRating === $r ? 'checked' : '' ?>
                                                   aria-label="<?= $r ?> star<?= $r === 1 ? '' : 's' ?>">

                                            <span aria-hidden="true"><?= $r <= $myRating ? '★' : '☆' ?></span>
                                        </label>
                                    <?php endfor; ?>

                                </div>

                                <div class="rating-hint" data-rating-hint aria-live="polite">
                                    <?= $myRating > 0
                                        ? 'You selected ' . $myRating . ' out of 5 stars. You can change your rating.'
                                        : 'Click a star to choose your rating.' ?>
                                </div>

                                <button class="primary-button small-button" type="submit">
                                    <?= $myRating > 0 ? 'Update Rating' : 'Submit Rating' ?>
                                </button>
                            </form>

                        <?php else: ?>

                            <div class="rating-line">
                                <span>Your uploaded note</span>
                                <strong>You cannot rate your own note.</strong>
                            </div>

                        <?php endif; ?>

                        <div class="note-actions">
                            <a class="primary-button"
                               href="<?= e($previewUrl) ?>"
                               data-preview-link>
                                Preview Note
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>

            <?php endif; ?>
            </div>

        <?php else: ?>

            <div class="section-heading">
                <div>
                    <h2>My Upload History</h2>
                    <p>
                        Your submissions for <?= e($selectedInstitute) ?>,
                        including their approval status.
                    </p>
                </div>
            </div>

            <div class="history-list">
            <?php if (!$history): ?>
                <div class="empty-state">
                    <strong>No upload history yet</strong>
                    <p>Your notes submitted under this institute will appear here.</p>
                </div>
            <?php else: ?>

                <?php foreach ($history as $item): ?>
                    <div class="history-item">
                        <div>
                            <h3><?= e($item['title']) ?></h3>
                            <p>
                                Submitted
                                <?= e(date('M d, Y · h:i A', strtotime($item['created_at']))) ?>
                                · <?= e($item['institute']) ?>
                            </p>
                        </div>

                        <span class="status <?= e(notesphere_status_class($item['status'])) ?>">
                            <?= e($item['status']) ?>
                        </span>
                    </div>
                <?php endforeach; ?>

            <?php endif; ?>
            </div>

        <?php endif; ?>
    </section>

<?php endif; ?>

<div class="bottom-note">
    <span>✓</span>
    <span>
        Notes are organized by institute. Always preview a note before downloading it.
    </span>
</div>

</main>

<script>
// Keep the page colors consistent with the saved theme.
(function () {
    try {
        const light = localStorage.getItem('notesphere_theme') === 'light';
        const meta = document.getElementById('themeColor');

        document.body.classList.toggle('light-mode', light);
        document.body.classList.toggle('dark-mode', !light);

        if (meta) {
            meta.setAttribute('content', light ? '#ffffff' : '#0b0b0b');
        }
    } catch (error) {}
})();

function getAlertTheme() {
    const light = document.documentElement.classList.contains('light-mode');

    return {
        background: light ? '#ffffff' : '#151817',
        color: light ? '#111827' : '#eff3f8',
        confirmButtonColor: light ? '#087f3e' : '#39FF14',
        cancelButtonColor: light ? '#64748b' : '#475569'
    };
}

// =========================================================
// CLICKABLE STAR RATING
// =========================================================

document.querySelectorAll('[data-star-picker]').forEach(function (picker) {
    const form = picker.closest('[data-rating-form]');
    if (!form) return;

    const hiddenRating = form.querySelector('[data-rating-value]');
    const hint = form.querySelector('[data-rating-hint]');
    const submitButton = form.querySelector('button[type="submit"]');
    const labels = Array.from(picker.querySelectorAll('.star-choice'));
    const radios = Array.from(picker.querySelectorAll('input[data-star]'));
    const initialRating = Number(hiddenRating.value) || 0;

    function paintStars(value) {
        labels.forEach(function (label) {
            const radio = label.querySelector('input[data-star]');
            const starValue = Number(radio.value);
            const selected = starValue <= value;
            const span = label.querySelector('span');

            label.classList.toggle('selected', selected);
            span.textContent = selected ? '★' : '☆';
        });
    }

    function updateRating(value) {
        value = Number(value) || 0;

        hiddenRating.value = value > 0 ? String(value) : '';
        paintStars(value);

        if (hint) {
            hint.textContent = value > 0
                ? 'You selected ' + value + ' out of 5 stars.'
                : 'Click a star to choose your rating.';
        }

        if (submitButton) {
            if (initialRating > 0) {
                submitButton.textContent = value > 0
                    ? 'Update Rating'
                    : 'Choose a Rating';
            } else {
                submitButton.textContent = value > 0
                    ? 'Submit Rating'
                    : 'Choose a Rating';
            }
        }
    }

    radios.forEach(function (radio) {
        radio.addEventListener('change', function () {
            updateRating(this.value);
        });

        radio.addEventListener('keydown', function (event) {
            if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) {
                return;
            }

            event.preventDefault();

            let index = radios.indexOf(this);

            if (event.key === 'ArrowRight' || event.key === 'ArrowUp') {
                index = Math.min(radios.length - 1, index + 1);
            } else {
                index = Math.max(0, index - 1);
            }

            radios[index].checked = true;
            radios[index].focus();
            updateRating(radios[index].value);
        });
    });

    // Hover preview for the star picker.
    labels.forEach(function (label) {
        label.addEventListener('mouseenter', function () {
            const value = Number(label.querySelector('input').value);

            labels.forEach(function (item) {
                const itemValue = Number(item.querySelector('input').value);
                item.classList.toggle('preview', itemValue <= value);
            });
        });

        label.addEventListener('mouseleave', function () {
            labels.forEach(function (item) {
                item.classList.remove('preview');
            });
        });
    });

    const initiallySelected = radios.find(function (radio) {
        return radio.checked;
    });

    updateRating(initiallySelected ? initiallySelected.value : hiddenRating.value);
});

// =========================================================
// THREE-DOT MENU OPEN/CLOSE
// =========================================================

function closeAllNoteMenus(exceptMenu) {
    document.querySelectorAll('[data-note-menu]').forEach(function (menu) {
        if (exceptMenu && menu === exceptMenu) return;

        const toggle = menu.querySelector('[data-menu-toggle]');
        const dropdown = menu.querySelector('.note-menu-dropdown');

        dropdown.classList.remove('open');
        toggle.setAttribute('aria-expanded', 'false');
    });
}

document.querySelectorAll('[data-menu-toggle]').forEach(function (toggle) {
    toggle.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();

        const menu = toggle.closest('[data-note-menu]');
        const dropdown = menu.querySelector('.note-menu-dropdown');
        const isOpen = dropdown.classList.contains('open');

        closeAllNoteMenus();

        if (!isOpen) {
            dropdown.classList.add('open');
            toggle.setAttribute('aria-expanded', 'true');
        }
    });
});

document.addEventListener('click', function (event) {
    if (!event.target.closest('[data-note-menu]')) {
        closeAllNoteMenus();
    }
});

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        closeAllNoteMenus();
    }
});

// =========================================================
// COPY NOTE LINK
// =========================================================

function copyTextFallback(text) {
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.left = '-9999px';
    textarea.style.top = '0';

    document.body.appendChild(textarea);
    textarea.select();
    textarea.setSelectionRange(0, textarea.value.length);

    let copied = false;

    try {
        copied = document.execCommand('copy');
    } catch (error) {
        copied = false;
    }

    textarea.remove();
    return copied;
}

async function copyNoteLink(url) {
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(url);
            return true;
        }
    } catch (error) {
        // Continue to fallback.
    }

    return copyTextFallback(url);
}

function getFullNoteUrl(relativeUrl) {
    return new URL(relativeUrl, window.location.href).href;
}

// =========================================================
// THREE-DOT MENU ACTIONS
// =========================================================

document.querySelectorAll('[data-note-action]').forEach(function (button) {
    button.addEventListener('click', async function () {
        const action = button.dataset.noteAction;
        const relativeUrl = button.dataset.noteUrl || '';
        const noteTitle = button.dataset.noteTitle || 'Note';
        const fullUrl = getFullNoteUrl(relativeUrl);

        closeAllNoteMenus();

        if (action === 'copy') {
            const copied = await copyNoteLink(fullUrl);

            if (copied) {
                Swal.fire({
                    icon: 'success',
                    title: 'Link Copied!',
                    text: 'The link for "' + noteTitle + '" has been copied.',
                    confirmButtonText: 'OK',
                    ...getAlertTheme()
                });
            } else {
                Swal.fire({
                    icon: 'info',
                    title: 'Copy the Note Link',
                    input: 'text',
                    inputValue: fullUrl,
                    inputAttributes: {
                        readonly: true,
                        'aria-label': 'Note link'
                    },
                    confirmButtonText: 'Close',
                    ...getAlertTheme()
                });
            }

            return;
        }

        if (action === 'share') {
            if (navigator.share) {
                try {
                    await navigator.share({
                        title: noteTitle + ' | NoteSphere',
                        text: 'Check out this note on NoteSphere.',
                        url: fullUrl
                    });
                } catch (error) {
                    if (error.name !== 'AbortError') {
                        const copied = await copyNoteLink(fullUrl);

                        Swal.fire({
                            icon: copied ? 'success' : 'info',
                            title: copied ? 'Link Copied!' : 'Share Note',
                            text: copied
                                ? 'Sharing was unavailable, so the note link was copied instead.'
                                : 'You can copy this link to share the note.',
                            ...(copied ? {} : { input: 'text', inputValue: fullUrl }),
                            confirmButtonText: 'OK',
                            ...getAlertTheme()
                        });
                    }
                }
            } else {
                const copied = await copyNoteLink(fullUrl);

                Swal.fire({
                    icon: copied ? 'success' : 'info',
                    title: copied ? 'Link Copied!' : 'Share Note',
                    text: copied
                        ? 'Your device does not support the share menu, so the note link was copied.'
                        : 'Copy the note link below to share it.',
                    ...(copied ? {} : { input: 'text', inputValue: fullUrl }),
                    confirmButtonText: 'OK',
                    ...getAlertTheme()
                });
            }

            return;
        }

        if (action === 'preview') {
            Swal.fire({
                title: 'Preview Note?',
                text: 'You can review this note before deciding to download it.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Preview Note',
                cancelButtonText: 'Cancel',
                reverseButtons: true,
                ...getAlertTheme()
            }).then(function (result) {
                if (result.isConfirmed) {
                    window.location.href = fullUrl;
                }
            });
        }
    });
});

// =========================================================
// PREVIEW BUTTON CONFIRMATION
// =========================================================

document.querySelectorAll('[data-preview-link]').forEach(function (link) {
    link.addEventListener('click', function (event) {
        if (
            event.ctrlKey ||
            event.metaKey ||
            event.shiftKey ||
            event.button !== 0
        ) {
            return;
        }

        event.preventDefault();

        Swal.fire({
            title: 'Preview Note?',
            text: 'You can review this note before deciding to download it.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Preview Note',
            cancelButtonText: 'Cancel',
            reverseButtons: true,
            ...getAlertTheme()
        }).then(function (result) {
            if (result.isConfirmed) {
                window.location.href = link.href;
            }
        });
    });
});

// =========================================================
// VALIDATE AND SUBMIT RATING
// =========================================================

document.querySelectorAll('[data-rating-form]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
        event.preventDefault();

        const hiddenRating = form.querySelector('[data-rating-value]');
        const rating = Number(hiddenRating ? hiddenRating.value : 0);

        if (!Number.isInteger(rating) || rating < 1 || rating > 5) {
            Swal.fire({
                icon: 'warning',
                title: 'Select a Rating',
                text: 'Please click one of the five stars before submitting.',
                confirmButtonText: 'OK',
                ...getAlertTheme()
            });
            return;
        }

        Swal.fire({
            title: 'Submit Rating?',
            text: 'You selected ' + rating +
                ' out of 5 stars. Your rating will help other students evaluate this note.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Submit Rating',
            cancelButtonText: 'Cancel',
            reverseButtons: true,
            ...getAlertTheme()
        }).then(function (result) {
            if (result.isConfirmed) {
                // Native submission sends the hidden rating field to PHP.
                form.submit();
            }
        });
    });
});

<?php if ($flash): ?>
Swal.fire({
    icon: <?= json_encode($flash['type'] === 'success' ? 'success' : 'error') ?>,
    title: <?= json_encode($flash['type'] === 'success' ? 'Success' : 'Unable to Complete') ?>,
    text: <?= json_encode($flash['message']) ?>,
    confirmButtonText: 'OK',
    ...getAlertTheme()
});
<?php endif; ?>
</script>
</body>
</html>
