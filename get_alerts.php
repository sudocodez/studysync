<?php
require_once 'db_config.php';

header('Content-Type: application/json');

$current_time = date('H:i:s');
$current_date = date('Y-m-d');
$now_ts = time();
$user_id = $_SESSION['user_id'];

// Helper to insert notification into DB + return it
function insertNotification($pdo, $user_id, $type, $title, $message, $link = '') {
    // Dedup: same type+title within last hour
    $dup_sql = $GLOBALS['db_type'] === 'mysql'
        ? "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = ? AND title = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        : "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = ? AND title = ? AND created_at > datetime('now', '-1 hour')";
    $stmt = $pdo->prepare($dup_sql);
    $stmt->execute([$user_id, $type, $title]);
    if ($stmt->fetchColumn() > 0) return null;

    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$user_id, $type, $title, $message, $link]);
    return $pdo->lastInsertId();
}

$alerts_data = [];

// ──────────────────────────────────────────────
// 1. SESSION STARTING WITHIN 30 MINUTES
// ──────────────────────────────────────────────
$thirty_min_later = date('H:i:s', $now_ts + 1800);
$five_min_later = date('H:i:s', $now_ts + 300);

$stmt = $pdo->prepare("
    SELECT sp.* FROM study_plan sp
    WHERE sp.user_id = ?
    AND sp.plan_date = ?
    AND sp.status = 'pending'
    AND sp.alerted = 0
    AND sp.start_time <= ?
    AND sp.start_time > ?
    ORDER BY sp.start_time ASC
");
$stmt->execute([$user_id, $current_date, $thirty_min_later, $current_time]);
$upcoming_sessions = $stmt->fetchAll();

foreach ($upcoming_sessions as $session) {
    $start_ts = strtotime($session['start_time']);
    $mins_until = round(($start_ts - $now_ts) / 60);

    // Mark alerted so we don't re-trigger
    $update = $pdo->prepare("UPDATE study_plan SET alerted = 1 WHERE id = ? AND user_id = ?");
    $update->execute([$session['id'], $user_id]);

    // 0-5 mins → "now" alarm
    if ($mins_until <= 0) {
        $type = 'now';
        $title = 'Session Starting Now';
        $message = "{$session['task_title']} starts now at {$session['start_time']}.";
        $link = 'dashboard.php';
    } elseif ($mins_until <= 5) {
        $type = 'upcoming';
        $title = 'Session Starting Soon';
        $message = "{$session['task_title']} starts in {$mins_until} min at {$session['start_time']}.";
        $link = 'dashboard.php';
    } elseif ($mins_until <= 30) {
        $type = 'reminder';
        $title = '30-Minute Reminder';
        $message = "{$session['task_title']} starts in {$mins_until} min at {$session['start_time']}. Get ready!";
        $link = 'dashboard.php';
    } else {
        continue;
    }

    insertNotification($pdo, $user_id, $type, $title, $message, $link);

    $alerts_data[] = [
        'id' => $session['id'],
        'type' => $type,
        'title' => $title,
        'message' => $message,
    ];
}

// ──────────────────────────────────────────────
// 1b. ALL UPCOMING SCHEDULED SESSIONS (beyond 30 min)
// ──────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT sp.* FROM study_plan sp
    WHERE sp.user_id = ?
    AND sp.plan_date >= ?
    AND sp.status = 'pending'
    AND sp.start_time > ?
    ORDER BY sp.plan_date, sp.start_time
    LIMIT 20
");
$stmt->execute([$user_id, $current_date, $thirty_min_later]);
$scheduled_sessions = $stmt->fetchAll();

foreach ($scheduled_sessions as $session) {
    $title = "Upcoming: {$session['task_title']}";
    $day_label = date('D, M j', strtotime($session['plan_date']));
    $time_label = date('g:i A', strtotime($session['start_time']));
    $message = "{$session['task_title']} scheduled on {$day_label} at {$time_label}.";
    $inserted = insertNotification($pdo, $user_id, 'upcoming', $title, $message, 'calendar.php');
    if ($inserted) {
        $alerts_data[] = [
            'id' => $session['id'],
            'type' => 'upcoming',
            'title' => $title,
            'message' => $message,
        ];
    }
}

// ──────────────────────────────────────────────
// 2. TASKS DUE WITHIN 2 DAYS (deadline warning)
// ──────────────────────────────────────────────
$day_after_tomorrow = date('Y-m-d', $now_ts + 172800);
$stmt = $pdo->prepare("
    SELECT * FROM tasks
    WHERE user_id = ?
    AND status = 'pending'
    AND due_date >= ? AND due_date <= ?
    AND alerted = 0
    ORDER BY due_date ASC
");
$stmt->execute([$user_id, $current_date, $day_after_tomorrow]);
$deadline_tasks = $stmt->fetchAll();

foreach ($deadline_tasks as $task) {
    $update = $pdo->prepare("UPDATE tasks SET alerted = 1 WHERE id = ? AND user_id = ?");
    $update->execute([$task['id'], $user_id]);

    $due_ts = strtotime($task['due_date']);
    $days_left = max(0, floor(($due_ts - $now_ts) / 86400));
    $hours_left = max(0, floor(($due_ts - $now_ts) / 3600));
    $risk = $days_left <= 0 ? 'CRITICAL' : ($days_left <= 1 ? 'HIGH RISK' : 'RISK');

    $title = "{$risk}: Task Due Soon";
    $message = "{$task['title']} due in {$days_left}d {$hours_left}h — allocate {$task['estimated_hours']}h to complete it.";
    $link = 'dashboard.php';

    insertNotification($pdo, $user_id, 'deadline', $title, $message, $link);

    $alerts_data[] = [
        'id' => $task['id'],
        'type' => 'deadline',
        'title' => $title,
        'message' => $message,
    ];
}

// ──────────────────────────────────────────────
// 3. OVERDUE TASKS
// ──────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT * FROM tasks
    WHERE user_id = ?
    AND status = 'pending'
    AND due_date < ?
    AND alerted = 0
");
$stmt->execute([$user_id, $current_date]);
$overdue_tasks = $stmt->fetchAll();

foreach ($overdue_tasks as $task) {
    $update = $pdo->prepare("UPDATE tasks SET alerted = 1 WHERE id = ? AND user_id = ?");
    $update->execute([$task['id'], $user_id]);

    $days_overdue = round(($now_ts - strtotime($task['due_date'])) / 86400);

    $title = 'OVERDUE — Take Action';
    $message = "{$task['title']} is overdue by {$days_overdue}d. Prioritise this now!";
    $link = 'dashboard.php';

    insertNotification($pdo, $user_id, 'overdue', $title, $message, $link);

    $alerts_data[] = [
        'id' => $task['id'],
        'type' => 'overdue',
        'title' => $title,
        'message' => $message,
    ];
}

// ──────────────────────────────────────────────
// 4. BURNOUT DETECTION
// ──────────────────────────────────────────────
$burnout_alerts = [];

// 4a. Weekly overload check (>35h scheduled this week)
$monday = date('Y-m-d', strtotime('monday this week'));
$sunday = date('Y-m-d', strtotime('sunday this week'));

$stmt = $pdo->prepare("SELECT start_time, end_time FROM study_plan WHERE user_id = ? AND plan_date BETWEEN ? AND ? AND status = 'pending'");
$stmt->execute([$user_id, $monday, $sunday]);
$week_sessions = $stmt->fetchAll();

$week_total_mins = 0;
foreach ($week_sessions as $s) {
    $s_ts = strtotime($s['start_time']);
    $e_ts = strtotime($s['end_time']);
    if ($e_ts > $s_ts) {
        $week_total_mins += ($e_ts - $s_ts) / 60;
    }
}

if ($week_total_mins > 2100) {
    $hours = round($week_total_mins / 60, 1);
    $title = 'High Workload This Week';
    $message = "You have {$hours}h of study scheduled this week. Consider reducing to 35h or less to prevent burnout. Quality over quantity!";
    $inserted = insertNotification($pdo, $user_id, 'wellness', $title, $message, 'dashboard.php');
    if ($inserted) {
        $burnout_alerts[] = ['type' => 'wellness', 'title' => $title, 'message' => $message];
    }
}

// 4b. Late-night session check (ends after 10 PM today or tomorrow)
for ($day_offset = 0; $day_offset <= 1; $day_offset++) {
    $check_date = date('Y-m-d', $now_ts + ($day_offset * 86400));
    $stmt = $pdo->prepare("SELECT id, task_title, start_time, end_time FROM study_plan WHERE user_id = ? AND plan_date = ? AND status = 'pending'");
    $stmt->execute([$user_id, $check_date]);
    $day_sessions = $stmt->fetchAll();

    foreach ($day_sessions as $s) {
        if (strtotime($s['end_time']) >= strtotime('22:00')) {
            $title = 'Late-Night Study Session';
            $message = "{$s['task_title']} ends at {$s['end_time']} — late study can disrupt sleep. Try to wrap up by 10 PM for better rest and retention.";
            $inserted = insertNotification($pdo, $user_id, 'wellness', $title, $message, 'dashboard.php');
            if ($inserted) {
                $burnout_alerts[] = ['type' => 'wellness', 'title' => $title, 'message' => $message];
            }
        }
    }
}

// 4c. Consecutive study days without rest (past sessions only)
$stmt = $pdo->prepare("SELECT DISTINCT DATE(start_time) as study_date FROM study_sessions WHERE user_id = ? AND start_time >= ? ORDER BY study_date DESC");
$stmt->execute([$user_id, date('Y-m-d', $now_ts - 518400)]); // last 6 days
$study_dates = $stmt->fetchAll(PDO::FETCH_COLUMN);

$consecutive_count = count($study_dates) > 0 ? 1 : 0;
for ($i = 1; $i < count($study_dates); $i++) {
    $diff = (strtotime($study_dates[$i - 1]) - strtotime($study_dates[$i])) / 86400;
    if ($diff <= 1.5) {
        $consecutive_count++;
    } else {
        break;
    }
}

if ($consecutive_count >= 6) {
    $title = 'Time for a Rest Day';
    $message = "You've studied {$consecutive_count} days in a row. Taking a rest day helps memory consolidation and prevents burnout. Consider a break!";
    $inserted = insertNotification($pdo, $user_id, 'wellness', $title, $message, 'dashboard.php');
    if ($inserted) {
        $burnout_alerts[] = ['type' => 'wellness', 'title' => $title, 'message' => $message];
    }
}

// ──────────────────────────────────────────────
// 5. WEEKLY SUMMARY PROMPT
// ──────────────────────────────────────────────
$prev_monday = date('Y-m-d', strtotime('monday last week'));
$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'weekly_summary' AND created_at >= ?");
$stmt->execute([$user_id, $monday]);
if ((int)$stmt->fetchColumn() === 0) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM study_sessions WHERE user_id = ? AND start_time >= ? AND start_time < ?");
    $stmt->execute([$user_id, $prev_monday, $monday]);
    $last_week_sessions = (int)$stmt->fetchColumn();
    if ($last_week_sessions > 0) {
        $title = 'Weekly Summary Ready';
        $message = 'Your performance summary for last week is available. See what worked and what needs attention.';
        $inserted = insertNotification($pdo, $user_id, 'weekly_summary', $title, $message, 'weekly_summary.php');
        if ($inserted) {
            $alerts_data[] = ['type' => 'weekly_summary', 'title' => $title, 'message' => $message];
        }
    }
}
// ──────────────────────────────────────────────
// 6. RETURN UNREAD COUNT + NEW ALERTS
// ──────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$user_id]);
$unread_count = (int)$stmt->fetchColumn();

$alerts_data = array_merge($alerts_data, $burnout_alerts);

echo json_encode([
    'success' => true,
    'alerts' => $alerts_data,
    'alert_count' => count($alerts_data),
    'unread_count' => $unread_count,
    'current_time' => $current_time
]);
