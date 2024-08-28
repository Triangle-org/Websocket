<?php

return [
    'debug' => (bool)env('APP_DEBUG', false),
    'name' => env('APP_NAME', 'Triangle App'),

    'controller_suffix' => env('CONTROLLER_SUFFIX', ''),
    'controller_reuse' => env('CONTROLLER_REUSE', true),
];
