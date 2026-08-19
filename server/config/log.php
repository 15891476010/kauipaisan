<?php
return [
    'default' => 'file', 'level' => [], 'type_channel' => [], 'close' => false,
    'processor' => null, 'channels' => [
        'file' => ['type'=>'File','path'=>runtime_path().'log','single'=>false,'apart_level'=>[],'max_files'=>30,'json'=>false,'processor'=>null,'close'=>false],
    ],
];

