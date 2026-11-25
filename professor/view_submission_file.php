<?php

// =========================================================
// NoteSphere - View Submission File
// Professor-only secure submission file viewer
//
// File:
// /NoteSphere/professor/view_submission_file.php
//
// Supports preview:
// PDF
// DOC / DOCX
// PPT / PPTX
// XLS / XLSX
// ODT / ODS / ODP
// JPG / JPEG / PNG / GIF / WEBP / SVG
// TXT / CSV / HTML / CSS / JS / PHP / JSON / XML
//
// Office documents are converted to PDF using LibreOffice.
//
// Archives / project files / unsupported files:
// Download only
//
// Security:
// - Professor only
// - Professor must own the announcement
// - File path comes from database only
// - Prevents arbitrary file access
// =========================================================

session_start();

require_once __DIR__ . '/../includes/db_config.php';

/** @var PDO $conn */
$pdo = $conn;

// =========================================================
// BASIC HELPERS
// =========================================================

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function sendErrorPage($title, $message, $backUrl = 'javascript:history.back()')
{
    http_response_code(404);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <title><?= e($title) ?> - NoteSphere</title>

        <link
            href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
            rel="stylesheet"
        >

        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

        <style>
            * {
                box-sizing: border-box;
            }

            html,
            body {
                margin: 0;
                padding: 0;
                width: 100%;
                min-height: 100%;
            }

            body {
                font-family: "Plus Jakarta Sans", sans-serif;
                background: #f4f7fb;
                color: #1f2937;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }

            .error-card {
                width: 100%;
                max-width: 560px;
                background: #ffffff;
                border-radius: 22px;
                padding: 40px 30px;
                text-align: center;
                box-shadow: 0 15px 50px rgba(0, 0, 0, 0.10);
            }

            .error-icon {
                width: 76px;
                height: 76px;
                margin: 0 auto 20px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                background: #fff0f0;
                color: #dc3545;
                font-size: 36px;
            }

            h1 {
                margin: 0 0 12px;
                font-size: 25px;
                font-weight: 800;
            }

            p {
                margin: 0 auto 25px;
                color: #6b7280;
                line-height: 1.7;
                font-size: 14px;
            }

            .back-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                padding: 12px 20px;
                border-radius: 12px;
                background: #3d8bff;
                color: #fff;
                text-decoration: none;
                font-size: 14px;
                font-weight: 700;
            }

            body.dark-mode {
                background: #111827;
                color: #f3f4f6;
            }

            body.dark-mode .error-card {
                background: #1f2937;
                box-shadow: 0 15px 50px rgba(0, 0, 0, 0.35);
            }

            body.dark-mode p {
                color: #9ca3af;
            }

            body.dark-mode .error-icon {
                background: rgba(220, 53, 69, 0.15);
            }

            @media (max-width: 600px) {
                .error-card {
                    padding: 30px 20px;
                    border-radius: 18px;
                }

                h1 {
                    font-size: 21px;
                }
            }
        </style>
    </head>

    <body>

        <div class="error-card">
            <div class="error-icon">!</div>

            <h1><?= e($title) ?></h1>

            <p><?= e($message) ?></p>

            <a href="<?= e($backUrl) ?>" class="back-btn">
                ← Go Back
            </a>
        </div>

        <script>
            (function () {
                const savedTheme =
                    localStorage.getItem("notesphere_theme") ||
                    localStorage.getItem("theme") ||
                    localStorage.getItem("colorMode");

                const darkMode =
                    savedTheme === "dark" ||
                    savedTheme === "dark-mode" ||
                    savedTheme === "Dark";

                if (darkMode) {
                    document.body.classList.add("dark-mode");
                }
            })();
        </script>
</body>
    </html>
    <?php
    exit;
}


// =========================================================
// PROFESSOR SECURITY CHECK
// =========================================================

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'professor'
) {
    header("Location: ../auth/login.php");
    exit();
}

$professorId = (int)$_SESSION['user_id'];


// =========================================================
// GET SUBMISSION ID
// =========================================================

$submissionId = filter_input(
    INPUT_GET,
    'submission_id',
    FILTER_VALIDATE_INT
);

if (!$submissionId || $submissionId <= 0) {
    sendErrorPage(
        'Invalid Submission',
        'The submission ID is missing or invalid.'
    );
}


// =========================================================
// DOWNLOAD MODE
// =========================================================

$isDownload = isset($_GET['download']) && $_GET['download'] === '1';


