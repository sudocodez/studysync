<?php
require_once 'db_config.php';

$today = date('Y-m-d');

// Clear old plan for today and future
$pdo->prepare("DELETE FROM study_plan WHERE user_id = ? AND plan_date >= ?")->execute([$_SESSION['user_id'], $today]);

// Get pending tasks sorted by priority then due date
$stmt = $pdo->prepare("SELECT * FROM tasks WHERE user_id = ? AND status = 'pending' ORDER BY priority DESC, due_date ASC");
$stmt->execute([$_SESSION['user_id']]);
$tasks = $stmt->fetchAll();

// Get available time slots for this user
$stmt = $pdo->prepare("SELECT * FROM available_time WHERE user_id = ? ORDER BY day_of_week, start_time");
$stmt->execute([$_SESSION['user_id']]);
$avail_slots = $stmt->fetchAll();

if (empty($avail_slots)) {
    // Fallback: use reasonable defaults if no availability set
    $avail_slots = [];
    foreach ([1, 2, 3, 4, 5] as $dow) {
        $avail_slots[] = ['day_of_week' => $dow, 'start_time' => '09:00', 'end_time' => '12:00'];
        $avail_slots[] = ['day_of_week' => $dow, 'start_time' => '14:00', 'end_time' => '17:00'];
    }
}

// Build a map: day_offset -> list of free date+time blocks
// We look ahead up to 30 days
$schedule_blocks = [];
for ($offset = 0; $offset < 30; $offset++) {
    $date = date('Y-m-d', strtotime("$today + $offset days"));
    $dow = (int)date('w', strtotime($date));

    foreach ($avail_slots as $slot) {
        if ((int)$slot['day_of_week'] === $dow) {
            $start_ts = strtotime($slot['start_time']);
            $end_ts = strtotime($slot['end_time']);
            if ($end_ts > $start_ts) {
                $schedule_blocks[] = [
                    'date' => $date,
                    'start_time' => $slot['start_time'],
                    'end_time' => $slot['end_time']
                ];
            }
        }
    }
}

$block_index = 0;

foreach ($tasks as $task) {
    if ($block_index >= count($schedule_blocks)) break;

    $block = $schedule_blocks[$block_index];
    $plan_date = $block['date'];
    $start = $block['start_time'];

    // Cap task duration to block length
    $block_mins = (strtotime($block['end_time']) - strtotime($start)) / 60;
    $task_mins = min((float)$task['estimated_hours'] * 60, $block_mins);
    $end = date('H:i:s', strtotime($start) + $task_mins * 60);

    $stmt = $pdo->prepare("INSERT INTO study_plan (user_id, task_id, task_title, plan_date, start_time, end_time, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
    $stmt->execute([$_SESSION['user_id'], $task['id'], $task['title'], $plan_date, $start, $end]);

    $block_index++;
}

header('Location: dashboard.php?plan_generated=1');
?>