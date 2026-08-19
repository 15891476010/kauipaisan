<?php
return [
    'app_name' => '快排 SaaS API', 'app_debug' => (bool)env('APP_DEBUG', false), 'app_trace' => false,
    'app_host' => env('APP_HOST','0.0.0.0'), 'app_status' => '', 'app_multi_module' => true,
    'root_namespace' => 'app', 'default_app' => 'index', 'default_timezone' => 'Asia/Shanghai',
    'show_error_msg' => true, 'exception_handle' => '', 'empty_controller' => '',
    'url_convert' => true, 'url_controller_layer' => 'controller', 'url_controller_suffix' => false,
    'controller_suffix' => false, 'default_filter' => 'htmlspecialchars', 'deny_app_list' => [],
];

