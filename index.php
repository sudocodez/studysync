<?php
require_once 'db_config.php';

if($_SERVER['REQUEST_METHOD'] == 'POST') {
    if(isset($_POST['login'])) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$_POST['username']]);
        $user = $stmt->fetch();
        if($user && password_verify($_POST['password'], $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            header('Location: dashboard.php');
            exit();
        } else {
            $error = "Invalid credentials";
        }
    } else if(isset($_POST['register'])) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $stmt->execute([$_POST['username']]);
        if($stmt->fetchColumn() > 0) {
            $error = "Username already exists";
        } else {
            $hashed_password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, password, email) VALUES (?, ?, ?)");
            $stmt->execute([$_POST['username'], $hashed_password, $_POST['email'] ?? '']);
            $success = "Registration successful! Please login.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>StudySync | Intelligent Study Planner</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .bg-layer {
            position: fixed;
            inset: 0;
            z-index: 0;
            overflow: hidden;
            background: var(--bg-primary);
        }
        .bg-animations {
            position: absolute;
            inset: 0;
            pointer-events: none;
        }
        .bg-animations span {
            position: absolute;
            font-size: 38px;
            opacity: 0.45;
            user-select: none;
            animation: floatMove var(--dur, 12s) ease-in-out infinite alternate;
            animation-delay: var(--del, 0s);
            will-change: transform;
        }
        @keyframes floatMove {
            0%   { transform: translate(0px, 0px) rotate(0deg) scale(1); }
            25%  { transform: translate(20px, -25px) rotate(3deg) scale(1.06); }
            50%  { transform: translate(-15px, -35px) rotate(-4deg) scale(0.96); }
            75%  { transform: translate(25px, -15px) rotate(2deg) scale(1.03); }
            100% { transform: translate(-10px, -30px) rotate(-2deg) scale(1); }
        }
        .bg-overlay {
            position: fixed;
            inset: 0;
            z-index: 0;
            pointer-events: none;
            background: linear-gradient(135deg, rgba(0,0,0,0.12) 0%, rgba(0,0,0,0.06) 50%, rgba(0,0,0,0.10) 100%);
        }
        [data-theme="dark"] .bg-overlay {
            background: linear-gradient(135deg, rgba(0,0,0,0.25) 0%, rgba(0,0,0,0.12) 50%, rgba(0,0,0,0.20) 100%);
        }
        [data-theme="dark"] .bg-animations span {
            opacity: 0.55;
        }
        .bg-animations span b {
            display: inline-block;
            pointer-events: none;
        }
    </style>
