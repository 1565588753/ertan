<?php
require_once __DIR__ . '/db.php';
$db = Database::getInstance();

$gradeId = isset($_GET['g']) ? intval($_GET['g']) : 0;
if ($gradeId < 1 || $gradeId > 6) {
    header('Location: index.php');
    exit;
}

// 获取年级信息
$grade = $db->fetchOne("SELECT * FROM grades WHERE id = ?", [$gradeId]);
if (!$grade) {
    header('Location: index.php');
    exit;
}

$year = isset($_GET['year']) ? intval($_GET['year']) : CURRENT_YEAR;
$month = isset($_GET['month']) ? intval($_GET['month']) : CURRENT_MONTH;

// 获取年级设置
$setting = $db->fetchOne("SELECT * FROM grade_settings WHERE grade_id = ? AND year = ? AND month = ?", 
    [$gradeId, $year, $month]);

// 获取班级列表
$classes = $db->fetchAll("SELECT * FROM classes WHERE grade_id = ? ORDER BY id ASC", [$gradeId]);

// 获取已保存的出勤数据
$attendanceData = [];
if (!empty($classes)) {
    $classIds = array_column($classes, 'id');
    $placeholders = implode(',', array_fill(0, count($classIds), '?'));
    $records = $db->fetchAll(
        "SELECT * FROM attendance WHERE class_id IN ({$placeholders}) AND year = ? AND month = ? ORDER BY class_id, lesson_number",
        array_merge($classIds, [$year, $month])
    );
    foreach ($records as $r) {
        $attendanceData[$r['class_id']][$r['lesson_number']] = $r;
    }
}

// 获取已保存的校外教师数据
$teachers = $db->fetchAll(
    "SELECT * FROM teacher_lessons WHERE grade_id = ? AND year = ? AND month = ?",
    [$gradeId, $year, $month]
);

// 处理提交
$submitSuccess = false;
$submitError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db->getPdo()->beginTransaction();

        // 保存出勤数据
        if (isset($_POST['attendance']) && is_array($_POST['attendance'])) {
            foreach ($_POST['attendance'] as $classId => $lessons) {
                $classId = intval($classId);
                foreach ($lessons as $lessonNum => $studentCount) {
                    $lessonNum = intval($lessonNum);
                    $studentCount = intval($studentCount);
                    if ($studentCount < 0) $studentCount = 0;
                    $lessonHours = $studentCount; // 课时数 = 人数 × 1节

                    // UPSERT
                    $existing = $db->fetchOne(
                        "SELECT id FROM attendance WHERE class_id = ? AND lesson_number = ? AND year = ? AND month = ?",
                        [$classId, $lessonNum, $year, $month]
                    );
                    if ($existing) {
                        $db->execute(
                            "UPDATE attendance SET student_count = ?, lesson_hours = ? WHERE id = ?",
                            [$studentCount, $lessonHours, $existing['id']]
                        );
                    } else {
                        $db->execute(
                            "INSERT INTO attendance (class_id, lesson_number, student_count, lesson_hours, year, month) VALUES (?, ?, ?, ?, ?, ?)",
                            [$classId, $lessonNum, $studentCount, $lessonHours, $year, $month]
                        );
                    }
                }
            }
        }

        // 保存校外教师数据
        if (isset($_POST['teachers']) && is_array($_POST['teachers'])) {
            // 删除旧数据
            $db->execute("DELETE FROM teacher_lessons WHERE grade_id = ? AND year = ? AND month = ?", 
                [$gradeId, $year, $month]);
            
            foreach ($_POST['teachers'] as $t) {
                $tName = trim($t['name'] ?? '');
                $tCount = intval($t['count'] ?? 0);
                if ($tName !== '' && $tCount > 0) {
                    $db->execute(
                        "INSERT INTO teacher_lessons (grade_id, teacher_name, lesson_count, year, month) VALUES (?, ?, ?, ?, ?)",
                        [$gradeId, $tName, $tCount, $year, $month]
                    );
                }
            }
        }

        $db->getPdo()->commit();
        $submitSuccess = true;

        // 重新加载数据
        $classes = $db->fetchAll("SELECT * FROM classes WHERE grade_id = ? ORDER BY id ASC", [$gradeId]);
        $attendanceData = [];
        if (!empty($classes)) {
            $classIds = array_column($classes, 'id');
            $placeholders = implode(',', array_fill(0, count($classIds), '?'));
            $records = $db->fetchAll(
                "SELECT * FROM attendance WHERE class_id IN ({$placeholders}) AND year = ? AND month = ? ORDER BY class_id, lesson_number",
                array_merge($classIds, [$year, $month])
            );
            foreach ($records as $r) {
                $attendanceData[$r['class_id']][$r['lesson_number']] = $r;
            }
        }
        $teachers = $db->fetchAll(
            "SELECT * FROM teacher_lessons WHERE grade_id = ? AND year = ? AND month = ?",
            [$gradeId, $year, $month]
        );
    } catch (Exception $e) {
        $db->getPdo()->rollBack();
        $submitError = '保存失败：' . $e->getMessage();
    }
}

