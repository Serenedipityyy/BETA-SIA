<?php
// Edit these values for your server. Keep this file out of version control.
return [
    'db_host'    => '127.0.0.1',
    'db_name'    => 'lifeline',
    'db_user'    => 'lifeline_app',
    'db_pass'    => 'ChangeThisPassword!',   // must match the password in 01_schema.sql
    'db_charset' => 'utf8mb4',

    'timezone_offset' => '+08:00',           // Philippines (Asia/Manila)

    'alert_cooldown_sec'      => 180,        // block a duplicate alert for the same room
    'max_active_per_reporter' => 3,          // max unresolved alerts one student may have
    'max_login_attempts'      => 5,
    'lockout_sec'             => 60,
];
