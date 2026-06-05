<?php
require_once __DIR__ . '/auth.php';
$db = Database::getInstance();

$message = '';
$error = '';

// 处理添加班级
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    try {
        if ($action === 'add') {
            $gradeId = intval($_POST['grade_id'] ?? 0);
            $batchCount = intval($_POST['batch_count'] ?? 0);
            $customNames = trim($_POST['custom_names'] ?? '');
            
            if ($gradeId < 1 || $gradeId > 6) throw new Exception('请选择年级');
            
            if (!empty($customNames)) {
                // 自定义班级名称（逗号或换行分隔）
                $names = preg_split('/[,，\n\r]+/', $customNames);
                $names = array_map('trim', $names);
                $names = array_filter($names);
                
                // 获取最大排序序号
                $maxOrder = $db->fetchOne("SELECT MAX(id) as max_id FROM classes WHERE grade_id = ?", [$gradeId]);
                $orderOffset = intval($maxOrder['max_id'] ?? 0);
                
                foreach ($names as $i => $name) {
                    if ($name !== '') {
                        // 检查是否已存在
                        $exists = $db->fetchOne("SELECT id FROM classes WHERE grade_id = ? AND name = ?", [$gradeId, $name]);
                        if (!$exists) {
                            $db->execute("INSERT INTO classes (grade_id, name) VALUES (?, ?)", [$gradeId, $name]);
                        }
                    }
                }
                $message = '班级添加成功！';
            } elseif ($batchCount > 0) {
                // 批量添加：一班、二班...N班
                $maxOrder = $db->fetchOne("SELECT MAX(id) as max_id FROM classes WHERE grade_id = ?", [$gradeId]);
                $start = intval($maxOrder['max_id'] ?? 0);
                
                for ($i = 1; $i <= $batchCount; $i++) {
                    // 确定班级名：从现有最大编号+1开始
                    $classNumber = $start + $i;
                    $name = $classNumber . '班';
                    
                    $exists = $db->fetchOne("SELECT id FROM classes WHERE grade_id = ? AND name = ?", [$gradeId, $name]);
                    if (!$exists) {
                        $db->execute("INSERT INTO classes (grade_id, name) VALUES (?, ?)", [$gradeId, $name]);
                    }
                }
                $message = "成功添加 {$batchCount} 个班级！";
            }
        } elseif ($action === 'delete') {
            $classId = intval($_POST['class_id'] ?? 0);
            $db->execute("DELETE FROM classes WHERE id = ?", [$classId]);
            $message = '班级已删除';
        } elseif ($action === 'rename') {
            $classId = intval($_POST['class_id'] ?? 0);
            $newName = trim($_POST['name'] ?? '');
            if ($newName !== '') {
                $db->execute("UPDATE classes SET name = ? WHERE id = ?", [$newName, $classId]);
                $message = '班级名称已更新';
            }
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$grades = $db->fetchAll("SELECT * FROM grades ORDER BY sort_order");

// 获取所有班级，按年级分组
$allClasses = [];
foreach ($grades as $g) {
    $allClasses[$g['id']] = $db->fetchAll("SELECT * FROM classes WHERE grade_id = ? ORDER BY id ASC", [$g['id']]);
}

adminHeader('班级管理');
?>

<?php if ($message): ?>
<div class="toast toast-success show" style="position:fixed;top:20px;right:20px;z-index:9999;padding:14px 20px;border-radius:12px;color:#fff;font-size:14px;box-shadow:0 10px 30px rgba(0,0,0,0.15);">
    ✅ <?= htmlspecialchars($message) ?>
</div>
<script>
    setTimeout(() => {
        var t = document.querySelector('.toast');
        if (t) { t.style.transition = 'opacity 0.5s'; t.style.opacity = '0'; setTimeout(() => t.remove(), 500); }
    }, 2000);
</script>
<?php endif; ?>
<?php if ($error): ?>
<div class="toast toast-error show" style="position:fixed;top:20px;right:20px;z-index:9999;padding:14px 20px;border-radius:12px;color:#fff;font-size:14px;box-shadow:0 10px 30px rgba(0,0,0,0.15);">
    ❌ <?= htmlspecialchars($error) ?>
</div>
<script>
    setTimeout(() => {
        var t = document.querySelector('.toast');
        if (t) { t.style.transition = 'opacity 0.5s'; t.style.opacity = '0'; setTimeout(() => t.remove(), 500); }
    }, 2000);
</script>
<?php endif; ?>

<!-- 批量添加 -->
<div class="card">
    <div class="card-header"><h2>批量添加班级</h2></div>
    <form method="post" style="display:flex;flex-direction:column;gap:12px;">
        <input type="hidden" name="action" value="add">
        
        <div class="form-group">
            <label>选择年级</label>
            <select name="grade_id" class="form-control" required>
                <option value="">请选择年级</option>
                <?php foreach ($grades as $g): ?>
                <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="display:flex;gap:12px;align-items:end;flex-wrap:wrap;">
            <div class="form-group" style="flex:1;min-width:150px;">
                <label>快速批量添加（输入数量）</label>
                <input type="number" name="batch_count" class="form-control" min="1" max="50" placeholder="如：6">
            </div>
            <div style="padding-bottom:16px;color:var(--text-secondary);font-size:13px;">或</div>
        </div>

        <div class="form-group">
            <label>自定义班级名称（用逗号或换行分隔，如：一班,二班,三班）</label>
            <textarea name="custom_names" class="form-control" rows="3" placeholder="一班,二班,三班,四班,五班,六班"></textarea>
        </div>

        <div>
            <button type="submit" class="btn btn-primary">+ 添加班级</button>
        </div>
    </form>
</div>

<!-- 现有班级列表 -->
<?php foreach ($grades as $g): 
    $classes = $allClasses[$g['id']] ?? [];
?>
<div class="card">
    <div class="card-header">
        <h3><?= htmlspecialchars($g['name']) ?></h3>
        <span class="badge badge-primary">共 <?= count($classes) ?> 个班</span>
    </div>
    <?php if (empty($classes)): ?>
    <div class="empty-state" style="padding:20px;">
        <p style="font-size:14px;">暂无班级，请在上方添加</p>
    </div>
    <?php else: ?>
    <div style="display:flex;flex-wrap:wrap;gap:8px;">
        <?php foreach ($classes as $c): ?>
        <div style="display:flex;align-items:center;gap:6px;background:#f8fafc;border-radius:8px;padding:6px 10px;border:1px solid var(--border);">
            <span style="font-size:14px;font-weight:600;color:var(--text);"><?= htmlspecialchars($c['name']) ?></span>
            <form method="post" style="display:inline;" onsubmit="return confirm('确定要删除此班级吗？')">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="class_id" value="<?= $c['id'] ?>">
                <button type="submit" class="btn btn-danger btn-xs" style="padding:2px 6px;font-size:11px;">✕</button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<?php adminFooter(); ?>