// 月份导航
$prevMonth = $month - 1;
$prevYear = $year;
if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
$nextMonth = $month + 1;
$nextYear = $year;
if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }

// 等级样式
$gradeColors = ['#ef4444','#f59e0b','#10b981','#3b82f6','#8b5cf6','#ec4899'];
$gradeColor = $gradeColors[$gradeId - 1] ?? '#3b82f6';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="format-detection" content="telephone=no">
    <title><?= htmlspecialchars($grade['name']) ?> - <?= APP_NAME ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .grade-hero {
            background: linear-gradient(135deg, <?= $gradeColor ?>, <?= $gradeColor ?>dd);
            color: #fff;
            padding: 24px 16px;
            text-align: center;
            position: relative;
        }
        .grade-hero .back-link {
            position: absolute;
            left: 16px;
            top: 24px;
            color: #fff;
            font-size: 14px;
            text-decoration: none;
            opacity: 0.8;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .grade-hero .back-link:hover { opacity: 1; }
        .grade-hero h1 { font-size: 24px; font-weight: 800; }
        .grade-hero p { font-size: 13px; opacity: 0.85; margin-top: 4px; }
        .grade-hero .price-info {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 12px;
            font-size: 13px;
        }
        .grade-hero .price-info span {
            background: rgba(255,255,255,0.2);
            padding: 3px 14px;
            border-radius: 12px;
        }
        .lesson-count-control {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 16px;
            background: var(--bg-card);
            border-radius: var(--radius);
            margin-bottom: 12px;
            box-shadow: var(--shadow-sm);
        }
        .lesson-count-control label {
            font-size: 14px;
            font-weight: 600;
            color: var(--text);
        }
        .lesson-count-control select {
            padding: 6px 30px 6px 12px;
            border: 2px solid var(--border);
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            color: var(--primary);
            background: #fff;
            outline: none;
            -webkit-appearance: none;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 8px center;
        }
        .save-bar {
            position: sticky;
            bottom: 0;
            left: 0;
            right: 0;
            background: #fff;
            padding: 12px 16px;
            box-shadow: 0 -4px 20px rgba(0,0,0,0.1);
            z-index: 50;
            display: flex;
            gap: 10px;
        }
        .save-bar .btn { flex: 1; }
        .toast-success { background: var(--success); }
        .toast-error { background: var(--danger); }
    </style>
</head>
<body>

<div class="grade-hero">
    <a href="index.php" class="back-link">← 返回</a>
    <h1><?= htmlspecialchars($grade['name']) ?></h1>
    <p><?= $year ?>年<?= $month ?>月 课后服务数据填写</p>
    <div class="price-info">
        <?php if ($setting): ?>
        <span>单价：<?= number_format($setting['unit_price'], 2) ?>元/节</span>
        <span>封顶：<?= number_format($setting['cap_price'], 2) ?>元/人</span>
        <span>天数：<?= $setting['teaching_days'] ?>天</span>
        <?php else: ?>
        <span>暂未设置月度参数</span>
        <?php endif; ?>
    </div>
</div>

<div class="container page-content">
    <!-- 月份切换 -->
    <div class="month-selector">
        <a href="?g=<?= $gradeId ?>&year=<?= $prevYear ?>&month=<?= $prevMonth ?>" class="month-nav">◀</a>
        <div class="month-display"><?= $year ?>年 <?= $month ?>月</div>
        <?php if ($nextYear <= CURRENT_YEAR && $nextMonth <= CURRENT_MONTH): ?>
        <a href="?g=<?= $gradeId ?>&year=<?= $nextYear ?>&month=<?= $nextMonth ?>" class="month-nav">▶</a>
        <?php else: ?>
        <span class="month-nav" style="opacity:0.3">▶</span>
        <?php endif; ?>
    </div>

    <?php if ($submitSuccess): ?>
    <div class="toast toast-success show" id="successToast" style="position:fixed;top:20px;right:20px;z-index:9999;padding:14px 20px;border-radius:12px;color:#fff;font-size:14px;box-shadow:0 10px 30px rgba(0,0,0,0.15);">
        ✅ 数据保存成功！
    </div>
    <script>
        setTimeout(() => {
            var t = document.getElementById('successToast');
            if (t) { t.style.transition = 'opacity 0.5s'; t.style.opacity = '0'; setTimeout(() => t.remove(), 500); }
        }, 2000);
    </script>
    <?php endif; ?>

    <?php if ($submitError): ?>
    <div class="toast toast-error show" style="position:fixed;top:20px;right:20px;z-index:9999;padding:14px 20px;border-radius:12px;color:#fff;font-size:14px;box-shadow:0 10px 30px rgba(0,0,0,0.15);">
        ❌ <?= htmlspecialchars($submitError) ?>
    </div>
    <?php endif; ?>

    <?php if (empty($classes)): ?>
    <div class="empty-state">
        <div class="empty-icon">📋</div>
        <p>该年级暂无班级</p>
        <p style="font-size:13px;margin-top:8px;">请联系管理员添加班级</p>
    </div>
    <?php else: ?>

    <form method="post" id="mainForm">
        <!-- 课节数控制 -->
        <div class="lesson-count-control">
            <label>📚 每班课节数：</label>
            <select id="lessonCountSelect" onchange="updateLessonRows()">
                <?php for ($i = 1; $i <= 20; $i++): ?>
                <option value="<?= $i ?>" <?= $i === 10 ? 'selected' : '' ?>><?= $i ?> 节课</option>
                <?php endfor; ?>
            </select>
        </div>

        <!-- 班级列表 -->
        <?php foreach ($classes as $class): ?>
        <div class="attendance-card">
            <div class="class-header">
                <span class="class-name"><?= htmlspecialchars($grade['name']) ?> <?= htmlspecialchars($class['name']) ?></span>
                <span class="badge badge-primary" id="total_<?= $class['id'] ?>">合计：0 课时</span>
            </div>
            <div class="class-body" id="lessons_<?= $class['id'] ?>">
                <?php
                // 最多显示20节课
                $maxLessons = 20;
                for ($l = 1; $l <= $maxLessons; $l++) {
                    $saved = isset($attendanceData[$class['id']][$l]) ? $attendanceData[$class['id']][$l] : null;
                    $count = $saved ? intval($saved['student_count']) : 0;
                    $hours = $saved ? floatval($saved['lesson_hours']) : 0;
                ?>
                <div class="lesson-row" data-lesson="<?= $l ?>" style="<?= $l > 10 ? 'display:none' : '' ?>">
                    <span class="lesson-label">第<?= $l ?>节</span>
                    <input type="number" class="lesson-input" 
                           name="attendance[<?= $class['id'] ?>][<?= $l ?>]" 
                           value="<?= $count ?>" min="0" max="999" 
                           placeholder="人数" 
                           oninput="calcLesson(<?= $class['id'] ?>, <?= $l ?>)" />
                    <span>人</span>
                    <span class="lesson-result" id="result_<?= $class['id'] ?>_<?= $l ?>"><?= $hours > 0 ? $hours : '' ?></span>
                    <span style="font-size:12px;color:var(--text-light)">课时</span>
                </div>
                <?php } ?>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- 校外教师课时 -->
        <div class="teacher-section">
            <h3>👩‍🏫 校外教师课时</h3>
            <p style="font-size:13px;color:var(--text-secondary);margin-bottom:12px;">填写本年级校外教师的姓名和本月上课节数</p>
            <div id="teacherList">
                <?php if (!empty($teachers)): ?>
                <?php foreach ($teachers as $i => $t): ?>
                <div class="teacher-row">
                    <input type="text" class="t-name form-control form-control-sm" 
                           name="teachers[<?= $i ?>][name]" value="<?= htmlspecialchars($t['teacher_name']) ?>" 
                           placeholder="教师姓名">
                    <input type="number" class="t-count form-control form-control-sm" 
                           name="teachers[<?= $i ?>][count]" value="<?= intval($t['lesson_count']) ?>" 
                           min="0" placeholder="节数">
                    <button type="button" class="btn btn-danger btn-xs" onclick="this.closest('.teacher-row').remove()">✕</button>
                </div>
                <?php endforeach; ?>
                <?php else: ?>
                <?php for ($i = 0; $i < 2; $i++): ?>
                <div class="teacher-row">
                    <input type="text" class="t-name form-control form-control-sm" 
                           name="teachers[<?= $i ?>][name]" value="" placeholder="教师姓名">
                    <input type="number" class="t-count form-control form-control-sm" 
                           name="teachers[<?= $i ?>][count]" value="" min="0" placeholder="节数">
                    <button type="button" class="btn btn-danger btn-xs" onclick="this.closest('.teacher-row').remove()">✕</button>
                </div>
                <?php endfor; ?>
                <?php endif; ?>
            </div>
            <button type="button" class="btn btn-outline btn-sm mt-8" onclick="addTeacherRow()">+ 添加教师</button>
        </div>

        <!-- 底部保存栏 -->
        <div class="save-bar">
            <button type="submit" class="btn btn-primary">💾 保存数据</button>
        </div>
    </form>
    <?php endif; ?>
</div>

<script>
    // 课时自动计算
    function calcLesson(classId, lessonNum) {
        var input = document.querySelector('input[name="attendance[' + classId + '][' + lessonNum + ']"]');
        var result = document.getElementById('result_' + classId + '_' + lessonNum);
        var count = parseInt(input.value) || 0;
        var hours = count; // 课时数 = 人数 × 1节
        result.textContent = hours > 0 ? hours : '';
        updateTotal(classId);
    }

    // 更新合计
    function updateTotal(classId) {
        var inputs = document.querySelectorAll('input[name^="attendance[' + classId + ']"]');
        var total = 0;
        inputs.forEach(function(inp) {
            total += parseInt(inp.value) || 0;
        });
        var badge = document.getElementById('total_' + classId);
        if (badge) badge.textContent = '合计：' + total + ' 课时';
    }

    // 显示/隐藏课节行
    function updateLessonRows() {
        var count = parseInt(document.getElementById('lessonCountSelect').value) || 10;
        document.querySelectorAll('.lesson-row').forEach(function(row) {
            var lesson = parseInt(row.dataset.lesson);
            row.style.display = lesson <= count ? 'flex' : 'none';
        });
        // 重新计算所有合计
        var classCards = document.querySelectorAll('.attendance-card');
        classCards.forEach(function(card) {
            var header = card.querySelector('.class-header');
            if (header) {
                var name = header.querySelector('.class-name');
                if (name) {
                    // Extract classId from the first input name
                    var firstInput = card.querySelector('input[name^="attendance["]');
                    if (firstInput) {
                        var match = firstInput.name.match(/attendance\[(\d+)\]/);
                        if (match) updateTotal(parseInt(match[1]));
                    }
                }
            }
        });
    }

    // 添加教师行
    var teacherIdx = <?= max(count($teachers), 2) ?>;
    function addTeacherRow() {
        var list = document.getElementById('teacherList');
        var div = document.createElement('div');
        div.className = 'teacher-row';
        div.innerHTML = 
            '<input type="text" class="t-name form-control form-control-sm" name="teachers[' + teacherIdx + '][name]" value="" placeholder="教师姓名">' +
            '<input type="number" class="t-count form-control form-control-sm" name="teachers[' + teacherIdx + '][count]" value="" min="0" placeholder="节数">' +
            '<button type="button" class="btn btn-danger btn-xs" onclick="this.closest(\'.teacher-row\').remove()">✕</button>';
        list.appendChild(div);
        teacherIdx++;
    }

    // 初始化合计
    document.addEventListener('DOMContentLoaded', function() {
        var classIds = [];
        document.querySelectorAll('input[name^="attendance["]').forEach(function(inp) {
            var match = inp.name.match(/attendance\[(\d+)\]/);
            if (match) {
                var cid = parseInt(match[1]);
                if (!classIds.includes(cid)) classIds.push(cid);
            }
        });
        classIds.forEach(function(cid) { updateTotal(cid); });
    });
</script>

</body>
</html>