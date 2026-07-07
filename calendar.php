<?php
require_once 'db_config.php';

// Determine the week to display
$week_offset = (int)($_GET['week'] ?? 0);
$monday = strtotime("last monday" . ($week_offset >= 0 ? "+$week_offset weeks" : "$week_offset weeks"));
if (date('w') == 1) $monday = strtotime("today" . ($week_offset >= 0 ? "+$week_offset weeks" : "$week_offset weeks"));

$days = [];
for ($i = 0; $i < 7; $i++) {
    $ts = strtotime("+$i days", $monday);
    $days[] = [
        'date' => date('Y-m-d', $ts),
        'label' => date('D M j', $ts)
    ];
}

$week_start = $days[0]['date'];
$week_end = $days[6]['date'];

// Get study plan for this week
$stmt = $pdo->prepare("SELECT sp.*, c.color as course_color, c.course_name
    FROM study_plan sp
    LEFT JOIN tasks t ON sp.task_id = t.id
    LEFT JOIN courses c ON t.course_id = c.id
    WHERE sp.user_id = ? AND sp.plan_date >= ? AND sp.plan_date <= ?
    ORDER BY sp.plan_date, sp.start_time");
$stmt->execute([$_SESSION['user_id'], $week_start, $week_end]);
$plan_items = $stmt->fetchAll();

// Group by date
$plan_by_date = [];
foreach ($plan_items as $item) {
    $plan_by_date[$item['plan_date']][] = $item;
}

$status_colors = [
    'pending' => 'var(--accent)',
    'completed' => 'var(--success)',
    'missed' => 'var(--danger)',
    'postponed' => 'var(--warning)'
];

$hours = [];
for ($h = 6; $h <= 22; $h++) {
    $hours[] = sprintf('%02d:00', $h);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendar | StudySync</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="liquid-glass.css">
    <style>
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .page-header h1 { font-size: 24px; font-weight: 600; }
        .week-nav { display: flex; align-items: center; gap: 16px; }
        .week-nav a { padding: 8px 16px; background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-sm); text-decoration: none; color: var(--text-primary); font-size: 13px; }
        .week-nav a:hover { background: var(--bg-primary); }
        .week-nav span { font-size: 14px; font-weight: 500; color: var(--text-secondary); }
        .cal-grid { display: grid; grid-template-columns: 60px repeat(7, 1fr); border: 1px solid var(--border); border-radius: var(--radius-md); overflow: hidden; background: var(--bg-card); }
        .cal-header { background: var(--bg-secondary); font-weight: 600; font-size: 12px; padding: 10px 8px; text-align: center; border-bottom: 1px solid var(--border); color: var(--text-secondary); }
        .cal-header.today { color: var(--accent); }
        .cal-time { font-size: 11px; color: var(--text-muted); text-align: right; padding: 4px 8px; border-right: 1px solid var(--border); border-bottom: 1px solid var(--border-light); height: 48px; }
        .cal-cell { border-right: 1px solid var(--border-light); border-bottom: 1px solid var(--border-light); height: 48px; position: relative; padding: 2px; }
        .cal-cell.today { background: var(--accent-soft); }
        .cal-event { padding: 4px 6px; margin-bottom: 2px; border-radius: 4px; font-size: 10px; cursor: default; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
        .cal-event:hover { overflow: visible; white-space: normal; }
        .empty-state { text-align: center; padding: 48px; color: var(--text-muted); }
        .empty-icon { font-size: 48px; margin-bottom: 16px; }
        .cal-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .cal-scroll .cal-grid { min-width: 640px; }
        @media (max-width: 900px) { .cal-grid { font-size: 10px; grid-template-columns: 50px repeat(7, 1fr); } .cal-header { font-size: 10px; } }
    </style>
</head>
<body>
    <div class="app-container">
        <?php require_once 'includes/sidebar.php'; ?>
        <main class="main-content">
            <div class="page-header">
                <h1>Weekly Schedule</h1>
                <div class="week-nav">
                    <a href="?week=<?= $week_offset - 1 ?>">← Previous</a>
                    <span><?= date('M j', $monday) ?> – <?= date('M j', strtotime($week_end)) ?></span>
                    <a href="?week=<?= $week_offset + 1 ?>">Next →</a>
                </div>
            </div>

            <?php
            $has_items = !empty($plan_items);
            if (!$has_items):
            ?>
                <div class="empty-state">
                    <div class="empty-icon">📅</div>
                    <div class="empty-text">No study sessions scheduled for this week</div>
                    <a href="dashboard.php" class="btn-primary" style="display: inline-block; margin-top: 16px; padding: 10px 24px; text-decoration: none; border-radius: var(--radius-sm);">Go to Dashboard</a>
                </div>
            <?php endif; ?>

            <div class="cal-scroll">
            <div class="cal-grid">
                <div class="cal-header" style="border-right: 1px solid var(--border);">Time</div>
                <?php foreach ($days as $d): ?>
                    <div class="cal-header<?= $d['date'] === date('Y-m-d') ? ' today' : '' ?>"><?= $d['label'] ?></div>
                <?php endforeach; ?>

                <?php foreach ($hours as $hour): ?>
                    <div class="cal-time"><?= date('g A', strtotime($hour)) ?></div>
                    <?php foreach ($days as $d): ?>
                        <?php
                        $cell_date = $d['date'];
                        $is_today = $cell_date === date('Y-m-d');
                        $events_html = '';

                        if (isset($plan_by_date[$cell_date])) {
                            foreach ($plan_by_date[$cell_date] as $item) {
                                $start_h = (int)date('H', strtotime($item['start_time']));
                                $end_h = (int)date('H', strtotime($item['end_time']));
                                $current_h = (int)substr($hour, 0, 2);
                                $status_color = $status_colors[$item['status']] ?? '#2D6A4F';

                                // Show event in its starting hour cell only
                                if ($start_h === $current_h) {
                                    $events_html .= "<div class='cal-event' style='background: {$status_color}18; color: {$status_color}; border-left: 3px solid {$status_color}; height: 40px;'>"
                                        . htmlspecialchars($item['task_title'])
                                        . " <span style='opacity:0.7'>" . date('g:i', strtotime($item['start_time'])) . "–" . date('g:i', strtotime($item['end_time'])) . "</span>"
                                        . "</div>";
                                }
                            }
                        }
                        ?>
                        <div class="cal-cell<?= $is_today ? ' today' : '' ?>"><?= $events_html ?></div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
            </div>
        </main>
    </div>
</body>
</html>