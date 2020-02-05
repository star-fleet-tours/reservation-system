<?php
return [
    'settings' => [
        'displayErrorDetails' => (bool)getenv('DISPLAY_ERRORS'), // set to false in production
        'addContentLengthHeader' => false, // Allow the web server to send the content-length header

        // Renderer settings
        'renderer' => [
            'template_path' => __DIR__ . '/../templates/',
            'layout' => 'layout.phtml',
            'attributes' => [
                'title' => 'Attend the Atlas V-11 Pre-Launch Tour with Star✦Fleet Tours',
                'stripePubKey' => getenv('STRIPE_PUBLIC_KEY'),
            ],
        ],

        // Monolog settings
        'logger' => [
            'name' => 'sft-app',
            'path' => isset($_ENV['docker']) ? 'php://stdout' : __DIR__ . '/../logs/app.log',
            'level' => \Monolog\Logger::DEBUG,
        ],
    ],
];
