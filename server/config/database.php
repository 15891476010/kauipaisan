<?php
return [
    'default' => env('DB_TYPE', 'mysql'),
    'connections' => [
        'mysql' => [
            'type' => 'mysql', 'hostname' => env('DB_HOST','127.0.0.1'), 'database' => env('DB_DATABASE','kuaipaisan'), 'username' => env('DB_USERNAME','root'), 'password' => env('DB_PASSWORD',''), 'hostport' => env('DB_PORT',3306), 'charset' => env('DB_CHARSET','utf8mb4'), 'prefix' => '', 'deploy' => 0, 'rw_separate' => false, 'break_reconnect' => true,
        ],
    ],
    'time_query_rule' => [], 'auto_timestamp' => true, 'datetime_format' => 'Y-m-d H:i:s',
];
