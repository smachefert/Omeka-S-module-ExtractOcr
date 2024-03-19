<?php declare(strict_types=1);

namespace ExtractOcr;

return [
    'form_elements' => [
        'invokables' => [
            Form\ConfigForm::class => Form\ConfigForm::class,
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    'extractocr' => [
        'config' => [
            // Don't set a default option to avoid issue with config form.
            // The option is set on install.
            'extractocr_types_files' => [],
            'extractocr_types_media' => [],
            'extractocr_content_store' => [],
            'extractocr_content_property' => 'bibo:content',
            'extractocr_content_language' => '',
            // Create an empty file when a page does not have text.
            'extractocr_create_empty_file' => false,
        ],
    ],
];
