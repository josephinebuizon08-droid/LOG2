<?php
    require_once __DIR__ . '/env_loader.php';

    define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: '');
    define('GEMINI_MODEL', getenv('GEMINI_MODEL') ?: 'gemini-3.5-flash');