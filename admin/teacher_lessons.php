<?php
require_once __DIR__ . '/auth.php';
$db = Database::getInstance();

$year = isset($_GET['year']) ? intval($_GET['year']) : CURRENT_YEAR;
$month = isset($_GET['month']) ? intval($_GET['month']) : CURRENT_MONTH;
$gradeFilter = isset($_GET['grade_id']) ? intval($_GET['grade_id']) : 0;
$message = '';

// 处理 CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'add') {
            $gradeId = intval($_POST['grade_id'] ?? 0);
            $tCount = intval($_POST['lesson_count'] ?? 0);
            
            if ($gradeId < 1 || $gradeId > 6) throw new Exception('请选择年级');
            if ($tCount < 0) $tCount = 0;
            
            $db->execute(
                "INSERT INTO teacher_lessons (grade_id, teacher_name, lesson_count, year, month) VALUES (?, ?, ?, ?, ?)",
                [$gradeId, '校外教师', $tCount, $year, $month]
            );
            $message = '教师课时记录已添加';
        } elseif ($action === 'edit') {
            $id = intval($_POST['id'] ?? 0);
            $tCount = intval($_POST['lesson_count'] ?? 0);
            
            if ($id < 1) throw new Exception('参数错误');
            if ($tCount < 0) $tCount = 0;
            
            $db->execute(
                "UPDATE teacher_lessons SET lesson_count = ? WHERE id = ?",
                [$tCount, $id]
            );
            $message = '教师课时记录已更新';
        } elseif ($action === 'delete') {
            $id = intval($_POST['id'] ?? 0);
            $db->execute("DELETE FROM teacher_lessons WHERE id = ?", [$id]);
            $message = '教师课时记录已删除';
        }
    } catch (Exception $e) {
        $message = '操作失败：' . $e->getMessage();
    }
}

$grades = $db->fetchAll("SELECT * FROM grades ORDER BY sort_order");

// 查询教师课时
$whereGrade = $gradeFilter > 0 ? "AND grade_id = {$gradeFilter}" : '';
$teachers = $db->fetchAll(
    "SELECT tl.*, g.name as grade_name 
     FROM teacher_lessons tl 
     LEFT JOIN grades g ON g.id = tl.grade_id 
     WHERE tl.year = ? AND tl.month = ? {$whereGrade}
     ORDER BY tl.grade_id, tl.id",
    [$year, $month]
);

adminHeader('校外教师课时管理');
?>

<?php if ($message): ?>
<?php if (strpos($message, '失败') === false): ?>
<div class="toast toast-success show" style="position:fixed;top:20px;right:20px;z-index:9999;padding:14px 20px;border-radius:12px;color:#fff;font-size:14px;box-shadow:0 10px 30px rgba(0,0,0,0.15);">
    ✅ <?= htmlspecialchars($message) ?>
</div>
<?php else: ?>
<div class="toast toast-error show" style="position:fixed;top:20px;right:20px;z-index:9999;padding:14px 20px;border-radius:12px;color:#fff;font-size:14px;box-shadow:0 10px 30px rgba(0,0,0,0.15);">
    ❌ <?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>
<script>
    setTimeout(() => {
        var t = document.querySelector('.toast');
        if (t) { t.style.transition = 'opacity 0.5s'; t.style.opacity = '0'; setTimeout(() => t.remove(), 500); }
    }, 2000);
</script>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2>校外教师课时管理</h2>
    </div>

    <!-- 筛选 -->
    <form method="get" style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:16px;align-items:end;">
        <div class="form-group" style="margin-bottom:0;min-width:100px;">
            <label style="font-size:13px;">年份</label>
            <select name="year" class="form-control form-control-sm">
                <?php for ($y = CURRENT_YEAR - 1; $y <= CURRENT_YEAR; $y++): ?>
                <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?>年</option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="form-group" style="margin-bottom:0;min-width:80px;">
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

