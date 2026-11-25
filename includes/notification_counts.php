<?php
/**
 * NoteSphere - shared notification counters.
 *
 * Single source of truth for the two header badges:
 *  - "help"     : unread Help Board comment notifications (the post owner only)
 *  - "messages" : unread direct messages + unread group messages
 *
 * Include after includes/db_config.php so $conn (PDO) and the session exist.
 */

if (!defined('NOTESPHERE_NOTIFICATION_COUNTS_LOADED')) {
    define('NOTESPHERE_NOTIFICATION_COUNTS_LOADED', true);

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    function ns_notification_csrf_token(): string
    {
        if (empty($_SESSION['ns_notification_csrf']) || !is_string($_SESSION['ns_notification_csrf'])) {
            $_SESSION['ns_notification_csrf'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['ns_notification_csrf'];
    }

    /** Make sure the read-tracking columns/tables exist on older installations. */
    function ns_notification_ensure_schema(PDO $pdo): void
    {
        static $checked = [];

        $key = spl_object_id($pdo);
        if (isset($checked[$key])) {
            return;
        }
        $checked[$key] = true;

        try {
            $columns = $pdo->query('SHOW COLUMNS FROM `messages`')->fetchAll(PDO::FETCH_COLUMN, 0);
            if (!in_array('is_read', $columns, true)) {
                $pdo->exec('ALTER TABLE `messages` ADD COLUMN `is_read` TINYINT(1) NOT NULL DEFAULT 0');
            }
        } catch (Throwable $ignored) {
            // The counters simply return 0 if messages cannot be inspected.
        }

        try {
            $exists = $pdo->query("SHOW TABLES LIKE 'message_group_reads'")->fetchColumn();
            if ($exists) {
                return;
            }

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `message_group_reads` (
                    `group_id` BIGINT UNSIGNED NOT NULL,
                    `user_id` INT UNSIGNED NOT NULL,
                    `last_read_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`group_id`, `user_id`),
                    KEY `idx_group_reads_user` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Throwable $ignored) {
            // Group messages stay uncounted when the read tracker cannot be created.
        }
    }

    /** @return array{help:int,messages:int,direct_messages:int,group_messages:int,total:int} */
    function ns_notification_counts(PDO $pdo, int $userId): array
    {
        ns_notification_ensure_schema($pdo);

        $help = 0;
        $direct = 0;
        $groups = 0;

        if ($userId > 0) {
            try {
                // One badge per new comment. DISTINCT prevents duplicate notification
                // rows (old and new notification writers) from inflating the number.
                $st = $pdo->prepare("
                    SELECT COUNT(*) FROM (
                        SELECT DISTINCT COALESCE(`comment_id`, -`notification_id`) AS comment_key
                        FROM `notifications`
                        WHERE `user_id` = ?
                          AND `is_read` = 0
                          AND (`type` = 'comment' OR `comment_id` IS NOT NULL)
                    ) AS unread_comments
                ");
                $st->execute([$userId]);
                $help = (int) $st->fetchColumn();
            } catch (Throwable $ignored) {
                $help = 0;
            }

            try {
                $st = $pdo->prepare('SELECT COUNT(*) FROM `messages` WHERE `receiver_id` = ? AND `is_read` = 0');
                $st->execute([$userId]);
                $direct = (int) $st->fetchColumn();
            } catch (Throwable $ignored) {
                $direct = 0;
            }

            try {
                $st = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM `message_group_messages` gm
                    INNER JOIN `message_group_members` mem
                        ON mem.`group_id` = gm.`group_id` AND mem.`user_id` = ?
                    LEFT JOIN `message_group_reads` gr
                        ON gr.`group_id` = gm.`group_id` AND gr.`user_id` = ?
                    WHERE gm.`sender_id` <> ?
                      AND gm.`id` > COALESCE(gr.`last_read_id`, 0)
                ");
                $st->execute([$userId, $userId, $userId]);
                $groups = (int) $st->fetchColumn();
            } catch (Throwable $ignored) {
                $groups = 0;
            }
        }

        return [
            'help' => $help,
            'messages' => $direct + $groups,
            'direct_messages' => $direct,
            'group_messages' => $groups,
            'total' => $help + $direct + $groups,
        ];
    }

    /** Mark comment notifications read. All parameters are optional (null = all). */
    function ns_mark_help_notifications_read(PDO $pdo, int $userId, ?int $questionId = null, ?int $commentId = null): void
    {
        $userId = max(0, $userId);
        if ($userId === 0) {
            return;
        }

        ns_notification_ensure_schema($pdo);

        foreach ([
            ['notifications', 'notification_id'],
            ['help_notifications', 'id'],
        ] as $tableInfo) {
            [$table, $idColumn] = $tableInfo;
            try {
                $sql = "UPDATE `$table` SET `is_read` = 1 WHERE `user_id` = ? AND `is_read` = 0";
                $params = [$userId];

                if ($questionId !== null && $questionId > 0) {
                    $sql .= ' AND `question_id` = ?';
                    $params[] = $questionId;
                }
                if ($commentId !== null && $commentId > 0) {
                    $sql .= ' AND `comment_id` = ?';
                    $params[] = $commentId;
                }
                if ($questionId === null && $commentId === null) {
                    // Whole notification centre: only clear comment notifications here.
                    $sql .= " AND (`type` = 'comment' OR `comment_id` IS NOT NULL)";
                }

                $pdo->prepare($sql)->execute($params);
            } catch (Throwable $ignored) {
                // Legacy table may not exist; the shared notifications table still works.
            }
        }
    }

    function ns_mark_direct_messages_read(PDO $pdo, int $userId, int $peerId): void
    {
        ns_notification_ensure_schema($pdo);
        if ($userId < 1 || $peerId < 1) {
            return;
        }
        try {
            $st = $pdo->prepare('UPDATE `messages` SET `is_read` = 1 WHERE `receiver_id` = ? AND `sender_id` = ? AND `is_read` = 0');
            $st->execute([$userId, $peerId]);
        } catch (Throwable $ignored) {
        }
    }

    function ns_mark_group_messages_read(PDO $pdo, int $userId, int $groupId): void
    {
        ns_notification_ensure_schema($pdo);
        if ($userId < 1 || $groupId < 1) {
            return;
        }
        try {
            $st = $pdo->prepare('SELECT MAX(`id`) FROM `message_group_messages` WHERE `group_id` = ?');
            $st->execute([$groupId]);
            $lastId = (int) $st->fetchColumn();

            $st = $pdo->prepare("
                INSERT INTO `message_group_reads` (`group_id`, `user_id`, `last_read_id`)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    `last_read_id` = GREATEST(`last_read_id`, VALUES(`last_read_id`)),
                    `updated_at` = CURRENT_TIMESTAMP
            ");
            $st->execute([$groupId, $userId, $lastId]);
        } catch (Throwable $ignored) {
        }
    }

    /** Unread count for one group conversation (used in the chat list). */
    function ns_group_unread_count(PDO $pdo, int $userId, int $groupId): int
    {
        ns_notification_ensure_schema($pdo);
        try {
            $st = $pdo->prepare("
                SELECT COUNT(*)
                FROM `message_group_messages` gm
                LEFT JOIN `message_group_reads` gr
                    ON gr.`group_id` = gm.`group_id` AND gr.`user_id` = ?
                WHERE gm.`group_id` = ?
                  AND gm.`sender_id` <> ?
                  AND gm.`id` > COALESCE(gr.`last_read_id`, 0)
            ");
            $st->execute([$userId, $groupId, $userId]);
            return (int) $st->fetchColumn();
        } catch (Throwable $ignored) {
            return 0;
        }
    }

    /** Echo the small badge element used by every page's navigation. */
    function ns_notification_badge_html(int $count, string $kind, bool $inline = false): string
    {
        $count = max(0, $count);
        $kind = ($kind === 'help' || $kind === 'direct_messages') ? $kind : 'messages';
        $labels = [
            'help' => 'Unread Help Board comments',
            'messages' => 'Unread messages',
            'direct_messages' => 'Unread private messages',
        ];
        $label = $labels[$kind];
        $class = 'ns-count-badge' . ($inline ? ' ns-inline' : '');
        $hidden = $count > 0 ? '' : ' hidden';
        $text = $count > 99 ? '99+' : (string) $count;

        return '<span class="' . $class . '" data-ns-count="' . $kind . '"'
            . $hidden . ' aria-label="' . $label . '">' . $text . '</span>';
    }
}
