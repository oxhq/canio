<?php

declare(strict_types=1);

return [
    'format' => 'a4',
    'margins' => [12, 12, 16, 12],
    'background' => true,
    'timeout' => 45,
    'wait' => [
        'strategy' => 'network_idle+fonts',
        'timeout' => 45,
    ],
    'debug' => [
        'artifacts' => ['html', 'mhtml', 'pdf'],
    ],
];
