<?php
require_once 'google_config.php';

if(isset($_GET['code'])) {
    $code = $_GET['code'];
    
    // Exchange code for tokens
    $token_url = 'https://oauth2.googleapis.com/token';
    $data = [
        'code' => $code,
        'client_id' => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri' => GOOGLE_REDIRECT_URI,
        'grant_type' => 'authorization_code'
    ];
    
    $ch = curl_init($token_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    
    $tokens = json_decode($response, true);
    
    if(isset($tokens['access_token'])) {
        // Save tokens to database
        $stmt = $pdo->prepare("UPDATE users SET google_calendar_token = ? WHERE id = ?");
        $stmt->execute([json_encode($tokens), $_SESSION['user_id']]);
        
        header('Location: dashboard.php?calendar=connected');
    }
}
?>