<?php
require_once 'db_config.php';
verify_csrf();

$days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$message = '';

// Handle add recurring slot
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_slot'])) {
    $day = (int)($_POST['day'] ?? 0);
    $start = $_POST['start_time'] ?? '';
    $end = $_POST['end_time'] ?? '';

    if ($day >= 0 && $day <= 6 && $start && $end) {
        $stmt = $pdo->prepare("INSERT INTO available_time (user_id, day_of_week, start_time, end_time, is_recurring) VALUES (?, ?, ?, ?, 1)");
        $stmt->execute([$_SESSION['user_id'], $day, $start, $end]);
        $message = 'Time slot added!';
        require 'API/regenerate.php';
    }
}

// Handle add specific-date slot
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_date_slot'])) {
    $date = $_POST['specific_date'] ?? '';
    $start = $_POST['start_time'] ?? '';
    $end = $_POST['end_time'] ?? '';

    if ($date && $start && $end) {
        $stmt = $pdo->prepare("INSERT INTO available_time (user_id, day_of_week, start_time, end_time, is_recurring, specific_date) VALUES (?, ?, ?, ?, 0, ?)");
        $day_of_week = (int)date('w', strtotime($date));
        $stmt->execute([$_SESSION['user_id'], $day_of_week, $start, $end, $date]);
        $message = 'Date-specific slot added!';
        require 'API/regenerate.php';
    }
}

// Handle edit slot
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_slot'])) {
    $id = (int)$_POST['slot_id'];
    $start = $_POST['start_time'] ?? '';
    $end = $_POST['end_time'] ?? '';
    if ($start && $end) {
        $stmt = $pdo->prepare("UPDATE available_time SET start_time = ?, end_time = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$start, $end, $id, $_SESSION['user_id']]);
        $message = 'Time slot updated!';
        require 'API/regenerate.php';
    }
}

// Handle deletion
if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("DELETE FROM available_time WHERE id = ? AND user_id = ?");
    $stmt->execute([(int)$_GET['delete'], $_SESSION['user_id']]);
    $message = 'Time slot removed.';
    require 'API/regenerate.php';
}

// Get existing slots
$stmt = $pdo->prepare("SELECT * FROM available_time WHERE user_id = ? ORDER BY is_recurring DESC, day_of_week, start_time");
$stmt->execute([$_SESSION['user_id']]);
$slots = $stmt->fetchAll();

