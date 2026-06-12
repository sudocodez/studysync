<?php
require_once 'db_config.php';

$plan_id = (int)($_POST['plan_id'] ?? 0);
$status = $_POST['status'] ?? 'completed';
$allowed_statuses = ['pending', 'completed', 'missed', 'postponed'];

if($plan_id <= 0 || !in_array($status, $allowed_statuses, true)) {
    http_response_code(400);
    exit('Invalid plan update');
}

// Update plan status
$stmt = $pdo->prepare("UPDATE study_plan SET status = ? WHERE id = ? AND user_id = ?");
$stmt->execute([$status, $plan_id, $_SESSION['user_id']]);

// If completed, also update the related task progress
if($status == 'completed' && $stmt->rowCount() > 0) {
    $stmt = $pdo->prepare("SELECT task_id FROM study_plan WHERE id = ? AND user_id = ?");
    $stmt->execute([$plan_id, $_SESSION['user_id']]);
    $task_id = $stmt->fetchColumn();
    
    if($task_id) {
        // Fetch current progress
        $stmt = $pdo->prepare("SELECT progress_percentage, status FROM tasks WHERE id = ? AND user_id = ?");
        $stmt->execute([$task_id, $_SESSION['user_id']]);
        $task = $stmt->fetch();

        if ($task && $task['progress_percentage'] < 100) {
            $new_pct = min((int)$task['progress_percentage'] + 25, 100);
            $new_status = $new_pct >= 100 ? 'completed' : 'in_progress';
            $completed_at = $new_pct >= 100 ? date('Y-m-d H:i:s') : null;

            $stmt = $pdo->prepare("UPDATE tasks SET progress_percentage = ?, progress_status = ?, status = ?, completed_at = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$new_pct, $new_status, $new_status, $completed_at, $task_id, $_SESSION['user_id']]);
        }
    }
}

header('Location: dashboard.php');
?>
