<?php
session_start();
require_once __DIR__ . '/app_config.php';

// db_config.php - SQLite version (no MySQL needed)

// SQLite database file
$db_file = __DIR__ . '/study_planner.sqlite';

try {
    $pdo = new PDO("sqlite:$db_file");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create tables automatically if they don't exist
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
    ");

    // Lightweight migrations for databases created before newer columns existed.
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
    
    // Insert demo user if not exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = 'demo'");
    $stmt->execute();
    if($stmt->fetchColumn() == 0) {
        $hashed_password = password_hash('123', PASSWORD_DEFAULT);
        $pdo->exec("INSERT INTO users (username, password, email) VALUES ('demo', '$hashed_password', 'demo@example.com')");
        
        // Insert sample courses for demo user
        $pdo->exec("
            INSERT INTO courses (user_id, course_name, course_code, color) VALUES 
            (1, 'Computer Science', 'CS101', '#4CAF50'),
            (1, 'Mathematics', 'MATH201', '#2196F3'),
            (1, 'Database Systems', 'DB301', '#FF9800');
        ");
        
        // Insert sample tasks
        $pdo->exec("
            INSERT INTO tasks (user_id, course_id, title, type, due_date, estimated_hours, priority, status) VALUES 
            (1, 1, 'Complete JavaScript Project', 'assignment', date('now', '+3 days'), 5, 80, 'pending'),
            (1, 2, 'Calculus Final Exam', 'exam', date('now', '+7 days'), 8, 100, 'pending'),
            (1, 1, 'Review Data Structures', 'study', date('now', '+1 day'), 2, 60, 'pending'),
            (1, 3, 'Design Database Schema', 'assignment', date('now', '+2 days'), 3, 75, 'pending');
        ");
        
        // Insert user stats
        $pdo->exec("INSERT INTO user_stats (user_id, total_study_hours, current_streak_days, tasks_completed) VALUES (1, 0, 0, 0)");
    }
    
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

if(!isset($_SESSION['user_id']) && basename($_SERVER['PHP_SELF']) != 'index.php' && basename($_SERVER['PHP_SELF']) != 'google_callback.php') {
    header('Location: index.php');
    exit();
}
?>
