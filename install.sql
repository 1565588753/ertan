-- ============================================
-- 课后服务预算管理系统 - 完整建库建表脚本
-- 使用方法：phpMyAdmin → 导入此文件
-- ============================================

-- 创建数据库（如果不存在）
CREATE DATABASE IF NOT EXISTS `afterschool_budget` 
    CHARACTER SET utf8mb4 
    COLLATE utf8mb4_unicode_ci;

USE `afterschool_budget`;

-- ============================================
-- 1. 年级表
-- ============================================
DROP TABLE IF EXISTS `grades`;
CREATE TABLE `grades` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(20) NOT NULL COMMENT '年级名称',
    `sort_order` INT NOT NULL DEFAULT 0 COMMENT '排序',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='年级';

-- 插入6个年级
INSERT INTO `grades` (`name`, `sort_order`) VALUES
('一年级', 1),
('二年级', 2),
('三年级', 3),
('四年级', 4),
('五年级', 5),
('六年级', 6);

-- ============================================
-- 2. 班级表
-- ============================================
DROP TABLE IF EXISTS `classes`;
CREATE TABLE `classes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `grade_id` INT NOT NULL COMMENT '所属年级',
    `name` VARCHAR(50) NOT NULL COMMENT '班级名称',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`grade_id`) REFERENCES `grades`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='班级';

-- ============================================
-- 3. 年级设置表（每月参数）
-- ============================================
DROP TABLE IF EXISTS `grade_settings`;
CREATE TABLE `grade_settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `grade_id` INT NOT NULL,
    `year` INT NOT NULL COMMENT '年份',
    `month` INT NOT NULL COMMENT '月份',
    `teaching_days` INT NOT NULL DEFAULT 0 COMMENT '上课天数',
    `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '每节课单价(元)',
    `cap_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '每人封顶价(元)',
    FOREIGN KEY (`grade_id`) REFERENCES `grades`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uk_grade_month` (`grade_id`, `year`, `month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='月度参数设置';

-- ============================================
-- 4. 上课记录表
-- ============================================
DROP TABLE IF EXISTS `attendance`;
CREATE TABLE `attendance` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `class_id` INT NOT NULL COMMENT '班级ID',
    `lesson_number` INT NOT NULL COMMENT '课节序号（第几节）',
    `student_count` INT NOT NULL DEFAULT 0 COMMENT '上课人数',
    `lesson_hours` DECIMAL(10,1) NOT NULL DEFAULT 0.0 COMMENT '课时数（=人数×1节）',
    `year` INT NOT NULL,
    `month` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`class_id`) REFERENCES `classes`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uk_class_lesson` (`class_id`, `lesson_number`, `year`, `month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='上课记录';

-- ============================================
-- 5. 教师课时统计表（校外教师）
-- ============================================
DROP TABLE IF EXISTS `teacher_lessons`;
CREATE TABLE `teacher_lessons` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `grade_id` INT NOT NULL,
    `teacher_name` VARCHAR(50) NOT NULL COMMENT '教师姓名',
    `lesson_count` INT NOT NULL DEFAULT 0 COMMENT '上课节数',
    `year` INT NOT NULL,
    `month` INT NOT NULL,
    FOREIGN KEY (`grade_id`) REFERENCES `grades`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='教师课时';

-- ============================================
-- 5b. 上课教师课时统计表（年级干事填写）
-- ============================================
DROP TABLE IF EXISTS `teacher_hours`;
CREATE TABLE `teacher_hours` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `grade_id` INT NOT NULL,
    `teacher_name` VARCHAR(50) NOT NULL COMMENT '教师姓名',
    `hours` DECIMAL(10,1) NOT NULL DEFAULT 0.0 COMMENT '课时数',
    `year` INT NOT NULL,
    `month` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`grade_id`) REFERENCES `grades`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='上课教师课时';

-- ============================================
-- 5c. 收费方案表（多套方案）
-- ============================================
DROP TABLE IF EXISTS `fee_plans`;
CREATE TABLE `fee_plans` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `year` INT NOT NULL,
    `month` INT NOT NULL,
    `plan_name` VARCHAR(50) NOT NULL COMMENT '方案名称',
    `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '每节课单价(元)',
    `cap_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '每人封顶价(元)',
    `sort_order` INT NOT NULL DEFAULT 0 COMMENT '排序',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_plan_month` (`year`, `month`, `plan_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='收费方案';

-- ============================================
-- 6. 管理员表
-- ============================================
DROP TABLE IF EXISTS `admin_users`;
CREATE TABLE `admin_users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理员';

-- 插入默认管理员（账号: admin, 密码: admin123）
INSERT INTO `admin_users` (`username`, `password_hash`) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');

-- ============================================
-- 完成！
-- ============================================
SELECT '安装完成！' AS '提示';