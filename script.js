// Auto-refresh alarms every 30 seconds
setInterval(() => {
    if(window.checkAlarms) window.checkAlarms();
}, 30000);

// Save scroll position
window.addEventListener('beforeunload', () => {
    localStorage.setItem('scrollPos', window.scrollY);
});

window.addEventListener('load', () => {
    const pos = localStorage.getItem('scrollPos');
    if(pos) window.scrollTo(0, parseInt(pos));
});
// ========== ADD THIS TO YOUR EXISTING script.js ==========

// Check for alerts every 30 seconds
setInterval(function() {
    fetchAlerts();
}, 30000);

// Initial load
fetchAlerts();

function fetchAlerts() {
    fetch('get_alerts.php')
        .then(response => response.json())
        .then(data => {
            if(data.alerts && data.alerts.length > 0) {
                data.alerts.forEach(alert => {
                    showAlertMessage(alert.message, alert.type);
                    
                    if(alert.type === 'now' || alert.type === 'overdue') {
                        playAlertSound();
                    }
                    
                    storeAlert(alert);
                });
            }
        })
        .catch(error => console.log('Alert fetch error:', error));
}

function showAlertMessage(message, type) {
    // Browser notification
    if('Notification' in window && Notification.permission === "granted") {
        new Notification("Study Planner Alert", { 
            body: message,
            icon: "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%234CAF50'%3E%3Cpath d='M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z'/%3E%3C/svg%3E"
        });
    }
    
    // In-app notification
    let container = document.getElementById('notificationContainer');
    if(!container) {
        container = document.createElement('div');
        container.id = 'notificationContainer';
        container.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 9999;';
        document.body.appendChild(container);
    }
    
    const notif = document.createElement('div');
    notif.className = `notification notification-${type || 'reminder'}`;
    const icons = { 'urgent': '🔴', 'overdue': '🔴', 'deadline': '⚠️', 'now': '🔔', 'reminder': '🔔', 'upcoming': '📚' };
    notif.innerHTML = `
        <div class="notification-content">
            <span class="notification-icon">${icons[type] || '🔔'}</span>
            <span class="notification-message">${message}</span>
            <button onclick="this.parentElement.parentElement.remove()" class="notification-close">✖</button>
        </div>
    `;
    container.appendChild(notif);
    setTimeout(() => notif.remove(), 12000);
}

function playAlertSound() {
    try {
        const audioContext = new (window.AudioContext || window.webkitAudioContext)();
        const oscillator = audioContext.createOscillator();
        const gainNode = audioContext.createGain();
        
        oscillator.connect(gainNode);
        gainNode.connect(audioContext.destination);
        
        oscillator.frequency.value = 880;
        gainNode.gain.value = 0.3;
        
        oscillator.start();
        gainNode.gain.exponentialRampToValueAtTime(0.00001, audioContext.currentTime + 1);
        oscillator.stop(audioContext.currentTime + 0.5);
        
        audioContext.resume();
    } catch(e) {
        console.log('Audio not supported');
    }
}

function storeAlert(alert) {
    let alerts = JSON.parse(localStorage.getItem('studyAlerts') || '[]');
    alerts.unshift({
        ...alert,
        timestamp: new Date().toISOString(),
        read: false
    });
    alerts = alerts.slice(0, 20);
    localStorage.setItem('studyAlerts', JSON.stringify(alerts));
}

// Add notification styles
if(!document.getElementById('notification-styles')) {
    const styleSheet = document.createElement('style');
    styleSheet.id = 'notification-styles';
    styleSheet.textContent = `
        .notification {
            margin-bottom: 10px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            animation: slideInRight 0.3s ease;
            min-width: 300px;
            max-width: 400px;
        }
        .notification-deadline {
            background: linear-gradient(135deg, #f44336, #ff9800);
            color: white;
        }
        .notification-urgent, .notification-now {
            background: linear-gradient(135deg, #f44336, #d32f2f);
            color: white;
        }
        .notification-reminder {
            background: linear-gradient(135deg, #ff9800, #f57c00);
            color: white;
        }
        .notification-overdue {
            background: linear-gradient(135deg, #9c27b0, #7b1fa2);
            color: white;
        }
        .notification-content {
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .notification-icon {
            font-size: 20px;
        }
        .notification-message {
            flex: 1;
            font-size: 14px;
        }
        .notification-close {
            background: none;
            border: none;
            color: white;
            cursor: pointer;
            font-size: 16px;
            opacity: 0.7;
        }
        .notification-close:hover {
            opacity: 1;
        }
        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
    `;
    document.head.appendChild(styleSheet);
}

// Request notification permission on page load
if('Notification' in window && Notification.permission === "default") {
    Notification.requestPermission();
}
