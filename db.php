<?php
require_once __DIR__ . '/config.php';

class Database {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die('数据库连接失败：' . $e->getMessage());
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getPdo() {
        return $this->pdo;
    }

    // 查询单条记录
    public function fetchOne($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    // 查询多条记录
    public function fetchAll($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // 执行SQL
    public function execute($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    // 获取最后插入ID
    public function lastInsertId() {
        return $this->pdo->lastInsertId();
    }

    // 检查表是否存在
    public function tableExists($tableName) {
        $stmt = $this->pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$tableName]);
        return $stmt->rowCount() > 0;
    }
}