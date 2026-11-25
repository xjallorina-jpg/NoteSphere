
<?php
/**
 * =========================================================
 * NoteSphere - Central Storage Helper
 * =========================================================
 *
 * Single source of truth:
 * system_storage
 *
 * Counts:
 * - Notes and note thumbnails
 * - Professor announcement attachments
 * - Student announcement submission files
 *
 * Storage quotas are always read from the Admin settings.
 * No hardcoded personal quota fallback is used.
 */

if (!defined('NOTESPHERE_STORAGE_LOADED')) {
    define('NOTESPHERE_STORAGE_LOADED', true);

    /**
     * =====================================================
     * FORMAT BYTES
     * =====================================================
     */
    function ns_format_bytes($bytes)
    {
        $bytes = max(0, (float) $bytes);

        if ($bytes >= pow(1024, 4)) {
            return number_format($bytes / pow(1024, 4), 2) . ' TB';
        }

        if ($bytes >= pow(1024, 3)) {
            return number_format($bytes / pow(1024, 3), 2) . ' GB';
        }

        if ($bytes >= pow(1024, 2)) {
            return number_format($bytes / pow(1024, 2), 2) . ' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }

        return number_format($bytes, 0) . ' B';
    }

    /**
     * =====================================================
     * CHECK WEB LINK
     * =====================================================
     */
    function ns_is_web_link($value)
    {
        if (!is_string($value) && !is_numeric($value)) {
            return false;
        }

        return (bool) preg_match(
            '~^(https?://|www\.)~i',
            trim((string) $value)
        );
    }

    /**
     * =====================================================
     * VALIDATE SQL IDENTIFIER
     * =====================================================
     */
    function ns_storage_valid_identifier($identifier)
    {
        return is_string($identifier)
            && preg_match('/^[A-Za-z0-9_]+$/', $identifier);
    }

    /**
     * =====================================================
     * CHECK TABLE EXISTS
     * =====================================================
     */
    function ns_storage_table_exists(PDO $pdo, $table)
    {
        if (!ns_storage_valid_identifier($table)) {
            return false;
        }

        try {
            // MariaDB does not accept a placeholder in "SHOW TABLES LIKE ?".
            // Query information_schema instead so the table name stays bound.
            $stmt = $pdo->prepare(
                'SELECT 1 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                 LIMIT 1'
            );
            $stmt->execute([$table]);

            return (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log(
                'NoteSphere table check failed: ' . $e->getMessage()
            );

            return false;
        }
    }

    /**
     * =====================================================
     * GET TABLE COLUMNS
     * =====================================================
     */
    function ns_storage_columns(PDO $pdo, $table)
    {
        if (!ns_storage_valid_identifier($table)) {
            return [];
        }

        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");

            return $stmt
                ? $stmt->fetchAll(PDO::FETCH_COLUMN)
                : [];
        } catch (Throwable $e) {
            error_log(
                'NoteSphere column check failed: ' . $e->getMessage()
            );

            return [];
        }
    }

    /**
     * =====================================================
     * GET ADMIN STORAGE SETTINGS
     * =====================================================
     */
    function ns_get_storage_limits(PDO $pdo)
    {
        if (!ns_storage_table_exists($pdo, 'system_storage')) {
            throw new RuntimeException(
                'The system_storage table does not exist.'
            );
        }

        $columns = ns_storage_columns($pdo, 'system_storage');

        $requiredColumns = [
            'total_storage_tb',
            'student_storage_gb',
            'professor_storage_gb'
        ];

        foreach ($requiredColumns as $column) {
            if (!in_array($column, $columns, true)) {
                throw new RuntimeException(
                    'Missing system_storage column: ' . $column
                );
            }
        }

        $orderBy = in_array('id', $columns, true)
            ? ' ORDER BY id ASC'
            : '';

        $stmt = $pdo->query(
            'SELECT total_storage_tb,
                    student_storage_gb,
                    professor_storage_gb
             FROM system_storage'
            . $orderBy .
            ' LIMIT 1'
        );

        $settings = $stmt
            ? $stmt->fetch(PDO::FETCH_ASSOC)
            : false;

        if (!$settings) {
            throw new RuntimeException(
                'No storage settings have been configured by the Admin.'
            );
        }

        foreach ($requiredColumns as $column) {
            if (
                !isset($settings[$column])
                || !is_numeric($settings[$column])
                || (float) $settings[$column] <= 0
            ) {
                throw new RuntimeException(
                    'Invalid Admin storage setting: ' . $column
                );
            }
        }

        $totalTB = (float) $settings['total_storage_tb'];
        $studentGB = (float) $settings['student_storage_gb'];
        $professorGB = (float) $settings['professor_storage_gb'];

        return [
            'total_storage_tb' => $totalTB,
            'student_storage_gb' => $studentGB,
            'professor_storage_gb' => $professorGB,

            'total_bytes' => (int) round(
                $totalTB * pow(1024, 4)
            ),

            'student_quota_bytes' => (int) round(
                $studentGB * pow(1024, 3)
            ),

            'professor_quota_bytes' => (int) round(
                $professorGB * pow(1024, 3)
            )
        ];
    }

    /**
     * =====================================================
     * RESOLVE STORED FILE PATH
     * =====================================================
     *
     * Supports paths such as:
     * uploads/notes/example.pdf
     * ../uploads/notes/example.pdf
     * NoteSphere/uploads/notes/example.pdf
     * Absolute Windows paths
     * Absolute Unix paths
     */
    function ns_resolve_file_path($storedPath)
    {
        if (
            !is_string($storedPath)
            || trim($storedPath) === ''
            || ns_is_web_link($storedPath)
        ) {
            return null;
        }

        $storedPath = trim($storedPath);
        $storedPath = trim($storedPath, "\"'");
        $storedPath = str_replace('\\', '/', $storedPath);

        // Absolute Windows path.
        if (preg_match('/^[A-Za-z]:\//', $storedPath)) {
            return is_file($storedPath) ? realpath($storedPath) : null;
        }

        // Absolute Unix path.
        if (strpos($storedPath, '/') === 0) {
            return is_file($storedPath) ? realpath($storedPath) : null;
        }

        $projectRoot = realpath(__DIR__);

        if (!$projectRoot) {
            return null;
        }

        // Normalize common relative path prefixes.
        $normalized = $storedPath;

        while (strpos($normalized, './') === 0) {
            $normalized = substr($normalized, 2);
        }

        while (strpos($normalized, '../') === 0) {
            $normalized = substr($normalized, 3);
        }

        $normalized = ltrim($normalized, '/');

        // Remove project folder prefix if present.
        $projectFolder = basename($projectRoot);

        if (
            strpos(
                strtolower($normalized),
                strtolower($projectFolder . '/')
            ) === 0
        ) {
            $normalized = substr(
                $normalized,
                strlen($projectFolder) + 1
            );
        }

        // Try project-relative path.
        $candidate = $projectRoot . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $normalized);

        if (is_file($candidate)) {
            return realpath($candidate);
        }

        // Try common upload folders using the stored filename.
        $fileName = basename($normalized);

        $directories = [
            $projectRoot . '/uploads',
            $projectRoot . '/uploads/notes',
            $projectRoot . '/uploads/notes/thumbnails',
            $projectRoot . '/uploads/announcements',
            $projectRoot . '/uploads/announcement',
            $projectRoot . '/uploads/submissions',
            $projectRoot . '/uploads/student',
            $projectRoot . '/uploads/professor',
            $projectRoot . '/uploads/files',
            $projectRoot . '/uploads/documents'
        ];

        foreach ($directories as $directory) {
            $candidate = $directory . '/' . $fileName;

            if (is_file($candidate)) {
                return realpath($candidate);
            }
        }

        return null;
    }

    /**
     * =====================================================
     * COUNT FILES REFERENCED BY A DATABASE TABLE
     * =====================================================
     */
    function ns_table_file_usage(
        PDO $pdo,
        $table,
        $userId,
        $ownerCandidates,
        $pathCandidates
    ) {
        if (!ns_storage_table_exists($pdo, $table)) {
            return 0;
        }

        $columns = ns_storage_columns($pdo, $table);

        $ownerColumn = null;

        foreach ($ownerCandidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                $ownerColumn = $candidate;
                break;
            }
        }

        $pathColumns = [];

        foreach ($pathCandidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                $pathColumns[] = $candidate;
            }
        }

        if (!$ownerColumn || !$pathColumns) {
            return 0;
        }

        $quotedPaths = array_map(
            function ($column) {
                return '`' . $column . '`';
            },
            $pathColumns
        );

        // Cloudinary files are not on disk. When the table records their size,
        // use it so storage quotas still reflect remote attachments.
        $sizeColumn = in_array('file_size', $columns, true) ? 'file_size' : null;

        if ($sizeColumn !== null) {
            $quotedPaths[] = '`file_size`';
        }

        $sql = 'SELECT ' . implode(', ', $quotedPaths)
            . ' FROM `' . $table . '`'
            . ' WHERE `' . $ownerColumn . '` = ?';

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$userId]);

            $usedBytes = 0;
            $seenPaths = [];

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                foreach ($pathColumns as $column) {
                    $storedPath = $row[$column] ?? null;

                    if (!is_string($storedPath) || trim($storedPath) === '') {
                        continue;
                    }

                    if (ns_is_web_link($storedPath)) {
                        $cloudKey = 'cloud:' . $storedPath;

                        if (
                            $sizeColumn !== null
                            && !isset($seenPaths[$cloudKey])
                        ) {
                            $seenPaths[$cloudKey] = true;
                            $usedBytes += max(
                                0,
                                (int) ($row[$sizeColumn] ?? 0)
                            );
                        }

                        continue;
                    }

                    $resolvedPath = ns_resolve_file_path($storedPath);

                    if (!$resolvedPath || !is_file($resolvedPath)) {
                        continue;
                    }

                    $realPath = realpath($resolvedPath);

                    if (!$realPath || isset($seenPaths[$realPath])) {
                        continue;
                    }

                    $seenPaths[$realPath] = true;

                    clearstatcache(true, $realPath);

                    $size = @filesize($realPath);

                    if ($size !== false) {
                        $usedBytes += (int) $size;
                    }
                }
            }

            return $usedBytes;
        } catch (Throwable $e) {
            error_log(
                'NoteSphere file usage query failed for '
                . $table . ': ' . $e->getMessage()
            );

            return 0;
        }
    }

    /**
     * =====================================================
     * GET USER STORAGE USAGE
     * =====================================================
     */
    function ns_get_user_storage(PDO $pdo, $userId, $role)
    {
        $role = strtolower(trim((string) $role));

        if ($role === 'admin') {
            return [
                'used_bytes' => 0,
                'quota_bytes' => null,
                'remaining_bytes' => null,
                'percentage' => 0,
                'used_text' => ns_format_bytes(0),
                'quota_text' => 'System Monitor',
                'remaining_text' => 'No Personal Quota',
                'storage_configured' => true
            ];
        }

        if (!in_array($role, ['student', 'professor'], true)) {
            throw new InvalidArgumentException(
                'Unsupported storage role.'
            );
        }

        $usedBytes = 0;

        // Notes and thumbnails owned by this user.
        $noteOwners = [
            'user_id',
            'student_id',
            'professor_id',
            'uploader_id',
            'uploaded_by',
            'owner_id',
            'created_by'
        ];

        $usedBytes += ns_table_file_usage(
            $pdo,
            'notes',
            $userId,
            $noteOwners,
            [
                'file_path',
                'filepath',
                'file_name',
                'filename',
                'file',
                'document_path',
                'note_file',
                'attachment',
                'attachment_path',
                'file_url',
                'thumbnail',
                'thumbnail_path'
            ]
        );

        // Direct-message attachments sent by this user.
        $usedBytes += ns_table_file_usage(
            $pdo,
            'messages',
            $userId,
            ['sender_id'],
            ['file_path']
        );

        // Group-message attachments sent by this user.
        $usedBytes += ns_table_file_usage(
            $pdo,
            'message_group_messages',
            $userId,
            ['sender_id'],
            ['file_path']
        );

        // Professor announcement attachments.
        if ($role === 'professor') {
            $usedBytes += ns_table_file_usage(
                $pdo,
                'announcements',
                $userId,
                [
                    'professor_id',
                    'user_id',
                    'author_id',
                    'created_by',
                    'posted_by',
                    'uploaded_by'
                ],
                [
                    'file_path',
                    'filepath',
                    'file_name',
                    'filename',
                    'file',
                    'attachment',
                    'attachment_path',
                    'document_path',
                    'announcement_file',
                    'attachment_file'
                ]
            );
        }

        // Student announcement submission attachments.
        if ($role === 'student') {
            $usedBytes += ns_table_file_usage(
                $pdo,
                'announcement_submissions',
                $userId,
                [
                    'student_id',
                    'user_id'
                ],
                [
                    'file_path',
                    'filepath',
                    'file_name',
                    'filename',
                    'file',
                    'attachment',
                    'attachment_path'
                ]
            );
        }

        // Always use Admin-configured limits.
        $limits = ns_get_storage_limits($pdo);

        $quotaBytes = $role === 'student'
            ? $limits['student_quota_bytes']
            : $limits['professor_quota_bytes'];

        $remainingBytes = max(0, $quotaBytes - $usedBytes);

        $percentage = $quotaBytes > 0
            ? ($usedBytes / $quotaBytes) * 100
            : 0;

        return [
            'used_bytes' => $usedBytes,
            'quota_bytes' => $quotaBytes,
            'remaining_bytes' => $remainingBytes,
            'percentage' => min(100, max(0, $percentage)),
            'used_text' => ns_format_bytes($usedBytes),
            'quota_text' => ns_format_bytes($quotaBytes),
            'remaining_text' => ns_format_bytes($remainingBytes),
            'storage_configured' => true
        ];
    }

    /**
     * =====================================================
     * CHECK WHETHER UPLOAD IS ALLOWED
     * =====================================================
     */
    function ns_can_upload(
        PDO $pdo,
        $userId,
        $role,
        $incomingBytes
    ) {
        try {
            $storage = ns_get_user_storage(
                $pdo,
                $userId,
                $role
            );

            if ($storage['quota_bytes'] === null) {
                return strtolower(trim((string) $role)) === 'admin';
            }

            $incomingBytes = max(0, (int) $incomingBytes);

            return $incomingBytes <= (int) $storage['remaining_bytes'];
        } catch (Throwable $e) {
            error_log(
                'NoteSphere storage validation failed: '
                . $e->getMessage()
            );

            return false;
        }
    }

    /**
     * =====================================================
     * CHECK UPLOAD WITH REPLACEMENT CREDIT
     * =====================================================
     */
    function ns_can_replace_upload(
        PDO $pdo,
        $userId,
        $role,
        $incomingBytes,
        $oldFileBytes = 0
    ) {
        try {
            $storage = ns_get_user_storage(
                $pdo,
                $userId,
                $role
            );

            if ($storage['quota_bytes'] === null) {
                return strtolower(trim((string) $role)) === 'admin';
            }

            $incomingBytes = max(0, (int) $incomingBytes);
            $oldFileBytes = max(0, (int) $oldFileBytes);

            $effectiveUsed = max(
                0,
                (int) $storage['used_bytes'] - $oldFileBytes
            );

            return (
                $effectiveUsed + $incomingBytes
            ) <= (int) $storage['quota_bytes'];
        } catch (Throwable $e) {
            error_log(
                'NoteSphere replacement storage validation failed: '
                . $e->getMessage()
            );

            return false;
        }
    }

    /**
     * =====================================================
     * GET SYSTEM-WIDE STORAGE USAGE
     * =====================================================
     */
    function ns_get_system_storage($pdo = null)
    {
        $uploadsDir = __DIR__ . '/uploads';
        $usedBytes = 0;

        if (is_dir($uploadsDir)) {
            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator(
                        $uploadsDir,
                        FilesystemIterator::SKIP_DOTS
                    )
                );

                foreach ($iterator as $file) {
                    if ($file->isFile()) {
                        $size = $file->getSize();

                        if ($size !== false) {
                            $usedBytes += (int) $size;
                        }
                    }
                }
            } catch (Throwable $e) {
                error_log(
                    'NoteSphere system storage scan failed: '
                    . $e->getMessage()
                );

                return [
                    'used_bytes' => $usedBytes,
                    'total_bytes' => null,
                    'remaining_bytes' => null,
                    'percentage' => 0,
                    'storage_configured' => false
                ];
            }
        }

        if (!$pdo) {
            return [
                'used_bytes' => $usedBytes,
                'total_bytes' => null,
                'remaining_bytes' => null,
                'percentage' => 0,
                'storage_configured' => false
            ];
        }

        try {
            $limits = ns_get_storage_limits($pdo);
            $totalBytes = $limits['total_bytes'];

            return [
                'used_bytes' => $usedBytes,
                'total_bytes' => $totalBytes,
                'remaining_bytes' => max(
                    0,
                    $totalBytes - $usedBytes
                ),
                'percentage' => $totalBytes > 0
                    ? min(
                        100,
                        max(0, ($usedBytes / $totalBytes) * 100)
                    )
                    : 0,
                'storage_configured' => true
            ];
        } catch (Throwable $e) {
            error_log(
                'NoteSphere system storage settings failed: '
                . $e->getMessage()
            );

            return [
                'used_bytes' => $usedBytes,
                'total_bytes' => null,
                'remaining_bytes' => null,
                'percentage' => 0,
                'storage_configured' => false
            ];
        }
    }
}
?>
