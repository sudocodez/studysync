<?php
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
    for ($offset = 0; $offset < 60; $offset++) {
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
                $schedule_blocks[] = [
                    'date' => $date,
                    'start_time' => $slot['start_time'],
                    'end_time' => $slot['end_time'],
                    'used_mins' => 0
                ];
            }
        }
    }

    $regen_stmt = $pdo->prepare("SELECT day_of_week, time_bucket, score FROM productivity_patterns WHERE user_id = ?");
    $regen_stmt->execute([$_SESSION['user_id']]);
    $patterns = [];
    while ($row = $regen_stmt->fetch()) {
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

    $total = count($schedule_blocks);

    foreach ($tasks as $task) {
        $remaining = (float)$task['estimated_hours'] * 60;
        $due = $task['due_date'];

        for ($pass = 0; $pass < 2 && $remaining > 0; $pass++) {
            for ($i = 0; $i < $total && $remaining > 0; $i++) {
                $block = &$schedule_blocks[$i];

                if ($pass === 0 && $block['date'] > $due) continue;

                $block_mins = (strtotime($block['end_time']) - strtotime($block['start_time'])) / 60;
                $free = $block_mins - $block['used_mins'];
                if ($free <= 0) continue;

                $take = min($remaining, $free);
                $session_start = date('H:i:s', strtotime($block['start_time']) + $block['used_mins'] * 60);
                $session_end = date('H:i:s', strtotime($session_start) + $take * 60);

                $ins = $pdo->prepare("INSERT INTO study_plan (user_id, task_id, task_title, plan_date, start_time, end_time, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
                $ins->execute([$_SESSION['user_id'], $task['id'], $task['title'], $block['date'], $session_start, $session_end]);

                $block['used_mins'] += $take;
                $remaining -= $take;
                $regen_count++;
            }
        }
        unset($block);
    }
}
