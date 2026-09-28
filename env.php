<?php

/**
 * Lightweight Environment File (.env) Loader
 * Personal Expense Tracker
 */

if (!function_exists('load_env')) {
    function load_env(string $path = __DIR__ . '/.env'): array
    {
        static $loaded = null;
        if ($loaded !== null) {
            return $loaded;
        }

        $loaded = [];
        if (!file_exists($path)) {
            return $loaded;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return $loaded;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip comments and empty lines
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }

            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                // Strip matching surrounding quotes
                $len = strlen($value);
                if ($len >= 2) {
                    $first = $value[0];
                    $last = $value[$len - 1];
                    if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                        $value = substr($value, 1, -1);
                    }
                }

                $loaded[$key] = $value;
                if (!isset($_ENV[$key])) {
                    $_ENV[$key] = $value;
                }
                if (!isset($_SERVER[$key])) {
                    $_SERVER[$key] = $value;
                }
                putenv("{$key}={$value}");
            }
        }

        return $loaded;
    }
}

if (!function_exists('env')) {
    function env(string $key, $default = null)
    {
        load_env();
        $val = getenv($key);
        if ($val === false) {
            $val = $_ENV[$key] ?? $_SERVER[$key] ?? $default;
        }

        if ($val === 'true' || $val === '(true)') {
            return true;
        }
        if ($val === 'false' || $val === '(false)') {
            return false;
        }
        if ($val === 'null' || $val === '(null)') {
            return null;
        }

        return $val;
    }
}

// Automatically load on include
load_env();
