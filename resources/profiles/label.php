<?php

declare(strict_types=1);

return [
    'paper_size' => [57, 500, 'mm'],
    'margins' => [2, 2, 2, 2],
    'background' => false,
    'timeout' => 15,
    'wait' => [
        'strategy' => 'dom_ready+fonts',
        'timeout' => 15,
    ],
    'debug' => [
        'artifacts' => ['html', 'pdf'],
    ],
];
