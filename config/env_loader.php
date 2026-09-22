<?php

if (!function_exists('ftms_load_env')) {
    function ftms_load_env(string $path): void
    {
        static $loaded = false;
        if ($loaded) {
            return; // only parse the file once per request
        }
        $loaded = true;

        if (!is_file($path) || !is_readable($path)) {
            return; // no .env file — fine, rely on real env vars
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip comments and blank lines
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Must contain an "=" to be a valid KEY=VALUE line
            if (!str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $name  = trim($name);
            $value = trim($value);

            // Strip matching surrounding quotes, if present
            if (strlen($value) >= 2) {
                $first = $value[0];
                $last  = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            if ($name === '') {
                continue;
            }

            // Don't override a real environment variable that's already
            // set (e.g. injected by your hosting platform).
            if (getenv($name) === false && !isset($_ENV[$name])) {
                putenv("$name=$value");
                $_ENV[$name]    = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
}

// Load the .env file that sits next to this config/ folder (project root).
ftms_load_env(__DIR__ . '/../.env');
