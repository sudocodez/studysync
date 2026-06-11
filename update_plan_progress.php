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
        $stmt = $pdo->prepare("
            UPDATE tasks
            SET progress_percentage = MIN(progress_percentage + 25, 100),
                progress_status = CASE
                    WHEN MIN(progress_percentage + 25, 100) >= 100 THEN 'completed'
                    ELSE 'in_progress'
                END,
                status = CASE
                    WHEN MIN(progress_percentage + 25, 100) >= 100 THEN 'completed'
                    ELSE 'in_progress'
                END,
                completed_at = CASE
                    WHEN MIN(progress_percentage + 25, 100) >= 100 THEN ?
                    ELSE completed_at
                END
            WHERE id = ? AND user_id = ? AND progress_percentage < 100
        ");
        $stmt->execute([date('Y-m-d H:i:s'), $task_id, $_SESSION['user_id']]);
    }
}

header('Location: dashboard.php');
?>
