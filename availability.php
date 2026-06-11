<?php
require_once 'db_config.php';

$days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_slot'])) {
    $day = (int)($_POST['day'] ?? 0);
    $start = $_POST['start_time'] ?? '';
    $end = $_POST['end_time'] ?? '';

    if ($day >= 0 && $day <= 6 && $start && $end) {
        $stmt = $pdo->prepare("INSERT INTO available_time (user_id, day_of_week, start_time, end_time) VALUES (?, ?, ?, ?)");
        $stmt->execute([$_SESSION['user_id'], $day, $start, $end]);
        $message = 'Time slot added!';
    }
}

// Handle deletion
if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("DELETE FROM available_time WHERE id = ? AND user_id = ?");
    $stmt->execute([(int)$_GET['delete'], $_SESSION['user_id']]);
    $message = 'Time slot removed.';
}

// Get existing slots
$stmt = $pdo->prepare("SELECT * FROM available_time WHERE user_id = ? ORDER BY day_of_week, start_time");
$stmt->execute([$_SESSION['user_id']]);
$slots = $stmt->fetchAll();

// Group by day
$grouped = [];
foreach ($slots as $slot) {
    $grouped[$slot['day_of_week']][] = $slot;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Availability | StudySync</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .page-header { margin-bottom: 24px; }
        .page-header h1 { font-size: 24px; font-weight: 600; }
        .page-header p { font-size: 13px; color: var(--text-muted); margin-top: 4px; }
        .av-content { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
        .av-card { background: var(--bg-card); border-radius: var(--radius-lg); border: 1px solid var(--border); padding: 24px; }
        .av-card h2 { font-size: 16px; font-weight: 600; margin-bottom: 16px; }
        .av-form { display: flex; flex-direction: column; gap: 12px; }
        .av-form label { font-size: 13px; font-weight: 500; color: var(--text-secondary); }
        .av-form select, .av-form input { padding: 10px 12px; background: var(--bg-primary); border: 1px solid var(--border); border-radius: var(--radius-sm); color: var(--text-primary); font-size: 14px; }
        .av-form select:focus, .av-form input:focus { outline: none; border-color: var(--accent); }
        .day-group { margin-bottom: 16px; }
        .day-group h3 { font-size: 14px; font-weight: 600; margin-bottom: 8px; color: var(--text-primary); }
        .slot-chip { display: inline-flex; align-items: center; gap: 8px; background: var(--accent-soft); color: var(--accent); padding: 6px 12px; border-radius: 20px; font-size: 12px; margin: 0 6px 6px 0; }
        .slot-chip a { color: var(--danger); text-decoration: none; font-weight: 700; margin-left: 4px; }
        .slot-chip a:hover { opacity: 0.7; }
        .empty-text { color: var(--text-muted); font-size: 13px; }
        .message { padding: 12px; background: rgba(16,185,129,0.1); border: 1px solid var(--success); border-radius: var(--radius-sm); color: var(--success); font-size: 13px; margin-bottom: 16px; }
        @media (max-width: 768px) { .av-content { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="app-container">
        <?php require_once 'includes/sidebar.php'; ?>
        <main class="main-content">
            <div class="page-header">
                <h1>Available Study Time</h1>
                <p>Set your weekly availability so StudySync can generate a smart schedule</p>
            </div>

            <?php if ($message): ?>
                <div class="message"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <div class="av-content">
                <div class="av-card">
                    <h2>Add Time Slot</h2>
                    <form method="POST" class="av-form">
                        <div>
                            <label>Day</label>
                            <select name="day" required>
                                <?php foreach ($days as $i => $d): ?>
                                    <option value="<?= $i ?>"><?= $d ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Start Time</label>
                            <input type="time" name="start_time" required>
                        </div>
                        <div>
                            <label>End Time</label>
                            <input type="time" name="end_time" required>
                        </div>
                        <button type="submit" name="save_slot" class="btn-primary" style="padding: 10px; border: none; border-radius: var(--radius-sm); cursor: pointer;">Add Slot</button>
                    </form>
                </div>

                <div class="av-card">
                    <h2>Your Weekly Schedule</h2>
                    <?php if (empty($slots)): ?>
                        <div class="empty-text">No availability set yet. Add your first time slot.</div>
                    <?php else: ?>
                        <?php foreach ($days as $i => $d): ?>
                            <?php if (isset($grouped[$i])): ?>
                                <div class="day-group">
                                    <h3><?= $d ?></h3>
                                    <?php foreach ($grouped[$i] as $slot): ?>
                                        <span class="slot-chip">
                                            <?= date('g:i A', strtotime($slot['start_time'])) ?> – <?= date('g:i A', strtotime($slot['end_time'])) ?>
                                            <a href="?delete=<?= $slot['id'] ?>" onclick="return confirm('Remove this slot?')">×</a>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
    <script>
        function openTaskModal() { alert('Use the Dashboard to add tasks.'); }
    </script>
</body>
</html>