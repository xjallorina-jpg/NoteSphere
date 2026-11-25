<?php
/**
 * NoteSphere - Environment helpers
 *
 * Reads configuration from real environment variables (Vercel, Docker, CI)
 * and, for local XAMPP development, from a .env file in the project root.
 *
 * The .env file is intentionally ignored by Git. Never commit real secrets.
 */

if (!defined('NOTESPHERE_ENV_LOADED')) {
    define('NOTESPHERE_ENV_LOADED', true);

    /**
     * Load a dotenv-style file without overriding variables that are already
     * provided by the hosting platform.
     */
    function ns_load_env_file(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#') {
                continue;
            }

            if (stripos($line, 'export ') === 0) {
                $line = trim(substr($line, 7));
            }

            $separator = strpos($line, '=');

            if ($separator === false) {
                continue;
            }

            $key = trim(substr($line, 0, $separator));

            if ($key === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }

            $value = trim(substr($line, $separator + 1));

            // Strip matching surrounding quotes.
            $length = strlen($value);

            if (
                $length >= 2
                && (($value[0] === '"' && $value[$length - 1] === '"')
                    || ($value[0] === "'" && $value[$length - 1] === "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            // Do not overwrite values injected by the platform.
            if (getenv($key) !== false) {
                continue;
            }

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    $nsProjectRoot = dirname(__DIR__);

    ns_load_env_file($nsProjectRoot . DIRECTORY_SEPARATOR . '.env');
    ns_load_env_file($nsProjectRoot . DIRECTORY_SEPARATOR . '.env.local');

    unset($nsProjectRoot);

    /**
     * Read an environment value with a fallback.
     *
     * @param mixed $default
     * @return mixed
     */
    function ns_env(string $key, $default = null)
    {
        $value = getenv($key);

        if ($value === false) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        }

        return ($value === null || $value === false) ? $default : $value;
    }

    function ns_env_bool(string $key, bool $default = false): bool
    {
        $value = ns_env($key);

        if ($value === null) {
            return $default;
        }

        return in_array(
            strtolower(trim((string) $value)),
            ['1', 'true', 'yes', 'on'],
            true
        );
    }

    function ns_env_int(string $key, int $default = 0): int
    {
        $value = ns_env($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * True when the code is running on Vercel.
     */
    function ns_is_vercel(): bool
    {
        return getenv('VERCEL') !== false
            || getenv('VERCEL_ENV') !== false
            || getenv('NOW_REGION') !== false;
    }

    /**
     * Base URL of the optional Node push server.
     *
     * On Vercel this must point to an externally hosted push server; otherwise
     * notifications are simply skipped when the endpoint is unreachable.
     */
    function ns_push_server_url(string $path = ''): string
    {
        $base = trim((string) ns_env('PUSH_SERVER_URL', 'http://127.0.0.1:4000'));
        $base = rtrim($base, '/');

        if ($base === '') {
            return '';
        }

        return $base . '/' . ltrim($path, '/');
    }
}
