<?php
require_once 'db_config.php';

$task_id = (int)($_POST['task_id'] ?? 0);
$progress = (int)($_POST['progress'] ?? 0);
$progress = max(0, min(100, $progress));

if($task_id <= 0) {
    header('Location: dashboard.php');
    exit();
}

if($progress >= 100) {
    $progress_status = 'completed';
    $task_status = 'completed';
} elseif($progress > 0) {
    $progress_status = 'in_progress';
    $task_status = 'in_progress';
} else {
    $progress_status = 'not_started';
    $task_status = 'pending';
}

$completed_at = $task_status === 'completed' ? date('Y-m-d H:i:s') : null;

$stmt = $pdo->prepare("
    UPDATE tasks
    SET progress_status = ?,
        progress_percentage = ?,
        status = ?,
        completed_at = ?
    WHERE id = ? AND user_id = ?
");
$stmt->execute([$progress_status, $progress, $task_status, $completed_at, $task_id, $_SESSION['user_id']]);

header('Location: dashboard.php');
?>
