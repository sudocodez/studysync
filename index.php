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
</head>
<body>
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