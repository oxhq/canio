<?php

declare(strict_types=1);

return [
    'format' => 'a4',
    'margins' => [10, 10, 14, 10],
    'background' => true,
    'timeout' => 30,
    'wait' => [
        'strategy' => 'network_idle+fonts+ready_flag',
        'timeout' => 30,
    ],
    'debug' => [
        'artifacts' => ['html', 'screenshot', 'pdf'],
    ],
];
