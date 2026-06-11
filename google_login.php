<?php
require_once 'google_config.php';

// Build Google OAuth URL
$auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
    'client_id' => GOOGLE_CLIENT_ID,
    'redirect_uri' => GOOGLE_REDIRECT_URI,
    'response_type' => 'code',
    'scope' => GOOGLE_CALENDAR_SCOPE,
    'access_type' => 'offline',
    'prompt' => 'consent'
]);

header('Location: ' . $auth_url);
?>