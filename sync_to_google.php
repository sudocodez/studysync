<?php
require_once 'google_config.php';

function refreshGoogleToken($tokens) {
    if (!isset($tokens['refresh_token'])) return $tokens;
    if (isset($tokens['expires_at']) && $tokens['expires_at'] > time()) return $tokens;

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'client_id' => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'refresh_token' => $tokens['refresh_token'],
        'grant_type' => 'refresh_token'
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $new = json_decode($response, true);
    if (!isset($new['access_token'])) return $tokens;

    $tokens['access_token'] = $new['access_token'];
    $tokens['expires_at'] = time() + ($new['expires_in'] ?? 3600);
    if (isset($new['refresh_token'])) $tokens['refresh_token'] = $new['refresh_token'];

    global $pdo;
    $pdo->prepare("UPDATE users SET google_calendar_token = ? WHERE id = ?")
        ->execute([json_encode($tokens), $_SESSION['user_id'] ?? 0]);

    return $tokens;
}

function syncPlanToGoogle($user_id, $plan_id, $title, $start_datetime, $end_datetime) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT google_calendar_token FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    
    if(!$user || !$user['google_calendar_token']) return false;
    
    $tokens = json_decode($user['google_calendar_token'], true);
    $tokens = refreshGoogleToken($tokens);
    $access_token = $tokens['access_token'] ?? null;
    
    if(!$access_token) return false;
    
    $timezone = date_default_timezone_get() ?: 'UTC';

    // Create event in Google Calendar
    $event = [
        'summary' => $title,
        'start' => ['dateTime' => $start_datetime, 'timeZone' => $timezone],
        'end' => ['dateTime' => $end_datetime, 'timeZone' => $timezone],
        'reminders' => [
            'useDefault' => false,
            'overrides' => [
                ['method' => 'popup', 'minutes' => 15],
                ['method' => 'popup', 'minutes' => 5]
            ]
        ]
    ];
    
    $ch = curl_init('https://www.googleapis.com/calendar/v3/calendars/primary/events');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($event));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    if(isset($result['id'])) {
        // Save Google event ID
        $pdo->prepare("UPDATE study_plan SET google_event_id = ? WHERE id = ?")
            ->execute([$result['id'], $plan_id]);
        return true;
    }
    
    return false;
}

// Call this when generating plan
function syncAllPlansToGoogle($user_id) {
    global $pdo;

    $timezone = date_default_timezone_get() ?: 'UTC';
    $tz = new DateTimeZone($timezone);
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("SELECT * FROM study_plan WHERE user_id = ? AND google_event_id IS NULL AND plan_date >= ?");
    $stmt->execute([$user_id, $today]);
    $plans = $stmt->fetchAll();
    
    foreach($plans as $plan) {
        $start = new DateTimeImmutable($plan['plan_date'] . ' ' . $plan['start_time'], $tz);
        $end = new DateTimeImmutable($plan['plan_date'] . ' ' . $plan['end_time'], $tz);
        $start_datetime = $start->format(DATE_RFC3339);
        $end_datetime = $end->format(DATE_RFC3339);
        syncPlanToGoogle($user_id, $plan['id'], $plan['task_title'], $start_datetime, $end_datetime);
    }
}
?>
