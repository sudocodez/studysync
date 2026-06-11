<?php
require_once 'db_config.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: dashboard.php');
    exit();
}

// Get the plan entry
$stmt = $pdo->prepare("SELECT * FROM study_plan WHERE id = ? AND user_id = ?");
$stmt->execute([$id, $_SESSION['user_id']]);
$plan = $stmt->fetch();

if (!$plan) {
    header('Location: dashboard.php');
    exit();
}

// Mark as missed
$pdo->prepare("UPDATE study_plan SET status = 'missed' WHERE id = ? AND user_id = ?")->execute([$id, $_SESSION['user_id']]);

// Find next available slot for rescheduling
$today = date('Y-m-d');
$stmt = $pdo->prepare("SELECT * FROM available_time WHERE user_id = ? ORDER BY day_of_week, start_time");
$stmt->execute([$_SESSION['user_id']]);
$avail_slots = $stmt->fetchAll();

// Look ahead up to 14 days for the next available slot
$rescheduled = false;
for ($offset = 1; $offset <= 14; $offset++) {
    $date = date('Y-m-d', strtotime("$today + $offset days"));
    $dow = (int)date('w', strtotime($date));

    foreach ($avail_slots as $slot) {
        if ((int)$slot['day_of_week'] === $dow) {
            $start = $slot['start_time'];
            $end = $slot['end_time'];
            $stmt = $pdo->prepare("INSERT INTO study_plan (user_id, task_id, task_title, plan_date, start_time, end_time, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
            $stmt->execute([$_SESSION['user_id'], $plan['task_id'], $plan['task_title'], $date, $start, $end]);
            $rescheduled = true;
            break 2;
        }
    }
}

if (!$rescheduled) {
    // Fallback: schedule for tomorrow 9AM
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $pdo->prepare("INSERT INTO study_plan (user_id, task_id, task_title, plan_date, start_time, end_time, status) VALUES (?, ?, ?, ?, '09:00', '10:00', 'pending')")
        ->execute([$_SESSION['user_id'], $plan['task_id'], $plan['task_title'], $tomorrow]);
}

header('Location: dashboard.php');
?>