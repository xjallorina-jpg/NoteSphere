<?php
/**
 * Public Cloudinary configuration for the browser upload helper.
 * Cloud name and unsigned upload preset are public by design.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../includes/cloudinary.php';

echo json_encode(ns_cloudinary_client_config(), JSON_UNESCAPED_SLASHES);
