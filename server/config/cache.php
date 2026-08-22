<?php
return [
    'default' => env('CACHE_DRIVER', extension_loaded('redis') ? 'redis' : 'file'), 'stores' => [
        'file' => ['type'=>'File','path'=>runtime_path().'cache'],
        'redis' => ['type'=>'redis','host'=>env('REDIS_HOST','127.0.0.1'),'port'=>env('REDIS_PORT',6379),'password'=>env('REDIS_PASSWORD',''),'select'=>(int)env('REDIS_DB',2),'expire'=>0,'timeout'=>2,'persistent'=>false,'prefix'=>'kp:'],
    ],
];
