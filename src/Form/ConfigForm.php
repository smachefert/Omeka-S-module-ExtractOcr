<?php
namespace ExtractOcr\Form;

use Omeka\Form\Element\PropertySelect;
use Zend\Form\Element;
use Zend\Form\Fieldset;
use Zend\Form\Form;

class ConfigForm extends Form
{
    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    public function init()
    {
        $this
            ->add([
                'name' => 'extractocr_content_store',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Store the raw text in the specified property', // @translate
                ],
                'attributes' => [
                    'id' => 'extractocr_content_store',
                ],
            ])
            ->add([
                'name' => 'extractocr_content_property',
                'type' => PropertySelect::class,
                'options' => [
                    'label' => 'Media property to save pdf raw text', // @translate
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
