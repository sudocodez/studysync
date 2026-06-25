<?php
require_once 'db_config.php';

$uid = $_SESSION['user_id'];

// Load current settings
$settings = [];
$stmt = $pdo->prepare("SELECT setting_key, setting_value FROM user_settings WHERE user_id = ?");
$stmt->execute([$uid]);
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$alarm_enabled = $settings['alarm_enabled'] ?? '1';
$alarm_lead = $settings['alarm_lead_minutes'] ?? '30';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings | StudySync</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="liquid-glass.css">
    <style>
        .settings-card { max-width: 600px; }
        .setting-row { display: flex; align-items: center; justify-content: space-between; padding: 18px 0; border-bottom: 1px solid var(--border-light); }
        .setting-row:last-child { border-bottom: none; }
        .setting-info { flex: 1; }
        .setting-label { font-size: 14px; font-weight: 500; }
        .setting-desc { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
        .toggle-wrap { position: relative; width: 48px; height: 26px; flex-shrink: 0; }
        .toggle-wrap input { opacity: 0; width: 0; height: 0; }
        .toggle-slider { position: absolute; inset: 0; background: var(--border); border-radius: 26px; cursor: pointer; transition: 0.3s; }
        .toggle-slider:before { content: ''; position: absolute; left: 3px; bottom: 3px; width: 20px; height: 20px; background: white; border-radius: 50%; transition: 0.3s; }
        .toggle-wrap input:checked + .toggle-slider { background: var(--accent); }
        .toggle-wrap input:checked + .toggle-slider:before { transform: translateX(22px); }
        .save-btn { margin-top: 20px; padding: 12px 32px; }
    </style>
</head>
<body>
    <div class="app-container">
        <?php require_once 'includes/sidebar.php'; ?>
        <main class="main-content">
            <h1 style="font-size: 24px; font-weight: 600; margin-bottom: 4px;">⚙️ Settings</h1>
            <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 24px;">Customize your StudySync experience</p>

            <div id="saveMsg" style="display: none; padding: 12px 16px; background: rgba(16,185,129,0.1); border: 1px solid var(--success); border-radius: var(--radius-sm); color: var(--success); font-size: 13px; margin-bottom: 20px;">✅ Settings saved successfully.</div>

            <div class="section-card settings-card">
                <h3 style="font-size: 15px; font-weight: 600; margin-bottom: 4px;">🔔 Notifications</h3>
                <p style="font-size: 12px; color: var(--text-muted); margin-bottom: 8px;">Control alarm sounds and notification behavior</p>

                <div class="setting-row">
                    <div class="setting-info">
                        <div class="setting-label">Alarm Sound</div>
                        <div class="setting-desc">Play alarm sound for urgent notifications (sessions starting, deadlines, overdue tasks)</div>
                    </div>
                    <label class="toggle-wrap">
                        <input type="checkbox" id="alarmEnabled" <?= $alarm_enabled === '1' ? 'checked' : '' ?>>
                        <span class="toggle-slider"></span>
                    </label>
                </div>

                <div class="setting-row">
                    <div class="setting-info">
                        <div class="setting-label">Notification Lead Time</div>
                        <div class="setting-desc">How many minutes before a session to trigger the alarm</div>
                    </div>
                    <select id="alarmLead" style="padding: 8px 12px; border-radius: var(--radius-sm); border: 1px solid var(--border); background: var(--bg-primary); color: var(--text-primary); font-size: 13px;">
                        <option value="5" <?= $alarm_lead === '5' ? 'selected' : '' ?>>5 min</option>
                        <option value="15" <?= $alarm_lead === '15' ? 'selected' : '' ?>>15 min</option>
                        <option value="30" <?= $alarm_lead === '30' ? 'selected' : '' ?>>30 min</option>
                        <option value="60" <?= $alarm_lead === '60' ? 'selected' : '' ?>>1 hour</option>
                    </select>
                </div>

                <button onclick="saveSettings()" class="btn-primary save-btn">Save Settings</button>
            </div>
        </main>
    </div>
    <script>
    var CSRF_TOKEN = '<?= $_SESSION['csrf_token'] ?>';
    function saveSettings() {
        var data = new URLSearchParams();
        data.append('alarm_enabled', document.getElementById('alarmEnabled').checked ? '1' : '0');
        data.append('alarm_lead_minutes', document.getElementById('alarmLead').value);
        data.append('csrf_token', CSRF_TOKEN);

        fetch('API/save_settings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: data
        })
        .then(function(r) { return r.json(); })
        .then(function(resp) {
            var msg = document.getElementById('saveMsg');
            msg.style.display = 'block';
            msg.style.background = resp.success ? 'rgba(16,185,129,0.1)' : 'rgba(239,68,68,0.1)';
            msg.style.borderColor = resp.success ? 'var(--success)' : 'var(--danger)';
            msg.style.color = resp.success ? 'var(--success)' : 'var(--danger)';
            msg.textContent = resp.success ? '✅ Settings saved successfully.' : '❌ ' + (resp.error || 'Save failed');
            setTimeout(function() { msg.style.display = 'none'; }, 3000);
        });
    }
    </script>
</body>
</html>
