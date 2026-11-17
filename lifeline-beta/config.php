<?php
// Database configuration supporting both local XAMPP and remote Railway environment variables
$host = getenv('MYSQLHOST') ?: 'localhost';
$user = getenv('MYSQLUSER') ?: 'root';
$pass = getenv('MYSQLPASSWORD') ?: '';
$dbname = getenv('MYSQLDATABASE') ?: 'railway'; // Replace 'railway' with your local DB name if needed for local testing
$port = getenv('MYSQLPORT') ?: '3306';

// Create database connection using MySQLi
$conn = new mysqli($host, $user, $pass, $dbname, $port);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Optional: Set charset to UTF-8
$conn->set_charset("utf8mb4");
?>
