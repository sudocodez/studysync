<?php
require_once 'db_config.php';

$uid = $_SESSION['user_id'];
$course_filter = isset($_GET['course']) ? (int)$_GET['course'] : 0;
$period = $_GET['period'] ?? 'all';
$start_date = $_GET['start'] ?? '';
$end_date = $_GET['end'] ?? '';

$where = "t.user_id = ?";
$params = [$uid];

if ($course_filter > 0) {
    $where .= " AND t.course_id = ?";
    $params[] = $course_filter;
}

if ($period === 'week') {
    $where .= " AND t.due_date >= date('now', 'weekday 0', '-7 days') AND t.due_date < date('now', 'weekday 0', '+1 days')";
} elseif ($period === 'month') {
    $where .= " AND t.due_date >= date('now', 'start of month') AND t.due_date < date('now', 'start of month', '+1 month')";
} elseif ($start_date && $end_date) {
    $where .= " AND t.due_date >= ? AND t.due_date <= ?";
    $params[] = $start_date;
    $params[] = $end_date;
}

$stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks t WHERE $where");
$stmt->execute($params);
$total_tasks = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks t WHERE $where AND t.status = 'completed'");
$stmt->execute($params);
$completed_tasks = (int)$stmt->fetchColumn();

$pending = $total_tasks - $completed_tasks;
$completion_rate = $total_tasks > 0 ? round(($completed_tasks / $total_tasks) * 100) : 0;

$stmt = $pdo->prepare("SELECT t.*, c.course_name FROM tasks t LEFT JOIN courses c ON t.course_id = c.id WHERE $where ORDER BY t.due_date DESC");
$stmt->execute($params);
$all_tasks = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT id, course_name FROM courses WHERE user_id = ? ORDER BY course_name");
$stmt->execute([$uid]);
$courses = $stmt->fetchAll();

$course_stats = [];
foreach ($courses as $c) {
    $cid = $c['id'];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = ? AND course_id = ?");
    $stmt->execute([$uid, $cid]);
    $course_total = (int)$stmt->fetchColumn();
    if ($course_total === 0) continue;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = ? AND course_id = ? AND status = 'completed'");
    $stmt->execute([$uid, $cid]);
    $course_done = (int)$stmt->fetchColumn();
    $course_stats[] = [
        'name' => $c['course_name'],
        'total' => $course_total,
        'done' => $course_done,
        'rate' => round(($course_done / $course_total) * 100)
    ];
}

