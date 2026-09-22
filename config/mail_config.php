<?php

    require_once __DIR__ . '/env_loader.php';

    define('MAIL_SMTP_HOST', getenv('MAIL_SMTP_HOST') ?: 'smtp.gmail.com');
    define('MAIL_SMTP_PORT', (int) (getenv('MAIL_SMTP_PORT') ?: 587));
    define('MAIL_SMTP_USERNAME', getenv('MAIL_SMTP_USERNAME') ?: '');
    define('MAIL_SMTP_PASSWORD', getenv('MAIL_SMTP_PASSWORD') ?: '');
    define('MAIL_FROM_ADDRESS', MAIL_SMTP_USERNAME);
    define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: 'Priority Handling Fleet');
