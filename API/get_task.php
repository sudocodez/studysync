<?php
require_once __DIR__ . '/../db_config.php';
header('Content-Type: application/json');

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    echo json_encode(['success' => false, 'error' => 'No task ID']);
    exit();
}

$stmt = $pdo->prepare("SELECT id, title, type, course_id, description, due_date, estimated_hours, status FROM tasks WHERE id = ? AND user_id = ?");
$stmt->execute([$id, $_SESSION['user_id']]);
$task = $stmt->fetch();

if ($task) {
    echo json_encode(['success' => true, 'task' => $task]);
} else {
    echo json_encode(['success' => false, 'error' => 'Task not found']);
}
