<?php
require_once 'db_config.php';

$today = date('Y-m-d');
$current_time = date('h:i A');
$day_name = date('l, F j');

// Get user name
$stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();
$user_name = $user ? $user['username'] : 'Student';

// Get tasks
$stmt = $pdo->prepare("SELECT id, title, type, due_date, estimated_hours, priority, status FROM tasks WHERE user_id = ? ORDER BY priority DESC, due_date ASC");
$stmt->execute([$_SESSION['user_id']]);
$tasks = $stmt->fetchAll();

// Calculate stats
$total_tasks = count($tasks);
$completed_tasks = count(array_filter($tasks, fn($t) => $t['status'] == 'completed'));
$pending_tasks = $total_tasks - $completed_tasks;
$completion_rate = $total_tasks > 0 ? round(($completed_tasks / $total_tasks) * 100) : 0;

// Overdue tasks
$overdue_tasks = array_filter($tasks, function($t) {
    return $t['status'] != 'completed' && $t['due_date'] < date('Y-m-d');
});

// Today's schedule
$stmt = $pdo->prepare("SELECT * FROM study_plan WHERE user_id = ? AND plan_date = ? ORDER BY start_time LIMIT 5");
$stmt->execute([$_SESSION['user_id'], $today]);
$today_plan = $stmt->fetchAll();

// Upcoming tasks
$stmt = $pdo->prepare("SELECT t.id, t.title, t.type, t.due_date, t.estimated_hours, t.priority, t.status, t.course_id, t.description, COALESCE(c.course_name, '') AS course_name FROM tasks t LEFT JOIN courses c ON t.course_id = c.id WHERE t.user_id = ? AND t.status != 'completed' AND t.due_date >= ? ORDER BY t.due_date ASC LIMIT 10");
$stmt->execute([$_SESSION['user_id'], $today]);
$upcoming_tasks = $stmt->fetchAll();

// Fetch courses for dropdown
$stmt = $pdo->prepare("SELECT id, course_name, course_code FROM courses WHERE user_id = ? ORDER BY course_name");
$stmt->execute([$_SESSION['user_id']]);
$courses = $stmt->fetchAll();

// Calculate time left and risk for each task
$now = time();
foreach ($upcoming_tasks as &$task) {
    $due_ts = strtotime($task['due_date']);
    $diff = $due_ts - $now;
    $task['days_left'] = max(0, floor($diff / 86400));
    $task['hours_left'] = max(0, floor($diff / 3600));

    if ($diff <= 0) {
        $task['risk'] = 'overdue';
        $task['risk_label'] = 'OVERDUE';
    } elseif ($diff <= 86400) {
        $task['risk'] = 'critical';
        $task['risk_label'] = 'CRITICAL';
    } elseif ($diff <= 172800) {
        $task['risk'] = 'high';
        $task['risk_label'] = 'HIGH RISK';
    } elseif ($diff <= 432000) {
        $task['risk'] = 'warning';
        $task['risk_label'] = 'DUE SOON';
    } else {
        $task['risk'] = 'on_track';
        $task['risk_label'] = floor($diff / 86400) . ' DAYS';
    }
}
unset($task);

// Get this week's study plan
$week_start = date('Y-m-d', strtotime('monday this week'));
$week_end = date('Y-m-d', strtotime('sunday this week'));
$stmt = $pdo->prepare("SELECT * FROM study_plan WHERE user_id = ? AND plan_date >= ? AND plan_date <= ? AND status != 'missed' ORDER BY plan_date, start_time");
$stmt->execute([$_SESSION['user_id'], $week_start, $week_end]);
$week_plan = $stmt->fetchAll();

