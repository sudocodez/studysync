<?php
require_once 'db_config.php';

$today = date('Y-m-d');
// Week: Monday to Sunday
$monday = date('Y-m-d', strtotime('monday this week'));
$sunday = date('Y-m-d', strtotime('sunday this week'));
$prev_monday = date('Y-m-d', strtotime('monday last week'));
$prev_sunday = date('Y-m-d', strtotime('sunday last week'));

$uid = $_SESSION['user_id'];

// Current week study sessions
$stmt = $pdo->prepare("SELECT COALESCE(SUM(duration_minutes), 0) as total_mins,
    COUNT(*) as total_sessions,
    COUNT(CASE WHEN duration_minutes >= 25 THEN 1 END) as productive_sessions
    FROM study_sessions WHERE user_id = ? AND start_time >= ? AND start_time < ?");
$stmt->execute([$uid, $monday, date('Y-m-d', strtotime($sunday . ' +1 day'))]);
$sessions_data = $stmt->fetch();
$hours_studied = round((int)$sessions_data['total_mins'] / 60, 1);
$session_count = (int)$sessions_data['total_sessions'];

// Study plan for current week
$stmt = $pdo->prepare("SELECT status, COUNT(*) as cnt FROM study_plan
    WHERE user_id = ? AND plan_date >= ? AND plan_date <= ?
    GROUP BY status");
$stmt->execute([$uid, $monday, $sunday]);
$plan_rows = $stmt->fetchAll();
$plan_completed = 0; $plan_missed = 0; $plan_pending = 0;
foreach ($plan_rows as $r) {
    if ($r['status'] === 'completed') $plan_completed = (int)$r['cnt'];
    elseif ($r['status'] === 'missed') $plan_missed = (int)$r['cnt'];
    elseif ($r['status'] === 'pending') $plan_pending = (int)$r['cnt'];
}
// Count overdue (pending on dates before today)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM study_plan WHERE user_id = ? AND plan_date < ? AND plan_date >= ? AND status = 'pending'");
$stmt->execute([$uid, $today, $monday]);
$overdue = (int)$stmt->fetchColumn();
$plan_missed += $overdue;
$plan_pending -= $overdue;

$total_planned = $plan_completed + $plan_missed + $plan_pending;
$on_time_denom = $plan_completed + $plan_missed;
$on_time_rate = $on_time_denom > 0 ? round($plan_completed / $on_time_denom * 100) : 0;
$on_time_rate = min($on_time_rate, 100);

// Tasks completed this week
$stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = ? AND completed_at >= ? AND completed_at < ?");
$stmt->execute([$uid, $monday, date('Y-m-d', strtotime($sunday . ' +1 day'))]);
$tasks_completed = (int)$stmt->fetchColumn();

// Tasks due this week (including overdue from before)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = ? AND due_date <= ? AND due_date >= ? AND status = 'pending'");
$stmt->execute([$uid, $sunday, $monday]);
$tasks_pending_due = (int)$stmt->fetchColumn();
$tasks_total_due = $tasks_completed + $tasks_pending_due;

// User stats
$stmt = $pdo->prepare("SELECT * FROM user_stats WHERE user_id = ?");
$stmt->execute([$uid]);
$stats = $stmt->fetch();
$streak = $stats ? (int)$stats['current_streak_days'] : 0;

// Last week comparison
$stmt = $pdo->prepare("SELECT COALESCE(SUM(duration_minutes), 0) FROM study_sessions WHERE user_id = ? AND start_time >= ? AND start_time < ?");
$stmt->execute([$uid, $prev_monday, date('Y-m-d', strtotime($prev_sunday . ' +1 day'))]);
$prev_mins = (int)$stmt->fetchColumn();
$prev_hours = round($prev_mins / 60, 1);
$hours_change = $prev_hours > 0 ? round(($hours_studied - $prev_hours) / $prev_hours * 100) : ($hours_studied > 0 ? 100 : 0);

// Productivity patterns
$stmt = $pdo->prepare("SELECT * FROM productivity_patterns WHERE user_id = ? ORDER BY score DESC");
$stmt->execute([$uid]);
$patterns = $stmt->fetchAll();
$best_time = $patterns[0] ?? null;
$worst_time = count($patterns) > 0 ? $patterns[count($patterns) - 1] : null;

// Daily breakdown (study_sessions per day of week)
$day_names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$daily_mins = array_fill(0, 7, 0);
$stmt = $pdo->prepare("SELECT start_time, duration_minutes FROM study_sessions WHERE user_id = ? AND start_time >= ? AND start_time < ?");
$stmt->execute([$uid, $monday, date('Y-m-d', strtotime($sunday . ' +1 day'))]);
foreach ($stmt->fetchAll() as $row) {
    $dow = (int)date('w', strtotime($row['start_time']));
    $daily_mins[$dow] += (int)$row['duration_minutes'];
}
$max_daily_mins = max($daily_mins) ?: 1;

// Generate insights
$insights = [];
if ($total_planned > 0) {
    if ($on_time_rate >= 90) {
        $insights[] = ['icon' => 'trophy', 'text' => "Excellent adherence! You completed {$on_time_rate}% of planned sessions.", 'type' => 'good'];
    } elseif ($on_time_rate >= 70) {
        $insights[] = ['icon' => 'thumbs-up', 'text' => "Good consistency ({$on_time_rate}% on-time). Try to reduce missed sessions.", 'type' => 'ok'];
    } else {
        $insights[] = ['icon' => 'warning', 'text' => "{$on_time_rate}% on-time rate needs attention. Review your workload and availability.", 'type' => 'warn'];
    }
}
if ($hours_studied == 0) {
    $insights[] = ['icon' => 'clock', 'text' => 'No study sessions logged this week. Start with small 25-minute blocks.', 'type' => 'warn'];
} elseif ($hours_studied < 5) {
    $insights[] = ['icon' => 'clock', 'text' => "Only {$hours_studied}h studied this week. Aim for at least 5–10 hours.", 'type' => 'warn'];
} elseif ($hours_studied > 25) {
    $insights[] = ['icon' => 'flame', 'text' => "{$hours_studied}h is a lot! Make sure to take breaks to avoid burnout.", 'type' => 'ok'];
} else {
    $insights[] = ['icon' => 'check-circle', 'text' => "{$hours_studied}h studied this week — good pace!", 'type' => 'good'];
}
if ($streak > 0) {
    $insights[] = ['icon' => 'zap', 'text' => "Current streak: {$streak} day" . ($streak > 1 ? 's' : '') . ". Keep it going!", 'type' => 'good'];
}
if ($best_time && $best_time['score'] >= 0.6) {
    $bucket = $best_time['time_bucket'];
    $day = $day_names[(int)$best_time['day_of_week']];
    if ($bucket === 'morning') $bucket_name = 'mornings';
    elseif ($bucket === 'afternoon') $bucket_name = 'afternoons';
    else $bucket_name = 'evenings';
    $insights[] = ['icon' => 'trending-up', 'text' => "Best productivity: {$day} {$bucket_name} (score: " . round($best_time['score'] * 100) . "%). Schedule harder tasks here.", 'type' => 'good'];
}
if ($worst_time && count($patterns) > 1 && $worst_time['score'] < 0.4) {
    $bucket = $worst_time['time_bucket'];
    $day = $day_names[(int)$worst_time['day_of_week']];
    if ($bucket === 'morning') $bucket_name = 'mornings';
    elseif ($bucket === 'afternoon') $bucket_name = 'afternoons';
    else $bucket_name = 'evenings';
    $insights[] = ['icon' => 'trending-down', 'text' => "Lowest productivity: {$day} {$bucket_name}. Consider lighter review here or taking a break.", 'type' => 'warn'];
}
if ($tasks_total_due > 0) {
    $task_rate = round($tasks_completed / $tasks_total_due * 100);
    if ($task_rate < 50) {
        $insights[] = ['icon' => 'tasks', 'text' => "Completed {$task_rate}% of due tasks. Prioritize upcoming deadlines.", 'type' => 'warn'];
    } else {
        $insights[] = ['icon' => 'tasks', 'text' => "Completed {$task_rate}% of due tasks. Good progress!", 'type' => 'good'];
    }
}
if ($hours_change > 20) {
    $insights[] = ['icon' => 'arrow-up', 'text' => "{$hours_change}% more study hours than last week — great improvement!", 'type' => 'good'];
} elseif ($hours_change < -20) {
    $insights[] = ['icon' => 'arrow-down', 'text' => "{$hours_change}% fewer hours than last week. Try to bounce back.", 'type' => 'warn'];
}
if ($overdue > 2) {
    $insights[] = ['icon' => 'alert-triangle', 'text' => "{$overdue} sessions are overdue. Regenerate your plan to catch up.", 'type' => 'warn', 'link' => 'dashboard.php'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Weekly Summary | StudySync</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .page-header { margin-bottom: 24px; }
        .page-header h1 { font-size: 24px; font-weight: 600; }
        .page-header p { font-size: 13px; color: var(--text-muted); margin-top: 4px; }
        .week-nav { display: flex; align-items: center; gap: 12px; margin-bottom: 24px; font-size: 14px; color: var(--text-secondary); }
        .summary-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px; }
        .summary-card { background: var(--bg-card); border-radius: var(--radius-lg); border: 1px solid var(--border); padding: 20px; text-align: center; }
        .summary-card .value { font-size: 28px; font-weight: 700; color: var(--accent); }
        .summary-card .label { font-size: 12px; color: var(--text-muted); margin-top: 4px; }
        .summary-card .sub { font-size: 11px; color: var(--text-secondary); margin-top: 2px; }
        .summary-card .change-up { color: var(--success); }
        .summary-card .change-down { color: var(--danger); }
        .detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px; }
        .card { background: var(--bg-card); border-radius: var(--radius-lg); border: 1px solid var(--border); padding: 24px; }
        .card h2 { font-size: 16px; font-weight: 600; margin-bottom: 16px; }
        .bar-group { display: flex; flex-direction: column; gap: 8px; }
        .bar-row { display: flex; align-items: center; gap: 10px; }
        .bar-label { width: 90px; font-size: 12px; color: var(--text-secondary); text-align: right; flex-shrink: 0; }
        .bar-track { flex: 1; height: 20px; background: var(--bg-primary); border-radius: 10px; overflow: hidden; }
        .bar-fill { height: 100%; border-radius: 10px; transition: width 0.6s ease; }
        .bar-fill.good { background: var(--success); }
        .bar-fill.ok { background: var(--warning); }
        .bar-fill.low { background: var(--danger); }
        .bar-value { width: 40px; font-size: 11px; color: var(--text-muted); text-align: left; flex-shrink: 0; }
        .insights-list { display: flex; flex-direction: column; gap: 10px; }
        .insight-item { display: flex; align-items: flex-start; gap: 12px; padding: 12px 16px; border-radius: var(--radius-sm); font-size: 13px; line-height: 1.4; }
        .insight-item.good { background: rgba(16,185,129,0.08); border-left: 3px solid var(--success); }
        .insight-item.ok { background: rgba(245,158,11,0.08); border-left: 3px solid var(--warning); }
        .insight-item.warn { background: rgba(239,68,68,0.08); border-left: 3px solid var(--danger); }
        .insight-item .insight-icon { flex-shrink: 0; width: 20px; text-align: center; font-size: 14px; }
        .insight-item .insight-link { color: var(--accent); text-decoration: none; font-weight: 500; }
        .insight-item .insight-link:hover { text-decoration: underline; }
        .pie-container { display: flex; align-items: center; justify-content: center; gap: 20px; }
        .pie-svg { width: 120px; height: 120px; }
        .pie-legend { display: flex; flex-direction: column; gap: 6px; font-size: 12px; }
        .pie-legend-item { display: flex; align-items: center; gap: 6px; }
        .legend-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
        .empty-state { text-align: center; padding: 40px 20px; color: var(--text-muted); font-size: 14px; }
        .quick-link { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; background: var(--accent); color: white; border-radius: var(--radius-sm); font-size: 13px; text-decoration: none; }
        .quick-link:hover { opacity: 0.9; }
        @media (max-width: 768px) { .summary-grid { grid-template-columns: repeat(2, 1fr); } .detail-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="app-container">
        <?php require_once 'includes/sidebar.php'; ?>
        <main class="main-content">
            <div class="page-header">
                <h1>Weekly Performance Summary</h1>
                <p><?= date('F j', strtotime($monday)) ?> – <?= date('F j, Y', strtotime($sunday)) ?></p>
            </div>

            <!-- Summary Cards -->
            <div class="summary-grid">
                <div class="summary-card">
                    <div class="value"><?= $hours_studied ?></div>
                    <div class="label">Hours Studied</div>
                    <div class="sub <?= $hours_change >= 0 ? 'change-up' : 'change-down' ?>">
                        <?= $hours_change >= 0 ? '+' : '' ?><?= $hours_change ?>% vs last week
                    </div>
                </div>
                <div class="summary-card">
                    <div class="value"><?= $plan_completed ?>/<?= $total_planned ?></div>
                    <div class="label">Sessions Completed / Total</div>
                    <div class="sub"><?= $plan_pending ?> pending, <?= $plan_missed ?> missed</div>
                </div>
                <div class="summary-card">
                    <div class="value"><?= $on_time_rate ?>%</div>
                    <div class="label">On-Time Rate</div>
                    <div class="sub"><?= $streak > 0 ? "Streak: {$streak}d" : 'No active streak' ?></div>
                </div>
                <div class="summary-card">
                    <div class="value"><?= $tasks_completed ?></div>
                    <div class="label">Tasks Completed</div>
                    <div class="sub"><?= $tasks_pending_due ?> still due this week</div>
                </div>
            </div>

            <!-- Details -->
            <div class="detail-grid">
                <!-- Daily breakdown -->
                <div class="card">
                    <h2>Daily Study Time</h2>
                    <?php if ($hours_studied > 0): ?>
                    <div class="bar-group">
                        <?php foreach ([1,2,3,4,5,6,0] as $d): $mins = $daily_mins[$d]; ?>
                        <div class="bar-row">
                            <div class="bar-label"><?= substr($day_names[$d], 0, 3) ?></div>
                            <div class="bar-track">
                                <div class="bar-fill <?= $mins == 0 ? 'low' : ($mins <= 60 ? 'ok' : 'good') ?>" style="width: <?= round($mins / $max_daily_mins * 100) ?>%"></div>
                            </div>
                            <div class="bar-value"><?= $mins > 0 ? round($mins / 60, 1) . 'h' : '—' ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="empty-state">No study sessions recorded yet this week.</div>
                    <?php endif; ?>
                </div>

                <!-- Session completion -->
                <div class="card">
                    <h2>Session Completion</h2>
                    <div class="pie-container">
                        <svg class="pie-svg" viewBox="0 0 36 36">
                            <?php
                            $total = max(1, $plan_completed + $plan_missed + $plan_pending);
                            $r = 15.9155;
                            $pct = round($plan_completed / $total * 100);
                            $circumference = 2 * pi() * $r;
                            $comp_len = $circumference * max(0, $plan_completed) / $total;
                            $miss_len = $circumference * max(0, $plan_missed) / $total;
                            $pend_len = $circumference * max(0, $plan_pending) / $total;
                            ?>
                            <circle cx="18" cy="18" r="<?= $r ?>" fill="none" stroke="var(--border)" stroke-width="3.5"/>
                            <?php if ($plan_completed > 0): ?>
                            <circle cx="18" cy="18" r="<?= $r ?>" fill="none" stroke="var(--success)" stroke-width="3.5"
                                stroke-dasharray="<?= $comp_len ?> <?= $circumference - $comp_len ?>"
                                stroke-dashoffset="0" transform="rotate(-90 18 18)"/>
                            <?php endif; ?>
                            <?php if ($plan_missed > 0): ?>
                            <circle cx="18" cy="18" r="<?= $r ?>" fill="none" stroke="var(--danger)" stroke-width="3.5"
                                stroke-dasharray="<?= $miss_len ?> <?= $circumference - $miss_len ?>"
                                stroke-dashoffset="<?= -$comp_len ?>" transform="rotate(-90 18 18)"/>
                            <?php endif; ?>
                            <?php if ($plan_pending > 0): ?>
                            <circle cx="18" cy="18" r="<?= $r ?>" fill="none" stroke="var(--warning)" stroke-width="3.5"
                                stroke-dasharray="<?= $pend_len ?> <?= $circumference - $pend_len ?>"
                                stroke-dashoffset="<?= -($comp_len + $miss_len) ?>" transform="rotate(-90 18 18)"/>
                            <?php endif; ?>
                            <text x="18" y="20" text-anchor="middle" font-size="8" font-weight="700" fill="currentColor"><?= $pct ?>%</text>
                        </svg>
                        <div class="pie-legend">
                            <div class="pie-legend-item"><span class="legend-dot" style="background:var(--success)"></span> Completed (<?= $plan_completed ?>)</div>
                            <div class="pie-legend-item"><span class="legend-dot" style="background:var(--danger)"></span> Missed (<?= $plan_missed ?>)</div>
                            <div class="pie-legend-item"><span class="legend-dot" style="background:var(--warning)"></span> Pending (<?= $plan_pending ?>)</div>
                        </div>
                    </div>
                    <?php if ($total_planned === 0): ?>
                    <div style="text-align:center; margin-top: 8px; font-size: 12px; color: var(--text-muted);">No sessions planned yet. <a href="dashboard.php" style="color:var(--accent)">Generate a plan</a> to see your breakdown.</div>
                    <?php endif; ?>
                </div>

                <!-- Insights -->
                <div class="card" style="grid-column: 1 / -1;">
                    <h2>What Worked & What Needs Attention</h2>
                    <?php if (!empty($insights)): ?>
                    <div class="insights-list">
                        <?php foreach ($insights as $insight): ?>
                        <div class="insight-item <?= $insight['type'] ?>">
                            <span class="insight-icon">
                                <?php
                                $icons = [
                                    'trophy' => '🏆', 'thumbs-up' => '👍', 'warning' => '⚠️', 'clock' => '⏰',
                                    'flame' => '🔥', 'check-circle' => '✅', 'zap' => '⚡',
                                    'trending-up' => '📈', 'trending-down' => '📉', 'tasks' => '📋',
                                    'arrow-up' => '⬆️', 'arrow-down' => '⬇️', 'alert-triangle' => '🚩',
                                ];
                                echo $icons[$insight['icon']] ?? '💡';
                                ?>
                            </span>
                            <span><?= htmlspecialchars($insight['text']) ?>
                                <?php if (isset($insight['link'])): ?>
                                <a href="<?= $insight['link'] ?>" class="insight-link">Take action →</a>
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="empty-state">Start using StudySync to get personalized weekly insights!</div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
