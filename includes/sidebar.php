<?php
// Shared sidebar navigation
$current_page = basename($_SERVER['PHP_SELF']);

$sidebar_unread = 0;
if (isset($pdo, $_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$_SESSION['user_id']]);
    $sidebar_unread = (int)$stmt->fetchColumn();
}
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="logo">
            <div class="logo-icon">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>
                    <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>
                    <circle cx="16" cy="8" r="3" stroke-width="1.2"/>
                    <path d="M14.5 9.5 16 8l1.5 1.5"/>
                </svg>
            </div>
            <span class="logo-text">StudySync</span>
        </div>
    </div>
    
    <nav class="sidebar-nav">
        <a href="dashboard.php" class="nav-item <?= $current_page === 'dashboard.php' ? 'active' : '' ?>">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2h-5v-7H9v7H5a2 2 0 0 1-2-2z"/>
            </svg>
            <span class="nav-text">Dashboard</span>
        </a>
        <a href="calendar.php" class="nav-item <?= $current_page === 'calendar.php' ? 'active' : '' ?>">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                <line x1="16" y1="2" x2="16" y2="6"/>
                <line x1="8" y1="2" x2="8" y2="6"/>
                <line x1="3" y1="10" x2="21" y2="10"/>
            </svg>
            <span class="nav-text">Calendar</span>
        </a>
        <a href="availability.php" class="nav-item <?= $current_page === 'availability.php' ? 'active' : '' ?>">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
            </svg>
            <span class="nav-text">Availability</span>
        </a>
        <a href="activities.php" class="nav-item <?= $current_page === 'activities.php' ? 'active' : '' ?>">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <polyline points="12 6 12 12 16 14"/>
            </svg>
            <span class="nav-text">Activities</span>
        </a>
        <a href="focus_timer.php" class="nav-item <?= $current_page === 'focus_timer.php' ? 'active' : '' ?>">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <polygon points="12 6 9.5 9.5 6 12 12 12"/>
            </svg>
            <span class="nav-text">Focus Timer</span>
        </a>
        <a href="notifications.php" class="nav-item <?= $current_page === 'notifications.php' ? 'active' : '' ?>" id="notifNavItem">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
            </svg>
            <span class="nav-text">Notifications</span>
            <span id="notifBadge" class="nav-badge" <?= $sidebar_unread > 0 ? '' : 'style="display:none;"' ?>><?= $sidebar_unread > 99 ? '99+' : $sidebar_unread ?></span>
        </a>
        <a href="profile.php" class="nav-item <?= $current_page === 'profile.php' ? 'active' : '' ?>">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                <circle cx="12" cy="7" r="4"/>
            </svg>
            <span class="nav-text">Profile</span>
        </a>
        <a href="weekly_summary.php" class="nav-item <?= $current_page === 'weekly_summary.php' ? 'active' : '' ?>">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
            </svg>
            <span class="nav-text">Weekly Summary</span>
        </a>
        <a href="add_course.php" class="nav-item <?= $current_page === 'add_course.php' ? 'active' : '' ?>">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>
                <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>
            </svg>
            <span class="nav-text">Courses</span>
        </a>
        <a href="#" class="nav-item" onclick="openTaskModal()">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 5v14M5 12h14"/>
            </svg>
            <span class="nav-text">Add New</span>
        </a>
    </nav>
    
    <div class="sidebar-footer">
        <a href="#" class="footer-item" onclick="toggleDarkMode()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
            </svg>
            <span>Dark Mode</span>
        </a>
        <a href="#" class="footer-item" onclick="openHelpCenter()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <path d="M12 16v-4M12 8h.01"/>
            </svg>
            <span>Help Center</span>
        </a>
        <a href="#" class="footer-item" onclick="openCalendarSync()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                <line x1="16" y1="2" x2="16" y2="6"/>
                <line x1="8" y1="2" x2="8" y2="6"/>
                <line x1="3" y1="10" x2="21" y2="10"/>
            </svg>
            <span>Calendar Sync</span>
        </a>
        <a href="logout.php" class="footer-item">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                <polyline points="16 17 21 12 16 7"/>
                <line x1="21" y1="12" x2="9" y2="12"/>
            </svg>
            <span>Log Out</span>
        </a>
    </div>
</aside>

<!-- Mobile hamburger -->
<button class="hamburger" id="hamburgerBtn" onclick="toggleSidebar()" aria-label="Toggle menu">
    <span></span>
    <span></span>
    <span></span>
</button>

