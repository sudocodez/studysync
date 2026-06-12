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
                    if (alert.type === 'now' || alert.type === 'overdue') {
                        playAlertSound();
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
    if (!badge) return;
    if (count > 0) {
        badge.textContent = count > 99 ? '99+' : count;
        badge.style.display = '';
    } else {
        badge.style.display = 'none';
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

function playAlertSound() {
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.frequency.value = 880;
        gain.gain.value = 0.3;
        osc.start();
        gain.gain.exponentialRampToValueAtTime(0.00001, ctx.currentTime + 1);
        osc.stop(ctx.currentTime + 0.5);
        ctx.resume();
    } catch (e) {}
}

// Inject toast animation
if (!document.getElementById('toast-styles')) {
    const s = document.createElement('style');
    s.id = 'toast-styles';
    s.textContent = `
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

// Browser notification helper (fallback when tab is not focused)
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
    const pos = localStorage.getItem('scrollPos');
    if (pos) window.scrollTo(0, parseInt(pos));
});
