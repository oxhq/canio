<?php

declare(strict_types=1);

return [
    'format' => 'a4',
    'margins' => [12, 12, 12, 12],
    'background' => true,
    'tagged' => true,
    'timeout' => 40,
    'postprocess' => [
        'optimize' => true,
    ],
    'debug' => [
        'artifacts' => ['html', 'dom_snapshot', 'pdf', 'metadata'],
    ],
];
