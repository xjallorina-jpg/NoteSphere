<?php
/**
 * NoteSphere - File storage helper
 *
 * Vercel Functions have a read-only deployment filesystem and a ~4.5 MB
 * request-body limit. To keep uploads working in production, the browser
 * uploads files directly to Cloudinary (assets/cloudinary_upload.js) and the
 * application only stores the resulting HTTPS URL.
 *
 * When Cloudinary is not configured (for example on local XAMPP), uploads keep
 * using the normal local uploads/ folder.
 */

require_once __DIR__ . '/env.php';

if (!defined('NOTESPHERE_CLOUDINARY_LOADED')) {
    define('NOTESPHERE_CLOUDINARY_LOADED', true);

    function ns_is_remote_file($path): bool
    {
        return is_string($path)
            && preg_match('~^https?://~i', trim($path)) === 1;
    }

    function ns_cloudinary_cloud_name(): string
    {
        return trim((string) ns_env('CLOUDINARY_CLOUD_NAME', ''));
    }

    function ns_cloudinary_upload_preset(): string
    {
        return trim((string) ns_env('CLOUDINARY_UPLOAD_PRESET', ''));
    }

    function ns_cloudinary_enabled(): bool
    {
        return ns_cloudinary_cloud_name() !== ''
            && ns_cloudinary_upload_preset() !== '';
    }

    /**
     * Public configuration consumed by assets/cloudinary_upload.js.
     */
    function ns_cloudinary_client_config(): array
    {
        return [
            'enabled' => ns_cloudinary_enabled(),
            'cloudName' => ns_cloudinary_cloud_name(),
            'uploadPreset' => ns_cloudinary_upload_preset(),
            'folder' => trim((string) ns_env('CLOUDINARY_FOLDER', 'notesphere'), '/'),
        ];
    }

    function ns_uploads_root(): string
    {
        $configured = trim((string) ns_env('NOTESPHERE_UPLOADS_DIR', ''));

        if ($configured !== '') {
            return rtrim($configured, '/\\');
        }

        // Vercel's deployment directory is read-only. /tmp is writable, so it
        // is used as a last-resort local fallback (files there are temporary;
        // Cloudinary is the production storage).
        if (ns_is_vercel()) {
            return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'notesphere-uploads';
        }

        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
    }

    /**
     * Validate a Cloudinary URL so users cannot inject arbitrary URLs into the
     * database through the hidden upload fields.
     */
    function ns_validate_cloudinary_url(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);

        if (
            !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== 'res.cloudinary.com'
        ) {
            return null;
        }

        $cloudName = ns_cloudinary_cloud_name();

        if ($cloudName === '') {
            return null;
        }

        $path = '/' . ltrim((string) ($parts['path'] ?? ''), '/');

        if (strpos($path, '/' . $cloudName . '/') !== 0) {
            return null;
        }

        return $url;
    }

    /**
     * Read one direct-to-Cloudinary upload from the request.
     *
     * The browser submits:
     *   <field>__url, <field>__name, <field>__size, <field>__type
     *
     * @return array{path:string,name:string,size:int,mime:string}|null
     */
    function ns_cloud_upload_from_request(string $field): ?array
    {
        if (!ns_cloudinary_enabled()) {
            return null;
        }

        $url = ns_validate_cloudinary_url((string) ($_POST[$field . '__url'] ?? ''));

        if ($url === null) {
            return null;
        }

        $name = trim((string) ($_POST[$field . '__name'] ?? ''));
        $size = max(0, (int) ($_POST[$field . '__size'] ?? 0));
        $mime = trim((string) ($_POST[$field . '__type'] ?? ''));

        if ($name === '') {
            $name = basename((string) parse_url($url, PHP_URL_PATH));
        }

        return [
            'path' => $url,
            'name' => $name,
            'size' => $size,
            'mime' => $mime,
        ];
    }

    /**
     * Read multiple direct-to-Cloudinary uploads from the request.
     *
     * The browser submits:
     *   <field>__urls[], <field>__names[], <field>__sizes[], <field>__types[]
     *
     * @return array<int,array{path:string,name:string,size:int,mime:string}>
     */
    function ns_cloud_uploads_from_request(string $field): array
    {
        if (!ns_cloudinary_enabled()) {
            return [];
        }

        $urls = $_POST[$field . '__urls'] ?? null;

        if (!is_array($urls)) {
            return [];
        }

        $names = is_array($_POST[$field . '__names'] ?? null) ? $_POST[$field . '__names'] : [];
        $sizes = is_array($_POST[$field . '__sizes'] ?? null) ? $_POST[$field . '__sizes'] : [];
        $types = is_array($_POST[$field . '__types'] ?? null) ? $_POST[$field . '__types'] : [];
        $items = [];

        foreach ($urls as $index => $rawUrl) {
            $url = ns_validate_cloudinary_url((string) $rawUrl);

            if ($url === null) {
                continue;
            }

            $name = trim((string) ($names[$index] ?? ''));

            if ($name === '') {
                $name = basename((string) parse_url($url, PHP_URL_PATH));
            }

            $items[] = [
                'path' => $url,
                'name' => $name,
                'size' => max(0, (int) ($sizes[$index] ?? 0)),
                'mime' => trim((string) ($types[$index] ?? '')),
            ];
        }

        return $items;
    }

    /**
     * Upload a server-side temp file to Cloudinary.
     *
     * Used by local development and by tools/migrate_uploads_to_cloudinary.php.
     */
    function ns_cloudinary_upload_file(
        string $tmpPath,
        string $originalName,
        string $subdir = ''
    ): ?string {
        if (!ns_cloudinary_enabled() || !is_file($tmpPath)) {
            return null;
        }

        if (!function_exists('curl_init')) {
            error_log('NoteSphere Cloudinary upload skipped: cURL is not available.');

            return null;
        }

        $cloudName = ns_cloudinary_cloud_name();
        $endpoint = 'https://api.cloudinary.com/v1_1/'
            . rawurlencode($cloudName)
            . '/auto/upload';

        $folder = trim((string) ns_env('CLOUDINARY_FOLDER', 'notesphere'), '/');

        if ($subdir !== '') {
            $folder = trim($folder . '/' . trim($subdir, '/'), '/');
        }

        $mime = function_exists('mime_content_type')
            ? (mime_content_type($tmpPath) ?: 'application/octet-stream')
            : 'application/octet-stream';

        $post = [
            'upload_preset' => ns_cloudinary_upload_preset(),
            'folder' => $folder,
            'use_filename' => 'true',
            'unique_filename' => 'true',
            'file' => new CURLFile($tmpPath, $mime, $originalName),
        ];

        $ch = curl_init($endpoint);

        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $error !== '') {
            error_log('NoteSphere Cloudinary upload failed: ' . $error);

            return null;
        }

        $decoded = json_decode((string) $response, true);

        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            error_log('NoteSphere Cloudinary upload rejected: ' . substr((string) $response, 0, 500));

            return null;
        }

        return ns_validate_cloudinary_url((string) ($decoded['secure_url'] ?? ''));
    }

    /**
     * Store a normal PHP upload ($_FILES entry).
     *
     * Returns:
     *   path - Cloudinary URL or local "uploads/..." path
     *   name - original filename
     *   size - bytes
     *   mime - MIME type
     *
     * @param array{name?:string,tmp_name?:string,size?:int,type?:string,error?:int} $file
     * @return array{path:string,name:string,size:int,mime:string}
     */
    function ns_store_uploaded_file(array $file, string $subdir = '', ?string $preferredName = null): array
    {
        $originalName = (string) ($preferredName ?? $file['name'] ?? 'file');
        $tmpPath = (string) ($file['tmp_name'] ?? '');
        $size = max(0, (int) ($file['size'] ?? 0));
        $mime = (string) ($file['type'] ?? '');

        if ($mime === '' && $tmpPath !== '' && is_file($tmpPath) && function_exists('mime_content_type')) {
            $mime = mime_content_type($tmpPath) ?: '';
        }

        if (ns_cloudinary_enabled()) {
            if ($tmpPath === '' || !is_file($tmpPath)) {
                throw new RuntimeException('The uploaded file could not be read.');
            }

            $url = ns_cloudinary_upload_file($tmpPath, $originalName, $subdir);

            if ($url === null) {
                throw new RuntimeException('Cloud storage upload failed. Please try again.');
            }

            return [
                'path' => $url,
                'name' => $originalName,
                'size' => $size,
                'mime' => $mime,
            ];
        }

        // Local fallback (XAMPP development).
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($originalName));
        $safeName = ltrim($safeName, '.');

        if ($safeName === '') {
            $safeName = 'file';
        }

        $extension = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
        $stem = pathinfo($safeName, PATHINFO_FILENAME);
        $unique = $stem . '_' . time() . '_' . bin2hex(random_bytes(5));

        if ($extension !== '') {
            $unique .= '.' . $extension;
        }

        $directory = ns_uploads_root();

        if ($subdir !== '') {
            $directory .= DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, trim($subdir, '/'));
        }

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the upload directory.');
        }

        $destination = $directory . DIRECTORY_SEPARATOR . $unique;

        if (!move_uploaded_file($tmpPath, $destination)) {
            throw new RuntimeException('Unable to save the uploaded file.');
        }

        $relative = 'uploads/' . ($subdir !== '' ? trim($subdir, '/') . '/' : '') . $unique;

        if ($size <= 0) {
            $size = (int) @filesize($destination);
        }

        return [
            'path' => $relative,
            'name' => $originalName,
            'size' => $size,
            'mime' => $mime,
        ];
    }

    /**
     * Store generated text (for notes created directly in the editor).
     */
    function ns_store_text_file(
        string $content,
        string $filename,
        string $subdir = 'notes'
    ): string {
        $tmp = tempnam(sys_get_temp_dir(), 'ns-text-');

        if ($tmp === false || @file_put_contents($tmp, $content) === false) {
            throw new RuntimeException('Unable to prepare the note file.');
        }

        try {
            if (ns_cloudinary_enabled()) {
                $url = ns_cloudinary_upload_file($tmp, $filename, $subdir);

                if ($url === null) {
                    throw new RuntimeException('Cloud storage upload failed. Please try again.');
                }

                return $url;
            }

            $directory = ns_uploads_root()
                . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, trim($subdir, '/'));

            if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new RuntimeException('Unable to create the upload directory.');
            }

            $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($filename));
            $destination = $directory . DIRECTORY_SEPARATOR . $safeName;

            if (@file_put_contents($destination, $content, LOCK_EX) === false) {
                throw new RuntimeException('Unable to save the note file.');
            }

            return 'uploads/' . trim($subdir, '/') . '/' . $safeName;
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Build a browser URL for a stored path.
     *
     * Cloudinary URLs are returned unchanged. Local paths are prefixed with the
     * current page's relative prefix (default "../") and "uploads/".
     */
    function ns_media_url($path, string $prefix = '../'): string
    {
        $path = trim(str_replace('\\', '/', (string) $path));

        if ($path === '') {
            return '';
        }

        if (ns_is_remote_file($path)) {
            return $path;
        }

        $path = ltrim($path, '/');

        if (strpos($path, 'uploads/') === 0) {
            return $prefix . $path;
        }

        return $prefix . 'uploads/' . $path;
    }

    /**
     * Delete a stored file. Cloudinary assets are kept (deleting them requires
     * the API secret); local files are removed normally.
     */
    function ns_delete_stored_file($path): void
    {
        $path = trim(str_replace('\\', '/', (string) $path));

        if ($path === '' || ns_is_remote_file($path)) {
            return;
        }

        $path = ltrim($path, '/');
        $candidate = ns_uploads_root() . DIRECTORY_SEPARATOR . (
            strpos($path, 'uploads/') === 0
                ? substr($path, strlen('uploads/'))
                : $path
        );

        $real = realpath($candidate);
        $root = realpath(ns_uploads_root());

        if (
            $real !== false
            && $root !== false
            && strpos($real, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) === 0
            && is_file($real)
        ) {
            @unlink($real);
        }
    }
}
