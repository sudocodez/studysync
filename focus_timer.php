<?php require_once 'db_config.php';

$session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
$task_id = isset($_GET['task_id']) ? (int)$_GET['task_id'] : 0;
$plan_id = isset($_GET['plan_id']) ? (int)$_GET['plan_id'] : 0;

$session_title = 'Focus Session';
if ($plan_id) {
    $stmt = $pdo->prepare("SELECT sp.*, t.title as task_title, t.id as tid FROM study_plan sp LEFT JOIN tasks t ON sp.task_id = t.id WHERE sp.id = ? AND sp.user_id = ?");
    $stmt->execute([$plan_id, $_SESSION['user_id']]);
    $s = $stmt->fetch();
    if ($s) {
        $session_title = htmlspecialchars($s['task_title'] ?? 'Study Session');
        $task_id = $s['tid'];
        $session_id = $s['id'];
    }
} elseif ($session_id) {
    $stmt = $pdo->prepare("SELECT sp.*, t.title as task_title FROM study_plan sp LEFT JOIN tasks t ON sp.task_id = t.id WHERE sp.id = ? AND sp.user_id = ?");
    $stmt->execute([$session_id, $_SESSION['user_id']]);
    $s = $stmt->fetch();
    if ($s) $session_title = htmlspecialchars($s['task_title'] ?? 'Study Session');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Focus Timer | StudySync</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="liquid-glass.css">
    <style>
        .main-content { flex:1; margin-left:280px; padding:32px 40px; display:flex; justify-content:center; align-items:center; min-height:100vh; }
        .timer-container { background:var(--bg-card); border-radius:var(--radius-lg); padding:48px; text-align:center; max-width:520px; width:100%; border:1px solid var(--border); position:relative; }
        .timer-display { font-size:80px; font-weight:700; font-family:monospace; margin:16px 0; letter-spacing:4px; color:var(--text-primary); }
        .timer-label { font-size:15px; color:var(--text-secondary); margin-bottom:4px; }
        .timer-sublabel { font-size:12px; color:var(--text-muted); margin-bottom:20px; }
        .timer-controls { display:flex; gap:12px; justify-content:center; margin:20px 0; }
        .timer-btn { padding:12px 28px; border:none; border-radius:var(--radius-md); cursor:pointer; font-weight:600; font-size:14px; transition:all 0.2s; }
        .timer-btn.primary { background:var(--accent); color:white; }
        .timer-btn.primary:hover { opacity:0.9; }
        .timer-btn.warning { background:var(--warning); color:white; }
        .timer-btn.danger { background:var(--danger); color:white; }
        .timer-btn.outline { background:transparent; color:var(--text-secondary); border:1px solid var(--border); }
        .timer-btn.outline:hover { background:var(--bg-primary); }
        .extend-row { display:flex; gap:8px; justify-content:center; margin-top:12px; }
        .extend-btn { padding:6px 16px; border:1px solid var(--border); background:var(--bg-card); border-radius:var(--radius-sm); cursor:pointer; font-size:12px; color:var(--text-secondary); }
        .extend-btn:hover { background:var(--accent); color:white; border-color:var(--accent); }
        .phase-badge { display:inline-block; padding:4px 16px; border-radius:20px; font-size:12px; font-weight:600; margin-bottom:12px; }
        .phase-work { background:var(--accent)20; color:var(--accent); }
        .phase-break { background:var(--warning)20; color:var(--warning); }
        .phase-done { background:var(--success)20; color:var(--success); }

        .overlay { position:fixed; inset:0; z-index:99999; background:rgba(0,0,0,0.85); display:flex; align-items:center; justify-content:center; animation:fadeIn 0.3s ease; }
        .overlay-card { background:#1a1a1a; border-radius:20px; padding:40px 36px; text-align:center; max-width:440px; width:90%; border:2px solid var(--accent); box-shadow:0 0 60px rgba(0,0,0,0.4); }
        .overlay-card.warning { border-color:var(--warning); }
        .overlay-icon { font-size:56px; margin-bottom:12px; }
        .overlay-title { font-size:22px; font-weight:700; color:white; margin-bottom:8px; }
        .overlay-msg { font-size:14px; color:#ccc; margin-bottom:20px; line-height:1.5; }
        .overlay-timer { font-size:48px; font-weight:700; font-family:monospace; color:var(--warning); margin:12px 0 20px; }
        .overlay-btn { padding:12px 32px; border:none; border-radius:12px; cursor:pointer; font-weight:700; font-size:15px; margin:4px; }
        .overlay-btn.primary { background:var(--accent); color:white; }
        .overlay-btn.warning { background:var(--warning); color:white; }
        .overlay-btn.outline { background:transparent; color:#ccc; border:1px solid #555; }
        .overlay-btn.outline:hover { border-color:#fff; color:#fff; }

        @keyframes fadeIn { from { opacity:0; } to { opacity:1; } }
        @media (max-width:768px) { .main-content { margin-left:0; padding:20px; } .timer-display { font-size:56px; } .overlay-timer { font-size:36px; } }
        @media (max-width:480px) { .timer-container { padding:32px 16px; } .timer-display { font-size:44px; } }
    </style>
</head>
<body>
    <div class="app-container">
        <?php require_once 'includes/sidebar.php'; ?>
        <main class="main-content">
            <div class="timer-container" id="timerContainer">
                <div class="phase-badge phase-work" id="phaseBadge">📖 Work Session</div>
                <div class="timer-label" id="sessionTitle"><?= $session_title ?></div>
                <div class="timer-sublabel" id="cycleInfo">Cycle 1</div>
                <div class="timer-display" id="timerDisplay">25:00</div>

                <div class="timer-controls" id="controlsWork">
                    <button class="timer-btn primary" id="startBtn" onclick="startTimer()">▶ Start</button>
                    <button class="timer-btn warning" id="pauseBtn" onclick="pauseTimer()" style="display:none;">⏸ Pause</button>
                    <button class="timer-btn outline" id="resetBtn" onclick="resetPhase()">↻ Reset</button>
                </div>

                <div class="extend-row" id="extendWork">
                    <button class="extend-btn" onclick="addTime(5)">+5 min</button>
                    <button class="extend-btn" onclick="addTime(10)">+10 min</button>
                </div>
            </div>
        </main>
    </div>

    <div id="overlayContainer"></div>

    <script>
        var CSRF_TOKEN = '<?= $_SESSION['csrf_token'] ?>';

        // ── Timer state ──
        var timerInterval = null;
        var timeRemaining = 25 * 60;
        var isRunning = false;
        var phase = 'work'; // 'work' | 'break' | 'done'
        var cycle = 1;

        var sessionId = <?= $session_id ?>;
        var taskId = <?= $task_id ?>;
        var activeSessionId = null;
        var planId = <?= $plan_id ?>;

        var elDisplay = document.getElementById('timerDisplay');
        var elPhase = document.getElementById('phaseBadge');
        var elCycle = document.getElementById('cycleInfo');
        var elStart = document.getElementById('startBtn');
        var elPause = document.getElementById('pauseBtn');
        var elReset = document.getElementById('resetBtn');
        var elExtend = document.getElementById('extendWork');
        var elOverlay = document.getElementById('overlayContainer');

        var breakActivities = [
            'Stretch your arms and neck',
            'Walk around the room',
            'Drink a glass of water',
            'Take 5 deep breaths',
            'Look out the window',
            'Do 10 jumping jacks',
            'Splash water on your face',
            'Roll your shoulders back',
            'Close your eyes and relax',
            'Tidy your desk a little'
        ];

        function formatTime(s) {
            var m = Math.floor(s / 60);
            var sec = s % 60;
            return (m < 10 ? '0' : '') + m + ':' + (sec < 10 ? '0' : '') + sec;
        }

        function updateDisplay() {
            elDisplay.textContent = formatTime(timeRemaining);
        }

        function setPhase(newPhase) {
            phase = newPhase;
            if (phase === 'work') {
                elPhase.textContent = '📖 Work Session';
                elPhase.className = 'phase-badge phase-work';
                elExtend.style.display = 'flex';
            } else if (phase === 'break') {
                elPhase.textContent = '☕ Break Time';
                elPhase.className = 'phase-badge phase-break';
                elExtend.style.display = 'none';
            } else {
                elPhase.textContent = '✅ Session Complete';
                elPhase.className = 'phase-badge phase-done';
                elExtend.style.display = 'none';
            }
        }

        function setTime(mins) {
            timeRemaining = mins * 60;
            updateDisplay();
        }

        // ── Controls ──

        function startTimer() {
            if (isRunning) return;
            isRunning = true;
            elStart.style.display = 'none';
            elPause.style.display = 'inline-flex';
        }

        function pauseTimer() {
            if (!isRunning) return;
            isRunning = false;
            clearInterval(timerInterval);
            timerInterval = null;
            elStart.style.display = 'inline-flex';
            elPause.style.display = 'none';
        }

        function resetPhase() {
            clearInterval(timerInterval);
            timerInterval = null;
            isRunning = false;
            elStart.style.display = 'inline-flex';
            elPause.style.display = 'none';
            if (phase === 'work') {
                setTime(25);
            } else if (phase === 'break') {
                setTime(5);
            }
        }

        function addTime(mins) {
            timeRemaining += mins * 60;
            updateDisplay();
        }

        // ── Timer tick ──

        function runTimer() {
            if (!isRunning) return;
            timerInterval = setInterval(function() {
                timeRemaining--;
                updateDisplay();

                if (timeRemaining <= 0) {
                    clearInterval(timerInterval);
                    timerInterval = null;
                    isRunning = false;
                    elStart.style.display = 'inline-flex';
                    elPause.style.display = 'none';
                    playAlarmSound();

                    if (phase === 'work') {
                        // Log session completion
                        if (activeSessionId) {
                            fetch('session.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF_TOKEN },
                                body: 'action=stop&session_id=' + activeSessionId
                            });
                            activeSessionId = null;
                        }
                        showBreakOverlay();
                    } else if (phase === 'break') {
                        showResumeOverlay();
                    }
                }
            }, 1000);
        }

        startTimer = function() {
            if (isRunning) return;
            isRunning = true;
            elStart.style.display = 'none';
            elPause.style.display = 'inline-flex';
            // Log session start on work phase
            if (phase === 'work' && !activeSessionId) {
                var params = 'action=start' + (planId ? '&plan_id=' + planId : taskId ? '&task_id=' + taskId : '');
                fetch('session.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF_TOKEN },
                    body: params
                }).then(function(r) { return r.json(); }).then(function(d) { if (d.success) activeSessionId = d.session_id; });
            }
            runTimer();
        };

        // ── Break Overlay ──
        function showBreakOverlay() {
            var activity = breakActivities[Math.floor(Math.random() * breakActivities.length)];
            var breakMins = 5;
            var breakTime = breakMins * 60;
            phase = 'break';

            elOverlay.innerHTML = '<div class="overlay">' +
                '<div class="overlay-card warning">' +
                '<div class="overlay-icon">🎉</div>' +
                '<div class="overlay-title">Great Work! Time for a Break</div>' +
                '<div class="overlay-msg">' + activity + '</div>' +
                '<div class="overlay-timer" id="breakTimer">' + formatTime(breakTime) + '</div>' +
                '<div style="font-size:12px;color:#888;margin-bottom:16px;">Break ends in</div>' +
                '<div>' +
                '<button class="overlay-btn warning" onclick="extendBreak(5)">+5 min Break</button>' +
                '<button class="overlay-btn outline" onclick="extendBreak(10)">+10 min Break</button>' +
                '</div>' +
                '</div></div>';

            var bt = breakTime;
            var bi = setInterval(function() {
                bt--;
                var t = document.getElementById('breakTimer');
                if (t) t.textContent = formatTime(bt);
                if (bt <= 0) {
                    clearInterval(bi);
                    closeOverlay();
                    playAlarmSound();
                    showResumeOverlay();
                }
            }, 1000);
            elOverlay._breakInterval = bi;
            elOverlay._breakTime = breakTime;
        }

        function extendBreak(mins) {
            if (elOverlay._breakInterval) clearInterval(elOverlay._breakInterval);
            var bt = elOverlay._breakTime + mins * 60;
            elOverlay._breakTime = bt;
            var t = document.getElementById('breakTimer');
            if (t) t.textContent = formatTime(bt);
            elOverlay._breakInterval = setInterval(function() {
                bt--;
                var tt = document.getElementById('breakTimer');
                if (tt) tt.textContent = formatTime(bt);
                if (bt <= 0) {
                    clearInterval(elOverlay._breakInterval);
                    closeOverlay();
                    playAlarmSound();
                    showResumeOverlay();
                }
            }, 1000);
        }

        // ── Resume Overlay ──
        function showResumeOverlay() {
            elOverlay.innerHTML = '<div class="overlay">' +
                '<div class="overlay-card">' +
                '<div class="overlay-icon">⏰</div>' +
                '<div class="overlay-title">Break is Over!</div>' +
                '<div class="overlay-msg">Ready to dive back in? Take a moment, then start your next session when you\'re ready.</div>' +
                '<div>' +
                '<button class="overlay-btn primary" onclick="startNextSession()">▶ Start Next Session</button>' +
                '</div>' +
                '<div style="margin-top:12px;">' +
                '<button class="overlay-btn outline" onclick="extendBreak(5)">+5 min Extra Break</button>' +
                '<button class="overlay-btn outline" onclick="extendBreak(10)">+10 min Extra Break</button>' +
                '</div>' +
                '</div></div>';
        }

        function startNextSession() {
            closeOverlay();
            cycle++;
            elCycle.textContent = 'Cycle ' + cycle;
            phase = 'work';
            setPhase('work');
            setTime(25);
            elExtend.style.display = 'flex';
            elStart.style.display = 'inline-flex';
            elPause.style.display = 'none';
        }

        function closeOverlay() {
            if (elOverlay._breakInterval) clearInterval(elOverlay._breakInterval);
            elOverlay.innerHTML = '';
        }

        // ── Alarm Sound ──
        function playAlarmSound() {
            try {
                var ctx = new (window.AudioContext || window.webkitAudioContext)();
                var high = true;
                for (var i = 0; i < 6; i++) {
                    var o = ctx.createOscillator();
                    var g = ctx.createGain();
                    o.connect(g);
                    g.connect(ctx.destination);
                    o.type = 'square';
                    o.frequency.value = high ? 1000 : 800;
                    high = !high;
                    g.gain.setValueAtTime(0.4, ctx.currentTime + i * 0.25);
                    g.gain.exponentialRampToValueAtTime(0.00001, ctx.currentTime + i * 0.25 + 0.22);
                    o.start(ctx.currentTime + i * 0.25);
                    o.stop(ctx.currentTime + i * 0.25 + 0.22);
                }
                ctx.resume();
            } catch (e) {}
        }

        // ── Init ──
        setPhase('work');
        setTime(25);
        elCycle.textContent = 'Cycle ' + cycle;
    </script>
</body>
</html>
