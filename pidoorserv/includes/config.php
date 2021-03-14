<?php
    return [
        'sqladdr' => '127.0.0.1', # Address to connect to SQL
        'sqldb' => 'users', # Database name
        'sqldb2' => 'access', # Database name
        'sqluser' => 'pidoors', # SQL Username
        'sqlpass' => 'p1d00r4p@ss!', # SQL Password
        'sqlsalt' => 'pid00rsmd5saltedsalter', # altering this will no longer allow any existing web logins to work. 
        'apppath' => '/home/pi/pidoorserv/', # Path to application
        'url' => 'http://172.17.22.99' #URL to Website
    ];

?>