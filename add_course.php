<?php
require_once 'db_config.php';
verify_csrf();

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_course'])) {
    $course_name = trim($_POST['course_name']);
    $course_code = trim($_POST['course_code']);
    $color = $_POST['color'] ?? '#4CAF50';
    if (!empty($course_name)) {
        $stmt = $pdo->prepare("INSERT INTO courses (user_id, course_name, course_code, color) VALUES (?, ?, ?, ?)");
        $stmt->execute([$_SESSION['user_id'], $course_name, $course_code, $color]);
        $success = "Course added successfully!";
    } else {
        $error = "Course name is required";
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_course'])) {
    $id = (int)$_POST['course_id'];
    $course_name = trim($_POST['course_name']);
    $course_code = trim($_POST['course_code']);
    $color = $_POST['color'] ?? '#4CAF50';
    if (!empty($course_name)) {
        $stmt = $pdo->prepare("UPDATE courses SET course_name = ?, course_code = ?, color = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$course_name, $course_code, $color, $id, $_SESSION['user_id']]);
        $success = "Course updated successfully!";
    } else {
        $error = "Course name is required";
    }
}

if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("DELETE FROM courses WHERE id = ? AND user_id = ?");
    $stmt->execute([(int)$_GET['delete'], $_SESSION['user_id']]);
    $success = "Course deleted successfully!";
}

$stmt = $pdo->prepare("SELECT * FROM courses WHERE user_id = ? ORDER BY course_name");
$stmt->execute([$_SESSION['user_id']]);
$courses = $stmt->fetchAll();

$course_stats = [];
foreach ($courses as $course) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as total_tasks, SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_tasks, SUM(estimated_hours) as total_hours FROM tasks WHERE user_id = ? AND course_id = ?");
    $stmt->execute([$_SESSION['user_id'], $course['id']]);
    $course_stats[$course['id']] = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Courses | StudySync</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="liquid-glass.css">
    <style>
        .page-header { margin-bottom: 24px; }
        .page-header h1 { font-size: 24px; font-weight: 600; }
        .page-header p { font-size: 13px; color: var(--text-muted); margin-top: 4px; }
        .add-form { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 24px; margin-bottom: 24px; }
        .add-form h2 { font-size: 16px; font-weight: 600; margin-bottom: 16px; }
        .form-group { margin-bottom: 14px; }
        .form-group label { display: block; font-size: 13px; font-weight: 500; color: var(--text-secondary); margin-bottom: 6px; }
        .form-group input, .form-group select { width: 100%; padding: 10px 12px; background: var(--bg-primary); border: 1px solid var(--border); border-radius: var(--radius-sm); color: var(--text-primary); font-size: 14px; }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: var(--accent); }
        .color-grid { display: flex; gap: 8px; flex-wrap: wrap; }
        .color-opt { width: 36px; height: 36px; border-radius: 50%; cursor: pointer; border: 3px solid transparent; transition: border-color 0.2s; }
        .color-opt.selected { border-color: var(--accent); }
        .courses-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px; }
        .course-card { background: var(--bg-card); border-radius: var(--radius-lg); border: 1px solid var(--border); overflow: hidden; transition: transform 0.2s, box-shadow 0.2s; }
        .course-card:hover { box-shadow: var(--shadow-md); }
        .course-header { padding: 20px; color: white; }
        .course-name { font-size: 18px; font-weight: 600; margin-bottom: 4px; }
        .course-code { font-size: 13px; opacity: 0.85; }
        .course-body { padding: 20px; }
        .course-stats { display: flex; justify-content: space-around; margin-bottom: 16px; padding-bottom: 16px; border-bottom: 1px solid var(--border-light); }
        .stat { text-align: center; }
        .stat-value { font-size: 22px; font-weight: 700; color: var(--text-primary); }
        .stat-label { font-size: 11px; color: var(--text-muted); margin-top: 2px; }
        .progress-bar { background: var(--bg-primary); border-radius: 10px; height: 8px; margin: 12px 0; overflow: hidden; }
        .progress-fill { height: 100%; border-radius: 10px; transition: width 0.3s; }
        .progress-text { text-align: center; font-size: 11px; color: var(--text-muted); }
        .course-actions { display: flex; gap: 10px; margin-top: 16px; }
        .course-actions a, .course-actions button { flex: 1; padding: 8px; border: none; border-radius: var(--radius-sm); cursor: pointer; font-size: 12px; font-weight: 500; text-align: center; text-decoration: none; }
        .btn-view { background: var(--accent); color: white; }
        .btn-del { background: rgba(239,68,68,0.1); color: var(--danger); }
        .btn-del:hover { background: var(--danger); color: white; }
        .btn-edit { background: var(--accent-soft); color: var(--accent); }
        .btn-edit:hover { background: var(--accent); color: white; }
        .alert-success { padding: 12px; background: rgba(16,185,129,0.1); border: 1px solid var(--success); border-radius: var(--radius-sm); color: var(--success); font-size: 13px; margin-bottom: 16px; }
        .alert-error { padding: 12px; background: rgba(239,68,68,0.1); border: 1px solid var(--danger); border-radius: var(--radius-sm); color: var(--danger); font-size: 13px; margin-bottom: 16px; }
        .empty-state { text-align: center; padding: 48px; color: var(--text-muted); }
        .empty-icon { font-size: 48px; margin-bottom: 16px; }
    </style>
