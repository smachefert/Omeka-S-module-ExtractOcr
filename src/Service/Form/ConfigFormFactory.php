<?php
namespace ExtractOcr\Service\Form;

use ExtractOcr\Form\ConfigForm;
use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Factory\FactoryInterface;

class ConfigFormFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $form = new ConfigForm(null, $options);
        $form->setConnection($services->get('Omeka\Connection'));
        return $form;
    }
}
