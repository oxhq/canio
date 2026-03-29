<?php

declare(strict_types=1);

return [
    'name' => 'javascript-probe',
    'title' => 'JavaScript Execution Probe',
    'profile' => 'report',
    'probe_url' => 'https://probe.canio.test/javascript',
    'golden_pdf_raster' => __DIR__.'/goldens/javascript-probe-pdf-raster.png',
    'pdf_raster' => [
        'thumbnail_size' => 1440,
        'sample_width' => 240,
        'sample_height' => 240,
    ],
    'js_signal' => [
        'crop_width' => 420,
        'crop_height' => 120,
        'min_dark_ratio' => 0.15,
        'min_green_ratio' => 0.005,
    ],
    'required_artifacts' => [
        'metadata',
        'pdf',
        'pageScreenshot',
        'sourceHtml',
        'domSnapshot',
    ],
    'dom_required_strings' => [
        'id="__codex_test__"',
        'data-codex-probe="ok"',
        'codex ok https://probe.canio.test/javascript',
    ],
    'source_absent_strings' => [
        '<div id="__codex_test__"',
        'data-codex-probe="ok"',
        'codex ok https://probe.canio.test/javascript',
    ],
];
