<?php
/**
 * NoteSphere - Session bootstrap
 *
 * Vercel Functions are short-lived and do not share a writable filesystem, so
 * PHP's default file-based sessions would randomly log users out. On Vercel
 * (or when DB_SESSIONS=1) sessions are stored in the MySQL database instead.
 *
 * Locally, PHP's native file sessions are still used unless DB_SESSIONS=1.
 */

if (!defined('NOTESPHERE_SESSION_LOADED')) {
    define('NOTESPHERE_SESSION_LOADED', true);

    if (!class_exists('NsDbSessionHandler', false)) {
        class NsDbSessionHandler implements SessionHandlerInterface
        {
            private PDO $pdo;
            private int $lifetime;

            public function __construct(PDO $pdo, int $lifetime = 0)
            {
                $this->pdo = $pdo;
                $this->lifetime = $lifetime > 0 ? $lifetime : 43200;
            }

            public function open(string $path, string $name): bool
            {
                return true;
            }

            public function close(): bool
            {
                return true;
            }

            public function read(string $id): string|false
            {
                try {
                    $stmt = $this->pdo->prepare(
                        'SELECT data FROM php_sessions
                         WHERE id = ? AND last_activity >= ? LIMIT 1'
                    );
                    $stmt->execute([$id, time() - $this->lifetime]);
                    $data = $stmt->fetchColumn();

                    return is_string($data) ? $data : '';
                } catch (Throwable $e) {
                    error_log('NoteSphere session read failed: ' . $e->getMessage());

                    return '';
                }
            }

            public function write(string $id, string $data): bool
            {
                try {
                    $stmt = $this->pdo->prepare(
                        'INSERT INTO php_sessions (id, data, last_activity)
                         VALUES (?, ?, ?)
                         ON DUPLICATE KEY UPDATE
                             data = VALUES(data),
                             last_activity = VALUES(last_activity)'
                    );

                    return $stmt->execute([$id, $data, time()]);
                } catch (Throwable $e) {
                    error_log('NoteSphere session write failed: ' . $e->getMessage());

                    return false;
                }
            }

            public function destroy(string $id): bool
            {
                try {
                    $stmt = $this->pdo->prepare(
                        'DELETE FROM php_sessions WHERE id = ?'
                    );

                    return $stmt->execute([$id]);
                } catch (Throwable $e) {
                    error_log('NoteSphere session destroy failed: ' . $e->getMessage());

                    return false;
                }
            }

            public function gc(int $max_lifetime): int|false
            {
                try {
                    $stmt = $this->pdo->prepare(
                        'DELETE FROM php_sessions WHERE last_activity < ?'
                    );
                    $stmt->execute([time() - max(1, $max_lifetime)]);

                    return $stmt->rowCount();
                } catch (Throwable $e) {
                    error_log('NoteSphere session gc failed: ' . $e->getMessage());

                    return false;
                }
            }
        }
    }

    /**
     * Create the shared session table once per request. This is safe to run on
     * every request because CREATE TABLE IF NOT EXISTS is cheap.
     */
    function ns_ensure_session_table(PDO $pdo): bool
    {
        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS php_sessions (
                    id VARCHAR(128) NOT NULL,
                    data MEDIUMBLOB NOT NULL,
                    last_activity INT UNSIGNED NOT NULL,
                    PRIMARY KEY (id),
                    KEY idx_php_sessions_last_activity (last_activity)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );

            return true;
        } catch (Throwable $e) {
            error_log('NoteSphere session table setup failed: ' . $e->getMessage());

            return false;
        }
    }

    function ns_request_is_https(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        $forwarded = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));

        return $forwarded === 'https' || ns_is_vercel();
    }

    /**
     * Start a session, using the database handler when required.
     *
     * Some legacy pages call session_start() before including db_config.php.
     * On Vercel that would create a file-based session that disappears between
     * requests, so an already-active native session is closed and restarted
     * with the database handler. Its data is preserved.
     */
    function ns_start_session(PDO $pdo): void
    {
        $useDb = ns_env_bool('DB_SESSIONS', ns_is_vercel());
        $preserved = [];

        if ($useDb && session_status() === PHP_SESSION_ACTIVE) {
            $preserved = is_array($_SESSION ?? null) ? $_SESSION : [];
            @session_write_close();
        }

        if ($useDb && ns_ensure_session_table($pdo)) {
            $lifetime = ns_env_int('SESSION_LIFETIME', 43200);

            session_set_save_handler(
                new NsDbSessionHandler($pdo, $lifetime),
                true
            );

            ini_set('session.gc_maxlifetime', (string) $lifetime);
            ini_set('session.use_strict_mode', '1');
        } elseif (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (session_status() === PHP_SESSION_NONE) {
            $params = session_get_cookie_params();

            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'domain' => $params['domain'] ?? '',
                'secure' => ns_request_is_https(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);

            session_name('NOTESPHERESESSID');

            if (!@session_start()) {
                error_log('NoteSphere session_start() failed.');
            }

            if ($preserved) {
                $_SESSION = array_merge($_SESSION, $preserved);
            }
        }
    }
}
