<?php
$host = getenv('MYSQLHOST') ?: getenv('DB_HOST');
$user = getenv('MYSQLUSER') ?: getenv('DB_USER');
$pass = getenv('MYSQLPASSWORD') ?: getenv('DB_PASS');
$dbname = getenv('MYSQLDATABASE') ?: getenv('DB_NAME');
$port = getenv('MYSQLPORT') ?: getenv('DB_PORT') ?: '3306';

$conn = new mysqli($host, $user, $pass, $dbname, $port);

if ($conn->connect_error) {
    die("Database Connection Failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");
?>
