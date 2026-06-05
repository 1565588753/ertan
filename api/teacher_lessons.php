<?php
/**
 * 教师课时 API
 * POST 处理新增/编辑/删除
 * GET 获取列表
 */
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $year = isset($_GET['year']) ? intval($_GET['year']) : CURRENT_YEAR;
    $month = isset($_GET['month']) ? intval($_GET['month']) : CURRENT_MONTH;
    $gradeId = isset($_GET['grade_id']) ? intval($_GET['grade_id']) : 0;
    
    $where = '';
    $params = [$year, $month];
    if ($gradeId > 0) {
        $where = 'AND grade_id = ?';
        $params[] = $gradeId;
    }
    
    $teachers = $db->fetchAll(
        "SELECT tl.*, g.name as grade_name 
         FROM teacher_lessons tl 
         LEFT JOIN grades g ON g.id = tl.grade_id 
         WHERE tl.year = ? AND tl.month = ? {$where}
         ORDER BY tl.grade_id, tl.id",
        $params
    );
    
    echo json_encode(['data' => $teachers]);
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 验证管理员身份
    session_start();
    if (!isset($_SESSION['admin_id'])) {
        echo json_encode(['success' => false, 'error' => '未登录']);
        exit;
    }
    
    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'error' => '未知操作'];
    
    try {
        if ($action === 'add') {
            $gradeId = intval($_POST['grade_id'] ?? 0);
            $tName = trim($_POST['teacher_name'] ?? '');
            $tCount = intval($_POST['lesson_count'] ?? 0);
            $year = intval($_POST['year'] ?? CURRENT_YEAR);
            $month = intval($_POST['month'] ?? CURRENT_MONTH);
            
            if ($gradeId < 1) throw new Exception('请选择年级');
            if ($tName === '') throw new Exception('请输入教师姓名');
            if ($tCount < 0) $tCount = 0;
            
            $db->execute(
                "INSERT INTO teacher_lessons (grade_id, teacher_name, lesson_count, year, month) VALUES (?, ?, ?, ?, ?)",
                [$gradeId, $tName, $tCount, $year, $month]
            );
            
            $response = ['success' => true, 'id' => $db->lastInsertId()];
            
        } elseif ($action === 'edit') {
            $id = intval($_POST['id'] ?? 0);
            $tName = trim($_POST['teacher_name'] ?? '');
            $tCount = intval($_POST['lesson_count'] ?? 0);
            
            if ($id < 1) throw new Exception('参数错误');
            if ($tName === '') throw new Exception('请输入教师姓名');
            if ($tCount < 0) $tCount = 0;
            
            $db->execute(
                "UPDATE teacher_lessons SET teacher_name = ?, lesson_count = ? WHERE id = ?",
                [$tName, $tCount, $id]
            );
            
            $response = ['success' => true];
            
        } elseif ($action === 'delete') {
            $id = intval($_POST['id'] ?? 0);
            $db->execute("DELETE FROM teacher_lessons WHERE id = ?", [$id]);
            $response = ['success' => true];
        }
    } catch (Exception $e) {
        $response = ['success' => false, 'error' => $e->getMessage()];
    }
    
    echo json_encode($response);
}