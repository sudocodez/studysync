<?php
require_once '../db_config.php';

header('Content-Type: application/json');

$current_time = date('H:i:s');
$current_date = date('Y-m-d');
$current_time_plus_5 = date('H:i:s', strtotime($current_time) + 300);

$stmt = $pdo->prepare("
    SELECT sp.*
    FROM study_plan sp
    WHERE sp.user_id = ?
    AND sp.plan_date = ?
    AND sp.status = 'pending'
    AND sp.alerted = 0
    AND sp.start_time <= ?
    ORDER BY sp.start_time ASC
");

$stmt->execute([$_SESSION['user_id'], $current_date, $current_time_plus_5]);
$alarms = $stmt->fetchAll();

$alarm_list = [];

foreach($alarms as $alarm) {
    $update = $pdo->prepare("UPDATE study_plan SET alerted = 1 WHERE id = ? AND user_id = ?");
    $update->execute([$alarm['id'], $_SESSION['user_id']]);

    $time_diff_seconds = strtotime($alarm['start_time']) - strtotime($current_time);
    $formatted_time = date('g:i A', strtotime($alarm['start_time']));

    if($time_diff_seconds <= 0) {
        $alarm_list[] = [
            'id' => $alarm['id'],
            'task_title' => $alarm['task_title'],
            'message' => "ALARM! You have a study session for '{$alarm['task_title']}' that started at {$formatted_time}!",
            'type' => 'urgent',
            'start_time' => $alarm['start_time']
        ];
    } elseif($time_diff_seconds <= 300) {
        $minutes = round($time_diff_seconds / 60);
        $alarm_list[] = [
            'id' => $alarm['id'],
            'task_title' => $alarm['task_title'],
            'message' => "ALARM in {$minutes} minute(s)! '{$alarm['task_title']}' at {$formatted_time}",
            'type' => 'reminder',
            'start_time' => $alarm['start_time']
        ];
    }
}

$stmt = $pdo->prepare("
    SELECT sp.*
    FROM study_plan sp
    WHERE sp.user_id = ?
    AND sp.plan_date = ?
    AND sp.status = 'pending'
    AND sp.start_time < ?
    AND sp.reschedule_alerted = 0
");

$stmt->execute([$_SESSION['user_id'], $current_date, $current_time]);
$missed_tasks = $stmt->fetchAll();

foreach($missed_tasks as $missed) {
    $update = $pdo->prepare("UPDATE study_plan SET status = 'missed', reschedule_alerted = 1 WHERE id = ? AND user_id = ?");
    $update->execute([$missed['id'], $_SESSION['user_id']]);

    // Auto-reschedule to next available slot
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("SELECT * FROM available_time WHERE user_id = ? ORDER BY day_of_week, start_time");
    $stmt->execute([$_SESSION['user_id']]);
    $avail_slots = $stmt->fetchAll();

    $rescheduled = false;
    for ($offset = 1; $offset <= 14; $offset++) {
        $date = date('Y-m-d', strtotime("$today + $offset days"));
        $dow = (int)date('w', strtotime($date));
        foreach ($avail_slots as $slot) {
            if ((int)$slot['day_of_week'] === $dow) {
                $pdo->prepare("INSERT INTO study_plan (user_id, task_id, task_title, plan_date, start_time, end_time, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')")
                    ->execute([$_SESSION['user_id'], $missed['task_id'], $missed['task_title'], $date, $slot['start_time'], $slot['end_time']]);
                $rescheduled = true;
                break 2;
            }
        }
    }
    if (!$rescheduled) {
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $pdo->prepare("INSERT INTO study_plan (user_id, task_id, task_title, plan_date, start_time, end_time, status) VALUES (?, ?, ?, ?, '09:00', '10:00', 'pending')")
            ->execute([$_SESSION['user_id'], $missed['task_id'], $missed['task_title'], $tomorrow]);
    }

    $alarm_list[] = [
        'id' => $missed['id'],
        'task_title' => $missed['task_title'],
        'message' => "You missed '{$missed['task_title']}'. It has been rescheduled.",
        'type' => 'missed',
        'needs_reschedule' => true
    ];
}

echo json_encode([
    'success' => true,
    'alarms' => $alarm_list,
    'alarm_count' => count($alarm_list),
    'current_time' => $current_time
]);
?>
