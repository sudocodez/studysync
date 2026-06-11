<?php
require_once 'db_config.php';

header('Content-Type: application/json');

$current_time = date('H:i:s');
$current_date = date('Y-m-d');

// Simple check for today's pending plans
$stmt = $pdo->prepare("
    SELECT task_title, start_time, end_time 
    FROM study_plan 
    WHERE user_id = ? 
    AND plan_date = ? 
    AND status = 'pending'
    AND start_time >= ?
    ORDER BY start_time ASC 
    LIMIT 3
");

$stmt->execute([$_SESSION['user_id'], $current_date, $current_time]);
$upcoming = $stmt->fetchAll();

echo json_encode([
    'has_alerts' => count($upcoming) > 0,
    'upcoming_tasks' => $upcoming,
    'message' => count($upcoming) > 0 ? "You have " . count($upcoming) . " study session(s) remaining today" : "No more study sessions today!"
]);
?>
