<?php
// 数据库配置
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'afterschool_budget');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// 应用配置
define('APP_NAME', '课后服务预算管理系统');
define('APP_VERSION', '1.0.0');
define('CURRENT_YEAR', date('Y'));
define('CURRENT_MONTH', intval(date('m')));

// 年级列表
$GRADES = [
    1 => '一年级',
    2 => '二年级',
    3 => '三年级',
    4 => '四年级',
    5 => '五年级',
    6 => '六年级'
];

// 错误报告
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);