<?php
require_once __DIR__ . '/../db_config.php';
verify_csrf();

header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;

if (!$user_id) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

switch ($action) {

    // Fetch notifications
    case 'list':
        $type_filter = $_GET['type'] ?? '';
        $limit = min((int)($_GET['limit'] ?? 50), 100);
        $offset = max((int)($_GET['offset'] ?? 0), 0);

        $sql = "SELECT * FROM notifications WHERE user_id = ?";
        $params = [$user_id];

        if ($type_filter) {
            $sql .= " AND type = ?";
            $params[] = $type_filter;
        }

        $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $notifications = $stmt->fetchAll();

        // Count unread
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$user_id]);
        $unread_count = (int)$stmt->fetchColumn();

        echo json_encode([
            'success' => true,
            'notifications' => $notifications,
            'unread_count' => $unread_count,
            'count' => count($notifications)
        ]);
        break;

    // Mark single as read
    case 'read':
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $user_id]);
        }
        echo json_encode(['success' => true]);
        break;

    // Mark all as read
    case 'read_all':
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$user_id]);
        echo json_encode(['success' => true]);
        break;

    // Get unread count only
    case 'count':
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$user_id]);
        echo json_encode([
            'success' => true,
            'unread_count' => (int)$stmt->fetchColumn()
        ]);
        break;

    // Insert notification (used internally by get_alerts.php)
    case 'insert':
        $type = $_POST['type'] ?? 'info';
        $title = $_POST['title'] ?? '';
        $message = $_POST['message'] ?? '';
        $link = $_POST['link'] ?? '';

        if (!$title || !$message) {
            echo json_encode(['success' => false, 'error' => 'title and message required']);
            break;
        }

        // Avoid duplicates for same type+title within last hour
        $dup_sql = $GLOBALS['db_type'] === 'mysql'
            ? "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = ? AND title = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
            : "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = ? AND title = ? AND created_at > datetime('now', '-1 hour')";
        $stmt = $pdo->prepare($dup_sql);
        $stmt->execute([$user_id, $type, $title]);
        if ($stmt->fetchColumn() > 0) {
            echo json_encode(['success' => true, 'duplicate' => true]);
            break;
        }

        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $type, $title, $message, $link]);

        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
}
