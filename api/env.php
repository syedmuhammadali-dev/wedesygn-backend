<?php
declare(strict_types=1);

function loadEnvironmentFile(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $paths = [dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env', dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env'];
    foreach ($paths as $path) {
        if (!is_file($path) || !is_readable($path)) {
            continue;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            continue;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = substr($line, 7);
            }

            $separator = strpos($line, '=');
            if ($separator === false) {
                continue;
            }

            $key = trim(substr($line, 0, $separator));
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) || getenv($key) !== false) {
                continue;
            }

            $value = trim(substr($line, $separator + 1));
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[strlen($value) - 1] === $value[0]) {
                $quote = $value[0];
                $value = substr($value, 1, -1);
                if ($quote === '"') {
                    $value = stripcslashes($value);
                }
            } else {
                $value = preg_replace('/\s+#.*$/', '', $value) ?? $value;
                $value = rtrim($value);
            }

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        return;
    }
}

loadEnvironmentFile();