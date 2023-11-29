<?php declare(strict_types=1);

namespace ExtractOcr\Form;

use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Laminas\Form\Form;
use Omeka\Form\Element\PropertySelect;

class ConfigForm extends Form
{
    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    public function init(): void
    {
        $this
            ->add([
                'name' => 'extractocr_media_type',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Format of the xml content', // @translate
                    'value_options' => [
                        'application/alto+xml' => 'alto',
                        'application/vnd.pdf2xml+xml' => 'pdf2xml',
                    ],
                ],
                'attributes' => [
                    'id' => 'extractocr_media_type',
                ],
            ])
            ->add([
                'name' => 'extractocr_content_store',
                'type' => Element\MultiCheckbox::class,
                'options' => [
                    'label' => 'Store the raw text in a property of a resource', // @translate
                    'info' => 'Text cannot be stored in item when an item is manually edited.', // @translate
                    'empty_option' => '',
                    'value_options' => [
                        'item' => 'Item', // @translate
                        'media_pdf' => 'Pdf media', // @translate
                        'media_xml' => 'Xml media', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'extractocr_content_store',
                ],
            ])
            ->add([
                'name' => 'extractocr_content_property',
                'type' => PropertySelect::class,
                'options' => [
                    'label' => 'Property to save pdf raw text', // @translate
                    'info' => 'To save content makes it searchable anywhere. It is recommended to use "bibo:content". Note that it will increase the noise in the results, unless you use a search engine.', // @translate
                    'empty_option' => '',
                    'term_as_value' => true,
                ],
                'attributes' => [
                    'id' => 'extractocr_content_property',
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select a media property…', // @translate
                ],
            ])
            ->add([
                'name' => 'extractocr_content_language',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Language code of the content', // @translate
                ],
                'attributes' => [
                    'id' => 'extractocr_content_language',
                ],
            ])
            ->add([
                'name' => 'extractocr_create_empty_xml',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Create xml file even if there is no text content', // @translate
                ],
                'attributes' => [
                    'id' => 'extractocr_create_empty_xml',
                ],
            ])

            ->add([
                'name' => 'extractocr_extractor',
                'type' => Fieldset::class,
                'options' => [
                    'label' => 'Extract OCR job', // @translate
                ],
            ])
        ;

        $this->get('extractocr_extractor')
            ->add([
                'name' => 'override',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Extract OCR even if the XML file already exists', // @translate
                ],
                'attributes' => [
                    'id' => 'override',
                ],
            ])
            ->add([
                'name' => 'item_ids',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Item ids', // @translate
                ],
                'attributes' => [
                    'id' => 'item_ids',
                    'placeholder' => '2-6 8 38-52 80-', // @ translate
                ],
            ])
            ->add([
                'name' => 'process',
                'type' => Element\Submit::class,
                'options' => [
                    'label' => 'Run in background', // @translate
                ],
                'attributes' => [
                    'id' => 'process',
                    'value' => 'Process', // @translate
                ],
            ]);

        $this->getInputFilter()
            ->add([
                'name' => 'extractocr_content_store',
                'required' => false,
            ])
            ->add([
                'name' => 'extractocr_content_property',
                'required' => false,
            ])
            ->add([
                'name' => 'extractocr_extractor',
                'required' => false,
            ])
        ;
    }
}
