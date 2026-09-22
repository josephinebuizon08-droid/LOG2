<?php

    require_once __DIR__ . '/env_loader.php';
    
    define('FTMS_API_KEY', getenv('FTMS_API_KEY') ?: '');

    if (FTMS_API_KEY === '') {
        error_log('WARNING: FTMS_API_KEY environment variable is not set.');
    }