</head>
<body>
    <div class="app-container">
        <?php require_once 'includes/sidebar.php'; ?>
        <main class="main-content">
            <div class="page-header">
                <h1>Courses</h1>
                <p>Manage your courses to better organize tasks and schedules</p>
            </div>

            <?php if (isset($success)): ?>
                <div class="alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <?php if (isset($error)): ?>
                <div class="alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="add-form">
                <h2>Add New Course</h2>
                <form method="POST" style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <?= csrf_field() ?><div class="form-group">
                        <label>Course Name *</label>
                        <input type="text" name="course_name" placeholder="e.g. Computer Science" required>
                    </div>
                    <div class="form-group">
                        <label>Course Code (Optional)</label>
                        <input type="text" name="course_code" placeholder="e.g. CS101">
                    </div>
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Color</label>
                        <div class="color-grid" id="colorGrid">
                            <?php
                            $color_opts = ['#4CAF50', '#2196F3', '#FF9800', '#9C27B0', '#F44336', '#00BCD4', '#FF5722', '#607D8B', '#E91E63', '#3F51B5'];
                            foreach ($color_opts as $c):
                            ?>
                                <div class="color-opt <?= $c === '#4CAF50' ? 'selected' : '' ?>" data-color="<?= $c ?>" style="background: <?= $c ?>" onclick="selectColor(this)"></div>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="color" id="colorInput" value="#4CAF50">
                    </div>
                    <button type="submit" name="add_course" class="btn-primary" style="padding: 10px; border: none; border-radius: var(--radius-sm); cursor: pointer; font-size: 14px;">Add Course</button>
                </form>
            </div>

            <?php if (count($courses) > 0): ?>
                <div class="courses-grid">
                    <?php foreach ($courses as $course):
                        $stats = $course_stats[$course['id']] ?? ['total_tasks' => 0, 'completed_tasks' => 0, 'total_hours' => 0];
                        $pct = $stats['total_tasks'] > 0 ? round(($stats['completed_tasks'] / $stats['total_tasks']) * 100) : 0;
                    ?>
                        <div class="course-card">
                            <div class="course-header" style="background: <?= htmlspecialchars($course['color']) ?>">
                                <div class="course-name"><?= htmlspecialchars($course['course_name']) ?></div>
                                <?php if ($course['course_code']): ?>
                                    <div class="course-code"><?= htmlspecialchars($course['course_code']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="course-body">
                                <div class="course-stats">
                                    <div class="stat"><div class="stat-value"><?= $stats['total_tasks'] ?></div><div class="stat-label">Tasks</div></div>
                                    <div class="stat"><div class="stat-value"><?= $stats['completed_tasks'] ?></div><div class="stat-label">Done</div></div>
                                    <div class="stat"><div class="stat-value"><?= round($stats['total_hours']) ?></div><div class="stat-label">Hours</div></div>
                                </div>
                                <?php if ($stats['total_tasks'] > 0): ?>
                                    <div class="progress-bar"><div class="progress-fill" style="width: <?= $pct ?>%; background: <?= htmlspecialchars($course['color']) ?>"></div></div>
                                    <div class="progress-text"><?= $pct ?>% complete</div>
                                <?php endif; ?>
                                <div class="course-actions">
                                    <a href="dashboard.php?course_id=<?= $course['id'] ?>" class="btn-view">View Tasks</a>
                                    <a href="#" class="btn-edit" onclick="openEditModal(<?= $course['id'] ?>, '<?= htmlspecialchars(addslashes($course['course_name'])) ?>', '<?= htmlspecialchars(addslashes($course['course_code'] ?? '')) ?>', '<?= htmlspecialchars($course['color']) ?>')">Edit</a>
                                    <a href="?delete=<?= $course['id'] ?>" class="btn-del" onclick="return confirm('Delete this course? This cannot be undone.')">Delete</a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-icon">📚</div>
                    <div class="empty-text">No courses yet. Add your first course above.</div>
                </div>
            <?php endif; ?>
        </main>
    </div>
        </main>
    </div>

    <!-- Edit Course Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content" style="max-width: 480px;">
            <div class="modal-header">
                <h3>Edit Course</h3>
            </div>
            <form method="POST" style="padding: 24px;">
                <?= csrf_field() ?>
                <input type="hidden" name="course_id" id="editCourseId">
                <div class="form-group">
                    <label>Course Name *</label>
                    <input type="text" name="course_name" id="editCourseName" required>
                </div>
                <div class="form-group">
                    <label>Course Code (Optional)</label>
                    <input type="text" name="course_code" id="editCourseCode">
                </div>
                <div class="form-group">
                    <label>Color</label>
                    <div class="color-grid" id="editColorGrid"></div>
                    <input type="hidden" name="color" id="editColorInput" value="#4CAF50">
                </div>
                <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                    <button type="button" class="btn-primary" style="padding: 10px 20px; border: none; border-radius: var(--radius-sm); cursor: pointer; font-size: 13px; background: var(--bg-primary); color: var(--text-secondary);" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" name="edit_course" class="btn-primary" style="padding: 10px 20px; border: none; border-radius: var(--radius-sm); cursor: pointer; font-size: 13px;">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openTaskModal() { alert('Use the Dashboard to add tasks.'); }
        function selectColor(el) {
            document.querySelectorAll('.color-opt').forEach(c => c.classList.remove('selected'));
            el.classList.add('selected');
            document.getElementById('colorInput').value = el.dataset.color;
        }
        function openEditModal(id, name, code, color) {
            document.getElementById('editCourseId').value = id;
            document.getElementById('editCourseName').value = name;
            document.getElementById('editCourseCode').value = code;
            renderEditColors(color);
            document.getElementById('editModal').style.display = 'flex';
        }
        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }
        function renderEditColors(selected) {
            const grid = document.getElementById('editColorGrid');
            const colors = ['#4CAF50', '#2196F3', '#FF9800', '#9C27B0', '#F44336', '#00BCD4', '#FF5722', '#607D8B', '#E91E63', '#3F51B5'];
            grid.innerHTML = '';
            colors.forEach(c => {
                const el = document.createElement('div');
                el.className = 'color-opt' + (c === selected ? ' selected' : '');
                el.style.background = c;
                el.dataset.color = c;
                el.onclick = function() {
                    document.querySelectorAll('#editColorGrid .color-opt').forEach(x => x.classList.remove('selected'));
                    el.classList.add('selected');
                    document.getElementById('editColorInput').value = c;
                };
                grid.appendChild(el);
            });
            document.getElementById('editColorInput').value = selected;
        }
    </script>
</body>
</html>