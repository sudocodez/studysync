<?php
require_once 'db_config.php';
verify_csrf();

$user_id = $_SESSION['user_id'];
$message = '';
$msg_type = '';

// Fetch current user
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    header('Location: index.php');
    exit();
}

// Handle email update
if (isset($_POST['update_email'])) {
    $email = trim($_POST['email']);
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $stmt = $pdo->prepare("UPDATE users SET email = ? WHERE id = ?");
        $stmt->execute([$email, $user_id]);
        $message = 'Email updated successfully.';
        $msg_type = 'success';
        $user['email'] = $email;
    } else {
        $message = 'Please enter a valid email address.';
        $msg_type = 'error';
    }
}

// Handle password change
if (isset($_POST['change_password'])) {
    $current = $_POST['current_password'];
    $new = $_POST['new_password'];
    $confirm = $_POST['confirm_password'];

    if (!password_verify($current, $user['password'])) {
        $message = 'Current password is incorrect.';
        $msg_type = 'error';
    } elseif (strlen($new) < 6) {
        $message = 'New password must be at least 6 characters.';
        $msg_type = 'error';
    } elseif ($new !== $confirm) {
        $message = 'New passwords do not match.';
        $msg_type = 'error';
    } else {
        $hashed = password_hash($new, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$hashed, $user_id]);
        $message = 'Password changed successfully.';
        $msg_type = 'success';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile | StudySync</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="app-container">
        <?php require_once 'includes/sidebar.php'; ?>
        <main class="main-content">
            <h1 style="font-size: 24px; font-weight: 600; margin-bottom: 24px;">Profile</h1>

            <?php if ($message): ?>
                <div style="padding: 12px 16px; border-radius: var(--radius-sm); margin-bottom: 20px; font-size: 13px; background: <?= $msg_type === 'success' ? 'var(--success)' : 'var(--danger)' ?>; color: white;">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <div class="two-column">
                <div class="section-card">
                    <div class="section-header">
                        <h2>Account Details</h2>
                    </div>
                    <div style="padding: 24px;">
                        <div style="margin-bottom: 20px;">
                            <label style="font-size: 12px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Username</label>
                            <div style="font-size: 16px; font-weight: 500; margin-top: 4px; color: var(--text-primary);"><?= htmlspecialchars($user['username']) ?></div>
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label style="font-size: 12px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Member Since</label>
                            <div style="font-size: 16px; font-weight: 500; margin-top: 4px; color: var(--text-primary);"><?= date('F j, Y', strtotime($user['created_at'])) ?></div>
                        </div>
                        <form method="POST">
                            <?= csrf_field() ?>
                            <label style="font-size: 12px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Email</label>
                            <div class="input-group" style="margin-top: 6px;">
                                <input type="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" placeholder="your@email.com">
                            </div>
                            <button type="submit" name="update_email" class="btn-primary" style="padding: 10px 20px; border: none; border-radius: var(--radius-sm); cursor: pointer; font-size: 13px; font-weight: 500;">Save Email</button>
                        </form>
                    </div>
                </div>

                <div class="section-card">
                    <div class="section-header">
                        <h2>Change Password</h2>
                    </div>
                    <form method="POST" style="padding: 24px;">
                        <?= csrf_field() ?>
                        <div class="input-group">
                            <label>Current Password</label>
                            <input type="password" name="current_password" placeholder="Enter current password" required>
                        </div>
                        <div class="input-group">
                            <label>New Password</label>
                            <input type="password" name="new_password" placeholder="At least 6 characters" required>
                        </div>
                        <div class="input-group">
                            <label>Confirm New Password</label>
                            <input type="password" name="confirm_password" placeholder="Repeat new password" required>
                        </div>
                        <button type="submit" name="change_password" class="btn-primary" style="padding: 10px 20px; border: none; border-radius: var(--radius-sm); cursor: pointer; font-size: 13px; font-weight: 500;">Change Password</button>
                    </form>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
