<?php
/**
 * NoteSphere Gmail SMTP Configuration
 *
 * Credentials are read from environment variables so they are never committed:
 *   MAIL_USERNAME      - full Gmail address
 *   MAIL_APP_PASSWORD  - Gmail App Password (remove spaces)
 *
 * For local development put them in the project's .env file.
 * For Vercel add them in Project Settings -> Environment Variables.
 */

require_once __DIR__ . '/env.php';

return [
    'username' => (string) ns_env('MAIL_USERNAME', ''),

    // Use a Gmail App Password, not your normal Gmail password.
    // Remove spaces from the App Password.
    'app_password' => (string) ns_env('MAIL_APP_PASSWORD', ''),
];