// Calculate workload per day
$stmt = $pdo->prepare("SELECT * FROM available_time WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$all_avail = $stmt->fetchAll();

$workload = [];
for ($i = 0; $i < 7; $i++) {
    $date = date('Y-m-d', strtotime("$week_start + $i days"));
    $dow = (int)date('w', strtotime($date));

    // Available minutes for this day
    $avail_mins = 0;
    foreach ($all_avail as $slot) {
        if ($slot['is_recurring'] && (int)$slot['day_of_week'] === $dow) {
            $avail_mins += (strtotime($slot['end_time']) - strtotime($slot['start_time'])) / 60;
        } elseif (!$slot['is_recurring'] && ($slot['specific_date'] ?? '') === $date) {
            $avail_mins += (strtotime($slot['end_time']) - strtotime($slot['start_time'])) / 60;
        }
    }

    // Scheduled minutes for this day
    $sched_mins = 0;
    foreach ($week_plan as $p) {
        if ($p['plan_date'] === $date) {
            $sched_mins += (strtotime($p['end_time']) - strtotime($p['start_time'])) / 60;
        }
    }

    $pct = $avail_mins > 0 ? round(($sched_mins / $avail_mins) * 100) : ($sched_mins > 0 ? 100 : 0);
    $workload[$date] = [
        'scheduled' => round($sched_mins),
        'available' => round($avail_mins),
        'pct' => min($pct, 100),
        'overload' => $pct > 100,
    ];
}

// Get user stats
$stmt = $pdo->prepare("SELECT * FROM user_stats WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user_stats = $stmt->fetch();

// Determine greeting based on hour
$hour = date('H');
if ($hour >= 5 && $hour < 12) $greeting = "Good Morning";
elseif ($hour >= 12 && $hour < 17) $greeting = "Good Afternoon";
else $greeting = "Good Evening";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>StudySync | Dashboard</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="liquid-glass.css">
</head>
<body>
    <div class="app-container">
        <?php require_once 'includes/sidebar.php'; ?>

        <!-- Main Content -->
        <main class="main-content">
            <div class="top-header">
                <div></div>
                <div class="date-time">
                    <div class="time"><?= $current_time ?></div>
                    <div class="date"><?= $day_name ?></div>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?= $pending_tasks ?></div>
                    <div class="stat-label">pending tasks</div>
                </div>
                <div class="stat-card" onclick="showPieChart()" style="cursor: pointer;">
                    <div class="stat-value"><?= $completion_rate ?>%</div>
                    <div class="stat-label">completion rate</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= number_format($user_stats['total_study_hours'] ?? 0, 1) ?></div>
                    <div class="stat-label">study hours</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= ($user_stats['current_streak_days'] ?? 0) ?>d</div>
                    <div class="stat-label">current streak</div>
                </div>
            </div>

            <!-- Greeting Section -->
            <div class="greeting-section" style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <div class="greeting-title"><?= $greeting ?>, <?= htmlspecialchars($user_name) ?></div>
                    <div class="greeting-subtitle">You have <?= $pending_tasks ?> task<?= $pending_tasks != 1 ? 's' : '' ?> due today</div>
                </div>
                <div style="text-align: right; background: rgba(255,255,255,0.1); padding: 10px 18px; border-radius: var(--radius-sm); border: 1px solid rgba(255,255,255,0.2);">
                    <div style="font-size: 22px; font-weight: 700;"><?= ($user_stats['longest_streak'] ?? 0) ?>d</div>
                    <div style="font-size: 11px; opacity: 0.8; letter-spacing: 0.3px;">LONGEST STREAK</div>
                </div>
            </div>

            <?php if (isset($_GET['plan_generated'])): ?>
                <div style="padding: 12px 16px; background: var(--accent-soft); border: 1px solid var(--accent); border-radius: var(--radius-sm); color: var(--accent); font-size: 13px; margin-bottom: 20px;">Study plan generated — your schedule is ready below.</div>
            <?php endif; ?>
            <?php if (isset($_GET['error'])): ?>
                <div style="padding: 12px 16px; background: rgba(239,68,68,0.1); border: 1px solid var(--danger); border-radius: var(--radius-sm); color: var(--danger); font-size: 13px; margin-bottom: 20px;">
                    <?php if ($_GET['error'] === 'invalid_task'): ?>
                        ❌ Task not saved. Title, due date, and estimated hours are required.
                    <?php elseif ($_GET['error'] === 'missing_fields'): ?>
                        ❌ Please fill in all required fields.
                    <?php else: ?>
                        ❌ An error occurred. Please try again.
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Weekly Timetable Summary -->
            <?php if (count($week_plan) > 0): ?>
                <div class="section-card" style="margin-bottom: 24px;">
                    <div class="section-header">
                        <h2>This Week's Timetable</h2>
                        <a href="calendar.php">View Full Calendar →</a>
                    </div>
                    <div style="padding: 16px 24px;">
                        <?php
                        $days_short = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                        $plan_by_day = [];
                        foreach ($week_plan as $p) {
                            $plan_by_day[$p['plan_date']][] = $p;
                        }
                        $day_names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                        ?>
                        <div class="weekly-timetable">
                            <div style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 8px;">
                            <?php for ($i = 0; $i < 7; $i++):
                                $date = date('Y-m-d', strtotime("$week_start + $i days"));
                                $is_today = $date === $today;
                                $day_plans = $plan_by_day[$date] ?? [];
                                $wl = $workload[$date] ?? ['scheduled' => 0, 'available' => 0, 'pct' => 0, 'overload' => false];
                                $wl_color = $wl['overload'] ? 'var(--danger)' : ($wl['pct'] > 80 ? 'var(--warning)' : 'var(--success)');
                            ?>
                                <div style="background: <?= $is_today ? 'var(--accent-soft)' : 'var(--bg-primary)' ?>; border-radius: var(--radius-sm); padding: 10px; border: 1px solid <?= $is_today ? 'var(--accent)' : 'var(--border-light)' ?>;">
                                    <div style="font-size: 11px; font-weight: 600; color: <?= $is_today ? 'var(--accent)' : 'var(--text-muted)' ?>; margin-bottom: 8px; text-align: center;"><?= $days_short[$i] ?> <?= date('j', strtotime($date)) ?></div>
                                    <?php if (count($day_plans) > 0): ?>
                                        <?php foreach ($day_plans as $p): ?>
                                            <div style="font-size: 10px; padding: 4px 6px; background: var(--bg-card); border-radius: 4px; margin-bottom: 4px; border-left: 3px solid var(--accent);">
                                                <div style="font-weight: 500; color: var(--text-primary); overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?= htmlspecialchars($p['task_title']) ?></div>
                                                <div style="color: var(--text-muted);"><?= date('g:i', strtotime($p['start_time'])) ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div style="text-align: center; color: var(--text-muted); font-size: 10px; padding: 8px 0;">—</div>
                                    <?php endif; ?>
                                    <?php if ($wl['scheduled'] > 0): ?>
                                        <div style="margin-top: 6px; height: 4px; background: var(--border-light); border-radius: 2px; overflow: hidden;">
                                            <div style="height: 100%; width: <?= $wl['pct'] ?>%; background: <?= $wl_color ?>; border-radius: 2px; transition: width 0.3s;"></div>
                                        </div>
                                        <div style="font-size: 9px; color: <?= $wl_color ?>; margin-top: 2px; text-align: center; font-weight: 600;">
                                            <?= $wl['scheduled'] ?>m / <?= $wl['available'] > 0 ? $wl['available'] . 'm' : 'no avail' ?>
                                            <?php if ($wl['overload']): ?>⚠<?php endif; ?>
                                        </div>
                                    <?php elseif ($wl['available'] > 0): ?>
                                        <div style="margin-top: 6px; font-size: 9px; color: var(--text-muted); text-align: center;"><?= $wl['available'] ?>m free</div>
                                    <?php endif; ?>
                                </div>
                            <?php endfor; ?>
                        </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Two Column Layout -->
            <div class="two-column">
                <!-- Today's Schedule -->
                <div class="section-card">
                    <div class="section-header">
                        <h2>Today's Schedule</h2>
                        <div style="display: flex; gap: 12px; align-items: center;">
                            <button onclick="generatePlan()" class="btn-primary" style="padding: 6px 16px; font-size: 12px;">+ Generate Plan</button>
                            <a href="#" onclick="generatePlan()" title="Regenerate full schedule from scratch" style="font-size: 12px;">↻ Regenerate</a>
                            <a href="calendar.php" style="font-size: 12px;">View all →</a>
                        </div>
                    </div>
                    <div class="timeline">
                        <?php if(count($today_plan) > 0): ?>
                            <?php foreach($today_plan as $plan): ?>
                                <div class="timeline-item">
                                    <div class="timeline-time"><?= date('g:i A', strtotime($plan['start_time'])) ?></div>
                                    <div class="timeline-dot"></div>
                                    <div class="timeline-content">
                                        <div class="timeline-title"><?= htmlspecialchars($plan['task_title']) ?></div>
                                        <div class="timeline-meta">Study Session</div>
                                    </div>
                                    <div class="timeline-actions">
                                        <button onclick="missSession(<?= $plan['id'] ?>)" title="Mark missed and reschedule" style="background: none; border: none; color: var(--text-muted); cursor: pointer; font-size: 13px; padding: 2px 6px;">✕</button>
                                        <button onclick="postponeSession(<?= $plan['id'] ?>)" title="Postpone to later date" style="background: none; border: none; color: var(--text-muted); cursor: pointer; font-size: 13px; padding: 2px 6px;">⏰</button>
                                        <button onclick="startSession(<?= $plan['id'] ?>)" title="Start this session" style="background: none; border: none; color: var(--success); cursor: pointer; font-size: 13px; padding: 2px 6px;">▶</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-icon">📅</div>
                                <div class="empty-text">No sessions scheduled today</div>
                                <button onclick="generatePlan()" class="btn-primary" style="margin-top: 16px; padding: 8px 20px;">Generate Plan</button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Upcoming Tasks -->
                <div class="section-card">
                    <div class="section-header">
                        <h2>Upcoming Tasks</h2>
                        <a href="#" onclick="openTaskModal()">Add New →</a>
                    </div>
                    <div class="task-list">
                        <?php if(count($upcoming_tasks) > 0): ?>
                            <?php foreach($upcoming_tasks as $task):
                                $type_icons = ['assignment' => '📝', 'exam' => '📋', 'study' => '📖', 'quiz' => '❓', 'project' => '🔨'];
                                $risk_colors = ['overdue' => 'var(--danger)', 'critical' => 'var(--danger)', 'high' => 'var(--warning)', 'warning' => 'var(--warning)', 'on_track' => 'var(--success)'];
                                $risk_bg = ['overdue' => 'rgba(239,68,68,0.1)', 'critical' => 'rgba(239,68,68,0.1)', 'high' => 'rgba(245,158,11,0.1)', 'warning' => 'rgba(245,158,11,0.1)', 'on_track' => 'rgba(16,185,129,0.1)'];
                            ?>
                                <div class="task-item" style="flex-wrap: wrap; gap: 8px;">
                                    <button class="task-check" onclick="toggleTask(<?= $task['id'] ?>)"></button>
                                    <div class="task-content" style="min-width: 0;">
                                        <div class="task-title"><?= htmlspecialchars($task['title']) ?></div>
                                        <div class="task-due">Due: <?= date('M d, Y', strtotime($task['due_date'])) ?> · <?= $type_icons[$task['type']] ?? '📖' ?> <?= $task['type'] ?> · <?= $task['estimated_hours'] ?>h allotted<?php if ($task['course_name']): ?> · <?= htmlspecialchars($task['course_name']) ?><?php endif; ?></div>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 6px;">
                                        <span style="font-size: 11px; font-weight: 600; padding: 4px 10px; border-radius: 12px; background: <?= $risk_bg[$task['risk']] ?>; color: <?= $risk_colors[$task['risk']] ?>; white-space: nowrap;">
                                            <?php if ($task['days_left'] > 0): ?>
                                                <?= $task['days_left'] ?>d <?= $task['hours_left'] - ($task['days_left'] * 24) ?>h left
                                            <?php else: ?>
                                                <?= $task['hours_left'] ?>h left
                                            <?php endif; ?>
                                            · <?= $task['risk_label'] ?>
                                        </span>
                                        <button onclick="openEditTaskModal(<?= $task['id'] ?>)" style="background: none; border: none; cursor: pointer; font-size: 14px; color: var(--text-muted); padding: 4px;" title="Edit">✏️</button>
                                        <button onclick="deleteTask(<?= $task['id'] ?>)" style="background: none; border: none; cursor: pointer; font-size: 14px; color: var(--text-muted); padding: 4px;" title="Delete">🗑️</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-icon">✅</div>
                                <div class="empty-text">No upcoming tasks</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Summary Timetable -->
            <?php
            $stmt = $pdo->prepare("
                SELECT t.id, t.title, t.type, t.due_date, t.priority, t.status as task_status,
                       c.course_name,
                       (SELECT sp2.plan_date FROM study_plan sp2 WHERE sp2.task_id = t.id AND sp2.user_id = t.user_id AND sp2.status = 'pending' ORDER BY sp2.plan_date, sp2.start_time LIMIT 1) as plan_date,
                       (SELECT sp2.start_time FROM study_plan sp2 WHERE sp2.task_id = t.id AND sp2.user_id = t.user_id AND sp2.status = 'pending' ORDER BY sp2.plan_date, sp2.start_time LIMIT 1) as plan_start,
                       (SELECT sp2.end_time FROM study_plan sp2 WHERE sp2.task_id = t.id AND sp2.user_id = t.user_id AND sp2.status = 'pending' ORDER BY sp2.plan_date, sp2.start_time LIMIT 1) as plan_end,
                       (SELECT sp2.status FROM study_plan sp2 WHERE sp2.task_id = t.id AND sp2.user_id = t.user_id ORDER BY sp2.plan_date, sp2.start_time LIMIT 1) as plan_status
                FROM tasks t
                LEFT JOIN courses c ON t.course_id = c.id
                WHERE t.user_id = ? AND t.status != 'completed'
                ORDER BY t.due_date ASC
                LIMIT 50");
            $stmt->execute([$_SESSION['user_id']]);
            $summary_tasks = $stmt->fetchAll();
            ?>
            <?php if (count($summary_tasks) > 0): ?>
            <div class="section-card" style="margin-top: 24px;">
                <div class="section-header">
                    <h2>📋 Summary Timetable</h2>
                    <a href="calendar.php" style="font-size: 12px;">View full calendar →</a>
                </div>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 8px;">
                        <thead>
                            <tr style="border-bottom: 2px solid var(--border);">
                                <th style="text-align: left; padding: 10px 12px; color: var(--text-muted); font-weight: 600;">Task</th>
                                <th style="text-align: left; padding: 10px 12px; color: var(--text-muted); font-weight: 600;">Course</th>
                                <th style="text-align: left; padding: 10px 12px; color: var(--text-muted); font-weight: 600;">Date &amp; Time Allotted</th>
                                <th style="text-align: left; padding: 10px 12px; color: var(--text-muted); font-weight: 600;">Status</th>
                                <th style="text-align: left; padding: 10px 12px; color: var(--text-muted); font-weight: 600;">Criticality</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($summary_tasks as $st): 
                                $days_until = (strtotime($st['due_date']) - time()) / 86400;
                                if ($st['due_date'] < $today) {
                                    $crit_label = 'CRITICAL';
                                    $crit_color = 'var(--danger)';
                                    $crit_icon = '🔴';
                                } elseif ($days_until <= 1) {
                                    $crit_label = 'HIGH';
                                    $crit_color = 'var(--danger)';
                                    $crit_icon = '🟠';
                                } elseif ($days_until <= 3) {
                                    $crit_label = 'MEDIUM';
                                    $crit_color = 'var(--warning)';
                                    $crit_icon = '🟡';
                                } else {
                                    $crit_label = 'LOW';
                                    $crit_color = 'var(--success)';
                                    $crit_icon = '🟢';
                                }
                                $has_plan = $st['plan_date'] && $st['plan_status'] === 'pending';
                            ?>
                            <tr style="border-bottom: 1px solid var(--border-light);">
                                <td style="padding: 10px 12px; font-weight: 500;">
                                    <?= htmlspecialchars($st['title']) ?>
                                    <div style="font-size: 11px; color: var(--text-muted); margin-top: 2px;"><?= ucfirst($st['type']) ?> · <?= htmlspecialchars($st['course_name'] ?: 'No course') ?></div>
                                </td>
                                <td style="padding: 10px 12px; color: var(--text-secondary);"><?= htmlspecialchars($st['course_name'] ?: '-') ?></td>
                                <td style="padding: 10px 12px;">
                                    <?php if ($has_plan): ?>
                                        <div style="font-weight: 500;"><?= date('D, M j', strtotime($st['plan_date'])) ?></div>
                                        <div style="font-size: 11px; color: var(--text-muted);"><?= date('g:i A', strtotime($st['plan_start'])) ?> – <?= date('g:i A', strtotime($st['plan_end'])) ?></div>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted);">Not scheduled yet</span>
                                        <div style="font-size: 11px; color: var(--text-muted);">Due: <?= date('M j', strtotime($st['due_date'])) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 10px 12px;">
                                    <span style="display: inline-block; padding: 2px 10px; border-radius: 10px; font-size: 11px; font-weight: 600;
                                        background: <?= $has_plan ? 'var(--accent)' : 'var(--text-muted)' ?>20;
                                        color: <?= $has_plan ? 'var(--accent)' : 'var(--text-muted)' ?>;">
                                        <?= $has_plan ? 'Scheduled' : 'Pending' ?>
                                    </span>
                                </td>
                                <td style="padding: 10px 12px;">
                                    <span style="display: inline-block; padding: 2px 10px; border-radius: 10px; font-size: 11px; font-weight: 600;
                                        background: <?= $crit_color ?>20;
                                        color: <?= $crit_color ?>;">
                                        <?= $crit_icon ?> <?= $crit_label ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Edit Task Modal -->
    <div id="editTaskModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Task</h3>
            </div>
            <form action="edit_task.php" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="task_id" id="editTaskId">
                <input type="text" name="title" id="editTaskTitle" placeholder="Task title" required style="width: 100%; padding: 12px; background: var(--bg-primary); border: 1px solid var(--border); border-radius: 8px; color: var(--text-primary); margin-bottom: 16px;">
                <select name="type" id="editTaskType" required style="width: 100%; padding: 12px; background: var(--bg-primary); border: 1px solid var(--border); border-radius: 8px; color: var(--text-primary); margin-bottom: 16px;">
                    <option value="study">Study Session</option>
                    <option value="assignment">Assignment</option>
                    <option value="exam">Exam</option>
                    <option value="quiz">Quiz</option>
                    <option value="project">Project</option>
                </select>
                <select name="course_id" id="editTaskCourse" style="width: 100%; padding: 12px; background: var(--bg-primary); border: 1px solid var(--border); border-radius: 8px; color: var(--text-primary); margin-bottom: 16px;">
                    <option value="">No Course</option>
                    <?php foreach ($courses as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['course_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <textarea name="description" id="editTaskDesc" placeholder="Description (optional)" rows="2" style="width: 100%; padding: 12px; background: var(--bg-primary); border: 1px solid var(--border); border-radius: 8px; color: var(--text-primary); margin-bottom: 16px; font-family: inherit; font-size: 14px; resize: vertical;"></textarea>
                <input type="date" name="due_date" id="editTaskDue" required style="width: 100%; padding: 12px; background: var(--bg-primary); border: 1px solid var(--border); border-radius: 8px; color: var(--text-primary); margin-bottom: 16px;">
                <input type="number" name="estimated_hours" id="editTaskHours" step="0.5" placeholder="Estimated hours" required style="width: 100%; padding: 12px; background: var(--bg-primary); border: 1px solid var(--border); border-radius: 8px; color: var(--text-primary); margin-bottom: 16px;">
                <div class="modal-error" style="display: none; padding: 10px 12px; background: rgba(239,68,68,0.1); border: 1px solid var(--danger); border-radius: 8px; color: var(--danger); font-size: 12px; margin-bottom: 12px;"></div>
                <div class="modal-buttons">
                    <button type="button" class="btn-secondary" onclick="closeEditTaskModal()">Cancel</button>
                    <button type="submit" class="btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Pie Chart Modal -->
    <div id="pieModal" class="modal" onclick="closePieChart(event)">
        <div class="pie-modal-content" onclick="event.stopPropagation()">
            <div class="pie-modal-header">
                <h3>Task Completion</h3>
                <button class="pie-close" onclick="closePieChart()">✕</button>
            </div>
            <div class="pie-chart-container">
                <svg class="pie-svg" viewBox="0 0 120 120">
                    <circle class="pie-bg" cx="60" cy="60" r="48" />
                    <circle class="pie-fill" id="pieFill" cx="60" cy="60" r="48"
                        stroke-dasharray="301.6"
                        stroke-dashoffset="<?= $total_tasks > 0 ? 301.6 * (1 - $completed_tasks / $total_tasks) : 301.6 ?>" />
                    <text class="pie-text" x="60" y="60" text-anchor="middle" dominant-baseline="central">
                        <?= $completion_rate ?>%
                    </text>
                </svg>
            </div>
            <div class="pie-legend">
                <div class="pie-legend-item">
                    <span class="pie-dot completed"></span>
                    <span>Completed</span>
                    <span class="pie-count"><?= $completed_tasks ?></span>
                </div>
                <div class="pie-legend-item">
                    <span class="pie-dot pending"></span>
                    <span>Pending</span>
                    <span class="pie-count"><?= $pending_tasks ?></span>
                </div>
            </div>
            <div class="pie-footer">
                <?= $completed_tasks ?> of <?= $total_tasks ?> tasks completed
            </div>
        </div>
    </div>

    <style>
        .pie-modal-content {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: 28px;
            width: 90%;
            max-width: 360px;
            border: 1px solid var(--border);
            box-shadow: var(--shadow-lg);
        }
        .pie-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .pie-modal-header h3 {
            font-size: 18px;
            font-weight: 600;
        }
        .pie-close {
            background: none;
            border: none;
            font-size: 18px;
            cursor: pointer;
            color: var(--text-muted);
            padding: 4px 8px;
            border-radius: 4px;
        }
        .pie-close:hover {
            background: var(--bg-primary);
            color: var(--text-primary);
        }
        .pie-chart-container {
            display: flex;
            justify-content: center;
            margin-bottom: 20px;
        }
        .pie-svg {
            width: 180px;
            height: 180px;
            transform: rotate(-90deg);
        }
        .pie-bg {
            fill: none;
            stroke: var(--border);
            stroke-width: 10;
        }
        .pie-fill {
            fill: none;
            stroke: var(--accent);
            stroke-width: 10;
            stroke-linecap: round;
            transition: stroke-dashoffset 0.6s ease;
        }
        .pie-text {
            transform: rotate(90deg);
            transform-origin: 60px 60px;
            font-size: 22px;
            font-weight: 700;
            fill: var(--text-primary);
        }
        .pie-legend {
            display: flex;
            gap: 24px;
            justify-content: center;
            margin-bottom: 16px;
        }
        .pie-legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: var(--text-secondary);
        }
        .pie-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }
        .pie-dot.completed { background: var(--accent); }
        .pie-dot.pending { background: var(--border); }
        .pie-count {
            font-weight: 600;
            color: var(--text-primary);
            margin-left: 4px;
        }
        .pie-footer {
            text-align: center;
            font-size: 12px;
            color: var(--text-muted);
            padding-top: 12px;
            border-top: 1px solid var(--border);
        }
    </style>

    <script>
        function showPieChart() {
            document.getElementById('pieModal').style.display = 'flex';
        }

        function closePieChart(e) {
            if (!e || e.target === document.getElementById('pieModal')) {
                document.getElementById('pieModal').style.display = 'none';
            }
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') document.getElementById('pieModal').style.display = 'none';
        });
        
        // Edit task
        function openEditTaskModal(taskId) {
            fetch('API/get_task.php?id=' + taskId)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('editTaskId').value = data.task.id;
                        document.getElementById('editTaskTitle').value = data.task.title;
                        document.getElementById('editTaskType').value = data.task.type;
                        document.getElementById('editTaskCourse').value = data.task.course_id || '';
                        document.getElementById('editTaskDesc').value = data.task.description || '';
                        document.getElementById('editTaskDue').value = data.task.due_date;
                        document.getElementById('editTaskHours').value = data.task.estimated_hours;
                        document.getElementById('editTaskModal').style.display = 'flex';
                    }
                });
        }

        function closeEditTaskModal() {
            document.getElementById('editTaskModal').style.display = 'none';
        }

        function deleteTask(taskId) {
            if (confirm('Delete this task? This cannot be undone.')) {
                apiPost('delete_task.php', 'task_id=' + taskId).then(() => location.reload());
            }
        }
        
        // Generate plan
        function generatePlan() {
            fetch('generate_plan.php').then(() => location.reload());
        }
        
        // Session tracking
        function apiPost(url, body) {
            return fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF_TOKEN },
                body: body
            });
        }

        function rescheduleEntry(planId, action) {
            if (!confirm(action === 'miss' ? 'Mark this session as missed and reschedule?' : 'Postpone this session to a later date?')) return;
            const btn = event.target;
            btn.textContent = '⏳';
            btn.onclick = null;
            apiPost('API/reschedule_entry.php', 'plan_id=' + planId + '&action=' + action)
            .then(r => r.json())
            .then(data => {
                location.reload();
            })
            .catch(() => location.reload());
        }

        function missSession(planId) { rescheduleEntry(planId, 'miss', event.target); }
        function postponeSession(planId) { rescheduleEntry(planId, 'postpone', event.target); }

        function startSession(planId) {
            window.location.href = 'focus_timer.php?plan_id=' + planId;
        }
        
        function stopSession(sessionId, btn) {
            btn.textContent = '⏳ Saving...';
            apiPost('session.php', 'action=stop&session_id=' + sessionId)
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    btn.textContent = '✅ ' + data.duration_minutes + 'm';
                    btn.style.color = 'var(--success)';
                    btn.onclick = null;
                    setTimeout(() => location.reload(), 1000);
                }
            });
        }
        
        // Check for active session on page load
        apiPost('session.php', 'action=active')
        .then(r => r.json())
        .then(data => {
            if (data.active) {
                // Find the plan's start button and update it
                const buttons = document.querySelectorAll('.timeline-item button');
                const startBtns = Array.from(buttons).filter(b => b.textContent === '▶ Start' || b.textContent.startsWith('⏳'));
                if (startBtns.length > 0) {
                    const firstBtn = startBtns[0];
                    firstBtn.textContent = '⏹ End';
                    firstBtn.style.color = 'var(--danger)';
                    firstBtn.onclick = function() { stopSession(data.session_id, firstBtn); };
                }
            }
        });
        
        // Toggle task completion
        function toggleTask(taskId) {
            const btn = event.target;
            const wasCompleted = btn.classList.contains('completed');
            const newStatus = wasCompleted ? 'pending' : 'completed';
            btn.classList.toggle('completed');
            fetch('update_task_status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'task_id=' + taskId + '&status=' + newStatus + '&csrf_token=' + CSRF_TOKEN
            }).then(() => location.reload());
        }
    </script>
    <script src="script.js"></script>
    <?php if (isset($_GET['calendar'])): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            <?php if ($_GET['calendar'] === 'connected'): ?>
                showToast('✅ Google Calendar connected! Your study sessions will sync automatically.', 'success');
            <?php elseif ($_GET['calendar'] === 'error'): ?>
                showToast('❌ Could not connect to Google Calendar. <?= isset($_GET['reason']) ? htmlspecialchars($_GET['reason']) : 'Please try again.' ?>', 'now');
            <?php endif; ?>
        });
    </script>
    <?php endif; ?>
</body>
</html>
