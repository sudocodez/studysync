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
// 4. RETURN UNREAD COUNT + NEW ALERTS
// ──────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$user_id]);
$unread_count = (int)$stmt->fetchColumn();

echo json_encode([
    'success' => true,
    'alerts' => $alerts_data,
    'alert_count' => count($alerts_data),
    'unread_count' => $unread_count,
    'current_time' => $current_time
]);
