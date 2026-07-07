// Notification polling every 30 seconds
setInterval(fetchAlerts, 30000);
fetchAlerts();
fetchUnreadCount();

function fetchAlerts() {
    fetch('get_alerts.php')
        .then(r => r.json())
        .then(data => {
            if (data.alerts && data.alerts.length > 0) {
                data.alerts.forEach(alert => {
                    showToast(alert.message, alert.type);
                    if ((alert.type === 'now' || alert.type === 'upcoming' || alert.type === 'deadline' || alert.type === 'overdue') && (typeof ALARM_ENABLED === 'undefined' || ALARM_ENABLED)) {
                        triggerAlarm(alert.message, alert.type);
                    }
                });
            }
            if (typeof data.unread_count !== 'undefined') {
                updateBadge(data.unread_count);
            }
        })
        .catch(e => console.log('Alert fetch error:', e));
}

function fetchUnreadCount() {
    fetch('API/notifications.php?action=count')
        .then(r => r.json())
        .then(data => {
            if (typeof data.unread_count !== 'undefined') {
                updateBadge(data.unread_count);
            }
        })
        .catch(() => {});
}

function updateBadge(count) {
    const badge = document.getElementById('notifBadge');
    if (badge) {
        badge.textContent = count > 99 ? '99+' : count;
        badge.style.display = count > 0 ? '' : 'none';
    }
}

