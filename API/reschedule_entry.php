<?php
require_once __DIR__ . '/../db_config.php';
header('Content-Type: application/json');

$plan_id = (int)($_POST['plan_id'] ?? 0);
$action = $_POST['action'] ?? ''; // 'miss' or 'postpone'

if ($plan_id <= 0 || !in_array($action, ['miss', 'postpone'], true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit();
}

$status = $action === 'miss' ? 'missed' : 'postponed';

// Get the plan entry
$stmt = $pdo->prepare("SELECT * FROM study_plan WHERE id = ? AND user_id = ?");
$stmt->execute([$plan_id, $_SESSION['user_id']]);
$plan = $stmt->fetch();

if (!$plan) {
    echo json_encode(['success' => false, 'error' => 'Plan entry not found']);
    exit();
}

// Mark current entry
$pdo->prepare("UPDATE study_plan SET status = ? WHERE id = ? AND user_id = ?")->execute([$status, $plan_id, $_SESSION['user_id']]);

// Reschedule to next available slot (skip today, up to 14 days ahead)
$today = date('Y-m-d');
$stmt = $pdo->prepare("SELECT * FROM available_time WHERE user_id = ? ORDER BY day_of_week, start_time");
$stmt->execute([$_SESSION['user_id']]);
$avail_slots = $stmt->fetchAll();

$rescheduled = false;
for ($offset = 1; $offset <= 14; $offset++) {
    $date = date('Y-m-d', strtotime("$today + $offset days"));
    $dow = (int)date('w', strtotime($date));

    foreach ($avail_slots as $slot) {
        if ((int)$slot['day_of_week'] !== $dow) continue;
        if ($slot['specific_date'] && $slot['specific_date'] !== $date) continue;

        // Check if slot is already occupied
        $check = $pdo->prepare("SELECT id FROM study_plan WHERE user_id = ? AND plan_date = ? AND start_time = ? AND status = 'pending'");
        $check->execute([$_SESSION['user_id'], $date, $slot['start_time']]);
        if ($check->fetch()) continue;

        $stmt = $pdo->prepare("INSERT INTO study_plan (user_id, task_id, task_title, plan_date, start_time, end_time, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
        $stmt->execute([$_SESSION['user_id'], $plan['task_id'], $plan['task_title'], $date, $slot['start_time'], $slot['end_time']]);
        $rescheduled = true;
        $new_plan_id = $pdo->lastInsertId();
        break 2;
    }
}

if (!$rescheduled) {
    // Fallback: tomorrow 9AM–10AM
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $stmt = $pdo->prepare("INSERT INTO study_plan (user_id, task_id, task_title, plan_date, start_time, end_time, status) VALUES (?, ?, ?, ?, '09:00', '10:00', 'pending')");
    $stmt->execute([$_SESSION['user_id'], $plan['task_id'], $plan['task_title'], $tomorrow]);
    $new_plan_id = $pdo->lastInsertId();
}

// Notify
$label = $action === 'miss' ? 'Missed' : 'Postponed';
$title = "$label — {$plan['task_title']}";
$message = $action === 'miss'
    ? "Session marked as missed and rescheduled. Check your updated schedule."
    : "Session postponed to a later slot. Check your updated schedule.";
$stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, 'info', ?, ?, 'dashboard.php')");
$stmt->execute([$_SESSION['user_id'], $title, $message]);

echo json_encode([
    'success' => true,
    'action' => $action,
    'new_plan_id' => $new_plan_id ?? 0,
    'title' => $label,
]);
