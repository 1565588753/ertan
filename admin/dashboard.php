<?php
require_once __DIR__ . '/auth.php';
$db = Database::getInstance();

$year = isset($_GET['year']) ? intval($_GET['year']) : CURRENT_YEAR;
$month = isset($_GET['month']) ? intval($_GET['month']) : CURRENT_MONTH;

// 获取全校统一收费标准
$feeSetting = $db->fetchOne("SELECT * FROM fee_settings WHERE year = ? AND month = ?", [$year, $month]);
$unitPrice = floatval($feeSetting['unit_price'] ?? 0);
$capPrice = floatval($feeSetting['cap_price'] ?? 0);

// 统计概览
$totalStudents = 0;
$totalLessons = 0;
$totalFees = 0;
$gradeStats = [];

$grades = $db->fetchAll("SELECT * FROM grades ORDER BY sort_order");

foreach ($grades as $g) {
    $classes = $db->fetchAll("SELECT id FROM classes WHERE grade_id = ?", [$g['id']]);
    $classIds = array_column($classes, 'id');
    
    $students = 0;
    $lessons = 0;
    $fees = 0;
    
    if (!empty($classIds)) {
        $ids = implode(',', $classIds);
        $att = $db->fetchAll("SELECT SUM(student_count) as sc, SUM(lesson_hours) as lh FROM attendance WHERE class_id IN ({$ids}) AND year = ? AND month = ?", [$year, $month]);
        if ($att && $att[0]['sc']) {
            $students = intval($att[0]['sc']);
            $lessons = floatval($att[0]['lh']);
            if ($unitPrice > 0) {
                $classCounts = $db->fetchAll("SELECT class_id, SUM(student_count) as total FROM attendance WHERE class_id IN ({$ids}) AND year = ? AND month = ? GROUP BY class_id", [$year, $month]);
                $fees = 0;
                foreach ($classCounts as $cc) {
                    $count = intval($cc['total']);
                    $raw = $count * $unitPrice;
                    $fees += min($raw, $capPrice * $count);
                }
            }
        }
    }
    
    $totalStudents += $students;
    $totalLessons += $lessons;
    $totalFees += $fees;
    
    $gradeStats[] = [
        'name' => $g['name'],
        'students' => $students,
        'lessons' => $lessons,
        'fees' => $fees
    ];
}

adminHeader('管理概览');
?>

<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-value"><?= number_format($totalLessons, 1) ?></div>
        <div class="stat-label">总课时数</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= number_format($totalStudents) ?></div>
        <div class="stat-label">总参与人次</div>
    </div>
    <div class="stat-card">
        <div class="stat-value">¥<?= number_format($totalFees, 0) ?></div>
        <div class="stat-label">总费用估算</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= count($grades) ?></div>
        <div class="stat-label">年级数</div>
    </div>
</div>

<!-- 月份选择 -->
<div class="month-selector" style="margin-bottom:20px;">
    <?php
    $py = $year; $pm = $month - 1;
    if ($pm < 1) { $pm = 12; $py--; }
    $ny = $year; $nm = $month + 1;
    if ($nm > 12) { $nm = 1; $ny++; }
    ?>
    <a href="?year=<?= $py ?>&month=<?= $pm ?>" class="month-nav">◀</a>
    <span class="month-display"><?= $year ?>年 <?= $month ?>月</span>
    <a href="?year=<?= $ny ?>&month=<?= $nm ?>" class="month-nav">▶</a>
</div>

<!-- 各年级统计 -->
<div class="card">
    <div class="card-header">
        <h2>各年级统计</h2>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>年级</th>
                    <th>参与人次</th>
                    <th>课时数</th>
                    <th>费用估算</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($gradeStats as $gs): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($gs['name']) ?></strong></td>
                    <td><?= number_format($gs['students']) ?></td>
                    <td><?= number_format($gs['lessons'], 1) ?></td>
                    <td>¥<?= number_format($gs['fees'], 0) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="total-row">
                    <td><strong>合计</strong></td>
                    <td><?= number_format($totalStudents) ?></td>
                    <td><?= number_format($totalLessons, 1) ?></td>
                    <td>¥<?= number_format($totalFees, 0) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:16px;">
    <div class="card">
        <div class="card-header"><h3>快捷操作</h3></div>
        <div style="display:flex;flex-direction:column;gap:10px;">
            <a href="settings.php" class="btn btn-primary btn-sm">⚙️ 月度参数设置</a>
            <a href="classes.php" class="btn btn-accent btn-sm">🏫 班级管理</a>
            <a href="statistics.php" class="btn btn-outline btn-sm">📈 查看统计报表</a>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><h3>提示信息</h3></div>
        <div style="font-size:14px;color:var(--text-secondary);line-height:1.8;">
            <p>• 年级干事通过首页选择年级即可填写数据</p>
            <p>• 请先在"月度设置"中配置各年级参数</p>
            <p>• 在"班级管理"中添加各年级班级</p>
        </div>
    </div>
</div>

<?php adminFooter(); ?>