<!-- 添加新记录 -->
<div class="card">
    <div class="card-header">
        <h3>添加课时</h3>
    </div>
    <form method="post" style="display:flex;flex-wrap:wrap;gap:10px;align-items:end;">
        <input type="hidden" name="action" value="add">
        <div class="form-group" style="margin-bottom:0;min-width:120px;">
            <label style="font-size:13px;">年级</label>
            <select name="grade_id" class="form-control form-control-sm" required>
                <option value="">请选择</option>
                <?php foreach ($grades as $g): ?>
                <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin-bottom:0;min-width:80px;">
            <label style="font-size:13px;">上课节数</label>
            <input type="number" name="lesson_count" class="form-control form-control-sm" min="0" value="0" required>
        </div>
        <input type="hidden" name="year" value="<?= $year ?>">
        <input type="hidden" name="month" value="<?= $month ?>">
        <button type="submit" class="btn btn-primary btn-sm">+ 添加</button>
    </form>
</div>

<!-- 记录列表 -->
<div class="card">
    <div class="card-header">
        <h3>现有记录</h3>
        <span class="badge badge-primary">共 <?= count($teachers) ?> 条</span>
    </div>

    <?php if (empty($teachers)): ?>
    <div class="empty-state" style="padding:20px;">
        <p>暂无记录</p>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>年级</th>
                    <th>上课节数</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($teachers as $t): ?>
                <tr>
                    <td><?= htmlspecialchars($t['grade_name']) ?></td>
                    <td>
                        <span id="count_<?= $t['id'] ?>"><?= intval($t['lesson_count']) ?></span>
                        <input type="number" id="edit_count_<?= $t['id'] ?>" 
                               value="<?= intval($t['lesson_count']) ?>" 
                               class="form-control form-control-sm" style="display:none;width:80px;" min="0">
                    </td>
                    <td>
                        <div style="display:flex;gap:6px;">
                            <button class="btn btn-accent btn-xs" id="edit_btn_<?= $t['id'] ?>" 
                                    onclick="editMode(<?= $t['id'] ?>)">编辑</button>
                            <button class="btn btn-primary btn-xs" id="save_btn_<?= $t['id'] ?>" 
                                    style="display:none;" 
                                    onclick="saveEdit(<?= $t['id'] ?>)">保存</button>
                            <button class="btn btn-outline btn-xs" id="cancel_btn_<?= $t['id'] ?>" 
                                    style="display:none;" 
                                    onclick="cancelEdit(<?= $t['id'] ?>)">取消</button>
                            <form method="post" style="display:inline;" onsubmit="return confirm('确定删除？')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-xs">删除</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<script>
function editMode(id) {
    document.getElementById('count_' + id).style.display = 'none';
    document.getElementById('edit_count_' + id).style.display = 'inline-block';
    document.getElementById('edit_btn_' + id).style.display = 'none';
    document.getElementById('save_btn_' + id).style.display = 'inline-flex';
    document.getElementById('cancel_btn_' + id).style.display = 'inline-flex';
}

function cancelEdit(id) {
    document.getElementById('count_' + id).style.display = 'inline';
    document.getElementById('edit_count_' + id).style.display = 'none';
    document.getElementById('edit_btn_' + id).style.display = 'inline-flex';
    document.getElementById('save_btn_' + id).style.display = 'none';
    document.getElementById('cancel_btn_' + id).style.display = 'none';
}

function saveEdit(id) {
    var count = document.getElementById('edit_count_' + id).value;
    
    var form = document.createElement('form');
    form.method = 'post';
    form.innerHTML = 
        '<input type="hidden" name="action" value="edit">' +
        '<input type="hidden" name="id" value="' + id + '">' +
        '<input type="hidden" name="lesson_count" value="' + count + '">';
    document.body.appendChild(form);
    form.submit();
}
</script>

<?php adminFooter(); ?>