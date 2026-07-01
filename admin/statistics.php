<?php
require_once __DIR__ . '/auth.php';
$db = Database::getInstance();

$year = isset($_GET['year']) ? intval($_GET['year']) : CURRENT_YEAR;
$month = isset($_GET['month']) ? intval($_GET['month']) : CURRENT_MONTH;
$gradeFilter = isset($_GET['grade_id']) ? intval($_GET['grade_id']) : 0;

// 获取多套收费方案
$feePlans = $db->fetchAll(
    "SELECT * FROM fee_plans WHERE year = ? AND month = ? ORDER BY sort_order ASC, id ASC",
    [$year, $month]
);

$grades = $db->fetchAll("SELECT * FROM grades ORDER BY sort_order");

// 构建查询条件
$whereGrade = $gradeFilter > 0 ? "AND g.id = {$gradeFilter}" : '';

// 各年级详细统计
$sql = "SELECT g.id as grade_id, g.name as grade_name, g.sort_order,
        COALESCE(gs.teaching_days, 0) as teaching_days
        FROM grades g
        LEFT JOIN grade_settings gs ON gs.grade_id = g.id AND gs.year = ? AND gs.month = ?
        WHERE 1=1 {$whereGrade}
        ORDER BY g.sort_order";
$gradeStats = $db->fetchAll($sql, [$year, $month]);

// 获取每个年级的详细数据
$schoolTotalStudents = 0;
$schoolTotalLessons = 0;
$schoolTotalTeacherHours = 0;
$schoolTotalExtTeacherLessons = 0;

// 获取特殊人员数据
$specialStaffList = $db->fetchAll(
    "SELECT * FROM special_staff WHERE year = ? AND month = ? ORDER BY FIELD(staff_type, '领导','后勤','校医')",
    [$year, $month]
);
$specialStaffTotal = [];
foreach ($specialStaffList as $ss) {
    $specialStaffTotal[$ss['staff_type']] = [
        'hours' => floatval($ss['total_hours']),
        'unit_price' => floatval($ss['unit_price']),
        'expenditure' => floatval($ss['total_hours']) * floatval($ss['unit_price'])
    ];
}
$totalSpecialExpenditure = array_sum(array_column($specialStaffTotal, 'expenditure'));