$stmt = $pdo->prepare("SELECT COUNT(*) FROM study_sessions ss JOIN study_plan sp ON ss.plan_id = sp.id WHERE sp.user_id = ?");
$stmt->execute([$uid]);
$sessions_completed = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM study_plan WHERE user_id = ? AND status = 'pending' AND plan_date >= date('now')");
$stmt->execute([$uid]);
$sessions_planned = (int)$stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Progress Report | StudySync</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="liquid-glass.css">
    <style>
        .filters { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; margin-bottom: 24px; }
        .filters select, .filters input { padding: 10px 14px; background: var(--bg-primary); border: 1px solid var(--border); border-radius: var(--radius-sm); color: var(--text-primary); font-size: 13px; }
        .filters button { padding: 10px 20px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .stat-card { padding: 20px; border-radius: var(--radius-sm); text-align: center; }
        .stat-number { font-size: 32px; font-weight: 700; line-height: 1.2; }
        .stat-label { font-size: 12px; color: var(--text-muted); margin-top: 4px; }
        .bar-wrap { height: 8px; background: var(--border); border-radius: 4px; overflow: hidden; margin-top: 12px; }
        .bar-fill { height: 100%; border-radius: 4px; transition: width 0.5s ease; }
        .course-row { display: flex; align-items: center; padding: 12px 0; border-bottom: 1px solid var(--border-light); }
        .course-row:last-child { border-bottom: none; }
        .course-name { flex: 1; font-size: 13px; font-weight: 500; }
        .course-bar { flex: 2; height: 6px; background: var(--border); border-radius: 3px; overflow: hidden; margin: 0 16px; }
        .course-bar-fill { height: 100%; border-radius: 3px; }
        .course-pct { font-size: 13px; font-weight: 600; min-width: 40px; text-align: right; color: var(--text-secondary); }
        .task-list { margin-top: 16px; }
        .task-row { display: flex; align-items: center; padding: 10px 12px; border-bottom: 1px solid var(--border-light); font-size: 13px; }
        .task-row:last-child { border-bottom: none; }
        .task-row .done { width: 20px; text-align: center; margin-right: 12px; font-size: 14px; }
        .task-row .name { flex: 1; }
        .task-row .course { color: var(--text-muted); font-size: 11px; margin-left: 8px; }
        .task-row .due { color: var(--text-muted); font-size: 11px; min-width: 70px; text-align: right; }
        .page-title { font-size: 24px; font-weight: 600; margin-bottom: 4px; }
    </style>
</head>
<body>
    <div class="app-container">
        <?php require_once 'includes/sidebar.php'; ?>
        <main class="main-content">
            <div class="page-title">📊 Progress Report</div>
            <p style="color: var(--text-muted); margin-bottom: 20px; font-size: 13px;">Track task completion and study progress</p>

            <form method="GET" class="filters">
                <select name="course">
                    <option value="0">All Courses</option>
                    <?php foreach ($courses as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $course_filter === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['course_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="period" onchange="this.form.submit()">
                    <option value="all" <?= $period === 'all' ? 'selected' : '' ?>>All Time</option>
                    <option value="week" <?= $period === 'week' ? 'selected' : '' ?>>This Week</option>
                    <option value="month" <?= $period === 'month' ? 'selected' : '' ?>>This Month</option>
                </select>
                <input type="date" name="start" value="<?= htmlspecialchars($start_date) ?>" placeholder="Start date">
                <input type="date" name="end" value="<?= htmlspecialchars($end_date) ?>" placeholder="End date">
                <button type="submit" class="btn-primary" style="padding: 10px 24px;">Filter</button>
                <a href="progress.php" style="font-size: 12px; color: var(--text-muted);">Clear</a>
            </form>

            <div class="stats-grid">
                <div class="stat-card section-card">
                    <div class="stat-number" style="color: var(--accent);"><?= $completion_rate ?>%</div>
                    <div class="stat-label">Completion Rate</div>
                    <div class="bar-wrap"><div class="bar-fill" style="width: <?= $completion_rate ?>%; background: var(--accent);"></div></div>
                </div>
                <div class="stat-card section-card">
                    <div class="stat-number" style="color: var(--success);"><?= $completed_tasks ?></div>
                    <div class="stat-label">Tasks Completed</div>
                </div>
                <div class="stat-card section-card">
                    <div class="stat-number" style="color: var(--warning);"><?= $pending ?></div>
                    <div class="stat-label">Pending Tasks</div>
                </div>
                <div class="stat-card section-card">
                    <div class="stat-number" style="color: var(--accent);"><?= $sessions_completed ?></div>
                    <div class="stat-label">Sessions Done</div>
                </div>
                <div class="stat-card section-card">
                    <div class="stat-number" style="color: var(--text-secondary);"><?= $sessions_planned ?></div>
                    <div class="stat-label">Sessions Planned</div>
                </div>
            </div>

            <?php if (count($course_stats) > 0): ?>
            <div class="section-card" style="margin-bottom: 24px;">
                <h3 style="font-size: 15px; font-weight: 600; margin-bottom: 16px;">Per-Course Progress</h3>
                <?php foreach ($course_stats as $cs): ?>
                <div class="course-row">
                    <div class="course-name"><?= htmlspecialchars($cs['name']) ?></div>
                    <div class="course-bar"><div class="course-bar-fill" style="width: <?= $cs['rate'] ?>%; background: var(--accent);"></div></div>
                    <div class="course-pct"><?= $cs['done'] ?>/<?= $cs['total'] ?> (<?= $cs['rate'] ?>%)</div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="section-card">
                <h3 style="font-size: 15px; font-weight: 600; margin-bottom: 12px;">Task List</h3>
                <?php if (count($all_tasks) > 0): ?>
                <div class="task-list">
                    <?php foreach ($all_tasks as $task): 
                        $is_done = $task['status'] === 'completed';
                        $overdue = !$is_done && $task['due_date'] < date('Y-m-d');
                    ?>
                    <div class="task-row" style="<?= $is_done ? 'opacity: 0.6;' : '' ?>">
                        <div class="done"><?= $is_done ? '✅' : ($overdue ? '🔴' : '⏳') ?></div>
                        <div class="name">
                            <?= htmlspecialchars($task['title']) ?>
                            <span class="course"><?= htmlspecialchars($task['course_name'] ?: '') ?></span>
                        </div>
                        <div class="due" style="<?= $overdue ? 'color: var(--danger); font-weight: 600;' : '' ?>">
                            <?= $overdue ? 'Overdue' : date('M j', strtotime($task['due_date'])) ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="empty-state" style="padding: 32px;">
                    <div class="empty-icon">📭</div>
                    <div class="empty-text">No tasks found for this filter</div>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>
