<?php
require_once __DIR__ . '/app_config.php';

// Google API Configuration
define('GOOGLE_CLIENT_ID', app_env('GOOGLE_CLIENT_ID', 'YOUR_CLIENT_ID_HERE'));
define('GOOGLE_CLIENT_SECRET', app_env('GOOGLE_CLIENT_SECRET', 'YOUR_CLIENT_SECRET_HERE'));
define('GOOGLE_REDIRECT_URI', app_env('GOOGLE_REDIRECT_URI', 'http://localhost/studyplanner/google_callback.php'));

// No need to edit below
require_once 'db_config.php';

// Google Calendar API scope
define('GOOGLE_CALENDAR_SCOPE', 'https://www.googleapis.com/auth/calendar');
?>
