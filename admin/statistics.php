<?php
require_once __DIR__ . '/auth.php';
$db = Database::getInstance();

$year = isset($_GET['year']) ? intval($_GET['year']) : CURRENT_YEAR;
$month = isset($_GET['month']) ? intval($_GET['month']) : CURRENT_MONTH;
$gradeFilter = isset($_GET['grade_id']) ? intval($_GET['grade_id']) : 0;

$grades = $db->fetchAll("SELECT * FROM grades ORDER BY sort_order");

// 构建查询条件
$whereGrade = $gradeFilter > 0 ? "AND g.id = {$gradeFilter}" : '';

// 各年级详细统计
$sql = "SELECT g.id as grade_id, g.name as grade_name, g.sort_order,
        COALESCE(gs.unit_price, 0) as unit_price,
        COALESCE(gs.cap_price, 0) as cap_price,
        COALESCE(gs.teaching_days, 0) as teaching_days
        FROM grades g
        LEFT JOIN grade_settings gs ON gs.grade_id = g.id AND gs.year = ? AND gs.month = ?
        WHERE 1=1 {$whereGrade}
        ORDER BY g.sort_order";
$gradeStats = $db->fetchAll($sql, [$year, $month]);

// 获取每个年级的详细数据
$detailData = [];
foreach ($gradeStats as &$gs) {
    $gid = $gs['grade_id'];
    
    // 班级数据
    $classes = $db->fetchAll("SELECT * FROM classes WHERE grade_id = ? ORDER BY id", [$gid]);
    $classDetails = [];
    $totalStudents = 0;
    $totalLessons = 0;
    $totalFee = 0;
    
    foreach ($classes as $c) {
        $atts = $db->fetchAll(
            "SELECT SUM(student_count) as sc, SUM(lesson_hours) as lh FROM attendance WHERE class_id = ? AND year = ? AND month = ?",
            [$c['id'], $year, $month]
        );
        $sc = intval($atts[0]['sc'] ?? 0);
        $lh = floatval($atts[0]['lh'] ?? 0);
        
        $fee = 0;
        if ($sc > 0 && $gs['unit_price'] > 0) {
            $rawFee = $sc * floatval($gs['unit_price']);
            $capPerPerson = floatval($gs['cap_price']);
            if ($capPerPerson > 0) {
                $fee = min($rawFee, $sc * $capPerPerson);
            } else {
                $fee = $rawFee;
            }
        }
        
        $totalStudents += $sc;
        $totalLessons += $lh;
        $totalFee += $fee;
        
        $classDetails[] = [
            'name' => $c['name'],
            'students' => $sc,
            'lessons' => $lh,
            'fee' => $fee
        ];
    }
    
    // 教师数据
    $teachers = $db->fetchAll(
        "SELECT * FROM teacher_lessons WHERE grade_id = ? AND year = ? AND month = ?",
        [$gid, $year, $month]
    );
    $totalTeacherLessons = 0;
    foreach ($teachers as $t) {
        $totalTeacherLessons += intval($t['lesson_count']);
    }
    
    $gs['classes'] = $classDetails;
    $gs['total_students'] = $totalStudents;
    $gs['total_lessons'] = $totalLessons;
    $gs['total_fee'] = $totalFee;
    $gs['teachers'] = $teachers;
    $gs['total_teacher_lessons'] = $totalTeacherLessons;
}
unset($gs);

adminHeader('统计报表');
?>

<div class="card">
    <div class="card-header">
        <h2>统计报表</h2>
    </div>

    <!-- 筛选栏 -->
    <form method="get" style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:16px;align-items:end;">
        <div class="form-group" style="margin-bottom:0;min-width:120px;">
            <label style="font-size:13px;">年份</label>
            <select name="year" class="form-control form-control-sm">
                <?php for ($y = CURRENT_YEAR - 1; $y <= CURRENT_YEAR; $y++): ?>
                <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?>年</option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="form-group" style="margin-bottom:0;min-width:100px;">
            <label style="font-size:13px;">月份</label>
            <select name="month" class="form-control form-control-sm">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= $m === $month ? 'selected' : '' ?>><?= $m ?>月</option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="form-group" style="margin-bottom:0;min-width:120px;">
            <label style="font-size:13px;">年级</label>
            <select name="grade_id" class="form-control form-control-sm">
                <option value="0">全部年级</option>
                <?php foreach ($grades as $g): ?>
                <option value="<?= $g['id'] ?>" <?= $g['id'] === $gradeFilter ? 'selected' : '' ?>><?= htmlspecialchars($g['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">查询</button>
    </form>
</div>

<?php foreach ($gradeStats as $gs): ?>
<div class="card">
    <div class="card-header">
        <h3><?= htmlspecialchars($gs['grade_name']) ?></h3>
        <div style="font-size:13px;color:var(--text-secondary);">
            单价：¥<?= number_format($gs['unit_price'], 2) ?> | 
            封顶：¥<?= number_format($gs['cap_price'], 2) ?> | 
            天数：<?= $gs['teaching_days'] ?>天
        </div>
    </div>

    <!-- 概览数据 -->
    <div class="stat-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:16px;">
        <div class="stat-card" style="padding:12px;">
            <div class="stat-value" style="font-size:22px;"><?= number_format($gs['total_students']) ?></div>
            <div class="stat-label">总人次</div>
        </div>
        <div class="stat-card" style="padding:12px;">
            <div class="stat-value" style="font-size:22px;"><?= number_format($gs['total_lessons'], 1) ?></div>
            <div class="stat-label">总课时</div>
        </div>
        <div class="stat-card" style="padding:12px;">
            <div class="stat-value" style="font-size:22px;">¥<?= number_format($gs['total_fee'], 0) ?></div>
            <div class="stat-label">费用</div>
        </div>
        <div class="stat-card" style="padding:12px;">
            <div class="stat-value" style="font-size:22px;"><?= number_format($gs['total_teacher_lessons']) ?></div>
            <div class="stat-label">教师课时</div>
        </div>
    </div>

    <!-- 班级明细 -->
    <?php if (!empty($gs['classes'])): ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>班级</th>
                    <th>参与人次</th>
                    <th>总课时数</th>
                    <th>费用估算</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($gs['classes'] as $c): ?>
                <tr>
                    <td><?= htmlspecialchars($gs['grade_name']) ?> <?= htmlspecialchars($c['name']) ?></td>
                    <td><?= number_format($c['students']) ?></td>
                    <td><?= number_format($c['lessons'], 1) ?></td>
                    <td>¥<?= number_format($c['fee'], 0) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="total-row">
                    <td><strong>合计</strong></td>
                    <td><?= number_format($gs['total_students']) ?></td>
                    <td><?= number_format($gs['total_lessons'], 1) ?></td>
                    <td>¥<?= number_format($gs['total_fee'], 0) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state" style="padding:16px;">
        <p style="font-size:14px;">暂无上课数据</p>
    </div>
    <?php endif; ?>

    <!-- 校外教师 -->
    <div style="margin-top:12px;padding-top:12px;border-top:1px dashed var(--border);">
        <div style="font-size:14px;font-weight:600;color:var(--text);margin-bottom:8px;">👩‍🏫 校外教师课时</div>
        <?php if (!empty($gs['teachers'])): ?>
        <div style="display:flex;flex-wrap:wrap;gap:8px;">
            <?php foreach ($gs['teachers'] as $t): ?>
            <span class="badge badge-warning"><?= htmlspecialchars($t['teacher_name']) ?>：<?= intval($t['lesson_count']) ?>节</span>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <span style="font-size:13px;color:var(--text-light);">暂无记录</span>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<?php adminFooter(); ?>