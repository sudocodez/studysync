<?php
require_once 'db_config.php';

$today = date('Y-m-d');

// Clear old pending plan for today and future (keep completed/missed)
$pdo->prepare("DELETE FROM study_plan WHERE user_id = ? AND plan_date >= ? AND status = 'pending'")->execute([$_SESSION['user_id'], $today]);

// Get pending tasks and calculate urgency score
$stmt = $pdo->prepare("SELECT * FROM tasks WHERE user_id = ? AND status = 'pending'");
$stmt->execute([$_SESSION['user_id']]);
$tasks = $stmt->fetchAll();

// Calculate urgency for each task: priority base + deadline proximity + effort
$now_ts = time();
foreach ($tasks as &$task) {
    $days_until_due = max(0, (strtotime($task['due_date']) - $now_ts) / 86400);
    // Urgency: higher base priority + tighter deadline + more hours needed
    $deadline_urgency = $days_until_due <= 1 ? 50 : ($days_until_due <= 3 ? 30 : 0);
    $effort_urgency = min(20, (float)$task['estimated_hours'] * 3);
    $task['urgency'] = (int)$task['priority'] + $deadline_urgency + $effort_urgency;
}
unset($task);

// Sort by urgency descending, then due_date ascending for equal urgency
usort($tasks, function ($a, $b) {
    if ($b['urgency'] !== $a['urgency']) return $b['urgency'] - $a['urgency'];
    return strcmp($a['due_date'], $b['due_date']);
});

// Get available time slots (recurring + specific date)
$stmt = $pdo->prepare("SELECT * FROM available_time WHERE user_id = ? ORDER BY is_recurring DESC, day_of_week, start_time");
$stmt->execute([$_SESSION['user_id']]);
$avail_slots = $stmt->fetchAll();

if (empty($avail_slots)) {
    // Fallback: use reasonable defaults if no availability set
    $avail_slots = [];
    foreach ([1, 2, 3, 4, 5] as $dow) {
        $avail_slots[] = ['day_of_week' => $dow, 'start_time' => '09:00', 'end_time' => '12:00', 'is_recurring' => 1, 'specific_date' => null];
        $avail_slots[] = ['day_of_week' => $dow, 'start_time' => '14:00', 'end_time' => '17:00', 'is_recurring' => 1, 'specific_date' => null];
    }
}

// Build schedule blocks: combine recurring + specific-date slots
$schedule_blocks = [];
$seen_dates = [];

for ($offset = 0; $offset < 30; $offset++) {
    $date = date('Y-m-d', strtotime("$today + $offset days"));
    $dow = (int)date('w', strtotime($date));

    foreach ($avail_slots as $slot) {
        // Recurring slots apply if day_of_week matches
        // Specific-date slots apply only if date matches
        if ($slot['is_recurring']) {
            if ((int)$slot['day_of_week'] !== $dow) continue;
        } else {
            if (($slot['specific_date'] ?? '') !== $date) continue;
        }

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

// Sort blocks by productivity score: within each day, high-scored slots first
$stmt = $pdo->prepare("SELECT day_of_week, time_bucket, score FROM productivity_patterns WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$patterns = [];
while ($row = $stmt->fetch()) {
    $patterns[$row['day_of_week']][$row['time_bucket']] = (float)$row['score'];
}

foreach ($schedule_blocks as &$block) {
    $dow = (int)date('w', strtotime($block['date']));
    $hour = (int)strtok($block['start_time'], ':');
    $bucket = $hour < 12 ? 'morning' : ($hour < 18 ? 'afternoon' : 'evening');
    $block['productivity'] = $patterns[$dow][$bucket] ?? 0.50;
}
unset($block);

usort($schedule_blocks, function ($a, $b) {
    if ($a['date'] !== $b['date']) return strcmp($a['date'], $b['date']);
    return $b['productivity'] <=> $a['productivity'];
});

$block_index = 0;

foreach ($tasks as $task) {
    $task_remaining_mins = (float)$task['estimated_hours'] * 60;
    $due_date = $task['due_date'];

    while ($task_remaining_mins > 0) {
        // Find next available block before deadline
        while ($block_index < count($schedule_blocks)) {
            $block = $schedule_blocks[$block_index];
            // Skip blocks past the deadline
            if ($block['date'] <= $due_date) break;
            $block_index++;
        }

        if ($block_index >= count($schedule_blocks)) break;

        $block = $schedule_blocks[$block_index];
        $plan_date = $block['date'];
        $start = $block['start_time'];

        $block_mins = (strtotime($block['end_time']) - strtotime($start)) / 60;

        // Use remaining task time, capped to block length
        $session_mins = min($task_remaining_mins, $block_mins);
        $end = date('H:i:s', strtotime($start) + $session_mins * 60);

        $stmt = $pdo->prepare("INSERT INTO study_plan (user_id, task_id, task_title, plan_date, start_time, end_time, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
        $stmt->execute([$_SESSION['user_id'], $task['id'], $task['title'], $plan_date, $start, $end]);

        $task_remaining_mins -= $session_mins;

        // If there's remaining time in this block (e.g. task filled only part), 
        // we still advance the block since per-block scheduling is simpler
        $block_index++;
    }
}

// Count how many sessions were created
$stmt = $pdo->prepare("SELECT COUNT(*) FROM study_plan WHERE user_id = ? AND plan_date >= ? AND status = 'pending'");
$stmt->execute([$_SESSION['user_id'], $today]);
$session_count = (int)$stmt->fetchColumn();

if ($session_count > 0) {
    $msg = $session_count > 1
        ? "Study plan generated — {$session_count} sessions scheduled starting from today."
        : "Study plan generated — 1 session scheduled.";
    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, 'success', 'Plan Generated', ?, 'calendar.php')");
    $stmt->execute([$_SESSION['user_id'], $msg]);
}

header('Location: dashboard.php?plan_generated=1');
