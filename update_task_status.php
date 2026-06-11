<?php
require_once 'db_config.php';

$task_id = (int)($_POST['task_id'] ?? 0);
$status = $_POST['status'] ?? 'pending';
$allowed_statuses = ['pending', 'completed', 'missed', 'in_progress'];

if($task_id <= 0 || !in_array($status, $allowed_statuses, true)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid task update']);
    exit();
}

$completed_at = $status === 'completed' ? date('Y-m-d H:i:s') : null;
$progress_percentage = $status === 'completed' ? 100 : null;
$progress_status = $status === 'completed' ? 'completed' : ($status === 'in_progress' ? 'in_progress' : 'not_started');

$stmt = $pdo->prepare("
    UPDATE tasks
    SET status = ?,
        completed_at = ?,
        progress_status = ?,
        progress_percentage = COALESCE(?, progress_percentage)
    WHERE id = ? AND user_id = ?
");
$stmt->execute([$status, $completed_at, $progress_status, $progress_percentage, $task_id, $_SESSION['user_id']]);

header('Content-Type: application/json');
echo json_encode(['success' => $stmt->rowCount() > 0]);
?>
