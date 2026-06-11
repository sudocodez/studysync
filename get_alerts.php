<?php
require_once 'db_config.php';

header('Content-Type: application/json');

// Get current time
$current_time = date('H:i:s');
$current_date = date('Y-m-d');
$current_time_plus_5 = date('H:i:s', strtotime($current_time) + 300);

// Get all pending study plans that are starting now or in the next 5 minutes
$stmt = $pdo->prepare("
    SELECT sp.*, t.title as task_name 
    FROM study_plan sp 
    LEFT JOIN tasks t ON sp.task_id = t.id 
    WHERE sp.user_id = ? 
    AND sp.plan_date = ? 
    AND sp.status = 'pending'
    AND sp.alerted = 0
    AND sp.start_time <= ?
    ORDER BY sp.start_time ASC
");

$stmt->execute([$_SESSION['user_id'], $current_date, $current_time_plus_5]);
$alerts = $stmt->fetchAll();

$alerts_data = [];

foreach($alerts as $alert) {
    // Mark as alerted so it doesn't trigger again
    $update = $pdo->prepare("UPDATE study_plan SET alerted = 1 WHERE id = ? AND user_id = ?");
    $update->execute([$alert['id'], $_SESSION['user_id']]);
    
    // Determine alert type
    $alert_type = 'upcoming';
    $minutes_diff = (strtotime($alert['start_time']) - strtotime($current_time)) / 60;
    
    if($minutes_diff <= 0) {
        $alert_type = 'now';
        $message = "🔔 TIME'S UP! It's time for: " . $alert['task_title'];
    } elseif($minutes_diff <= 5) {
        $message = "⏰ Reminder: " . $alert['task_title'] . " starts in " . round($minutes_diff) . " minutes at " . $alert['start_time'];
    } else {
        $message = "📚 Up next: " . $alert['task_title'] . " at " . $alert['start_time'];
    }
    
    $alerts_data[] = [
        'id' => $alert['id'],
        'task_title' => $alert['task_title'],
        'start_time' => $alert['start_time'],
        'message' => $message,
        'type' => $alert_type
    ];
}

// Get tasks approaching deadline (1-2 days before due)
$day_after_tomorrow = date('Y-m-d', strtotime('+2 days'));
$stmt = $pdo->prepare("
    SELECT *, (julianday(due_date) - julianday(?)) as days_left
    FROM tasks 
    WHERE user_id = ? 
    AND status = 'pending' 
    AND due_date >= ? AND due_date <= ?
    AND alerted = 0
    ORDER BY due_date ASC
");
$stmt->execute([$current_date, $_SESSION['user_id'], $current_date, $day_after_tomorrow]);
$deadline_tasks = $stmt->fetchAll();

foreach ($deadline_tasks as $task) {
    $update = $pdo->prepare("UPDATE tasks SET alerted = 1 WHERE id = ? AND user_id = ?");
    $update->execute([$task['id'], $_SESSION['user_id']]);

    $days_left = round($task['days_left']);
    $hours_left = round((strtotime($task['due_date']) - time()) / 3600);
    $risk = $days_left <= 0 ? 'CRITICAL' : ($days_left <= 1 ? 'HIGH RISK' : 'RISK');

    $alerts_data[] = [
        'id' => $task['id'],
        'task_title' => $task['title'],
        'message' => "⚠️ {$risk}: '{$task['title']}' due in {$days_left}d {$hours_left}h — you need to allocate {$task['estimated_hours']}h of study time!",
        'type' => 'deadline'
    ];
}

// Also get overdue tasks
$stmt = $pdo->prepare("
    SELECT * FROM tasks 
    WHERE user_id = ? 
    AND status = 'pending' 
    AND due_date < ?
    AND alerted = 0
");
$stmt->execute([$_SESSION['user_id'], $current_date]);
$overdue_tasks = $stmt->fetchAll();

foreach($overdue_tasks as $task) {
    $update = $pdo->prepare("UPDATE tasks SET alerted = 1 WHERE id = ? AND user_id = ?");
    $update->execute([$task['id'], $_SESSION['user_id']]);
    
    $days_overdue = (strtotime($current_date) - strtotime($task['due_date'])) / 86400;
    
    $alerts_data[] = [
        'id' => $task['id'],
        'task_title' => $task['title'],
        'message' => "🔴 OVERDUE by " . round($days_overdue) . "d: '{$task['title']}' — prioritize this now!",
        'type' => 'overdue'
    ];
}

// Return alerts as JSON
echo json_encode([
    'success' => true,
    'alerts' => $alerts_data,
    'alert_count' => count($alerts_data),
    'current_time' => $current_time
]);
?>
