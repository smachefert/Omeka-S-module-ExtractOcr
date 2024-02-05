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
            'extractocr_media_type' => 'application/alto+xml',
            // Don't set a default option to avoid issue with config form.
            'extractocr_content_store' => [],
            'extractocr_content_property' => 'bibo:content',
            'extractocr_content_language' => '',
            'extractocr_create_empty_xml' => false,
        ],
    ],
];
