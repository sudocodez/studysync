<?php
require_once 'db_config.php';

if(isset($_GET['id'])) {
    $stmt = $pdo->prepare("
        UPDATE study_plan
        SET status = 'completed'
        WHERE id = ? AND user_id = ?
    ");
    $stmt->execute([(int)$_GET['id'], $_SESSION['user_id']]);
} elseif(isset($_GET['task_id'])) {
    $stmt = $pdo->prepare("
        UPDATE tasks
        SET status = 'completed',
            progress_status = 'completed',
            progress_percentage = 100,
            completed_at = ?
        WHERE id = ? AND user_id = ?
    ");
    $stmt->execute([date('Y-m-d H:i:s'), (int)$_GET['task_id'], $_SESSION['user_id']]);
}

header('Location: dashboard.php');
?>
