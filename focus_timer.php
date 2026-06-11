<?php require_once 'db_config.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Focus Timer | StudySync</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .main-content { 
            flex: 1; 
            margin-left: 280px; 
            padding: 32px 40px; 
            display: flex; 
            justify-content: center; 
            align-items: center; 
            min-height: 100vh; 
        }
        .timer-container { 
            background: var(--bg-card); 
            border-radius: var(--radius-lg); 
            padding: 48px; 
            text-align: center; 
            max-width: 500px; 
            width: 100%; 
            border: 1px solid var(--border);
        }
        .timer-display { 
            font-size: 72px; 
            font-weight: 700; 
            font-family: monospace; 
            margin: 24px 0; 
            letter-spacing: 4px;
            color: var(--text-primary);
        }
        .timer-label { 
            font-size: 14px; 
            color: var(--text-secondary); 
            margin-top: 8px; 
        }
        .timer-controls { 
            display: flex; 
            gap: 16px; 
            justify-content: center; 
            margin: 24px 0; 
        }
        .timer-btn { 
            padding: 12px 24px; 
            border: none; 
            border-radius: var(--radius-md); 
            cursor: pointer; 
            font-weight: 500; 
            transition: all 0.2s; 
        }
        .timer-btn.start { 
            background: var(--accent); 
            color: white; 
        }
        .timer-btn.pause { 
            background: var(--warning); 
            color: white; 
        }
        .timer-btn.reset { 
            background: var(--bg-primary); 
            color: var(--text-secondary); 
            border: 1px solid var(--border);
        }
        .mode-buttons { 
            display: flex; 
            gap: 12px; 
            justify-content: center; 
            margin-top: 16px; 
        }
        .mode-btn { 
            padding: 8px 20px; 
            border: 1px solid var(--border); 
            background: var(--bg-card); 
            border-radius: 20px; 
            cursor: pointer; 
            font-size: 12px;
            color: var(--text-primary);
        }
        .mode-btn.active { 
            background: var(--accent); 
            color: white; 
            border-color: var(--accent); 
        }
        @media (max-width: 768px) { 
            .main-content { margin-left: 0; padding: 20px; }
            .timer-display { font-size: 48px; }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php require_once 'includes/sidebar.php'; ?>

        <main class="main-content">
            <div class="timer-container">
                <h2 style="margin-bottom: 16px; font-size: 24px;">Focus Timer</h2>
                <div class="timer-display" id="timerDisplay">25:00</div>
                <div class="timer-label" id="timerLabel">Work Session</div>
                
                <div class="timer-controls">
                    <button class="timer-btn start" id="startBtn" onclick="startTimer()">Start</button>
                    <button class="timer-btn pause" id="pauseBtn" onclick="pauseTimer()" style="display:none;">Pause</button>
                    <button class="timer-btn reset" onclick="resetTimer()">Reset</button>
                </div>
                
                <div class="mode-buttons">
                    <button class="mode-btn active" data-time="25" onclick="setMode(this, 25)">25 min</button>
                    <button class="mode-btn" data-time="50" onclick="setMode(this, 50)">50 min</button>
                    <button class="mode-btn" data-time="90" onclick="setMode(this, 90)">90 min</button>
                </div>
            </div>
        </main>
    </div>

    <script>
        let timerInterval = null;
        let timeRemaining = 25 * 60; // 25 minutes in seconds
        let isRunning = false;
        let currentMode = 25;

        const timerDisplay = document.getElementById('timerDisplay');
        const timerLabel = document.getElementById('timerLabel');
        const startBtn = document.getElementById('startBtn');
        const pauseBtn = document.getElementById('pauseBtn');

        function formatTime(seconds) {
            const mins = Math.floor(seconds / 60);
            const secs = seconds % 60;
            return `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
        }

        function updateDisplay() {
            timerDisplay.textContent = formatTime(timeRemaining);
        }

        function startTimer() {
            if (isRunning) return;
            isRunning = true;
            startBtn.style.display = 'none';
            pauseBtn.style.display = 'inline-flex';
            
            // Log session start
            let sessionId = null;
            const taskId = new URLSearchParams(window.location.search).get('task_id') || 0;
            fetch('session.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=start&task_id=' + taskId
            }).then(r => r.json()).then(d => { if (d.success) sessionId = d.session_id; });

            timerInterval = setInterval(() => {
                timeRemaining--;
                updateDisplay();
                
                if (timeRemaining <= 0) {
                    clearInterval(timerInterval);
                    isRunning = false;
                    timerLabel.textContent = "Time's up! 🎉";
                    startBtn.style.display = 'inline-flex';
                    pauseBtn.style.display = 'none';
                    playNotificationSound();
                    // Log session completion
                    if (sessionId) {
                        fetch('session.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'action=stop&session_id=' + sessionId
                        });
                    }
                }
            }, 1000);
        }

        function pauseTimer() {
            if (!isRunning) return;
            isRunning = false;
            clearInterval(timerInterval);
            startBtn.style.display = 'inline-flex';
            pauseBtn.style.display = 'none';
        }

        function resetTimer() {
            clearInterval(timerInterval);
            isRunning = false;
            timeRemaining = currentMode * 60;
            updateDisplay();
            timerLabel.textContent = currentMode === 25 ? "Work Session" : "Focus Session";
            startBtn.style.display = 'inline-flex';
            pauseBtn.style.display = 'none';
        }

        function setMode(btn, minutes) {
            document.querySelectorAll('.mode-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            currentMode = minutes;
            resetTimer();
        }

        function playNotificationSound() {
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


    </script>
</body>
</html>