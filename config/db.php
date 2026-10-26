<?php

// แก้ไขค่าเริ่มต้นให้ตรงกับฐานข้อมูล หรือกำหนดผ่าน environment variables
$dbHost = getenv('DB_HOST') ?: 'ns31.hostinglotus.net';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbName = getenv('DB_NAME') ?: 'roietkps_db';
$dbUser = getenv('DB_USER') ?: 'roietkps_user';
$dbPassword = getenv('DB_PASSWORD');
$dbPassword = $dbPassword === false ? 'Sumet@4631022' : $dbPassword;

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $dbHost,
    $dbPort,
    $dbName
);

try {
    $pdo = new PDO($dsn, $dbUser, $dbPassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $exception) {
    // ไม่แสดงรายละเอียดการเชื่อมต่อฐานข้อมูลให้ผู้ใช้งานเห็น
    error_log('Database connection failed (PDO code: ' . $exception->getCode() . ')');
    throw new RuntimeException('ไม่สามารถเชื่อมต่อฐานข้อมูลได้ กรุณาติดต่อผู้ดูแลระบบ', 0, $exception);
}

return $pdo;