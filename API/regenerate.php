<?php
// Include this file after task/availability changes to auto-regenerate the plan.
// It only regenerates if the user has both tasks and availability set.

$regen_count = 0;
$regen_stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = ? AND status = 'pending'");
$regen_stmt->execute([$_SESSION['user_id']]);
$has_tasks = (int)$regen_stmt->fetchColumn() > 0;

$regen_stmt = $pdo->prepare("SELECT COUNT(*) FROM available_time WHERE user_id = ?");
$regen_stmt->execute([$_SESSION['user_id']]);
$has_avail = (int)$regen_stmt->fetchColumn() > 0;

if ($has_tasks && $has_avail) {
    $today = date('Y-m-d');
    $pdo->prepare("DELETE FROM study_plan WHERE user_id = ? AND plan_date >= ? AND status = 'pending'")->execute([$_SESSION['user_id'], $today]);

    $regen_stmt = $pdo->prepare("SELECT * FROM tasks WHERE user_id = ? AND status = 'pending'");
    $regen_stmt->execute([$_SESSION['user_id']]);
    $tasks = $regen_stmt->fetchAll();

    $now_ts = time();
    foreach ($tasks as &$t) {
        $days_until = max(0, (strtotime($t['due_date']) - $now_ts) / 86400);
        $deadline_urgency = $days_until <= 1 ? 50 : ($days_until <= 3 ? 30 : 0);
        $effort_urgency = min(20, (float)$t['estimated_hours'] * 3);
        $t['urgency'] = (int)$t['priority'] + $deadline_urgency + $effort_urgency;
    }
    unset($t);
    usort($tasks, function ($a, $b) {
        if ($b['urgency'] !== $a['urgency']) return $b['urgency'] - $a['urgency'];
        return strcmp($a['due_date'], $b['due_date']);
    });

    $regen_stmt = $pdo->prepare("SELECT * FROM available_time WHERE user_id = ? ORDER BY is_recurring DESC, day_of_week, start_time");
    $regen_stmt->execute([$_SESSION['user_id']]);
    $avail_slots = $regen_stmt->fetchAll();

    if (empty($avail_slots)) {
        $avail_slots = [];
        foreach ([1, 2, 3, 4, 5] as $dow) {
            $avail_slots[] = ['day_of_week' => $dow, 'start_time' => '09:00', 'end_time' => '12:00', 'is_recurring' => 1, 'specific_date' => null];
            $avail_slots[] = ['day_of_week' => $dow, 'start_time' => '14:00', 'end_time' => '17:00', 'is_recurring' => 1, 'specific_date' => null];
        }
    }

    $schedule_blocks = [];
    for ($offset = 0; $offset < 30; $offset++) {
        $date = date('Y-m-d', strtotime("$today + $offset days"));
        $dow = (int)date('w', strtotime($date));
        foreach ($avail_slots as $slot) {
            if ($slot['is_recurring']) {
                if ((int)$slot['day_of_week'] !== $dow) continue;
            } else {
                if (($slot['specific_date'] ?? '') !== $date) continue;
            }
            $start_ts = strtotime($slot['start_time']);
            $end_ts = strtotime($slot['end_time']);
            if ($end_ts > $start_ts) {
                $schedule_blocks[] = ['date' => $date, 'start_time' => $slot['start_time'], 'end_time' => $slot['end_time']];
            }
        }
    }

    $block_index = 0;
    foreach ($tasks as $task) {
        $remaining = (float)$task['estimated_hours'] * 60;
        $due = $task['due_date'];
        while ($remaining > 0) {
            while ($block_index < count($schedule_blocks)) {
                if ($schedule_blocks[$block_index]['date'] <= $due) break;
                $block_index++;
            }
            if ($block_index >= count($schedule_blocks)) break;
            $block = $schedule_blocks[$block_index];
            $block_mins = (strtotime($block['end_time']) - strtotime($block['start_time'])) / 60;
            $session_mins = min($remaining, $block_mins);
            $end = date('H:i:s', strtotime($block['start_time']) + $session_mins * 60);
            $ins = $pdo->prepare("INSERT INTO study_plan (user_id, task_id, task_title, plan_date, start_time, end_time, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
            $ins->execute([$_SESSION['user_id'], $task['id'], $task['title'], $block['date'], $block['start_time'], $end]);
            $remaining -= $session_mins;
            $block_index++;
            $regen_count++;
        }
    }
}