// Group recurring by day
$recurring_grouped = [];
$date_slots = [];
foreach ($slots as $slot) {
    if ($slot['is_recurring']) {
        $recurring_grouped[$slot['day_of_week']][] = $slot;
    } else {
        $date_slots[] = $slot;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Availability | StudySync</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="liquid-glass.css">
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
        .slot-chip { display: inline-flex; align-items: center; gap: 8px; background: var(--accent-soft); color: var(--accent); padding: 6px 12px; border-radius: 20px; font-size: 12px; margin: 0 6px 6px 0; cursor: pointer; }
        .slot-chip:hover { background: var(--accent); color: white; }
        .slot-chip a { color: var(--danger); text-decoration: none; font-weight: 700; margin-left: 4px; }
        .slot-chip a:hover { opacity: 0.7; }
        .date-slot { display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; background: var(--bg-primary); border-radius: var(--radius-sm); margin-bottom: 8px; border: 1px solid var(--border-light); }
        .date-slot-info { font-size: 13px; }
        .date-slot-date { font-weight: 600; color: var(--text-primary); }
        .date-slot-time { color: var(--text-secondary); margin-left: 8px; }
        .date-slot-actions { display: flex; gap: 6px; }
        .date-slot-actions button, .date-slot-actions a { background: none; border: none; cursor: pointer; font-size: 14px; padding: 2px 4px; text-decoration: none; }
        .tab-bar { display: flex; gap: 0; margin-bottom: 16px; border-bottom: 1px solid var(--border); }
        .tab-btn { padding: 10px 20px; border: none; background: none; cursor: pointer; font-size: 13px; font-weight: 500; color: var(--text-muted); border-bottom: 2px solid transparent; }
        .tab-btn.active { color: var(--accent); border-bottom-color: var(--accent); }
        .tab-panel { display: none; }
        .tab-panel.active { display: block; }
        .empty-text { color: var(--text-muted); font-size: 13px; }
        .message { padding: 12px; background: rgba(16,185,129,0.1); border: 1px solid var(--success); border-radius: var(--radius-sm); color: var(--success); font-size: 13px; margin-bottom: 16px; }
        .section-subtitle { font-size: 14px; font-weight: 600; margin: 20px 0 12px; color: var(--text-primary); }
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
                    <div class="tab-bar">
                        <button class="tab-btn active" onclick="switchTab('weekly', this)">Weekly</button>
                        <button class="tab-btn" onclick="switchTab('specific', this)">Specific Date</button>
                    </div>

                    <div id="tabWeekly" class="tab-panel active">
                        <form method="POST" class="av-form">
                            <?= csrf_field() ?>
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
                            <button type="submit" name="save_slot" class="btn-primary" style="padding: 10px; border: none; border-radius: var(--radius-sm); cursor: pointer;">Add Weekly Slot</button>
                        </form>
                    </div>

                    <div id="tabSpecific" class="tab-panel">
                        <form method="POST" class="av-form">
                            <?= csrf_field() ?>
                            <div>
                                <label>Date</label>
                                <input type="date" name="specific_date" required>
                            </div>
                            <div>
                                <label>Start Time</label>
                                <input type="time" name="start_time" required>
                            </div>
                            <div>
                                <label>End Time</label>
                                <input type="time" name="end_time" required>
                            </div>
                            <button type="submit" name="save_date_slot" class="btn-primary" style="padding: 10px; border: none; border-radius: var(--radius-sm); cursor: pointer;">Add Date Slot</button>
                        </form>
                    </div>
                </div>

                <div class="av-card">
                    <h2>Your Schedule</h2>

                    <?php if (empty($slots)): ?>
                        <div class="empty-text">No availability set yet. Add your first time slot.</div>
                    <?php else: ?>
                        <?php if (!empty($recurring_grouped)): ?>
                            <div class="section-subtitle">Weekly Recurring</div>
                            <?php foreach ($days as $i => $d): ?>
                                <?php if (isset($recurring_grouped[$i])): ?>
                                    <div class="day-group">
                                        <h3><?= $d ?></h3>
                                        <?php foreach ($recurring_grouped[$i] as $slot): ?>
                                            <span class="slot-chip" onclick="openEditModal(<?= $slot['id'] ?>, '<?= $slot['start_time'] ?>', '<?= $slot['end_time'] ?>')">
                                                <?= date('g:i A', strtotime($slot['start_time'])) ?> – <?= date('g:i A', strtotime($slot['end_time'])) ?>
                                                <a href="?delete=<?= $slot['id'] ?>" onclick="event.stopPropagation(); return confirm('Remove this slot?')">×</a>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <?php if (!empty($date_slots)): ?>
                            <div class="section-subtitle">Date-Specific</div>
                            <?php foreach ($date_slots as $slot): ?>
                                <div class="date-slot">
                                    <div class="date-slot-info">
                                        <span class="date-slot-date"><?= date('D, M j', strtotime($slot['specific_date'])) ?></span>
                                        <span class="date-slot-time"><?= date('g:i A', strtotime($slot['start_time'])) ?> – <?= date('g:i A', strtotime($slot['end_time'])) ?></span>
                                    </div>
                                    <div class="date-slot-actions">
                                        <button onclick="openEditModal(<?= $slot['id'] ?>, '<?= $slot['start_time'] ?>', '<?= $slot['end_time'] ?>')" title="Edit">✏️</button>
                                        <a href="?delete=<?= $slot['id'] ?>" onclick="return confirm('Remove this slot?')" title="Delete">🗑️</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Edit Slot Modal -->
    <div id="editSlotModal" class="modal">
        <div class="modal-content" style="max-width: 400px;">
            <div class="modal-header">
                <h3>Edit Time Slot</h3>
            </div>
            <form method="POST" style="padding: 24px;">
                <?= csrf_field() ?>
                <input type="hidden" name="slot_id" id="editSlotId">
                <div class="av-form">
                    <div>
                        <label>Start Time</label>
                        <input type="time" name="start_time" id="editSlotStart" required>
                    </div>
                    <div>
                        <label>End Time</label>
                        <input type="time" name="end_time" id="editSlotEnd" required>
                    </div>
                    <div style="display: flex; gap: 10px; justify-content: flex-end;">
                        <button type="button" class="btn-primary" style="padding: 10px 20px; border: none; border-radius: var(--radius-sm); cursor: pointer; background: var(--bg-primary); color: var(--text-secondary);" onclick="closeEditModal()">Cancel</button>
                        <button type="submit" name="edit_slot" class="btn-primary" style="padding: 10px 20px; border: none; border-radius: var(--radius-sm); cursor: pointer;">Save</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openEditModal(id, start, end) {
            document.getElementById('editSlotId').value = id;
            document.getElementById('editSlotStart').value = start;
            document.getElementById('editSlotEnd').value = end;
            document.getElementById('editSlotModal').style.display = 'flex';
        }
        function closeEditModal() {
            document.getElementById('editSlotModal').style.display = 'none';
        }
        function switchTab(tab, btn) {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById('tab' + tab.charAt(0).toUpperCase() + tab.slice(1)).classList.add('active');
        }
        function openTaskModal() { alert('Use the Dashboard to add tasks.'); }
    </script>
</body>
</html>
