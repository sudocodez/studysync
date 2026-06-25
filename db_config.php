<?php
session_start();
require_once __DIR__ . '/app_config.php';

// Database configuration - MySQL primary, SQLite fallback
$db_type = 'sqlite'; // will switch to 'mysql' if connection succeeds

try {
    // Try MySQL first
    $mysql_host = app_env('DB_HOST', 'localhost');
    $mysql_db   = app_env('DB_NAME', 'studysync');
    $mysql_user = app_env('DB_USER', 'root');
    $mysql_pass = app_env('DB_PASS', '');
    $mysql_port = app_env('DB_PORT', '3306');

    // Create database if it doesn't exist
    $temp_pdo = new PDO("mysql:host=$mysql_host;port=$mysql_port;charset=utf8mb4", $mysql_user, $mysql_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $temp_pdo->exec("CREATE DATABASE IF NOT EXISTS `$mysql_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $temp_pdo = null;

    $pdo = new PDO("mysql:host=$mysql_host;port=$mysql_port;dbname=$mysql_db;charset=utf8mb4", $mysql_user, $mysql_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $db_type = 'mysql';

    // Create MySQL tables
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            email VARCHAR(255),
            google_calendar_token TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS courses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            course_name VARCHAR(255) NOT NULL,
            course_code VARCHAR(50),
            color VARCHAR(7) DEFAULT '#4CAF50',
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS tasks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            course_id INT,
            title VARCHAR(255) NOT NULL,
            type ENUM('assignment','exam','study','quiz','project') DEFAULT 'study',
            due_date DATE NOT NULL,
            due_time TIME,
            estimated_hours DECIMAL(5,1) NOT NULL,
            priority INT DEFAULT 40,
            status ENUM('pending','completed','missed','in_progress') DEFAULT 'pending',
            progress_status VARCHAR(20) DEFAULT 'not_started',
            progress_percentage INT DEFAULT 0,
            alerted TINYINT(1) DEFAULT 0,
            completed_at DATETIME,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS weekly_timetable (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            course_id INT,
            day_of_week TINYINT CHECK(day_of_week BETWEEN 0 AND 6),
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            location VARCHAR(255),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS available_time (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            day_of_week TINYINT CHECK(day_of_week BETWEEN 0 AND 6),
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            is_recurring TINYINT(1) DEFAULT 1,
            specific_date DATE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS study_plan (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            task_id INT,
            task_title VARCHAR(255) NOT NULL,
            plan_date DATE NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            status ENUM('pending','completed','missed','postponed') DEFAULT 'pending',
            alerted TINYINT(1) DEFAULT 0,
            reschedule_alerted TINYINT(1) DEFAULT 0,
            google_event_id VARCHAR(255),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS study_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            task_id INT,
            plan_id INT,
            start_time DATETIME NOT NULL,
            end_time DATETIME,
            duration_minutes INT,
            notes TEXT,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL,
            FOREIGN KEY (plan_id) REFERENCES study_plan(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS chat_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            user_message TEXT NOT NULL,
            bot_response TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS user_stats (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNIQUE NOT NULL,
            total_study_hours DECIMAL(8,1) DEFAULT 0,
            current_streak_days INT DEFAULT 0,
            longest_streak INT DEFAULT 0,
            last_study_date DATE,
            tasks_completed INT DEFAULT 0,
            on_time_percentage DECIMAL(5,1) DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            type VARCHAR(50) NOT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            link VARCHAR(500),
            is_read TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS productivity_patterns (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            day_of_week TINYINT NOT NULL,
            time_bucket VARCHAR(10) NOT NULL,
            sessions_completed INT DEFAULT 0,
            sessions_on_time INT DEFAULT 0,
            score DECIMAL(5,2) DEFAULT 0.50,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY (user_id, day_of_week, time_bucket)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS user_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            setting_key VARCHAR(50) NOT NULL,
            setting_value TEXT,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY (user_id, setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // MySQL column migrations (check and add missing columns)
    try {
        $pdo->exec("ALTER TABLE tasks ADD COLUMN progress_status VARCHAR(20) DEFAULT 'not_started'");
    } catch(PDOException $e) {
        // Column already exists - ignore
    }
    try {
        $pdo->exec("ALTER TABLE tasks ADD COLUMN progress_percentage INT DEFAULT 0");
    } catch(PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE study_plan ADD COLUMN reschedule_alerted TINYINT(1) DEFAULT 0");
    } catch(PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE study_plan ADD COLUMN google_event_id VARCHAR(255)");
    } catch(PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE tasks ADD COLUMN description TEXT");
    } catch(PDOException $e) {}
    // Insert demo user if not exists
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE username = 'demo'");
    if($stmt->fetchColumn() == 0) {
        $hashed_password = password_hash('123', PASSWORD_DEFAULT);
        $pdo->exec("INSERT INTO users (username, password, email) VALUES ('demo', '$hashed_password', 'demo@example.com')");

        $pdo->exec("
            INSERT INTO courses (user_id, course_name, course_code, color) VALUES 
            (1, 'Computer Science', 'CS101', '#4CAF50'),
            (1, 'Mathematics', 'MATH201', '#2196F3'),
            (1, 'Database Systems', 'DB301', '#FF9800');
        ");

        $pdo->exec("
            INSERT INTO tasks (user_id, course_id, title, type, due_date, estimated_hours, priority, status) VALUES 
            (1, 1, 'Complete JavaScript Project', 'assignment', DATE_ADD(CURDATE(), INTERVAL 3 DAY), 5, 80, 'pending'),
            (1, 2, 'Calculus Final Exam', 'exam', DATE_ADD(CURDATE(), INTERVAL 7 DAY), 8, 100, 'pending'),
            (1, 1, 'Review Data Structures', 'study', DATE_ADD(CURDATE(), INTERVAL 1 DAY), 2, 60, 'pending'),
            (1, 3, 'Design Database Schema', 'assignment', DATE_ADD(CURDATE(), INTERVAL 2 DAY), 3, 75, 'pending');
        ");

        $pdo->exec("INSERT INTO user_stats (user_id, total_study_hours, current_streak_days, tasks_completed) VALUES (1, 0, 0, 0)");
    }

} catch(PDOException $e) {
    // MySQL failed — fall back to SQLite
    $db_file = __DIR__ . '/study_planner.sqlite';

    try {
        $pdo = new PDO("sqlite:$db_file");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db_type = 'sqlite';

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE NOT NULL,
                password TEXT NOT NULL,
                email TEXT,
                google_calendar_token TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS courses (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                course_name TEXT NOT NULL,
                course_code TEXT,
                color TEXT DEFAULT '#4CAF50',
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS tasks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                course_id INTEGER,
                title TEXT NOT NULL,
                type TEXT CHECK(type IN ('assignment','exam','study','quiz','project')) DEFAULT 'study',
                due_date TEXT NOT NULL,
                due_time TEXT,
                estimated_hours REAL NOT NULL,
                priority INTEGER DEFAULT 40,
                status TEXT CHECK(status IN ('pending','completed','missed','in_progress')) DEFAULT 'pending',
                progress_status TEXT DEFAULT 'not_started',
                progress_percentage INTEGER DEFAULT 0,
                alerted INTEGER DEFAULT 0,
                completed_at TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL
            );

            CREATE TABLE IF NOT EXISTS weekly_timetable (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                course_id INTEGER,
                day_of_week INTEGER CHECK(day_of_week BETWEEN 0 AND 6),
                start_time TEXT NOT NULL,
                end_time TEXT NOT NULL,
                location TEXT,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL
            );

            CREATE TABLE IF NOT EXISTS available_time (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                day_of_week INTEGER CHECK(day_of_week BETWEEN 0 AND 6),
                start_time TEXT NOT NULL,
                end_time TEXT NOT NULL,
                is_recurring INTEGER DEFAULT 1,
                specific_date TEXT,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS study_plan (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                task_id INTEGER,
                task_title TEXT NOT NULL,
                plan_date TEXT NOT NULL,
                start_time TEXT NOT NULL,
                end_time TEXT NOT NULL,
                status TEXT CHECK(status IN ('pending','completed','missed','postponed')) DEFAULT 'pending',
                alerted INTEGER DEFAULT 0,
                reschedule_alerted INTEGER DEFAULT 0,
                google_event_id TEXT,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL
            );

            CREATE TABLE IF NOT EXISTS study_sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                task_id INTEGER,
                plan_id INTEGER,
                start_time TEXT NOT NULL,
                end_time TEXT,
                duration_minutes INTEGER,
                notes TEXT,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL,
                FOREIGN KEY (plan_id) REFERENCES study_plan(id) ON DELETE SET NULL
            );

            CREATE TABLE IF NOT EXISTS chat_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                user_message TEXT NOT NULL,
                bot_response TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS user_stats (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER UNIQUE NOT NULL,
                total_study_hours REAL DEFAULT 0,
                current_streak_days INTEGER DEFAULT 0,
                longest_streak INTEGER DEFAULT 0,
                last_study_date TEXT,
                tasks_completed INTEGER DEFAULT 0,
                on_time_percentage REAL DEFAULT 0,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                type TEXT NOT NULL,
                title TEXT NOT NULL,
                message TEXT NOT NULL,
                link TEXT,
                is_read INTEGER DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS productivity_patterns (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                day_of_week INTEGER NOT NULL,
                time_bucket TEXT NOT NULL,
                sessions_completed INTEGER DEFAULT 0,
                sessions_on_time INTEGER DEFAULT 0,
                score REAL DEFAULT 0.50,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                UNIQUE (user_id, day_of_week, time_bucket)
            );

            CREATE TABLE IF NOT EXISTS user_settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                setting_key TEXT NOT NULL,
                setting_value TEXT,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                UNIQUE (user_id, setting_key)
            );
        ");

        // SQLite column migrations
        $task_columns = $pdo->query("PRAGMA table_info(tasks)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if(!in_array('progress_status', $task_columns)) {
            $pdo->exec("ALTER TABLE tasks ADD COLUMN progress_status TEXT DEFAULT 'not_started'");
        }
        if(!in_array('progress_percentage', $task_columns)) {
            $pdo->exec("ALTER TABLE tasks ADD COLUMN progress_percentage INTEGER DEFAULT 0");
        }

        $plan_columns = $pdo->query("PRAGMA table_info(study_plan)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if(!in_array('reschedule_alerted', $plan_columns)) {
            $pdo->exec("ALTER TABLE study_plan ADD COLUMN reschedule_alerted INTEGER DEFAULT 0");
        }
        if(!in_array('google_event_id', $plan_columns)) {
            $pdo->exec("ALTER TABLE study_plan ADD COLUMN google_event_id TEXT");
        }

        if(!in_array('description', $task_columns)) {
            $pdo->exec("ALTER TABLE tasks ADD COLUMN description TEXT");
        }

        // Demo user
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = 'demo'");
        $stmt->execute();
        if($stmt->fetchColumn() == 0) {
            $hashed_password = password_hash('123', PASSWORD_DEFAULT);
            $pdo->exec("INSERT INTO users (username, password, email) VALUES ('demo', '$hashed_password', 'demo@example.com')");

            $pdo->exec("
                INSERT INTO courses (user_id, course_name, course_code, color) VALUES 
                (1, 'Computer Science', 'CS101', '#4CAF50'),
                (1, 'Mathematics', 'MATH201', '#2196F3'),
                (1, 'Database Systems', 'DB301', '#FF9800');
            ");

            $pdo->exec("
                INSERT INTO tasks (user_id, course_id, title, type, due_date, estimated_hours, priority, status) VALUES 
                (1, 1, 'Complete JavaScript Project', 'assignment', date('now', '+3 days'), 5, 80, 'pending'),
                (1, 2, 'Calculus Final Exam', 'exam', date('now', '+7 days'), 8, 100, 'pending'),
                (1, 1, 'Review Data Structures', 'study', date('now', '+1 day'), 2, 60, 'pending'),
                (1, 3, 'Design Database Schema', 'assignment', date('now', '+2 days'), 3, 75, 'pending');
            ");

            $pdo->exec("INSERT INTO user_stats (user_id, total_study_hours, current_streak_days, tasks_completed) VALUES (1, 0, 0, 0)");
        }

    } catch(PDOException $e2) {
        die("Connection failed (MySQL and SQLite): " . $e2->getMessage());
    }
}

if(!isset($_SESSION['user_id']) && basename($_SERVER['PHP_SELF']) != 'index.php' && basename($_SERVER['PHP_SELF']) != 'google_callback.php') {
    header('Location: index.php');
    exit();
}

// Expose db_type for any script that needs it
$GLOBALS['db_type'] = $db_type;

// CSRF protection
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function csrf_token() {
    return $_SESSION['csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . $_SESSION['csrf_token'] . '">';
}

function verify_csrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'], $token)) {
            http_response_code(403);
            if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            } else {
                die('Invalid CSRF token');
            }
            exit();
        }
    }
    return true;
}
?>
