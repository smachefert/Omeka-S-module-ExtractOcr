<?php 
  
namespace ExtractOcr;
  
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
        if ((int) shell_exec('hash pdftotext 2>&- || echo 1')) {
          $logger->info("pdftotext not found");
          throw new ModuleCannotInstallException(__('The pdftotext command-line utility '
                . 'is not installed. pdftotext must be installed to install this plugin.'));
        }

        $this->allowXML($serviceLocator->get('Omeka\Settings'));
    }

    /**
     * @brief allow XML's extension and media type
     *        in omeka's settings
     * @param Omeka's SettingsInterface
     */
    protected function allowXML($settings) {
        $extension_whitelist =  $settings->get('extension_whitelist');
        $media_type_whitelist = $settings->get('media_type_whitelist');

        $xml_extension = [
            "xml"
        ];

        $xml_media_type = [
            "application/xml",
            "text/xml"
        ];

        foreach($xml_extension as $extension) {
            if ( !in_array($extension, $extension_whitelist)) {
                $extension_whitelist[] = $extension;
            }
        }

        foreach($xml_media_type as $media_type) {
            if ( !in_array($media_type, $media_type_whitelist)) {
                $media_type_whitelist[] = $media_type;
            }
        }

        $settings->set('extension_whitelist', $extension_whitelist);
        $settings->set('media_type_whitelist', $media_type_whitelist);
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
            \Omeka\Api\Adapter\MediaAdapter::class,
            'api.hydrate.post',
            function (\Zend\EventManager\Event $event) {

                list($basePath, $baseUri ) = $this->getPathConfig();
                $entity = $event->getParam('entity');
                if (! $entity->getId()) {
                    $fileExt = $entity->getExtension();
                    if (in_array($fileExt, array('pdf', 'PDF'))) {

                        $fileName = basename($entity->getSource(), ".pdf").".xml";
                        $job = $this->serviceLocator->get('Omeka\Job\Dispatcher')->dispatch('ExtractOcr\Job\ExtractOcr',
                            [
                                'itemId' => $entity->getItem()->getId(),
                                'filename' => $fileName,
                                'storageId' => $entity->getStorageId(),
                                'extension' => $entity->getExtension(),
                                'basePath' => $basePath ,
                                'baseUri' => $baseUri,
                            ]);
                    }
                }
            }
        );
    }

    //TODO add parameter for xml storage path
    protected function getPathConfig() {

        $config = $this->serviceLocator->get('Config');

        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');

        $baseUri = $config['file_store']['local']['base_uri'];
        if (null === $baseUri) {
            $helpers = $this->serviceLocator->get('ViewHelperManager');
            $serverUrlHelper = $helpers->get('ServerUrl');
            $basePathHelper = $helpers->get('BasePath');
            $baseUri = $serverUrlHelper($basePathHelper('files'));
        }

        return [ $basePath . '/original', $baseUri . '/original' ];
    }
}
