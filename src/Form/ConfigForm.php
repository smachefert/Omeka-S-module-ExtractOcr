<?php
namespace ExtractOcr\Form;

use Doctrine\DBAL\Connection;
use Zend\Form\Element;
use Zend\Form\Form;

class ConfigForm extends Form
{
    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    public function init()
    {
        $this->add([
            'name' => 'override',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => "Extract OCR even if the XML file already exists", // @translate
            ],
        ]);

        $this->add([
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
    }

    /**
     * @param Connection $connection
     */
    public function setConnection(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @return \Doctrine\DBAL\Connection
     */
    public function getConnection()
    {
        return $this->connection;
    }
}
