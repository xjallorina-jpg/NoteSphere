<?php
/**
 * NoteSphere - live notification badge counts.
 * GET  -> current counts
 * POST -> mark an item read, then return the refreshed counts
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../includes/db_config.php';
require_once __DIR__ . '/../includes/notification_counts.php';

/** @var PDO $conn */

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Please sign in again.']);
    exit;
}

$userId = (int) $_SESSION['user_id'];

function ns_counts_json(array $extra = []): void
{
    global $conn, $userId;
    try {
        $counts = ns_notification_counts($conn, $userId);
        echo json_encode(array_merge(['ok' => true], $counts, $extra), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('[NoteSphere badge counts] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Could not load notification counts.']);
    }
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $raw = file_get_contents('php://input');
    $input = json_decode((string) $raw, true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    if (!hash_equals(ns_notification_csrf_token(), (string) ($input['csrf'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid session token. Refresh the page and try again.']);
        exit;
    }

    try {
        switch ((string) ($input['action'] ?? '')) {
            case 'mark_help':
                ns_mark_help_notifications_read($conn, $userId);
                break;

            case 'mark_comment':
                ns_mark_help_notifications_read(
                    $conn,
                    $userId,
                    (int) ($input['question_id'] ?? 0) ?: null,
                    (int) ($input['comment_id'] ?? 0) ?: null
                );
                break;

            case 'mark_message':
                ns_mark_direct_messages_read($conn, $userId, (int) ($input['peer_id'] ?? 0));
                break;

            case 'mark_group':
                ns_mark_group_messages_read($conn, $userId, (int) ($input['group_id'] ?? 0));
                break;

            default:
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
                exit;
        }
    } catch (Throwable $e) {
        error_log('[NoteSphere badge mark] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Could not update notification counts.']);
        exit;
    }
}

ns_counts_json();
