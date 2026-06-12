<?php
require_once 'db_config.php';
verify_csrf();

$allowed_types = ['assignment', 'exam', 'study', 'quiz', 'project'];
$title = trim($_POST['title'] ?? '');
$type = $_POST['type'] ?? 'study';
$due_date = $_POST['due_date'] ?? '';
$estimated_hours = (float)($_POST['estimated_hours'] ?? 0);
$course_id = !empty($_POST['course_id']) ? (int)$_POST['course_id'] : null;
$description = trim($_POST['description'] ?? '');
$date_parts = explode('-', $due_date);
$valid_due_date = count($date_parts) === 3
    && checkdate((int)$date_parts[1], (int)$date_parts[2], (int)$date_parts[0]);

if($title === '' || !in_array($type, $allowed_types, true) || !$valid_due_date || $estimated_hours <= 0) {
    header('Location: dashboard.php?error=invalid_task');
    exit();
}

// Calculate priority
$priority = 40;
if($type == 'exam') {
    $priority = 100;
} elseif($type == 'assignment') {
    $days_to_deadline = (strtotime($due_date) - time()) / 86400;
    $priority = $days_to_deadline <= 3 ? 80 : 60;
}

$stmt = $pdo->prepare("INSERT INTO tasks (user_id, course_id, title, type, due_date, estimated_hours, priority, description, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
$stmt->execute([
    $_SESSION['user_id'],
    $course_id,
    $title,
    $type,
    $due_date,
    $estimated_hours,
    $priority,
    $description
]);

require 'API/regenerate.php';

header('Location: dashboard.php');
