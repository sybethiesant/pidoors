<?php
/**
 * PiDoors Configuration File
 * Access Control System
 */

return [
    // Database Configuration
    'sqladdr' => '127.0.0.1',
    'sqldb' => 'users',
    'sqldb2' => 'access',
    'sqluser' => 'pidoors',
    'sqlpass' => 'p1d00r4p@ss!',

    // Application Paths
    'apppath' => '/home/pi/pidoorserv/',
    'url' => 'http://172.17.22.99',

    // Session Configuration
    'session_timeout' => 3600, // 1 hour in seconds
    'session_name' => 'PIDOORS_SESSION',

    // Password Requirements
    'password_min_length' => 8,
    'password_require_mixed_case' => true,
    'password_require_numbers' => true,

    // Access Control Settings
    'default_unlock_duration' => 5, // seconds
    'max_failed_attempts' => 5,
    'lockout_duration' => 300, // 5 minutes in seconds

    // Timezone
    'timezone' => 'America/New_York'
];
