<?php
// Serve an attachment inline for browser-native preview.
// Used by the Preview button; Download button uses direct file link.
require_once __DIR__ . '/../includes/cloudinary.php';

$path = isset($_GET['file']) ? trim($_GET['file']) : '';
if ($path === '') { http_response_code(400); exit('No file'); }

// Cloudinary assets are already browser-viewable; redirect to the validated
// HTTPS URL rather than trying to read a local file.
if (ns_is_remote_file($path)) {
    $remote = ns_validate_cloudinary_url($path);
    if ($remote === null) { http_response_code(404); exit('Not found'); }
    header('X-Content-Type-Options: nosniff');
    header('Location: ' . $remote, true, 302);
    exit();
}

$path = str_replace('\\','/',$path);
$path = preg_replace('~^(?:\./|(?:\.\./))+~','',$path);
$full = __DIR__ . '/../' . ltrim($path,'/');
$real = realpath($full);
$base = realpath(__DIR__ . '/../uploads');
if ($real === false || $base === false || strpos($real, $base) !== 0 || !is_file($real)) {
    http_response_code(404); exit('Not found');
}
$ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
$mime = [
  'jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','bmp'=>'image/bmp','svg'=>'image/svg+xml',
  'pdf'=>'application/pdf','mp4'=>'video/mp4','webm'=>'video/webm','m4v'=>'video/mp4','mov'=>'video/quicktime',
  'mp3'=>'audio/mpeg','wav'=>'audio/wav','ogg'=>'audio/ogg','m4a'=>'audio/mp4',
  'txt'=>'text/plain; charset=utf-8','log'=>'text/plain; charset=utf-8','md'=>'text/plain; charset=utf-8','json'=>'application/json','xml'=>'text/xml','csv'=>'text/csv',
];
$ct = $mime[$ext] ?? 'application/octet-stream';
// For formats requiring conversion (docx/xlsx/pptx), force download instead of corrupt preview.
if (in_array($ext, ['docx','xlsx','xls','pptx','zip','rar'], true)) {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="'.basename($real).'"');
    readfile($real);
    exit();
}
header('Content-Type: '.$ct);
header('Content-Disposition: inline; filename="'.basename($real).'"');
header('Content-Length: '.filesize($real));
readfile($real);
exit();