// Toast notifications
function showToast(message, type) {
    let container = document.getElementById('toastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toastContainer';
        container.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 9999; display: flex; flex-direction: column; gap: 10px;';
        document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    const bgColors = {
        now: 'var(--danger)',
        overdue: 'var(--danger)',
        deadline: 'var(--warning)',
        reminder: 'var(--accent)',
        upcoming: 'var(--accent)',
        success: 'var(--success)',
        info: 'var(--accent)',
    };
    toast.style.cssText = `
        padding: 12px 16px;
        border-radius: var(--radius-sm);
        background: ${bgColors[type] || 'var(--accent)'};
        color: white;
        font-size: 13px;
        line-height: 1.5;
        box-shadow: var(--shadow-lg);
        animation: slideInRight 0.3s ease;
        min-width: 280px;
        max-width: 400px;
        display: flex;
        align-items: center;
        gap: 10px;
        cursor: pointer;
    `;
    toast.innerHTML = `<span style="flex:1;">${message}</span><span style="cursor:pointer;opacity:0.7;flex-shrink:0;" onclick="this.parentElement.remove()">✕</span>`;
    toast.onclick = () => toast.remove();
    container.appendChild(toast);
    setTimeout(() => { if (toast.parentNode) toast.remove(); }, 12000);
}

let alarmCtx = null;
let alarmTimer = null;
let alarmOverlay = null;

function triggerAlarm(message, type) {
    if (alarmOverlay) return;

    alarmOverlay = document.createElement('div');
    alarmOverlay.id = 'alarmOverlay';
    alarmOverlay.innerHTML = `
        <div class="alarm-card">
            <div class="alarm-icon">🔔</div>
            <div class="alarm-title">${type === 'now' ? 'SESSION STARTING NOW' : type === 'upcoming' ? 'SESSION STARTING SOON' : type === 'deadline' ? 'DEADLINE APPROACHING' : 'OVERDUE — TAKE ACTION'}</div>
            <div class="alarm-msg">${message}</div>
            <button class="alarm-dismiss" onclick="dismissAlarm()">✕ DISMISS ALARM</button>
        </div>
    `;
    document.body.appendChild(alarmOverlay);

    try {
        alarmCtx = new (window.AudioContext || window.webkitAudioContext)();
        var high = true;
        function beep() {
            if (!alarmCtx) return;
            var osc = alarmCtx.createOscillator();
            var gain = alarmCtx.createGain();
            osc.connect(gain);
            gain.connect(alarmCtx.destination);
            osc.type = 'square';
            osc.frequency.value = high ? 1000 : 800;
            high = !high;
            gain.gain.setValueAtTime(0.5, alarmCtx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.00001, alarmCtx.currentTime + 0.28);
            osc.start(alarmCtx.currentTime);
            osc.stop(alarmCtx.currentTime + 0.28);
            alarmTimer = setTimeout(beep, 300);
        }
        beep();
        alarmCtx.resume();
    } catch (e) { alarmCtx = null; }
}

function dismissAlarm() {
    if (alarmTimer) { clearTimeout(alarmTimer); alarmTimer = null; }
    if (alarmCtx) { alarmCtx.close(); alarmCtx = null; }
    if (alarmOverlay) { alarmOverlay.remove(); alarmOverlay = null; }
}

// Inject styles
if (!document.getElementById('alarm-styles')) {
    var s = document.createElement('style');
    s.id = 'alarm-styles';
    s.textContent = `
        #alarmOverlay {
            position: fixed; inset: 0; z-index: 99999;
            background: rgba(0,0,0,0.85);
            display: flex; align-items: center; justify-content: center;
            animation: alarmFadeIn 0.3s ease;
        }
        .alarm-card {
            background: #1a1a1a; border-radius: 20px; padding: 48px 40px 36px;
            text-align: center; max-width: 460px; width: 90%;
            box-shadow: 0 0 60px rgba(255,50,50,0.3);
            border: 2px solid #ff3333;
            animation: alarmPulse 0.6s ease-in-out infinite alternate;
        }
        .alarm-icon { font-size: 64px; margin-bottom: 16px; animation: alarmShake 0.3s ease-in-out infinite alternate; }
        .alarm-title { font-size: 22px; font-weight: 800; color: #ff4444; margin-bottom: 12px; letter-spacing: 1px; }
        .alarm-msg { font-size: 15px; color: #ccc; margin-bottom: 28px; line-height: 1.5; }
        .alarm-dismiss {
            background: #ff3333; color: white; border: none; border-radius: 12px;
            padding: 14px 40px; font-size: 16px; font-weight: 700; cursor: pointer;
            transition: background 0.2s; letter-spacing: 1px;
        }
        .alarm-dismiss:hover { background: #cc0000; }
        @keyframes alarmFadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes alarmPulse { from { box-shadow: 0 0 40px rgba(255,50,50,0.2); } to { box-shadow: 0 0 80px rgba(255,50,50,0.5); } }
        @keyframes alarmShake { from { transform: rotate(-8deg); } to { transform: rotate(8deg); } }
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
    `;
    document.head.appendChild(s);
}

// Request notification permission
if ('Notification' in window && Notification.permission === 'default') {
    Notification.requestPermission();
}

// Browser notification helper
function sendBrowserNotification(title, body) {
    if ('Notification' in window && Notification.permission === 'granted' && document.hidden) {
        new Notification(title, { body });
    }
}

// Save scroll position
window.addEventListener('beforeunload', () => {
    localStorage.setItem('scrollPos', window.scrollY);
});
window.addEventListener('load', () => {
    var pos = localStorage.getItem('scrollPos');
    if (pos) window.scrollTo(0, parseInt(pos));
});

// Global form validation for modals
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.modal form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            var errorDiv = this.querySelector('.modal-error');
            if (!errorDiv) return;
            var missing = [];
            var fields = this.querySelectorAll('[required]');
            fields.forEach(function(f) {
                if (!f.value.trim()) {
                    var label = f.placeholder || f.name || 'Field';
                    missing.push(label);
                    f.style.borderColor = 'var(--danger)';
                } else {
                    f.style.borderColor = '';
                }
            });
            if (missing.length > 0) {
                e.preventDefault();
                errorDiv.style.display = 'block';
                errorDiv.textContent = '❌ Missing: ' + missing.join(', ');
            } else {
                errorDiv.style.display = 'none';
            }
        });
    });
});