<!-- Sidebar overlay (mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<!-- Floating Chat Button -->
<div class="chat-fab">
    <button class="chat-toggle" onclick="toggleChat()">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
        </svg>
    </button>
    <div class="chat-window" id="chatWindow">
        <div class="chat-header">
            <h3 style="font-size: 15px;">StudySync Assistant</h3>
            <button class="chat-close" onclick="toggleChat()" style="background: none; border: none; font-size: 20px; cursor: pointer;">✕</button>
        </div>
        <div class="chat-messages" id="chatMessages">
            <div class="chat-message assistant">Hello! How can I help with your studies today?</div>
        </div>
        <div class="chat-input-area">
            <textarea class="chat-input" id="chatInput" rows="1" placeholder="Ask me anything..."></textarea>
            <button class="chat-send" onclick="sendChatMessage()">Send</button>
        </div>
    </div>
</div>

<div id="helpModal" class="sidebar-modal">
    <div class="sidebar-modal-backdrop" onclick="closeHelpCenter()"></div>
    <div class="sidebar-modal-content">
        <div class="sidebar-modal-header">
            <h3>Help Center</h3>
            <button class="sidebar-modal-close" onclick="closeHelpCenter()">✕</button>
        </div>
        <div class="sidebar-modal-body">
            <div class="help-section">
                <h4>Getting Started</h4>
                <p>Add your courses in <strong>Courses</strong>, then create tasks. Set your weekly study hours in <strong>Availability</strong> and click <em>Generate Plan</em> on the Dashboard to build a schedule.</p>
            </div>
            <div class="help-section">
                <h4>Dashboard</h4>
                <p>Shows your stats, today's schedule, and upcoming tasks. Click the completion rate to see a pie chart. Start/stop study sessions from the timeline to track hours and streaks.</p>
            </div>
            <div class="help-section">
                <h4>Focus Timer</h4>
                <p>Use Pomodoro-style timers (25/50/90 min). Completed sessions are automatically logged to your study hours and streak.</p>
            </div>
            <div class="help-section">
                <h4>Calendar</h4>
                <p>Weekly view of all planned study sessions. Navigate between weeks and see task colors. Sessions are generated from your study plan.</p>
            </div>
            <div class="help-section">
                <h4>AI Assistant</h4>
                <p>Ask the chatbot anything about studying, time management, or using the app. Click the chat icon in the bottom-right corner.</p>
            </div>
            <div class="help-section">
                <h4>Quick Tips</h4>
                <ul>
                    <li>Set realistic availability hours — the planner uses these to schedule tasks</li>
                    <li>Break large assignments into smaller tasks with their own deadlines</li>
                    <li>Use the Focus Timer to build a daily study habit</li>
                    <li>Check the Calendar weekly to see your upcoming workload</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<div id="calendarModal" class="sidebar-modal">
    <div class="sidebar-modal-backdrop" onclick="closeCalendarSync()"></div>
    <div class="sidebar-modal-content">
        <div class="sidebar-modal-header">
            <h3>Calendar Sync</h3>
            <button class="sidebar-modal-close" onclick="closeCalendarSync()">✕</button>
        </div>
        <div class="sidebar-modal-body">
            <div class="help-section">
                <h4>Study Schedule Calendar</h4>
                <p>Your study plan is displayed in the <a href="calendar.php" style="color: var(--accent); text-decoration: none; font-weight: 500;">Weekly Calendar</a>. It shows all your scheduled study sessions organized by day and time.</p>
            </div>
            <div class="help-section">
                <h4>Google Calendar Integration</h4>
                <p>Coming soon! Future updates will allow syncing your study plan directly with Google Calendar, Outlook, and other calendar services. You'll be able to export your schedule with one click.</p>
            </div>
            <div class="help-section">
                <h4>ICS Export</h4>
                <p>For now, you can view your schedule in the <a href="calendar.php" style="color: var(--accent); text-decoration: none; font-weight: 500;">Calendar page</a> and manually add sessions to your preferred calendar app.</p>
            </div>
            <div class="help-section" style="background: var(--accent-soft); border-radius: var(--radius-sm); padding: 14px;">
                <div style="font-weight: 600; margin-bottom: 4px; color: var(--accent);">Quick Actions</div>
                <a href="calendar.php" class="sidebar-modal-btn">View Calendar →</a>
                <a href="availability.php" style="display: inline-block; margin-top: 8px; margin-left: 8px; padding: 8px 16px; background: var(--bg-primary); color: var(--text-primary); border-radius: var(--radius-sm); text-decoration: none; font-size: 13px; border: 1px solid var(--border);">Set Availability</a>
            </div>
        </div>
    </div>
</div>

<style>
.sidebar-modal {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    z-index: 2000;
    align-items: center;
    justify-content: center;
}
.sidebar-modal-backdrop {
    position: absolute;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.4);
}
.sidebar-modal-content {
    position: relative;
    background: var(--bg-card);
    border-radius: var(--radius-lg);
    width: 90%;
    max-width: 480px;
    max-height: 80vh;
    overflow-y: auto;
    border: 1px solid var(--border);
    box-shadow: var(--shadow-lg);
    animation: fadeIn 0.2s ease;
}
.sidebar-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 24px;
    border-bottom: 1px solid var(--border);
    position: sticky;
    top: 0;
    background: var(--bg-card);
}
.sidebar-modal-header h3 {
    font-size: 18px;
    font-weight: 600;
}
.sidebar-modal-close {
    background: none;
    border: none;
    font-size: 18px;
    cursor: pointer;
    color: var(--text-muted);
    padding: 4px 8px;
    border-radius: 4px;
}
.sidebar-modal-close:hover {
    background: var(--bg-primary);
    color: var(--text-primary);
}
.sidebar-modal-body {
    padding: 20px 24px;
}
.sidebar-modal-btn {
    display: inline-block;
    margin-top: 8px;
    padding: 8px 16px;
    background: var(--accent);
    color: white;
    border-radius: var(--radius-sm);
    text-decoration: none;
    font-size: 13px;
}
[data-theme="dark"] .sidebar-modal-btn {
    color: #1A3C2A;
}
.help-section {
    margin-bottom: 18px;
}
.help-section h4 {
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 6px;
    color: var(--text-primary);
}
.help-section p {
    font-size: 13px;
    line-height: 1.6;
    color: var(--text-secondary);
}
.help-section ul {
    margin: 6px 0 0 16px;
    font-size: 13px;
    color: var(--text-secondary);
    line-height: 1.7;
}
</style>

