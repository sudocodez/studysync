-- StudySync Database Schema
-- Run this in phpMyAdmin SQL tab to create the database

CREATE DATABASE IF NOT EXISTS `studysync` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `studysync`;

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
    description TEXT,
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

-- Seed: demo user (password: 123)
INSERT INTO users (username, password, email) VALUES (
    'demo',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'demo@example.com'
);

INSERT INTO courses (user_id, course_name, course_code, color) VALUES
    (1, 'Computer Science', 'CS101', '#4CAF50'),
    (1, 'Mathematics', 'MATH201', '#2196F3'),
    (1, 'Database Systems', 'DB301', '#FF9800');

INSERT INTO tasks (user_id, course_id, title, type, due_date, estimated_hours, priority, status) VALUES
    (1, 1, 'Complete JavaScript Project', 'assignment', DATE_ADD(CURDATE(), INTERVAL 3 DAY), 5, 80, 'pending'),
    (1, 2, 'Calculus Final Exam', 'exam', DATE_ADD(CURDATE(), INTERVAL 7 DAY), 8, 100, 'pending'),
    (1, 1, 'Review Data Structures', 'study', DATE_ADD(CURDATE(), INTERVAL 1 DAY), 2, 60, 'pending'),
    (1, 3, 'Design Database Schema', 'assignment', DATE_ADD(CURDATE(), INTERVAL 2 DAY), 3, 75, 'pending');

INSERT INTO user_stats (user_id, total_study_hours, current_streak_days, tasks_completed) VALUES (1, 0, 0, 0);
