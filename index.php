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
        .bg-animations {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 0;
            overflow: hidden;
        }
        .bg-animations span {
            position: absolute;
            font-size: 42px;
            font-weight: 300;
            opacity: 0.2;
            color: var(--text-primary);
            user-select: none;
            animation: floatUp var(--dur, 20s) ease-in-out infinite alternate;
            animation-delay: var(--del, 0s);
        }
        @keyframes floatUp {
            0%   { transform: translateY(0px) rotate(0deg) scale(1); }
            33%  { transform: translateY(-30px) rotate(4deg) scale(1.08); }
            66%  { transform: translateY(-10px) rotate(-3deg) scale(0.95); }
            100% { transform: translateY(-20px) rotate(2deg) scale(1.03); }
        }
        /* Dark mode: slightly more visible */
        [data-theme="dark"] .bg-animations span {
            opacity: 0.25;
        }
    </style>
</head>
<body>
    <div class="bg-animations">
        <span style="top:5%;left:8%;--dur:22s;--del:0s;font-size:52px;">&#8747;</span>
        <span style="top:15%;right:12%;--dur:26s;--del:2s;font-size:44px;">&#931;</span>
        <span style="top:30%;left:5%;--dur:20s;--del:4s;font-size:48px;">&#960;</span>
        <span style="top:45%;right:8%;--dur:24s;--del:1s;font-size:38px;">&#8730;</span>
        <span style="top:55%;left:10%;--dur:28s;--del:3s;font-size:40px;">&#945;</span>
        <span style="top:65%;right:6%;--dur:21s;--del:5s;font-size:46px;">&#946;</span>
        <span style="top:75%;left:15%;--dur:25s;--del:2s;font-size:36px;">&#947;</span>
        <span style="top:8%;right:20%;--dur:23s;--del:6s;font-size:34px;">f(x)</span>
        <span style="top:50%;left:20%;--dur:27s;--del:7s;font-size:36px;">E=mc&sup2;</span>
        <span style="top:35%;right:18%;--dur:22s;--del:8s;font-size:40px;">x&sup2;+y&sup2;=r&sup2;</span>
        <span style="top:20%;left:22%;--dur:29s;--del:0s;font-size:30px;">H&sub2;O</span>
        <span style="top:70%;right:22%;--dur:24s;--del:4s;font-size:32px;">&#916;</span>
        <span style="top:85%;left:8%;--dur:26s;--del:1s;font-size:38px;">&#8721;</span>
        <span style="top:40%;left:2%;--dur:30s;--del:9s;font-size:44px;">&#8706;</span>
        <span style="top:60%;right:3%;--dur:22s;--del:6s;font-size:40px;">lim</span>
        <span style="top:10%;left:35%;--dur:28s;--del:3s;font-size:36px;">&#8734;</span>
        <span style="top:80%;right:15%;--dur:24s;--del:5s;font-size:32px;">&#8862;</span>
        <span style="top:25%;right:25%;--dur:26s;--del:7s;font-size:38px;">&#9001;x&#9002;</span>
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
</body>
</html>