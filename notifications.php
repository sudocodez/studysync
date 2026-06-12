<?php
require_once 'db_config.php';

// Get all notifications
$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 100");
$stmt->execute([$_SESSION['user_id']]);
$notifications = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$_SESSION['user_id']]);
$unread_count = (int)$stmt->fetchColumn();

$type_icons = [
    'now' => '🔔',
    'reminder' => '⏰',
    'upcoming' => '📚',
    'deadline' => '⚠️',
    'overdue' => '🔴',
    'success' => '✅',
    'info' => 'ℹ️',
    'warning' => '⚡',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications | StudySync</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 12px; }
        .page-header h1 { font-size: 24px; font-weight: 600; }
        .page-header-actions { display: flex; gap: 10px; }
        .filter-btn { padding: 8px 16px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--bg-card); color: var(--text-secondary); cursor: pointer; font-size: 12px; }
        .filter-btn.active { background: var(--accent); color: white; border-color: var(--accent); }
        .mark-all-btn { padding: 8px 16px; border: none; border-radius: var(--radius-sm); background: var(--accent-soft); color: var(--accent); cursor: pointer; font-size: 12px; font-weight: 500; }
        .notif-list { display: flex; flex-direction: column; gap: 1px; background: var(--border); border-radius: var(--radius-lg); overflow: hidden; border: 1px solid var(--border); }
        .notif-item { display: flex; gap: 14px; padding: 16px 20px; background: var(--bg-card); cursor: pointer; transition: background 0.15s; align-items: flex-start; }
        .notif-item:hover { background: var(--bg-primary); }
        .notif-item.unread { border-left: 3px solid var(--accent); }
        .notif-icon { font-size: 22px; flex-shrink: 0; margin-top: 2px; }
        .notif-body { flex: 1; min-width: 0; }
        .notif-title { font-size: 14px; font-weight: 600; color: var(--text-primary); margin-bottom: 4px; }
        .notif-item.unread .notif-title { color: var(--accent); }
        .notif-message { font-size: 13px; color: var(--text-secondary); line-height: 1.5; }
        .notif-time { font-size: 11px; color: var(--text-muted); margin-top: 6px; }
        .notif-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--accent); flex-shrink: 0; margin-top: 6px; display: none; }
        .notif-item.unread .notif-dot { display: block; }
        .empty-state { text-align: center; padding: 60px 24px; color: var(--text-muted); }
        .empty-icon { font-size: 48px; margin-bottom: 16px; }
        .empty-text { font-size: 14px; }
        .filters { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
        @media (max-width: 768px) { .page-header { flex-direction: column; align-items: flex-start; } }
    </style>
</head>
<body>
    <div class="app-container">
        <?php require_once 'includes/sidebar.php'; ?>
        <main class="main-content">
            <div class="page-header">
                <div>
                    <h1>Notifications</h1>
                    <p style="font-size: 13px; color: var(--text-muted); margin-top: 4px;"><?= $unread_count ?> unread · <?= count($notifications) ?> total</p>
                </div>
                <div class="page-header-actions">
                    <?php if ($unread_count > 0): ?>
                        <button class="mark-all-btn" onclick="markAllRead()">Mark All Read</button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="filters">
                <button class="filter-btn active" data-filter="" onclick="filterNotif(this, '')">All</button>
                <button class="filter-btn" data-filter="now" onclick="filterNotif(this, 'now')">🔔 Alarms</button>
                <button class="filter-btn" data-filter="reminder" onclick="filterNotif(this, 'reminder')">⏰ Reminders</button>
                <button class="filter-btn" data-filter="deadline" onclick="filterNotif(this, 'deadline')">⚠️ Deadlines</button>
                <button class="filter-btn" data-filter="overdue" onclick="filterNotif(this, 'overdue')">🔴 Overdue</button>
                <button class="filter-btn" data-filter="success" onclick="filterNotif(this, 'success')">✅ Success</button>
            </div>

            <?php if (count($notifications) > 0): ?>
                <div class="notif-list" id="notifList">
                    <?php foreach ($notifications as $n):
                        $is_unread = !$n['is_read'];
                        $icon = $type_icons[$n['type']] ?? '🔔';
                        $time = date('M j, g:i A', strtotime($n['created_at']));
                    ?>
                        <div class="notif-item <?= $is_unread ? 'unread' : '' ?>" data-type="<?= htmlspecialchars($n['type']) ?>" onclick="handleNotifClick(<?= $n['id'] ?>, '<?= htmlspecialchars($n['link'] ?? '') ?>')">
                            <div class="notif-icon"><?= $icon ?></div>
                            <div class="notif-body">
                                <div class="notif-title"><?= htmlspecialchars($n['title']) ?></div>
                                <div class="notif-message"><?= htmlspecialchars($n['message']) ?></div>
                                <div class="notif-time"><?= $time ?></div>
                            </div>
                            <div class="notif-dot"></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-icon">🔔</div>
                    <div class="empty-text">No notifications yet. They'll appear here when you have upcoming sessions, approaching deadlines, or plan updates.</div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        var CSRF_TOKEN = '<?= $_SESSION['csrf_token'] ?>';

        function apiPost(url, body) {
            return fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF_TOKEN },
                body: body
            });
        }

        function handleNotifClick(id, link) {
            // Mark as read
            apiPost('API/notifications.php', 'action=read&id=' + id);
            // Navigate if link exists
            if (link) window.location.href = link;
        }

        function markAllRead() {
            apiPost('API/notifications.php', 'action=read_all').then(() => location.reload());
        }

        function filterNotif(btn, type) {
            document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            document.querySelectorAll('.notif-item').forEach(item => {
                if (!type || item.dataset.type === type) {
                    item.style.display = '';
                } else {
                    item.style.display = 'none';
                }
            });
        }
    </script>
</body>
</html>
