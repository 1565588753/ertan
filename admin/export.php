<?php
/**
 * 数据导出（Excel）
 * type=attendance  各班考勤明细
 * type=statistics  各年级预算统计
 */
require_once __DIR__ . '/../db.php';
session_start();
if (!isset($_SESSION['admin_id'])) {
    die('请先登录');
}
$db = Database::getInstance();

$type = $_GET['type'] ?? '';
$year = isset($_GET['year']) ? intval($_GET['year']) : 0;
$month = isset($_GET['month']) ? intval($_GET['month']) : 0;
$gradeId = isset($_GET['grade_id']) ? intval($_GET['grade_id']) : 0;

if (!$year || !$month) {
    $year = date('Y');
    $month = date('m');
}

// 设置 Excel 下载头
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $year . '年' . $month . '月课后服务数据.xls');
header('Cache-Control: max-age=0');

// 公共样式
echo '<html xmlns:o="urn:schemas-microsoft-com:office:office"
      xmlns:x="urn:schemas-microsoft-com:office:excel"
      xmlns="http://www.w3.org/TR/REC-html40">
<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<style>
    td, th { padding: 4px 8px; border: 1px solid #ccc; font-size: 12px; }
    th { background: #4472c4; color: #fff; font-weight: bold; text-align: center; }
    .title { font-size: 16px; font-weight: bold; text-align: center; border: none; }
    .subtitle { font-size: 12px; text-align: center; border: none; color: #666; }
    .bg-yellow { background: #fff2cc; }
    .bg-green { background: #e2efda; }
    .bg-red { background: #fce4ec; }
    .num { text-align: right; }
    .total { font-weight: bold; background: #d9e2f3; }
</style>
</head><body>';

switch ($type) {
    case 'attendance':
        exportAttendance($db, $year, $month, $gradeId);
        break;
    case 'statistics':
        exportStatistics($db, $year, $month, $gradeId);
        break;
    default:
        echo '<p>无效的导出类型</p>';
}

echo '</body></html>';

// ======================== 考勤明细导出 ========================
function exportAttendance($db, $year, $month, $gradeId) {
    $where = $gradeId > 0 ? "WHERE g.id = $gradeId" : '';
    $grades = $db->fetchAll("SELECT * FROM grades $where ORDER BY sort_order");
    
    echo '<table>';
    echo '<tr><td class="title" colspan="20">' . $year . '年' . $month . '月 课后服务考勤明细表</td></tr>';
    echo '<tr><td class="subtitle" colspan="20">导出时间：' . date('Y-m-d H:i') . '</td></tr>';
    echo '<tr><td colspan="20"></td></tr>';
    
    foreach ($grades as $g) {
        $classes = $db->fetchAll("SELECT id, name FROM classes WHERE grade_id = ?", [$g['id']]);
        if (empty($classes)) continue;
        
        // 获取教学天数
        $gs = $db->fetchOne("SELECT teaching_days FROM grade_settings WHERE grade_id = ? AND year = ? AND month = ?", [$g['id'], $year, $month]);
        $teachingDays = intval($gs['teaching_days'] ?? 0);
        
        echo '<tr><td colspan="20" style="font-size:14px;font-weight:bold;border:none;padding:10px 0 4px;">' . htmlspecialchars($g['name']) . '（本月' . $teachingDays . '节）</td></tr>';
        
        echo '<tr>';
        echo '<th>班级</th>';
        for ($l = 1; $l <= $teachingDays; $l++) {
            echo '<th>上' . $l . '节</th>';
        }
        echo '<th>学生总人数</th>';
        echo '<th>总课时</th>';
        echo '</tr>';
        
        $gradeTotalStudents = 0;
        $gradeTotalHours = 0;
        
        foreach ($classes as $c) {
            // 获取该班分组数据
            $att = $db->fetchAll(
                "SELECT lesson_number, student_count FROM attendance 
                 WHERE class_id = ? AND year = ? AND month = ? 
                 ORDER BY lesson_number",
                [$c['id'], $year, $month]
            );
            $attMap = [];
            $classStudents = 0;
            $classHours = 0;
            foreach ($att as $a) {
                $ln = intval($a['lesson_number']);
                $sc = intval($a['student_count']);
                $attMap[$ln] = $sc;
                $classStudents += $sc;
                $classHours += $sc * $ln;
            }
            
            echo '<tr>';
            echo '<td>' . htmlspecialchars($c['name']) . '</td>';
            for ($l = 1; $l <= $teachingDays; $l++) {
                $val = $attMap[$l] ?? 0;
                echo '<td class="num">' . ($val > 0 ? $val : '') . '</td>';
            }
            echo '<td class="num">' . $classStudents . '</td>';
            echo '<td class="num">' . $classHours . '</td>';
            echo '</tr>';
            
            $gradeTotalStudents += $classStudents;
            $gradeTotalHours += $classHours;
        }
        
        // 年级合计行
        echo '<tr class="total">';
        echo '<td>合计</td>';
        for ($l = 1; $l <= $teachingDays; $l++) {
            $sum = 0;
            foreach ($classes as $c) {
                $att = $db->fetchOne(
                    "SELECT student_count FROM attendance WHERE class_id = ? AND year = ? AND month = ? AND lesson_number = ?",
                    [$c['id'], $year, $month, $l]
                );
                $sum += intval($att['student_count'] ?? 0);
            }
            echo '<td class="num">' . ($sum > 0 ? $sum : '') . '</td>';
        }
        echo '<td class="num">' . $gradeTotalStudents . '</td>';
        echo '<td class="num">' . $gradeTotalHours . '</td>';
        echo '</tr>';
        
        echo '<tr><td colspan="20" style="border:none;height:8px;"></td></tr>';
    }
    
    echo '</table>';
}

// ======================== 预算统计导出 ========================
function exportStatistics($db, $year, $month, $gradeId) {
    $where = $gradeId > 0 ? "WHERE g.id = $gradeId" : '';
    $grades = $db->fetchAll("SELECT * FROM grades $where ORDER BY sort_order");
    $feePlans = $db->fetchAll(
        "SELECT * FROM fee_plans WHERE year = ? AND month = ? ORDER BY sort_order ASC, id ASC",
        [$year, $month]
    );
    
    echo '<table>';
    echo '<tr><td class="title" colspan="' . (count($feePlans) * 3 + 2) . '">' . $year . '年' . $month . '月 课后服务预算统计表</td></tr>';
    echo '<tr><td class="subtitle" colspan="' . (count($feePlans) * 3 + 2) . '">导出时间：' . date('Y-m-d H:i') . '</td></tr>';
    echo '<tr><td colspan="' . (count($feePlans) * 3 + 2) . '"></td></tr>';
    
    foreach ($grades as $g) {
        $classes = $db->fetchAll("SELECT id, name FROM classes WHERE grade_id = ?", [$g['id']]);
        if (empty($classes)) continue;
        
        $classIds = array_column($classes, 'id');
        $ids = implode(',', $classIds);
        
        // 获取考勤分组数据
        $att = $db->fetchAll(
            "SELECT a.class_id, a.lesson_number, a.student_count, c.name as class_name
             FROM attendance a JOIN classes c ON c.id = a.class_id
             WHERE a.class_id IN ($ids) AND a.year = ? AND a.month = ?
             ORDER BY a.class_id, a.lesson_number",
            [$year, $month]
        );
        
        $gradeStudents = array_sum(array_column($att, 'student_count'));
        $gradeHours = array_sum(array_map(function($r) { return $r['student_count'] * $r['lesson_number']; }, $att));
        
        // 教师课时
        $gs = $db->fetchOne("SELECT teacher_total_hours FROM grade_settings WHERE grade_id = ? AND year = ? AND month = ?", [$g['id'], $year, $month]);
        $teacherHours = floatval($gs['teacher_total_hours'] ?? 0);
        $ext = $db->fetchOne("SELECT SUM(lesson_count) as total FROM teacher_lessons WHERE grade_id = ? AND year = ? AND month = ?", [$g['id'], $year, $month]);
        $extHours = intval($ext['total'] ?? 0);
        $totalTeacherAll = $teacherHours + $extHours;
        
        echo '<tr><td colspan="' . (count($feePlans) * 3 + 2) . '" style="font-size:14px;font-weight:bold;border:none;padding:10px 0 4px;">' . htmlspecialchars($g['name']) . '（学生' . $gradeStudents . '人，总课时' . $gradeHours . '，教师' . $totalTeacherAll . '节）</td></tr>';
        
        // 各方案对比表头
        echo '<tr>';
        echo '<th rowspan="2">班级</th>';
        echo '<th rowspan="2">学生人数</th>';
        echo '<th rowspan="2">总课时</th>';
        foreach ($feePlans as $fp) {
            echo '<th colspan="3" style="background:#5b9bd5;">' . htmlspecialchars($fp['plan_name']) . '</th>';
        }
        echo '</tr><tr>';
        foreach ($feePlans as $fp) {
            echo '<th style="background:#5b9bd5;">收入</th>';
            echo '<th style="background:#5b9bd5;">教师支出</th>';
            echo '<th style="background:#5b9bd5;">结余</th>';
        }
        echo '</tr>';
        
        // 各班数据
        foreach ($classes as $c) {
            $ca = array_filter($att, function($r) use ($c) { return $r['class_id'] == $c['id']; });
            $classStudents = array_sum(array_column($ca, 'student_count'));
            $classHours = array_sum(array_map(function($r) { return $r['student_count'] * $r['lesson_number']; }, $ca));
            
            echo '<tr>';
            echo '<td>' . htmlspecialchars($c['name']) . '</td>';
            echo '<td class="num">' . $classStudents . '</td>';
            echo '<td class="num">' . $classHours . '</td>';
            
            foreach ($feePlans as $fp) {
                $up = floatval($fp['unit_price']);
                $cp = floatval($fp['cap_price']);
                $tpr = floatval($fp['teacher_pay_rate'] ?? 0);
                
                $income = 0;
                foreach ($ca as $a) {
                    $income += $a['student_count'] * min($a['lesson_number'] * $up, $cp);
                }
                $expenditure = $totalTeacherAll * $tpr;
                $balance = $income - $expenditure;
                
                echo '<td class="num">¥' . number_format($income, 0) . '</td>';
                echo '<td class="num">¥' . number_format($expenditure, 0) . '</td>';
                echo '<td class="num ' . ($balance >= 0 ? 'bg-green' : 'bg-red') . '">¥' . number_format($balance, 0) . '</td>';
            }
            echo '</tr>';
        }
        
        echo '<tr><td colspan="' . (count($feePlans) * 3 + 2) . '" style="border:none;height:8px;"></td></tr>';
    }
    
    echo '</table>';
}