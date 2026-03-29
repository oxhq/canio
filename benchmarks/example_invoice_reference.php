<?php

declare(strict_types=1);

return [
    'name' => 'example-invoice',
    'title' => 'Example Invoice Preview',
    'profile' => 'invoice',
    'invoice' => [
        'number' => 'INV-2026-0007',
        'customer' => 'Northwind Studio',
        'issued_at' => '2026-03-27',
        'due_at' => '2026-04-10',
        'currency' => 'USD',
        'lines' => [
            ['description' => 'Design sprint retainer', 'quantity' => 1, 'amount' => 4200],
            ['description' => 'Production support', 'quantity' => 8, 'amount' => 120],
        ],
    ],
    'required_artifacts' => [
        'metadata',
        'pageScreenshot',
        'pdf',
        'renderSpec',
        'sourceHtml',
        'domSnapshot',
        'consoleLog',
        'networkLog',
    ],
    'required_strings' => [
        'Canio Example Invoice',
        'INV-2026-0007',
        'Northwind Studio',
        'USD 4,200.00',
        'USD 960.00',
        'USD 5,160.00',
    ],
    'golden_screenshot' => __DIR__.'/goldens/example-invoice-page.png',
    'golden_pdf_raster' => __DIR__.'/goldens/example-invoice-pdf-raster.png',
    'pdf_bytes' => [
        'expected' => 159916,
        'tolerance_ratio' => 0.25,
    ],
    'screenshot' => [
        'sample_width' => 160,
        'sample_height' => 160,
        'min_similarity' => 0.995,
        'max_changed_ratio' => 0.02,
    ],
    'pdf_raster' => [
        'thumbnail_size' => 1440,
        'sample_width' => 240,
        'sample_height' => 240,
        'min_similarity' => 1.0,
        'max_changed_ratio' => 0.0,
    ],
    'tuning' => [
        'poll_interval_ms' => [25, 50, 75],
        'settle_frames' => [1, 2, 3],
        'pool_warm' => [0, 1],
        'runs' => [
            'cold' => 1,
            'warm' => 3,
        ],
        'score_weights' => [
            'cold' => 0.35,
            'warm' => 0.65,
        ],
        'report' => [
            'top' => 5,
        ],
    ],
];
