<?php

use Slim\App;

return function (App $app) {
    $app->add(new \Slim\Middleware\Session([
      'name' => 'sft',
      'autorefresh' => true,
      'lifetime' => '1 hour'
    ]));
};
