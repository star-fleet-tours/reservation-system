<?php

use Slim\App;

return function (App $app) {
    $container = $app->getContainer();

    // view renderer
    $container['renderer'] = function ($c) {
        $settings = $c->get('settings')['renderer'];
        return new \Slim\Views\PhpRenderer($settings['template_path'], $settings['attributes'] ?? [], $settings['layout']);
    };

    // monolog
    $container['logger'] = function ($c) {
        $settings = $c->get('settings')['logger'];
        $logger = new \Monolog\Logger($settings['name']);
        $logger->pushProcessor(new \Monolog\Processor\UidProcessor());
        $logger->pushHandler(new \Monolog\Handler\StreamHandler($settings['path'], $settings['level']));
        return $logger;
    };

    $container['session'] = function ($c) {
          return new \SlimSession\Helper;
    };

    $container['redis'] = function ($c) {
          return new \Predis\Client;
    };

    $container['hashids'] = function ($c) {
          return new \Hashids\Hashids('sft', 4, '0123456789ABCDEFGHIJKLMNPQRSTUVWXYZ');
    };
};
