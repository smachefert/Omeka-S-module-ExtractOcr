<?php

namespace PdfToc;

use Omeka\Module\AbstractModule;
use Omeka\Module\Manager as ModuleManager;
use Omeka\Module\Exception\ModuleCannotInstallException;
use Zend\View\Model\ViewModel;
use Zend\Mvc\Controller\AbstractController;
use Zend\Form\Fieldset;
use Zend\EventManager\Event;
use Zend\EventManager\SharedEventManagerInterface;
use Zend\Mvc\MvcEvent;
use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\Form\Element\Textarea;
use Zend\Form\Element\Text;
use Zend\Debug\Debug;
use Omeka\Mvc\Controller\Plugin\Logger;
//use Zend\Log\Logger;
use Zend\Log\Writer;
use Zend\View\Renderer\PhpRenderer;

class Module extends AbstractModule
{
    public function install(ServiceLocatorInterface $serviceLocator)
    {
        $logger = $serviceLocator->get('Omeka\Logger');
        // Don't install if the pdftotext command doesn't exist.
        // See: http://stackoverflow.com/questions/592620/check-if-a-program-exists-from-a-bash-script
        if ((int) shell_exec('hash pdftk 2>&- || echo 1')) {
            $logger->info("pdftk not found");
            throw new ModuleCannotInstallException(__('The pdftk command-line utility '
                . 'is not installed. pdftk must be installed to install this plugin.'));
        }
    }

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    /**
     * Attach listeners to events.
     *
     * @param SharedEventManagerInterface $sharedEventManager
     */
    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\ItemAdapter',
            'api.create.post',
            [$this, 'extractToc']
        );

        $sharedEventManager->attach(
            'Omeka\Api\Adapter\ItemAdapter',
            'api.update.post',
            [$this, 'extractToc']
        );
    }


    public function extractToc(\Zend\EventManager\Event $event)
    {
        $response = $event->getParams()['response'];
        $item = $response->getContent();

        foreach ($item->getMedia() as $media ) {
            $fileExt = $media->getExtension();
            if (in_array($fileExt, array('pdf', 'PDF'))) {

                $filePath = OMEKA_PATH . '/files/original/' . $media->getStorageId() . '.' . $fileExt;

                $this->serviceLocator->get('Omeka\Job\Dispatcher')->dispatch('PdfToc\Job\ExtractToc',
                    [
                        'itemId' => $media->getItem()->getId(),
                        'mediaId' => $media->getId(),
                        'filePath' => $filePath,
                        'iiifUrl' => 'http://' . $_SERVER['HTTP_HOST'] . '/omeka-s/iiif',
                    ]);
            }
        }
    }

}
