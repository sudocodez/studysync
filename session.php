<?php
require_once 'db_config.php';
header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

if ($action === 'start') {
    $task_id = (int)($_POST['task_id'] ?? 0);
    $plan_id = (int)($_POST['plan_id'] ?? 0);

    $stmt = $pdo->prepare("INSERT INTO study_sessions (user_id, task_id, plan_id, start_time) VALUES (?, ?, ?, datetime('now'))");
    $stmt->execute([$_SESSION['user_id'], $task_id ?: null, $plan_id ?: null]);
    $session_id = $pdo->lastInsertId();

    // Also mark plan as in_progress if plan_id given
    if ($plan_id) {
        $pdo->prepare("UPDATE study_plan SET status = 'in_progress' WHERE id = ? AND user_id = ?")->execute([$plan_id, $_SESSION['user_id']]);
    }

    echo json_encode(['success' => true, 'session_id' => $session_id]);

} elseif ($action === 'stop') {
    $session_id = (int)($_POST['session_id'] ?? 0);

    // Get session start time
    $stmt = $pdo->prepare("SELECT * FROM study_sessions WHERE id = ? AND user_id = ? AND end_time IS NULL");
    $stmt->execute([$session_id, $_SESSION['user_id']]);
    $session = $stmt->fetch();

    if (!$session) {
        echo json_encode(['success' => false, 'error' => 'Session not found']);
        exit();
    }

    $end_time = date('Y-m-d H:i:s');
    $start_ts = strtotime($session['start_time']);
    $end_ts = strtotime($end_time);
    $duration_mins = round(($end_ts - $start_ts) / 60);

    $pdo->prepare("UPDATE study_sessions SET end_time = ?, duration_minutes = ? WHERE id = ?")->execute([$end_time, $duration_mins, $session_id]);

    // If linked to a plan, mark it completed
    if ($session['plan_id']) {
        $pdo->prepare("UPDATE study_plan SET status = 'completed' WHERE id = ? AND user_id = ?")->execute([$session['plan_id'], $_SESSION['user_id']]);

        // Also check if task linked to plan should be updated
        $stmt = $pdo->prepare("SELECT task_id FROM study_plan WHERE id = ?")->execute([$session['plan_id']]);
        $task_id = $pdo->query("SELECT task_id FROM study_plan WHERE id = $session[plan_id]")->fetchColumn();
        if ($task_id) {
            $pdo->prepare("UPDATE tasks SET progress_percentage = MIN(progress_percentage + 25, 100), progress_status = CASE WHEN progress_percentage + 25 >= 100 THEN 'completed' ELSE 'in_progress' END WHERE id = ? AND user_id = ?")->execute([$task_id, $_SESSION['user_id']]);
        }
    }

    // Update user_stats
    $stmt = $pdo->prepare("SELECT * FROM user_stats WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $stats = $stmt->fetch();

    $today = date('Y-m-d');
    if ($stats) {
        $new_total = $stats['total_study_hours'] + round($duration_mins / 60, 1);
        $new_streak = ($stats['last_study_date'] === date('Y-m-d', strtotime('-1 day'))) ? $stats['current_streak_days'] + 1 : ($stats['last_study_date'] === $today ? $stats['current_streak_days'] : 1);
        $new_longest = max($stats['longest_streak'], $new_streak);
        $pdo->prepare("UPDATE user_stats SET total_study_hours = ?, current_streak_days = ?, longest_streak = ?, last_study_date = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?")
            ->execute([$new_total, $new_streak, $new_longest, $today, $_SESSION['user_id']]);
    } else {
        $pdo->prepare("INSERT INTO user_stats (user_id, total_study_hours, current_streak_days, longest_streak, last_study_date) VALUES (?, ?, 1, 1, ?)")
            ->execute([$_SESSION['user_id'], round($duration_mins / 60, 1), $today]);
    }

    echo json_encode(['success' => true, 'duration_minutes' => $duration_mins]);

} elseif ($action === 'active') {
    $stmt = $pdo->prepare("SELECT * FROM study_sessions WHERE user_id = ? AND end_time IS NULL ORDER BY start_time DESC LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $active = $stmt->fetch();

    if ($active) {
        $elapsed = round((time() - strtotime($active['start_time'])) / 60);
        echo json_encode(['success' => true, 'active' => true, 'session_id' => $active['id'], 'elapsed_minutes' => $elapsed, 'start_time' => $active['start_time']]);
    } else {
        echo json_encode(['success' => true, 'active' => false]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
}