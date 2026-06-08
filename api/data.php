<?php
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$db = Database::getInstance();
$action = $_GET['action'] ?? '';
$year = isset($_GET['year']) ? intval($_GET['year']) : CURRENT_YEAR;
$month = isset($_GET['month']) ? intval($_GET['month']) : CURRENT_MONTH;

switch ($action) {
    case 'grade_stats':
        $gradeId = intval($_GET['grade_id'] ?? 0);
        if ($gradeId < 1 || $gradeId > 6) {
            echo json_encode(['error' => '无效的年级ID']);
            exit;
        }

        $grade = $db->fetchOne("SELECT * FROM grades WHERE id = ?", [$gradeId]);
        $setting = $db->fetchOne("SELECT * FROM grade_settings WHERE grade_id = ? AND year = ? AND month = ?",
            [$gradeId, $year, $month]);
        $classes = $db->fetchAll("SELECT * FROM classes WHERE grade_id = ? ORDER BY id", [$gradeId]);
        
        $classData = [];
        foreach ($classes as $c) {
            $atts = $db->fetchAll(
                "SELECT * FROM attendance WHERE class_id = ? AND year = ? AND month = ? ORDER BY lesson_number",
                [$c['id'], $year, $month]
            );
            $classData[] = [
                'class_id' => $c['id'],
                'class_name' => $c['name'],
                'attendance' => $atts
            ];
        }

        $teachers = $db->fetchAll(
            "SELECT * FROM teacher_lessons WHERE grade_id = ? AND year = ? AND month = ?",
            [$gradeId, $year, $month]
        );

        // 获取收费方案
        $feePlans = $db->fetchAll(
            "SELECT * FROM fee_plans WHERE year = ? AND month = ? ORDER BY sort_order ASC",
            [$year, $month]
        );

        // 获取教师课时
        $teacherHours = $db->fetchAll(
            "SELECT * FROM teacher_hours WHERE grade_id = ? AND year = ? AND month = ?",
            [$gradeId, $year, $month]
        );

        echo json_encode([
            'grade_name' => $grade ? $grade['name'] : '',
            'settings' => $setting ?: null,
            'classes' => $classData,
            'teacher_lessons' => $teachers,
            'fee_plans' => $feePlans,
            'teacher_hours' => $teacherHours
        ]);
        break;

    case 'overview':
        $totalStudents = 0;
        $totalLessons = 0;
        
        $grades = $db->fetchAll("SELECT * FROM grades ORDER BY sort_order");
        foreach ($grades as $g) {
            $classes = $db->fetchAll("SELECT id FROM classes WHERE grade_id = ?", [$g['id']]);
            
            if (!empty($classes)) {
                $ids = implode(',', array_column($classes, 'id'));
                $att = $db->fetchAll(
                    "SELECT SUM(student_count) as sc, SUM(lesson_hours) as lh FROM attendance WHERE class_id IN ({$ids}) AND year = ? AND month = ?",
                    [$year, $month]
                );
                $students = intval($att[0]['sc'] ?? 0);
                $lessons = floatval($att[0]['lh'] ?? 0);
                
                $totalStudents += $students;
                $totalLessons += $lessons;
            }
        }

        // 获取所有收费方案
        $feePlans = $db->fetchAll(
            "SELECT * FROM fee_plans WHERE year = ? AND month = ? ORDER BY sort_order ASC",
            [$year, $month]
        );

        echo json_encode([
            'total_students' => $totalStudents,
            'total_lessons' => $totalLessons,
            'fee_plans' => $feePlans,
            'year' => $year,
            'month' => $month
        ]);
        break;

    default:
        echo json_encode(['error' => '未知操作']);
        break;
}