<script>
var CSRF_TOKEN = '<?= $_SESSION['csrf_token'] ?>';

function toggleDarkMode() {
    const html = document.documentElement;
    const currentTheme = html.getAttribute('data-theme');
    if (currentTheme === 'dark') {
        html.removeAttribute('data-theme');
        localStorage.setItem('theme', 'light');
    } else {
        html.setAttribute('data-theme', 'dark');
        localStorage.setItem('theme', 'dark');
    }
}

// Sidebar toggle for mobile
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const btn = document.getElementById('hamburgerBtn');
    sidebar.classList.toggle('open');
    if (overlay) overlay.classList.toggle('open');
    if (btn) btn.classList.toggle('open');
}

function openHelpCenter() {
    document.getElementById('helpModal').style.display = 'flex';
    document.addEventListener('keydown', helpEscHandler);
}

function closeHelpCenter() {
    document.getElementById('helpModal').style.display = 'none';
    document.removeEventListener('keydown', helpEscHandler);
}

function openCalendarSync() {
    document.getElementById('calendarModal').style.display = 'flex';
    document.addEventListener('keydown', calEscHandler);
}

function closeCalendarSync() {
    document.getElementById('calendarModal').style.display = 'none';
    document.removeEventListener('keydown', calEscHandler);
}

function helpEscHandler(e) { if (e.key === 'Escape') closeHelpCenter(); }
function calEscHandler(e) { if (e.key === 'Escape') closeCalendarSync(); }

// Load saved theme
const savedTheme = localStorage.getItem('theme');
if (savedTheme === 'dark') {
    document.documentElement.setAttribute('data-theme', 'dark');
}

function toggleChat() {
    document.getElementById('chatWindow').classList.toggle('open');
}

function sendChatMessage() {
    const input = document.getElementById('chatInput');
    const message = input.value.trim();
    if (!message) return;

    const messagesDiv = document.getElementById('chatMessages');
    messagesDiv.innerHTML += `<div class="chat-message user">${escapeHtml(message)}</div>`;

    const formData = new FormData();
    formData.append('message', message);

    fetch('chatbot.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.response) {
            messagesDiv.innerHTML += `<div class="chat-message assistant">${escapeHtml(data.response)}</div>`;
        }
    })
    .catch(error => console.log('Chat error:', error));

    input.value = '';
    messagesDiv.scrollTop = messagesDiv.scrollHeight;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

document.addEventListener('DOMContentLoaded', function() {
    const chatInput = document.getElementById('chatInput');
    if (chatInput) {
        chatInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendChatMessage();
            }
        });
    }
});
</script>