// =========================================================
// GET SUBMISSION
//
// IMPORTANT:
// Professor can only access a submission if:
// submission -> announcement -> professor
// belongs to current professor.
// =========================================================

try {

    $stmt = $pdo->prepare("
        SELECT
            s.id AS submission_id,
            s.announcement_id,
            s.student_id,
            s.submission_text,
            s.file_path,
            s.grade,
            s.feedback,
            s.submitted_at,

            a.professor_id,
            a.title AS announcement_title,
            a.subject,
            a.year_level,
            a.section,

            u.full_name AS student_name,
            u.email AS student_email

        FROM announcement_submissions s

        INNER JOIN announcements a
            ON s.announcement_id = a.id

        INNER JOIN users u
            ON s.student_id = u.user_id

        WHERE
            s.id = ?
            AND a.professor_id = ?

        LIMIT 1
    ");

    $stmt->execute([
        $submissionId,
        $professorId
    ]);

    $submission = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {

    sendErrorPage(
        'Database Error',
        'NoteSphere could not retrieve this submission.'
    );
}


if (!$submission) {
    sendErrorPage(
        'Submission Not Found',
        'This submission does not exist or you do not have permission to view it.'
    );
}


// =========================================================
// CHECK FILE PATH
// =========================================================

$storedFilePath = trim((string)($submission['file_path'] ?? ''));

if ($storedFilePath === '') {
    sendErrorPage(
        'No File Attached',
        'This submission does not contain an attached file.'
    );
}


// =========================================================
// PROJECT ROOT
// =========================================================

$projectRoot = realpath(dirname(__DIR__));

if ($projectRoot === false) {
    sendErrorPage(
        'Server Error',
        'NoteSphere could not locate the project directory.'
    );
}


// =========================================================
// NORMALIZE STORED PATH
// =========================================================

$storedFilePath = str_replace('\\', '/', $storedFilePath);


// =========================================================
// RESOLVE ACTUAL FILE PATH
//
// Example DB value:
// ../uploads/submissions/file.docx
//
// Since viewer is inside /professor:
// __DIR__ . '/../uploads/submissions/file.docx'
// =========================================================

$possiblePath = __DIR__ . '/' . $storedFilePath;

$realFilePath = realpath($possiblePath);


// =========================================================
// SECOND RESOLUTION METHOD
//
// Some existing records may contain:
// uploads/submissions/file.docx
//
// So try project root as well.
// =========================================================

if ($realFilePath === false) {

    $cleanStoredPath = ltrim($storedFilePath, './');

    $possiblePath2 = $projectRoot . '/' . $cleanStoredPath;

    $realFilePath = realpath($possiblePath2);
}


// =========================================================
// CHECK FILE EXISTS
// =========================================================

if (
    $realFilePath === false ||
    !is_file($realFilePath) ||
    !is_readable($realFilePath)
) {
    sendErrorPage(
        'File Not Found',
        'The submitted file could not be found on the NoteSphere server.'
    );
}


// =========================================================
// SECURITY:
// FILE MUST STAY INSIDE NOTE SPHERE PROJECT
// =========================================================

$normalizedRoot = strtolower(
    str_replace('\\', '/', rtrim($projectRoot, '/\\'))
);

$normalizedFile = strtolower(
    str_replace('\\', '/', $realFilePath)
);

$rootPrefix = $normalizedRoot . '/';

if (
    $normalizedFile !== $normalizedRoot &&
    strpos($normalizedFile, $rootPrefix) !== 0
) {
    sendErrorPage(
        'Access Denied',
        'This file is outside the NoteSphere storage area.'
    );
}


// =========================================================
// FILE INFORMATION
// =========================================================

$fileName = basename($realFilePath);

$fileExtension = strtolower(
    pathinfo($fileName, PATHINFO_EXTENSION)
);

$fileSize = filesize($realFilePath);

if ($fileSize === false) {
    $fileSize = 0;
}


// =========================================================
// FILE SIZE FORMAT
// =========================================================

function formatFileSize($bytes)
{
    if ($bytes <= 0) {
        return '0 B';
    }

    $units = [
        'B',
        'KB',
        'MB',
        'GB',
        'TB'
    ];

    $index = 0;

    while (
        $bytes >= 1024 &&
        $index < count($units) - 1
    ) {
        $bytes /= 1024;
        $index++;
    }

    return number_format($bytes, 2) . ' ' . $units[$index];
}


// =========================================================
// MIME TYPES
// =========================================================

$mimeTypes = [

    // PDF
    'pdf' => 'application/pdf',

    // Word
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',

    // PowerPoint
    'ppt'  => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',

    // Excel
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',

    // OpenDocument
    'odt' => 'application/vnd.oasis.opendocument.text',
    'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
    'odp' => 'application/vnd.oasis.opendocument.presentation',

    // Images
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'svg'  => 'image/svg+xml',
    'bmp'  => 'image/bmp',
    'ico'  => 'image/x-icon',

    // Text
    'txt'  => 'text/plain',
    'csv'  => 'text/csv',
    'log'  => 'text/plain',

    // Web/code
    'html' => 'text/html',
    'htm'  => 'text/html',
    'css'  => 'text/css',
    'js'   => 'text/javascript',
    'json' => 'application/json',
    'xml'  => 'application/xml',

    // PHP/code
    'php'  => 'text/plain',
    'php3' => 'text/plain',
    'php4' => 'text/plain',
    'php5' => 'text/plain',
    'phtml' => 'text/plain',

    // Programming
    'c'    => 'text/plain',
    'h'    => 'text/plain',
    'cpp'  => 'text/plain',
    'hpp'  => 'text/plain',
    'java' => 'text/plain',
    'py'   => 'text/plain',
    'sql'  => 'text/plain',
    'md'   => 'text/plain',
    'ini'  => 'text/plain',
    'conf' => 'text/plain',
];


// =========================================================
// FILE CATEGORIES
// =========================================================

$imageExtensions = [
    'jpg',
    'jpeg',
    'png',
    'gif',
    'webp',
    'svg',
    'bmp',
    'ico'
];

$textExtensions = [
    'txt',
    'csv',
    'log',
    'html',
    'htm',
    'css',
    'js',
    'json',
    'xml',
    'php',
    'php3',
    'php4',
    'php5',
    'phtml',
    'c',
    'h',
    'cpp',
    'hpp',
    'java',
    'py',
    'sql',
    'md',
    'ini',
    'conf'
];

$officeExtensions = [
    'doc',
    'docx',
    'ppt',
    'pptx',
    'xls',
    'xlsx',
    'odt',
    'ods',
    'odp'
];

$pdfExtensions = [
    'pdf'
];


// =========================================================
// DOWNLOAD MODE
//
// Archives, project files, and unsupported files can always
// be downloaded.
//
// Also allows user to explicitly click Download File.
// =========================================================

if ($isDownload) {

    $mime = $mimeTypes[$fileExtension] ?? 'application/octet-stream';

    // Prevent browser from trying to render/download HTML as a page
    // when user explicitly asks for download.
    header('Content-Type: application/octet-stream');

    header(
        'Content-Disposition: attachment; filename="' .
        str_replace(
            ['"', "\r", "\n"],
            '',
            $fileName
        ) .
        '"'
    );

    header('Content-Length: ' . $fileSize);

    header('X-Content-Type-Options: nosniff');

    header('Cache-Control: private, no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    readfile($realFilePath);
    exit;
}


// =========================================================
// OFFICE DOCUMENT -> PDF CONVERSION
// =========================================================

if (in_array($fileExtension, $officeExtensions, true)) {

    // -----------------------------------------------------
    // Possible LibreOffice locations
    // -----------------------------------------------------

    $libreOfficePaths = [

        'C:\\Program Files\\LibreOffice\\program\\soffice.exe',

        'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',

        'C:\\Program Files\\LibreOffice\\program\\soffice.com',

        'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.com'
    ];

    $soffice = null;

    foreach ($libreOfficePaths as $path) {

        if (is_file($path)) {
            $soffice = $path;
            break;
        }
    }


    // -----------------------------------------------------
    // If LibreOffice is not found
    // -----------------------------------------------------

    if ($soffice === null) {

        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>

            <meta charset="UTF-8">

            <meta
                name="viewport"
                content="width=device-width, initial-scale=1.0"
            >

            <title>Preview Setup Required - NoteSphere</title>

            <link
                href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
                rel="stylesheet"
            >

            <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

            <style>

                * {
                    box-sizing: border-box;
                }

                html,
                body {
                    margin: 0;
                    padding: 0;
                    min-height: 100%;
                }

                body {
                    font-family: "Plus Jakarta Sans", sans-serif;
                    background: #f4f7fb;
                    color: #1f2937;
                    min-height: 100vh;
                }

                .page {
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 24px;
                }

                .card {
                    width: 100%;
                    max-width: 650px;
                    background: #fff;
                    border-radius: 24px;
                    padding: 38px;
                    text-align: center;
                    box-shadow:
                        0 15px 45px rgba(0, 0, 0, 0.10);
                }

                .icon {
                    width: 82px;
                    height: 82px;
                    border-radius: 50%;
                    margin: 0 auto 20px;
                    background: #fff4df;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 38px;
                }

                h1 {
                    margin: 0 0 12px;
                    font-size: 25px;
                    font-weight: 800;
                }

                .filename {
                    display: inline-block;
                    max-width: 100%;
                    padding: 10px 15px;
                    border-radius: 10px;
                    background: #f3f4f6;
                    color: #374151;
                    font-size: 13px;
                    font-weight: 700;
                    word-break: break-word;
                    margin-bottom: 18px;
                }

                .description {
                    color: #6b7280;
                    font-size: 14px;
                    line-height: 1.7;
                    margin-bottom: 25px;
                }

                .buttons {
                    display: flex;
                    gap: 10px;
                    justify-content: center;
                    flex-wrap: wrap;
                }

                .btn {
                    border: 0;
                    text-decoration: none;
                    border-radius: 12px;
                    padding: 12px 18px;
                    font-family: inherit;
                    font-size: 14px;
                    font-weight: 700;
                    cursor: pointer;
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    gap: 8px;
                }

                .btn-primary {
                    background: #3d8bff;
                    color: #fff;
                }

                .btn-secondary {
                    background: #e9eef5;
                    color: #334155;
                }

                body.dark-mode {
                    background: #111827;
                    color: #f3f4f6;
                }

                body.dark-mode .card {
                    background: #1f2937;
                    box-shadow:
                        0 15px 45px rgba(0, 0, 0, 0.35);
                }

                body.dark-mode .filename {
                    background: #111827;
                    color: #e5e7eb;
                }

                body.dark-mode .description {
                    color: #9ca3af;
                }

                body.dark-mode .btn-secondary {
                    background: #374151;
                    color: #e5e7eb;
                }

                @media (max-width: 600px) {

                    .page {
                        padding: 15px;
                    }

                    .card {
                        padding: 28px 20px;
                        border-radius: 18px;
                    }

                    h1 {
                        font-size: 21px;
                    }

                    .btn {
                        width: 100%;
                    }
                }

            </style>

        </head>

        <body>

            <div class="page">

                <div class="card">

                    <div class="icon">
                        📄
                    </div>

                    <h1>
                        Preview Setup Required
                    </h1>

                    <div class="filename">
                        <?= e($fileName) ?>
                    </div>

                    <div class="description">
                        NoteSphere needs
                        <strong>LibreOffice</strong>
                        to preview Word, PowerPoint, Excel, and
                        OpenDocument files inside the browser.
                        <br><br>

                        Install LibreOffice on the computer running XAMPP.
                        <br><br>

                        Expected location:
                        <br>
                        <strong>
                            C:\Program Files\LibreOffice\program\soffice.exe
                        </strong>
                    </div>

                    <div class="buttons">

                        <a
                            href="?submission_id=<?= (int)$submissionId ?>&download=1"
                            class="btn btn-primary"
                        >
                            ⬇ Download File
                        </a>

                        <a
                            href="javascript:history.back()"
                            class="btn btn-secondary"
                        >
                            ← Go Back
                        </a>

                    </div>

                </div>

            </div>

            <script>

                (function () {

                    const savedTheme =
                        localStorage.getItem("notesphere_theme") ||
                        localStorage.getItem("theme") ||
                        localStorage.getItem("colorMode");

                    const darkMode =
                        savedTheme === "dark" ||
                        savedTheme === "dark-mode" ||
                        savedTheme === "Dark";

                    if (darkMode) {
                        document.body.classList.add("dark-mode");
                    }

                })();

            </script>
</body>
        </html>

        <?php
        exit;
    }


    // -----------------------------------------------------
    // Create temporary directory
    // -----------------------------------------------------

    $tempBase = sys_get_temp_dir();

    $tempDir = $tempBase .
        DIRECTORY_SEPARATOR .
        'notesphere_preview_' .
        bin2hex(random_bytes(10));

    if (!mkdir($tempDir, 0700, true)) {

        sendErrorPage(
            'Preview Error',
            'NoteSphere could not create a temporary preview folder.'
        );
    }


    // -----------------------------------------------------
    // Cleanup helper
    // -----------------------------------------------------

    function cleanupPreviewDirectory($directory)
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {

            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory .
                DIRECTORY_SEPARATOR .
                $item;

            if (is_file($path)) {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }


    // -----------------------------------------------------
    // Copy source document into temp directory
    //
    // This helps LibreOffice work with a clean temporary file.
    // -----------------------------------------------------

    $temporaryInput =
        $tempDir .
        DIRECTORY_SEPARATOR .
        $fileName;

    if (!copy($realFilePath, $temporaryInput)) {

        cleanupPreviewDirectory($tempDir);

        sendErrorPage(
            'Preview Error',
            'NoteSphere could not prepare the document for preview.'
        );
    }


    // -----------------------------------------------------
    // LibreOffice command
    // -----------------------------------------------------

    $command =
        escapeshellarg($soffice) .
        ' --headless' .
        ' --convert-to pdf' .
        ' --outdir ' .
        escapeshellarg($tempDir) .
        ' ' .
        escapeshellarg($temporaryInput);


    // -----------------------------------------------------
    // Execute LibreOffice
    // -----------------------------------------------------

    $output = [];
    $returnCode = 0;

    exec(
        $command . ' 2>&1',
        $output,
        $returnCode
    );


    // -----------------------------------------------------
    // Expected PDF
    // -----------------------------------------------------

    $convertedPdf =
        $tempDir .
        DIRECTORY_SEPARATOR .
        pathinfo(
            $fileName,
            PATHINFO_FILENAME
        ) .
        '.pdf';


    // -----------------------------------------------------
    // LibreOffice sometimes creates different case
    // -----------------------------------------------------

    if (!is_file($convertedPdf)) {

        $pdfFiles = glob(
            $tempDir .
            DIRECTORY_SEPARATOR .
            '*.pdf'
        );

        if (!empty($pdfFiles)) {
            $convertedPdf = $pdfFiles[0];
        }
    }


    // -----------------------------------------------------
    // Conversion failed
    // -----------------------------------------------------

    if (
        $returnCode !== 0 ||
        !is_file($convertedPdf) ||
        !is_readable($convertedPdf)
    ) {

        cleanupPreviewDirectory($tempDir);

        ?>
        <!DOCTYPE html>
        <html lang="en">

        <head>

            <meta charset="UTF-8">

            <meta
                name="viewport"
                content="width=device-width, initial-scale=1.0"
            >

            <title>Preview Error - NoteSphere</title>

            <link
                href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
                rel="stylesheet"
            >

            <style>

                * {
                    box-sizing: border-box;
                }

                body {
                    margin: 0;
                    min-height: 100vh;
                    font-family: "Plus Jakarta Sans", sans-serif;
                    background: #f4f7fb;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 20px;
                }

                .card {
                    width: 100%;
                    max-width: 620px;
                    background: #fff;
                    border-radius: 22px;
                    padding: 35px;
                    text-align: center;
                    box-shadow: 0 15px 45px rgba(0,0,0,.10);
                }

                h1 {
                    margin-top: 0;
                    font-size: 24px;
                }

                p {
                    color: #6b7280;
                    line-height: 1.7;
                    font-size: 14px;
                }

                .file {
                    display: inline-block;
                    padding: 10px 14px;
                    background: #f3f4f6;
                    border-radius: 10px;
                    margin: 10px 0 20px;
                    font-weight: 700;
                    font-size: 13px;
                    word-break: break-word;
                }

                .buttons {
                    display: flex;
                    gap: 10px;
                    justify-content: center;
                    flex-wrap: wrap;
                    margin-top: 25px;
                }

                a {
                    text-decoration: none;
                    padding: 12px 18px;
                    border-radius: 12px;
                    font-weight: 700;
                    font-size: 14px;
                }

                .download {
                    background: #3d8bff;
                    color: #fff;
                }

                .back {
                    background: #e5e7eb;
                    color: #374151;
                }

                body.dark-mode {
                    background: #111827;
                }

                body.dark-mode .card {
                    background: #1f2937;
                    color: #f3f4f6;
                }

                body.dark-mode p {
                    color: #9ca3af;
                }

                body.dark-mode .file {
                    background: #111827;
                    color: #e5e7eb;
                }

                body.dark-mode .back {
                    background: #374151;
                    color: #e5e7eb;
                }

            </style>

        </head>

        <body>

            <div class="card">

                <h1>
                    Preview Error
                </h1>

                <div class="file">
                    <?= e($fileName) ?>
                </div>

                <p>
                    LibreOffice was found, but NoteSphere could not
                    convert this document into a previewable PDF.
                </p>

                <div class="buttons">

                    <a
                        href="?submission_id=<?= (int)$submissionId ?>&download=1"
                        class="download"
                    >
                        ⬇ Download File
                    </a>

                    <a
                        href="javascript:history.back()"
                        class="back"
                    >
                        ← Go Back
                    </a>

                </div>

            </div>

            <script>

                (function () {

                    const savedTheme =
                        localStorage.getItem("notesphere_theme") ||
                        localStorage.getItem("theme") ||
                        localStorage.getItem("colorMode");

                    if (
                        savedTheme === "dark" ||
                        savedTheme === "dark-mode" ||
                        savedTheme === "Dark"
                    ) {
                        document.body.classList.add("dark-mode");
                    }

                })();

            </script>
</body>
        </html>

        <?php
        exit;
    }


    // -----------------------------------------------------
    // Serve converted PDF
    // -----------------------------------------------------

    $pdfSize = filesize($convertedPdf);

    if ($pdfSize === false) {
        $pdfSize = 0;
    }

    header('Content-Type: application/pdf');

    header(
        'Content-Disposition: inline; filename="' .
        str_replace(
            ['"', "\r", "\n"],
            '',
            pathinfo($fileName, PATHINFO_FILENAME) . '.pdf'
        ) .
        '"'
    );

    header('Content-Length: ' . $pdfSize);

    header('X-Content-Type-Options: nosniff');

    header(
        'Cache-Control: private, no-store, no-cache, must-revalidate'
    );

    header('Pragma: no-cache');

    readfile($convertedPdf);

    // Cleanup after read.
    cleanupPreviewDirectory($tempDir);

    exit;
}


// =========================================================
// PDF DIRECT PREVIEW
// =========================================================

if (in_array($fileExtension, $pdfExtensions, true)) {

    header('Content-Type: application/pdf');

    header(
        'Content-Disposition: inline; filename="' .
        str_replace(
            ['"', "\r", "\n"],
            '',
            $fileName
        ) .
        '"'
    );

    header('Content-Length: ' . $fileSize);

    header('X-Content-Type-Options: nosniff');

    header(
        'Cache-Control: private, no-store, no-cache, must-revalidate'
    );

    header('Pragma: no-cache');

    readfile($realFilePath);

    exit;
}


// =========================================================
// IMAGE DIRECT PREVIEW
// =========================================================

if (in_array($fileExtension, $imageExtensions, true)) {

    $mime = $mimeTypes[$fileExtension] ?? 'application/octet-stream';

    header('Content-Type: ' . $mime);

    header(
        'Content-Disposition: inline; filename="' .
        str_replace(
            ['"', "\r", "\n"],
            '',
            $fileName
        ) .
        '"'
    );

    header('Content-Length: ' . $fileSize);

    header('X-Content-Type-Options: nosniff');

    header(
        'Cache-Control: private, no-store, no-cache, must-revalidate'
    );

    header('Pragma: no-cache');

    readfile($realFilePath);

    exit;
}


// =========================================================
// TEXT / CODE FILE PREVIEW
// =========================================================

if (in_array($fileExtension, $textExtensions, true)) {

    $content = @file_get_contents($realFilePath);

    if ($content === false) {

        sendErrorPage(
            'Preview Error',
            'NoteSphere could not read this text file.'
        );
    }

    ?>
    <!DOCTYPE html>
    <html lang="en">

    <head>

        <meta charset="UTF-8">

        <meta
            name="viewport"
            content="width=device-width, initial-scale=1.0"
        >

        <title>
            <?= e($fileName) ?> - NoteSphere
        </title>

        <link
            href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
            rel="stylesheet"
        >

        <style>

            * {
                box-sizing: border-box;
            }

            html,
            body {
                margin: 0;
                padding: 0;
                min-height: 100%;
            }

            body {
                font-family: "Plus Jakarta Sans", sans-serif;
                background: #f4f7fb;
                color: #1f2937;
            }

            .topbar {
                position: sticky;
                top: 0;
                z-index: 10;

                min-height: 70px;
                padding: 12px 18px;

                background: rgba(255,255,255,.95);
                border-bottom: 1px solid #e5e7eb;

                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
            }

            .file-info {
                min-width: 0;
            }

            .file-name {
                font-size: 15px;
                font-weight: 800;
                word-break: break-word;
            }

            .file-meta {
                margin-top: 3px;
                color: #6b7280;
                font-size: 12px;
            }

            .actions {
                display: flex;
                align-items: center;
                gap: 8px;
                flex-shrink: 0;
            }

            .btn {
                border: 0;
                text-decoration: none;
                border-radius: 10px;
                padding: 10px 14px;
                font-family: inherit;
                font-size: 13px;
                font-weight: 700;
                cursor: pointer;
                white-space: nowrap;
            }

            .btn-back {
                background: #e9eef5;
                color: #334155;
            }

            .btn-download {
                background: #3d8bff;
                color: #fff;
            }

            .viewer {
                padding: 20px;
            }

            pre {
                margin: 0;
                width: 100%;
                min-height: calc(100vh - 110px);

                background: #111827;
                color: #e5e7eb;

                border-radius: 16px;
                padding: 22px;

                overflow: auto;

                font-family:
                    Consolas,
                    "Courier New",
                    monospace;

                font-size: 13px;
                line-height: 1.7;

                white-space: pre-wrap;
                word-break: break-word;
            }

            body.dark-mode {
                background: #111827;
                color: #f3f4f6;
            }

            body.dark-mode .topbar {
                background: rgba(31,41,55,.96);
                border-bottom-color: #374151;
            }

            body.dark-mode .file-meta {
                color: #9ca3af;
            }

            body.dark-mode .btn-back {
                background: #374151;
                color: #e5e7eb;
            }

            @media (max-width: 700px) {

                .topbar {
                    align-items: flex-start;
                    flex-direction: column;
                }

                .actions {
                    width: 100%;
                }

                .btn {
                    flex: 1;
                    text-align: center;
                }

                .viewer {
                    padding: 10px;
                }

                pre {
                    border-radius: 12px;
                    padding: 15px;
                    font-size: 12px;
                }
            }

        </style>

    </head>

    <body>

        <div class="topbar">

            <div class="file-info">

                <div class="file-name">
                    <?= e($fileName) ?>
                </div>

                <div class="file-meta">
                    <?= e(strtoupper($fileExtension)) ?>
                    •
                    <?= e(formatFileSize($fileSize)) ?>
                </div>

            </div>

            <div class="actions">

                <a
                    href="javascript:history.back()"
                    class="btn btn-back"
                    title="Close / Back"
                    aria-label="Back"
                    style="font-weight:800;"
                >
                    ✕
                </a>

                <a
                    href="?submission_id=<?= (int)$submissionId ?>&download=1"
                    class="btn btn-download"
                >
                    ⬇ Download
                </a>

            </div>

        </div>

        <div class="viewer">

            <pre><?= e($content) ?></pre>

        </div>

        <script>

            (function () {

                const savedTheme =
                    localStorage.getItem("notesphere_theme") ||
                    localStorage.getItem("theme") ||
                    localStorage.getItem("colorMode");

                if (
                    savedTheme === "dark" ||
                    savedTheme === "dark-mode" ||
                    savedTheme === "Dark"
                ) {
                    document.body.classList.add("dark-mode");
                }

            })();

        </script>
</body>
    </html>

    <?php
    exit;
}


// =========================================================
// UNSUPPORTED / ARCHIVE / PROJECT FILE
// =========================================================
//
// These files should DOWNLOAD instead of being forced into
// a browser preview.
//
// Examples:
// ZIP
// RAR
// 7Z
// APK
// EXE
// ISO
// PSD
// AI
// project folders/files
// unknown binary files
// =========================================================

$archiveExtensions = [
    'zip',
    'rar',
    '7z',
    'tar',
    'gz',
    'bz2',
    'xz'
];

$projectExtensions = [
    'apk',
    'exe',
    'msi',
    'iso',
    'dmg',
    'psd',
    'ai',
    'sketch',
    'fig',
    'blend',
    'unitypackage',
    'jar',
    'war',
    'class',
    'dll'
];


// =========================================================
// DOWNLOAD-ONLY PAGE
// =========================================================

$isArchive =
    in_array(
        $fileExtension,
        $archiveExtensions,
        true
    );

$isProject =
    in_array(
        $fileExtension,
        $projectExtensions,
        true
    );

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        File Preview - NoteSphere
    </title>

    <link
        href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>

        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            min-height: 100%;
        }

        body {
            font-family: "Plus Jakarta Sans", sans-serif;
            background: #f4f7fb;
            color: #1f2937;
            min-height: 100vh;
        }

        .page {
            min-height: 100vh;

            display: flex;
            align-items: center;
            justify-content: center;

            padding: 24px;
        }

        .card {
            width: 100%;
            max-width: 650px;

            background: #ffffff;

            border-radius: 24px;

            padding: 40px 32px;

            text-align: center;

            box-shadow:
                0 15px 50px rgba(0, 0, 0, .10);
        }

        .file-icon {
            width: 88px;
            height: 88px;

            margin: 0 auto 20px;

            border-radius: 22px;

            display: flex;
            align-items: center;
            justify-content: center;

            background: #eef5ff;

            font-size: 42px;
        }

        h1 {
            margin: 0 0 12px;

            font-size: 25px;
            font-weight: 800;
        }

        .filename {
            display: inline-block;

            max-width: 100%;

            padding: 10px 14px;

            border-radius: 10px;

            background: #f3f4f6;

            font-size: 13px;
            font-weight: 700;

            word-break: break-word;

            margin-bottom: 16px;
        }

        .description {
            color: #6b7280;

            font-size: 14px;

            line-height: 1.7;

            margin-bottom: 8px;
        }

        .file-size {
            color: #9ca3af;

            font-size: 12px;

            margin-bottom: 26px;
        }

        .buttons {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;

            padding: 12px 18px;

            border-radius: 12px;

            text-decoration: none;

            font-family: inherit;

            font-size: 14px;
            font-weight: 700;
        }

        .btn-download {
            background: #3d8bff;
            color: #fff;
        }

        .btn-back {
            background: #e9eef5;
            color: #334155;
        }

        body.dark-mode {
            background: #111827;
            color: #f3f4f6;
        }

        body.dark-mode .card {
            background: #1f2937;

            box-shadow:
                0 15px 50px rgba(0, 0, 0, .35);
        }

        body.dark-mode .file-icon {
            background: #111827;
        }

        body.dark-mode .filename {
            background: #111827;
            color: #e5e7eb;
        }

        body.dark-mode .description {
            color: #9ca3af;
        }

        body.dark-mode .file-size {
            color: #6b7280;
        }

        body.dark-mode .btn-back {
            background: #374151;
            color: #e5e7eb;
        }

        @media (max-width: 600px) {

            .page {
                padding: 15px;
            }

            .card {
                padding: 30px 20px;
                border-radius: 18px;
            }

            .file-icon {
                width: 72px;
                height: 72px;
                font-size: 34px;
            }

            h1 {
                font-size: 21px;
            }

            .buttons {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }
        }

    </style>

</head>

<body>

    <div class="page">

        <div class="card">

            <div class="file-icon">

                <?php if ($isArchive): ?>

                    🗜️

                <?php elseif ($isProject): ?>

                    💻

                <?php else: ?>

                    📎

                <?php endif; ?>

            </div>

            <h1>
                Preview Not Available
            </h1>

            <div class="filename">
                <?= e($fileName) ?>
            </div>

            <div class="description">

                <?php if ($isArchive): ?>

                    This is an archive file.
                    It cannot be previewed directly in the browser.
                    Please download it to open its contents.

                <?php elseif ($isProject): ?>

                    This file is a project or binary file.
                    NoteSphere will download it instead of trying
                    to display it inside the browser.

                <?php else: ?>

                    This file type cannot be previewed directly
                    inside the browser.

                <?php endif; ?>

            </div>

            <div class="file-size">

                File size:
                <?= e(formatFileSize($fileSize)) ?>

            </div>

            <div class="buttons">

                <a
                    href="?submission_id=<?= (int)$submissionId ?>&download=1"
                    class="btn btn-download"
                >
                    ⬇ Download File
                </a>

                <a
                    href="javascript:history.back()"
                    class="btn btn-back"
                >
                    ← Go Back
                </a>

            </div>

        </div>

    </div>


    <script>

        // =================================================
        // AUTOMATIC NOTESPHERE THEME
        // =================================================

        (function () {

            const savedTheme =
                localStorage.getItem("notesphere_theme") ||
                localStorage.getItem("theme") ||
                localStorage.getItem("colorMode");

            if (
                savedTheme === "dark" ||
                savedTheme === "dark-mode" ||
                savedTheme === "Dark"
            ) {

                document.body.classList.add("dark-mode");

            } else if (
                savedTheme === "light" ||
                savedTheme === "light-mode" ||
                savedTheme === "Light"
            ) {

                document.body.classList.remove("dark-mode");

            } else {

                // Follow computer/browser preference
                if (
                    window.matchMedia &&
                    window.matchMedia(
                        "(prefers-color-scheme: dark)"
                    ).matches
                ) {
                    document.body.classList.add("dark-mode");
                }

            }

        })();

    </script>
</body>
</html>