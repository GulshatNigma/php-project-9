<?php

require __DIR__ . "/../vendor/autoload.php";

use Slim\Factory\AppFactory;
use Slim\Views\PhpRenderer;
use Slim\Middleware\MethodOverrideMiddleware;

$app = AppFactory::create();
$app->addErrorMiddleware(true, true, true);

$app->get('/', function($request, $responce) {
    $responce->getBody()->write("Welcome!");
    return $responce;
});

$app->run();


