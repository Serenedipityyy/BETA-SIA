<?php
return [
    'db_host' => getenv('MYSQLHOST') ?: getenv('DB_HOST') ?: 'localhost',
    'db_name' => getenv('MYSQLDATABASE') ?: getenv('DB_NAME') ?: 'lifeline',
    'db_user' => getenv('MYSQLUSER') ?: getenv('DB_USER') ?: 'root',
    'db_pass' => getenv('MYSQLPASSWORD') ?: getenv('DB_PASS') ?: '',
    'db_port' => getenv('MYSQLPORT') ?: getenv('DB_PORT') ?: '3306',
    'db_charset' => 'utf8mb4',
    'timezone_offset' => '+08:00'
];
