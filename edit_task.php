<?php
require_once 'db_config.php';
verify_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['task_id'])) {
    header('Location: dashboard.php');
    exit();
}

$id = (int)$_POST['task_id'];
$title = trim($_POST['title'] ?? '');
$type = $_POST['type'] ?? 'study';
$course_id = !empty($_POST['course_id']) ? (int)$_POST['course_id'] : null;
$description = trim($_POST['description'] ?? '');
$due_date = $_POST['due_date'] ?? '';
$estimated_hours = (float)($_POST['estimated_hours'] ?? 0);
$allowed_types = ['assignment', 'exam', 'study', 'quiz', 'project'];
$date_parts = explode('-', $due_date);

if ($title === '' || !in_array($type, $allowed_types, true) || $estimated_hours <= 0) {
    header('Location: dashboard.php?error=invalid_task');
    exit();
}

// Recalculate priority
$priority = 40;
if ($type == 'exam') {
    $priority = 100;
} elseif ($type == 'assignment') {
    $days_to_deadline = (strtotime($due_date) - time()) / 86400;
    $priority = $days_to_deadline <= 3 ? 80 : 60;
}

$stmt = $pdo->prepare("UPDATE tasks SET title = ?, type = ?, course_id = ?, description = ?, due_date = ?, estimated_hours = ?, priority = ? WHERE id = ? AND user_id = ?");
$stmt->execute([$title, $type, $course_id, $description, $due_date, $estimated_hours, $priority, $id, $_SESSION['user_id']]);

require 'API/regenerate.php';

header('Location: dashboard.php');
