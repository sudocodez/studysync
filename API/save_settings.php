<?php
require_once __DIR__ . '/../db_config.php';
verify_csrf();

$uid = $_SESSION['user_id'];
$allowed_keys = ['alarm_enabled', 'alarm_lead_minutes'];

$saved = 0;
foreach ($allowed_keys as $key) {
    if (!isset($_POST[$key])) continue;
    $value = $_POST[$key];

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_settings WHERE user_id = ? AND setting_key = ?");
    $stmt->execute([$uid, $key]);
    if ((int)$stmt->fetchColumn() > 0) {
        $stmt = $pdo->prepare("UPDATE user_settings SET setting_value = ? WHERE user_id = ? AND setting_key = ?");
        $stmt->execute([$value, $uid, $key]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO user_settings (user_id, setting_key, setting_value) VALUES (?, ?, ?)");
        $stmt->execute([$uid, $key, $value]);
    }
    $saved++;
}

echo json_encode(['success' => $saved > 0]);
