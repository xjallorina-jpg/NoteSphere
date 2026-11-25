<?php
/**
 * NoteSphere Gmail SMTP Configuration - example
 *
 * The real includes/mail_config.php now reads environment variables.
 * Set these locally in .env or in the Vercel project settings:
 *
 *   MAIL_USERNAME=your-email@gmail.com
 *   MAIL_APP_PASSWORD=your-gmail-app-password
 */

require_once __DIR__ . '/env.php';

return [
    'username' => (string) ns_env('MAIL_USERNAME', 'your-email@gmail.com'),
    'app_password' => (string) ns_env('MAIL_APP_PASSWORD', 'your-gmail-app-password'),
];
