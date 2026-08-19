<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
use think\App;
$app = new App();
$http = $app->http;
$response = $http->run();
$response->send();
$http->end($response);

