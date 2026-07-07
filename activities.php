<?php
require_once 'db_config.php';

// Get completed tasks and study sessions
$stmt = $pdo->prepare("SELECT *, COALESCE(completed_at, due_date) AS completed_on FROM tasks WHERE user_id = ? AND status = 'completed' ORDER BY completed_at DESC, due_date DESC LIMIT 20");
$stmt->execute([$_SESSION['user_id']]);
$completed_tasks = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM study_plan WHERE user_id = ? AND status = 'completed' ORDER BY plan_date DESC LIMIT 20");
$stmt->execute([$_SESSION['user_id']]);
$completed_sessions = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activities | StudySync</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="liquid-glass.css">
    <style>
        .page-header { margin-bottom: 24px; }
        .page-header h1 { font-size: 24px; font-weight: 600; }
        .activity-card { background: var(--bg-card); border-radius: var(--radius-lg); border: 1px solid var(--border); margin-bottom: 24px; overflow: hidden; }
        .card-header { padding: 16px 20px; border-bottom: 1px solid var(--border); font-weight: 600; }
        .activity-item { display: flex; align-items: center; gap: 16px; padding: 16px 20px; border-bottom: 1px solid var(--border-light); }
        .activity-icon { width: 40px; height: 40px; background: var(--accent-soft); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: var(--accent); }
        .activity-content { flex: 1; }
        .activity-title { font-weight: 500; margin-bottom: 4px; color: var(--text-primary); }
        .activity-date { font-size: 12px; color: var(--text-muted); }
        .empty-state { text-align: center; padding: 48px; color: var(--text-muted); }
    </style>
</head>
<body>
    <div class="app-container">
        <?php require_once 'includes/sidebar.php'; ?>

        <main class="main-content">
            <div class="page-header"><h1>📊 Activities & History</h1></div>
        
        <div class="activity-card">
            <div class="card-header">✅ Completed Tasks</div>
            <?php if(count($completed_tasks) > 0): ?>
                <?php foreach($completed_tasks as $task): ?>
                    <div class="activity-item">
                        <div class="activity-icon">✓</div>
                        <div class="activity-content">
                            <div class="activity-title"><?= htmlspecialchars($task['title']) ?></div>
                            <div class="activity-date">Completed on <?= date('M d, Y g:i A', strtotime($task['completed_on'])) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">No completed tasks yet. Start studying!</div>
            <?php endif; ?>
        </div>
        
        <div class="activity-card">
            <div class="card-header">📚 Completed Study Sessions</div>
            <?php if(count($completed_sessions) > 0): ?>
                <?php foreach($completed_sessions as $session): ?>
                    <div class="activity-item">
                        <div class="activity-icon">📖</div>
                        <div class="activity-content">
                            <div class="activity-title"><?= htmlspecialchars($session['task_title']) ?></div>
                            <div class="activity-date"><?= date('M d, Y', strtotime($session['plan_date'])) ?> at <?= date('g:i A', strtotime($session['start_time'])) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">No study sessions completed yet.</div>
            <?php endif; ?>
        </div>
    </main>
</div>


</body>
</html>