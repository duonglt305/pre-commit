<?php

return [
    'psr2' => [
        'standard' => __DIR__ . '/../phpcs.xml',
        'report' => 'diff',
        'ignored' => [
            '*/database/*',
            '*/public/*',
            '*/assets/*',
            '*/vendor/*',
        ],
    ],
];
