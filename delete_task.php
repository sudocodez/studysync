<?php
require_once 'db_config.php';
verify_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['task_id'])) {
    header('Location: dashboard.php');
    exit();
}

$id = (int)$_POST['task_id'];

$stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ? AND user_id = ?");
$stmt->execute([$id, $_SESSION['user_id']]);

require 'API/regenerate.php';

header('Location: dashboard.php');