foreach ($gradeStats as &$gs) {
    $gid = $gs['grade_id'];
    
    // 班级数据
    $classes = $db->fetchAll("SELECT * FROM classes WHERE grade_id = ? ORDER BY id", [$gid]);
    $classDetails = [];
    $totalStudents = 0;
    $totalLessons = 0;
    
    foreach ($classes as $c) {
        // 正确逻辑：lesson_number = X节 → 有多少学生本月上了X节课
        $att = $db->fetchAll(
            "SELECT lesson_number, student_count 
             FROM attendance WHERE class_id = ? AND year = ? AND month = ?",
            [$c['id'], $year, $month]
        );
        $ts = 0; // 总学生数 = Σ(各组人数)
        $tl = 0; // 总课时 = Σ(人数 × 节数)
        $attendanceGroups = [];
        foreach ($att as $row) {
            $ts += intval($row['student_count']);
            $tl += intval($row['student_count']) * intval($row['lesson_number']);
            $attendanceGroups[] = [
                'lesson_number' => intval($row['lesson_number']),
                'student_count' => intval($row['student_count'])
            ];
        }
        
        $totalStudents += $ts;
        $totalLessons += $tl;
        
        $classDetails[] = [
            'name' => $c['name'],
            'students' => $ts,
            'lessons' => $tl,
            'attendance_groups' => $attendanceGroups
        ];
    }
    
    // 教师课时数据 (从 grade_settings 读取)
    $gs = $db->fetchOne("SELECT teacher_total_hours FROM grade_settings WHERE grade_id = ? AND year = ? AND month = ?", [$gid, $year, $month]);
    $totalTeacherHours = floatval($gs['teacher_total_hours'] ?? 0);
    
    // 校外教师数据 (teacher_lessons)
    $extTeachers = $db->fetchAll(
        "SELECT * FROM teacher_lessons WHERE grade_id = ? AND year = ? AND month = ?",
        [$gid, $year, $month]
    );
    $totalExtTeacherLessons = 0;
    foreach ($extTeachers as $t) {
        $totalExtTeacherLessons += intval($t['lesson_count']);
    }
    
    // 按方案计算收支
    $planBudgets = [];
    foreach ($feePlans as $fp) {
        $unitPrice = floatval($fp['unit_price']);
        $capPrice = floatval($fp['cap_price']);
        $teacherPayRate = floatval($fp['teacher_pay_rate'] ?? 0);
        
        // 按分组逐生计算：每组收费 = 人数 × min(节数×单价, 封顶价)
        $income = 0;
        foreach ($allGroups as $ag) {
            $income += $ag['student_count'] * min($ag['lesson_number'] * $unitPrice, $capPrice);
        }
        
        $totalTeacherAll = $totalTeacherHours + $totalExtTeacherLessons;
        $expenditure = $totalTeacherAll * $teacherPayRate;
        $balance = $income - $expenditure;
        
        $planBudgets[] = [
            'plan_name' => $fp['plan_name'],
            'unit_price' => $unitPrice,
            'cap_price' => $capPrice,
            'teacher_pay_rate' => $teacherPayRate,
            'income' => $income,
            'expenditure' => $expenditure,
            'balance' => $balance
        ];
    }
    
    $gs['classes'] = $classDetails;
    $gs['total_students'] = $totalStudents;
    $gs['total_lessons'] = $totalLessons;
    // 汇总所有班级的所有分组
    $allGroups = [];
    foreach ($classDetails as $cd) {
        foreach ($cd['attendance_groups'] as $ag) {
            $allGroups[] = [
                'class_name' => $cd['name'],
                'lesson_number' => $ag['lesson_number'],
                'student_count' => $ag['student_count']
            ];
        }
    }
    $gs['all_attendance_groups'] = $allGroups;
    $gs['teachers'] = [];
    $gs['total_teacher_hours'] = $totalTeacherHours;
    $gs['ext_teachers'] = $extTeachers;
    $gs['total_ext_teacher_lessons'] = $totalExtTeacherLessons;
    $gs['plan_budgets'] = $planBudgets;
    
    $schoolTotalStudents += $totalStudents;
    $schoolTotalLessons += $totalLessons;
    $schoolTotalTeacherHours += $totalTeacherHours;
    $schoolTotalExtTeacherLessons += $totalExtTeacherLessons;
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
    <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
        <a href="export.php?type=attendance&year=<?= $year ?>&month=<?= $month ?>&grade_id=<?= $gradeFilter ?>" class="btn btn-sm" style="background:#28a745;color:#fff;text-decoration:none;padding:6px 14px;border-radius:6px;font-size:13px;">
            📥 导出各班考勤
        </a>
        <a href="export.php?type=statistics&year=<?= $year ?>&month=<?= $month ?>&grade_id=<?= $gradeFilter ?>" class="btn btn-sm" style="background:#17a2b8;color:#fff;text-decoration:none;padding:6px 14px;border-radius:6px;font-size:13px;">
            📥 导出预算统计
        </a>
    </div>
</div>

<?php foreach ($gradeStats as $gs): ?>
<div class="card">
    <div class="card-header">
        <h3><?= htmlspecialchars($gs['grade_name']) ?></h3>
        <div style="font-size:13px;color:var(--text-secondary);">
            节数：<?= $gs['teaching_days'] ?>节
        </div>
    </div>

    <!-- 各方案收支对照 -->
    <?php if (!empty($gs['plan_budgets'])): ?>
    <div style="margin-bottom:16px;">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;">
            <?php foreach ($gs['plan_budgets'] as $pb): ?>
            <div style="background:#fff;border-radius:12px;padding:12px 14px;border:1px solid <?= $pb['balance'] >= 0 ? '#bbf7d0' : '#fecaca' ?>;box-shadow:0 1px 4px rgba(0,0,0,0.04);<?= $pb['balance'] < 0 ? 'background:#fefcfc;' : '' ?>">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                    <div style="font-size:14px;font-weight:700;color:var(--text);"><?= htmlspecialchars($pb['plan_name']) ?></div>
                    <span style="font-size:11px;padding:2px 8px;border-radius:6px;font-weight:600;<?= $pb['balance'] >= 0 ? 'background:#f0fdf4;color:#16a34a;' : 'background:#fef2f2;color:#ef4444;' ?>">
                        <?= $pb['balance'] >= 0 ? '✓ 盈' : '✗ 亏' ?>
                    </span>
                </div>
                <div style="font-size:12px;color:var(--text-secondary);margin-bottom:6px;">
                    ¥<?= number_format($pb['unit_price'], 2) ?>/节 · 封顶¥<?= number_format($pb['cap_price'], 0) ?>
                    <br>教师¥<?= number_format($pb['teacher_pay_rate'], 0) ?>/节
                </div>
                <div style="display:flex;justify-content:space-between;font-size:13px;padding:4px 0;">
                    <span style="color:#3b82f6;">收入</span>
                    <span style="font-weight:600;color:#3b82f6;">¥<?= number_format($pb['income'], 0) ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:13px;padding:4px 0;">
                    <span style="color:#ef4444;">支出</span>
                    <span style="font-weight:600;color:#ef4444;">¥<?= number_format($pb['expenditure'], 0) ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:13px;padding:4px 0;border-top:1px solid #f0f0f0;margin-top:4px;">
                    <span>结余</span>
                    <span style="font-weight:700;color:<?= $pb['balance'] >= 0 ? '#10b981' : '#ef4444' ?>;">
                        ¥<?= number_format($pb['balance'], 0) ?>
                    </span>
                </div>
                <button class="calc-toggle" onclick="toggleCalc(this)" style="margin-top:6px;width:100%;justify-content:center;">
                    ▶ 明细
                </button>
                <div class="calc-detail">
                    <div style="font-weight:600;color:var(--text);margin-bottom:4px;">📐 分组收费规则</div>
                    <div class="row"><span class="label">每组收费</span><span class="value">人数 × min(节数×单价, 封顶价)</span></div>
                    <div class="row total" style="margin-bottom:6px;"><span class="label">总收入</span><span class="value">Σ 所有分组</span></div>
                    
                    <div style="font-weight:600;color:var(--text);margin-bottom:4px;">📊 详细计算</div>
                    <?php $hasDetail = false; ?>
                    <?php foreach ($gs['all_attendance_groups'] as $ag): 
                        $ps = $ag['student_count'] * min($ag['lesson_number'] * $pb['unit_price'], $pb['cap_price']);
                        $capped = min($ag['lesson_number'] * $pb['unit_price'], $pb['cap_price']);
                        $hasDetail = true;
                    ?>
                    <div class="row" style="font-size:12px;">
                        <span class="label"><?= $ag['class_name'] ?> 上<?= $ag['lesson_number'] ?>节 × <?= $ag['student_count'] ?>人</span>
                        <span class="value">
                            <?php if ($capped < $ag['lesson_number'] * $pb['unit_price']): ?>
                                = <?= $ag['student_count'] ?> × ¥<?= number_format($capped, 2) ?> = ¥<?= number_format($ps, 0) ?> <span style="color:#ef4444;">(封顶)</span>
                            <?php else: ?>
                                = <?= $ag['student_count'] ?> × ¥<?= number_format($capped, 2) ?> = ¥<?= number_format($ps, 0) ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                    <?php if (!$hasDetail): ?>
                    <div class="row"><span class="label" style="color:var(--text-light);">暂无出勤数据</span></div>
                    <?php endif; ?>
                    
                    <div class="row total income" style="margin-top:4px;">
                        <span class="label">预估收入</span>
                        <span class="value">¥<?= number_format($pb['income'], 0) ?></span>
                    </div>
                    <div style="font-weight:600;color:var(--text);margin-top:8px;margin-bottom:4px;">📉 支出</div>
                    <div class="row"><span class="label">教师总课时</span><span class="value"><?= number_format($gs['total_teacher_hours'] + $gs['total_ext_teacher_lessons'], 1) ?></span></div>
                    <div class="row"><span class="label">× 教师课时费</span><span class="value">¥<?= number_format($pb['teacher_pay_rate'], 0) ?></span></div>
                    <div class="row total"><span class="label">支出</span><span class="value">¥<?= number_format($pb['expenditure'], 0) ?></span></div>
                    <div style="border-top:1px dashed var(--border);margin:6px 0 4px;"></div>
                    <div class="row"><span class="label">结余</span><span class="value" style="color:<?= $pb['balance'] >= 0 ? '#10b981' : '#ef4444' ?>;">¥<?= number_format($pb['balance'], 0) ?></span></div>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
    </div>
    <?php endif; ?>

    <!-- 概览数据 -->
    <div class="stat-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:16px;">
        <div class="stat-card" style="padding:12px;">
            <div class="stat-value" style="font-size:22px;"><?= number_format($gs['total_students']) ?></div>
            <div class="stat-label">学生总人数</div>
        </div>
        <div class="stat-card" style="padding:12px;">
            <div class="stat-value" style="font-size:22px;"><?= number_format($gs['total_lessons'], 1) ?></div>
            <div class="stat-label">总课时</div>
        </div>
        <div class="stat-card" style="padding:12px;">
            <div class="stat-value" style="font-size:22px;"><?= number_format($gs['total_teacher_hours'], 1) ?></div>
            <div class="stat-label">教师课时</div>
        </div>
        <div class="stat-card" style="padding:12px;">
            <div class="stat-value" style="font-size:22px;"><?= number_format($gs['total_ext_teacher_lessons']) ?></div>
            <div class="stat-label">校外课时</div>
        </div>
    </div>

    <!-- 班级明细 -->
    <?php if (!empty($gs['classes'])): ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>班级</th>
                    <th>学生人数</th>
                    <th>总课时数</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($gs['classes'] as $c): ?>
                <tr>
                    <td><?= htmlspecialchars($gs['grade_name']) ?> <?= htmlspecialchars($c['name']) ?></td>
                    <td><?= number_format($c['students']) ?></td>
                    <td><?= number_format($c['lessons'], 1) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="total-row">
                    <td><strong>合计</strong></td>
                    <td><?= number_format($gs['total_students']) ?></td>
                    <td><?= number_format($gs['total_lessons'], 1) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state" style="padding:16px;">
        <p style="font-size:14px;">暂无上课数据</p>
    </div>
    <?php endif; ?>

    <!-- 上课教师课时 -->
    <div style="margin-top:12px;padding-top:12px;border-top:1px dashed var(--border);">
        <div style="font-size:14px;font-weight:600;color:var(--text);margin-bottom:8px;">👩‍🏫 上课教师课时</div>
        <?php if ($gs['total_teacher_hours'] > 0): ?>
        <div style="font-size:24px;font-weight:700;color:var(--primary);">
            <?= number_format($gs['total_teacher_hours'], 1) ?> 课时
        </div>
        <div style="font-size:12px;color:var(--text-light);margin-top:4px;">（由年级干事填写，与上交纸质版一致）</div>
        <?php else: ?>
        <span style="font-size:13px;color:var(--text-light);">暂无记录（请在年级页面"发放统计"中填写总课时）</span>
        <?php endif; ?>
    </div>

    <!-- 校外教师 -->
    <div style="margin-top:12px;padding-top:12px;border-top:1px dashed var(--border);">
        <div style="font-size:14px;font-weight:600;color:var(--text);margin-bottom:8px;">👩‍🏫 校外教师课时</div>
        <?php if (!empty($gs['ext_teachers'])): ?>
        <div style="display:flex;flex-wrap:wrap;gap:8px;">
            <?php foreach ($gs['ext_teachers'] as $t): ?>
            <span class="badge badge-warning"><?= htmlspecialchars($t['teacher_name']) ?>：<?= intval($t['lesson_count']) ?>节</span>
            <?php endforeach; ?>
        </div>
        <div style="margin-top:6px;font-size:13px;color:var(--text-secondary);">
            小计：<strong><?= number_format($gs['total_ext_teacher_lessons']) ?>节</strong>
        </div>
        <?php else: ?>
        <span style="font-size:13px;color:var(--text-light);">暂无记录</span>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<script>
function toggleCalc(btn) {
    btn.classList.toggle('open');
    var detail = btn.nextElementSibling;
    if (detail) detail.classList.toggle('open');
    btn.innerHTML = btn.classList.contains('open') ? '▼ 收起明细' : '▶ 明细';
}
</script>
<?php adminFooter(); ?>