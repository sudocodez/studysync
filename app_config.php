<?php
// app_config.php - shared application configuration

function loadEnvFile($path) {
    if(!file_exists($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach($lines as $line) {
        $line = trim($line);
        if($line === '' || substr($line, 0, 1) === '#' || strpos($line, '=') === false) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");

        if($key !== '' && getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

loadEnvFile(__DIR__ . '/.env');

function app_env($key, $default = '') {
    $value = getenv($key);
    return $value === false ? $default : $value;
}

$app_timezone = app_env('APP_TIMEZONE', date_default_timezone_get() ?: 'UTC');
if(in_array($app_timezone, timezone_identifiers_list(), true)) {
    date_default_timezone_set($app_timezone);
}
?>
