<?php

namespace ExtractOcr;

use ExtractOcr\Form\ConfigForm;
use ExtractOcr\Job\ExtractOcr;
use Omeka\Module\AbstractModule;
use Omeka\Module\Exception\ModuleCannotInstallException;
use Omeka\Stdlib\Message;
use Zend\EventManager\Event;
use Zend\EventManager\SharedEventManagerInterface;
use Zend\Mvc\Controller\AbstractController;
use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\View\Renderer\PhpRenderer;

class Module extends AbstractModule
{
    public function install(ServiceLocatorInterface $serviceLocator)
    {
        $logger = $serviceLocator->get('Omeka\Logger');
        $t = $serviceLocator->get('MvcTranslator');
        // Don't install if the pdftotext command doesn't exist.
        // See: http://stackoverflow.com/questions/592620/check-if-a-program-exists-from-a-bash-script
        if ((int) shell_exec('hash pdftotext 2>&- || echo 1')) {
            $logger->info('pdftotext not found');
            throw new ModuleCannotInstallException($t->translate('The pdftotext command-line utility '
                . 'is not installed. pdftotext must be installed to install this plugin.'));
        }

        $this->allowXML($serviceLocator->get('Omeka\Settings'));
    }

    /**
     * @brief allow XML's extension and media type
     *        in omeka's settings
     * @param Omeka's SettingsInterface
     */
    protected function allowXML($settings)
    {
        $extension_whitelist = $settings->get('extension_whitelist');
        $media_type_whitelist = $settings->get('media_type_whitelist');

        $xml_extension = [
            'xml',
        ];

        $xml_media_type = [
            'application/xml',
            'text/xml',
        ];

        foreach ($xml_extension as $extension) {
            if (!in_array($extension, $extension_whitelist)) {
                $extension_whitelist[] = $extension;
            }
        }

        foreach ($xml_media_type as $media_type) {
            if (!in_array($media_type, $media_type_whitelist)) {
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

    public function getConfigForm(PhpRenderer $renderer)
    {
        $services = $this->getServiceLocator();
        $form = $services->get('FormElementManager')->get(ConfigForm::class);
        $form->init();
        $html = '<p>'
            . sprintf(
                $renderer->translate('XML files will be rebuilt for all PDF files of your Omeka install'), // @translate
                '<code>', '</code>'
            )
            . '</p>';
        $html .= $renderer->formCollection($form);
        return $html;
    }

    public function handleConfigForm(AbstractController $controller)
    {
        list($basePath, $baseUri) = $this->getPathConfig();

        $services = $this->getServiceLocator();
        $form = $services->get('FormElementManager')->get(ConfigForm::class);
        $logger = $services->get('Omeka\Logger');
        $logger->info('ExtractOCR in bulk mode');

        $params = $controller->getRequest()->getPost();

        $form->init();
        $form->setData($params);
        if (!$form->isValid()) {
            $controller->messenger()->addErrors($form->getMessages());
            return false;
        }

        $params = $form->getData();

        if (empty($params['process']) || $params['process'] !== $controller->translate('Process')) {
            $message = 'No job launched.'; // @translate
            $controller->messenger()->addWarning($message);
            return;
        }

        unset($params['csrf']);
        unset($params['process']);

        // We are going to send the item to be processed
        $api = $services->get('Omeka\ApiManager');
        $response = $api->search('media', ['media_type' => 'application/pdf'])->getContent();

        $countPdf = 0;
        $countProcessing = 0;
        foreach ($response as $media) {
            $fileExt = $media->extension();

            if (in_array($fileExt, ['pdf', 'PDF'])) {
                $logger->info(sprintf('Extracting OCR for %s', $media->source()));
                $countPdf++;
                $targetFilename = sprintf('%s.%s', basename($media->source(), '.pdf'), 'xml');

                $searchXmlFile = $this->getMediaFromFilename($media->item()->id(), $targetFilename);

                $toProcess = false;
                if ($params['override'] == 1) {
                    $toProcess = true;
                    if ($searchXmlFile) {
                        $logger->info('XML already exists and override set to true, we are going to delete');
                        $api->delete('media', $searchXmlFile->id());
                    }
                } elseif (!$searchXmlFile) {
                    $toProcess = true;
                    $logger->info('XML file does not exist, we are going to create it');
                } else {
                    $logger->info('XML file already exists, override not set, skipping');
                }

                if ($toProcess === true) {
                    $countProcessing++;
                    $this->startExtractOcrJob(
                        $media->item()->id(),
                        $targetFilename,
                        $media->storageId(),
                        $media->extension(),
                        $basePath,
                        $baseUri
                    );
                }
            }
        }

        $message = new Message(sprintf(
            'Creating Extract OCR files in background (%s PDF, %s XML will be created)', // @translate,
            $countPdf,
            $countProcessing
        ));
        $controller->messenger()->addSuccess($message);
    }

    /**
     * Attach listeners to events.
     *
     * @param SharedEventManagerInterface $sharedEventManager
     */
    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.create.post',
            [$this, 'extractOcr']
        );

        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.update.post',
            [$this, 'extractOcr']
        );
    }

    // TODO add parameter for xml storage path.
    protected function getPathConfig()
    {
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

    /**
     * @brief launch extractOcr's job
     * @param Event $event
     */
    public function extractOcr(\Zend\EventManager\Event $event)
    {
        list($basePath, $baseUri) = $this->getPathConfig();

        $response = $event->getParams()['response'];
        $item = $response->getContent();

        foreach ($item->getMedia() as $media) {
            $fileExt = $media->getExtension();
            if (in_array($fileExt, ['pdf', 'PDF'])) {
                $targetFilename = sprintf('%s.%s', basename($media->getSource(), '.pdf'), 'xml');

                if (!$this->getMediaFromFilename($item->getId(), $targetFilename)) {
                    $this->startExtractOcrJob(
                        $media->getItem()->getId(),
                        $targetFilename,
                        $media->getStorageId(),
                        $media->getExtension(),
                        $basePath,
                        $baseUri
                    );
                }
            }
        }
    }

    private function startExtractOcrJob($itemId, $filename, $storageId, $extension, $basePath, $baseUri)
    {
        $this->serviceLocator->get('Omeka\Job\Dispatcher')->dispatch('ExtractOcr\Job\ExtractOcr',
            [
                'itemId' => $itemId,
                'filename' => $filename,
                'storageId' => $storageId,
                'extension' => $extension,
                'basePath' => $basePath ,
                'baseUri' => $baseUri,
            ]);
    }

    private function getMediaFromFilename($item_id, $filename)
    {
        $services = $this->getServiceLocator();
        $api = $services->get('Omeka\ApiManager');

        $searchXmlFile = $api->search('media', ['item_id' => $item_id, 'o:source' => $filename])->getContent();
        if (sizeof($searchXmlFile) == 0) {
            return false;
        }
        return $searchXmlFile[0];
    }
}