</head>
<body>
    <div class="bg-layer">
        <div class="bg-animations" id="bgAnimations">
            <span style="top:4%;left:6%;--dur:11s;--del:0s;"><b>📚</b></span>
            <span style="top:12%;right:10%;--dur:13s;--del:1s;"><b>✏️</b></span>
            <span style="top:22%;left:3%;--dur:10s;--del:3s;"><b>🎓</b></span>
            <span style="top:35%;right:5%;--dur:14s;--del:2s;"><b>📝</b></span>
            <span style="top:48%;left:8%;--dur:12s;--del:4s;"><b>⚗️</b></span>
            <span style="top:58%;right:8%;--dur:15s;--del:1s;"><b>🔬</b></span>
            <span style="top:68%;left:4%;--dur:11s;--del:5s;"><b>📐</b></span>
            <span style="top:78%;right:3%;--dur:13s;--del:2s;"><b>💡</b></span>
            <span style="top:6%;left:28%;--dur:14s;--del:6s;"><b>🧮</b></span>
            <span style="top:18%;right:25%;--dur:10s;--del:0s;"><b>📖</b></span>
            <span style="top:42%;left:22%;--dur:12s;--del:3s;"><b>🖋️</b></span>
            <span style="top:55%;right:22%;--dur:15s;--del:7s;"><b>🏆</b></span>
            <span style="top:72%;left:18%;--dur:11s;--del:2s;"><b>🧪</b></span>
            <span style="top:88%;right:15%;--dur:13s;--del:4s;"><b>📊</b></span>
            <span style="top:30%;left:35%;--dur:10s;--del:5s;"><b>🔭</b></span>
            <span style="top:65%;right:18%;--dur:14s;--del:6s;"><b>💻</b></span>
            <span style="top:10%;left:50%;--dur:12s;--del:1s;"><b>⏳</b></span>
            <span style="top:50%;left:45%;--dur:15s;--del:8s;"><b>🧠</b></span>
            <span style="top:8%;left:70%;--dur:13s;--del:9s;"><b>🎯</b></span>
            <span style="top:20%;right:35%;--dur:11s;--del:4s;"><b>📌</b></span>
            <span style="top:38%;left:55%;--dur:14s;--del:7s;"><b>⭐</b></span>
            <span style="top:55%;left:30%;--dur:12s;--del:2s;"><b>🔥</b></span>
            <span style="top:75%;right:30%;--dur:10s;--del:5s;"><b>💯</b></span>
            <span style="top:15%;left:15%;--dur:15s;--del:8s;"><b>🎵</b></span>
            <span style="top:40%;right:28%;--dur:13s;--del:3s;"><b>📋</b></span>
            <span style="top:60%;left:38%;--dur:11s;--del:6s;"><b>⚡</b></span>
            <span style="top:82%;right:8%;--dur:14s;--del:9s;"><b>☕</b></span>
            <span style="top:25%;left:45%;--dur:12s;--del:1s;"><b>🎧</b></span>
            <span style="top:70%;left:28%;--dur:10s;--del:4s;"><b>🌙</b></span>
            <span style="top:5%;right:35%;--dur:15s;--del:7s;"><b>💪</b></span>
            <span style="top:45%;left:70%;--dur:13s;--del:2s;"><b>✅</b></span>
            <span style="top:90%;left:10%;--dur:11s;--del:6s;"><b>🎨</b></span>
        </div>
        <div class="bg-overlay"></div>
    </div>
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <div class="logo-icon" style="margin: 0 auto 16px; width: 60px; height: 60px; border-radius: 50%; overflow: hidden;">
                    <img src="logo1.png" alt="Kiut" style="width:100%;height:100%;object-fit:cover;display:block;">
                </div>
                <h1>StudySync</h1>
                <p>Intelligent Study Planner & Scheduler</p>
            </div>
            
            <?php if(isset($error)): ?>
                <div style="background: rgba(239,68,68,0.1); border: 1px solid var(--danger); border-radius: 12px; padding: 12px; margin-bottom: 20px; color: var(--danger); font-size: 13px;">
                    ❌ <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
            
            <?php if(isset($success)): ?>
                <div style="background: rgba(16,185,129,0.1); border: 1px solid var(--success); border-radius: 12px; padding: 12px; margin-bottom: 20px; color: var(--success); font-size: 13px;">
                    ✅ <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" class="auth-form">
                <div class="input-group">
                    <label>Username</label>
                    <input type="text" name="username" placeholder="Enter your username" required>
                </div>
                <div class="input-group">
                    <label>Password</label>
                    <input type="password" name="password" placeholder="••••••••" required>
                </div>
                <button type="submit" name="login" class="auth-btn">Sign In</button>
            </form>
            
            <div class="auth-divider">or</div>
            
            <form method="POST" class="auth-form">
                <div class="input-group">
                    <label>Username</label>
                    <input type="text" name="username" placeholder="Choose a username" required>
                </div>
                <div class="input-group">
                    <label>Email (Optional)</label>
                    <input type="email" name="email" placeholder="your@email.com">
                </div>
                <div class="input-group">
                    <label>Password</label>
                    <input type="password" name="password" placeholder="Create a password" required>
                </div>
                <button type="submit" name="register" class="auth-btn">Create Account</button>
            </form>
        </div>
    </div>
    <script>
        (function() {
            const container = document.getElementById('bgAnimations');
            if (!container) return;
            const items = container.querySelectorAll('span b');
            let mouseX = -9999, mouseY = -9999;
            document.addEventListener('mousemove', function(e) {
                mouseX = e.clientX;
                mouseY = e.clientY;
            });
            function tick() {
                items.forEach(function(el) {
                    const rect = el.getBoundingClientRect();
                    const cx = rect.left + rect.width/2;
                    const cy = rect.top + rect.height/2;
                    const dx = mouseX - cx;
                    const dy = mouseY - cy;
                    const dist = Math.sqrt(dx*dx + dy*dy);
                    if (dist < 180) {
                        const strength = (1 - dist/180) * 0.6;
                        el.style.transform = 'translate(' + (dx * strength).toFixed(1) + 'px,' + (dy * strength).toFixed(1) + 'px)';
                    } else {
                        el.style.transform = '';
                    }
                });
                requestAnimationFrame(tick);
            }
            tick();
        })();
    </script>
</body>
</html>