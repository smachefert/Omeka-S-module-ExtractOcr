<?php

namespace ExtractOcr;

use ExtractOcr\Form\ConfigForm;
use ExtractOcr\Job\ExtractOcr;
use Omeka\Module\AbstractModule;
use Omeka\Module\Exception\ModuleCannotInstallException;
use Omeka\Settings\SettingsInterface;
use Omeka\Stdlib\Message;
use Zend\EventManager\Event;
use Zend\EventManager\SharedEventManagerInterface;
use Zend\Mvc\Controller\AbstractController;
use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\View\Renderer\PhpRenderer;

class Module extends AbstractModule
{
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function install(ServiceLocatorInterface $services)
    {
        $logger = $services->get('Omeka\Logger');
        $t = $services->get('MvcTranslator');
        // Don't install if the pdftotext command doesn't exist.
        // See: http://stackoverflow.com/questions/592620/check-if-a-program-exists-from-a-bash-script
        if ((int) shell_exec('hash pdftotext 2>&- || echo 1')) {
            $logger->info('pdftotext not found');
            throw new ModuleCannotInstallException(
                $t->translate('The pdftotext command-line utility is not installed. pdftotext must be installed to install this plugin.') //@translate
            );
        }

        $this->allowXML($services->get('Omeka\Settings'));
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

    /**
     * Allow XML's extension and media type in omeka's settings
     *
     * @param SettingsInterface
     */
    protected function allowXML(SettingsInterface $settings)
    {
        $extensionWhitelist = $settings->get('extension_whitelist', []);
        $xmlExtensions = [
            'xml',
        ];
        $extensionWhitelist = array_unique(array_merge($extensionWhitelist, $xmlExtensions));
        $settings->set('extension_whitelist', $extensionWhitelist);

        $mediaTypeWhitelist = $settings->get('media_type_whitelist');
        $xmlMediaTypes = [
            'application/xml',
            'text/xml',
            'application/vnd.pdf2xml+xml',
        ];
        $mediaTypeWhitelist = array_unique(array_merge($mediaTypeWhitelist, $xmlMediaTypes));
        $settings->set('media_type_whitelist', $mediaTypeWhitelist);
    }

    public function getConfigForm(PhpRenderer $renderer)
    {
        $services = $this->getServiceLocator();
        $form = $services->get('FormElementManager')->get(ConfigForm::class);
        $form->init();
        $html = '<p>'
            . sprintf(
                $renderer->translate('XML files will be rebuilt for all PDF files of your Omeka install.'), // @translate
                '<code>', '</code>'
            )
            . '</p>';
        $html .= $renderer->formCollection($form);
        return $html;
    }

    public function handleConfigForm(AbstractController $controller)
    {
        $services = $this->getServiceLocator();
        $form = $services->get('FormElementManager')->get(ConfigForm::class);

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
            return true;
        }

        unset($params['csrf']);
        unset($params['process']);
        $params['override'] = (bool) $params['override'];
        list($params['basePath'], $params['baseUri']) = $this->getPathConfig();

        $dispatcher = $services->get(\Omeka\Job\Dispatcher::class);
        $job = $dispatcher->dispatch(\ExtractOcr\Job\ExtractOcr::class, $params);

        $message = new Message(
            'Creating Extract OCR files in background (job %1$s#%2$s%3$s, %4$slogs%3$s).', // @translate
            sprintf(
                '<a href="%s">',
                htmlspecialchars($controller->url()->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]))
            ),
            $job->getId(),
            '</a>',
            sprintf(
                '<a href="%s">',
                htmlspecialchars($controller->url()->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId(), 'action' => 'log']))
            )
        );
        $message->setEscapeHtml(false);
        $controller->messenger()->addSuccess($message);
        return true;
    }

    /**
     * Launch extract ocr's job for an item.
     *
     * @param Event $event
     */
    public function extractOcr(Event $event)
    {
        $response = $event->getParams()['response'];
        /** @var \Omeka\Entity\Item $item */
        $item = $response->getContent();

        $hasPdf = false;
        $targetFilename = null;
        foreach ($item->getMedia() as $media) {
            if (strtolower($media->getExtension()) === 'pdf' && $media->getMediaType() === 'application/pdf') {
                $hasPdf = true;
                $targetFilename = basename($media->getSource(), '.pdf') . '.xml';
                break;
            }
        }

        if (!$hasPdf || $targetFilename === '.xml') {
            return;
        }

        // Don't override an already processed pdf when updating an item.
        if ($this->getMediaFromFilename($item->getId(), $targetFilename, 'xml')) {
            return;
        }

        $params = [
            'itemId' => $item->getId(),
            'override' => false,
        ];
        list($params['basePath'], $params['baseUri']) = $this->getPathConfig();
        $this->getServiceLocator()->get('Omeka\Job\Dispatcher')->dispatch(\ExtractOcr\Job\ExtractOcr::class, $params);
    }

    /**
     * Get a media from item id, source name and extension.
     *
     * @todo Improve search of ocr pdf2xml files.
     *
     * @param int $itemId
     * @param string $filename
     * @param string $extension
     * @return \Omeka\Api\Representation\MediaRepresentation|null
     */
    protected function getMediaFromFilename($itemId, $filename, $extension)
    {
        $services = $this->getServiceLocator();
        $api = $services->get('Omeka\ApiManager');

        // The api search() doesn't allow to search a source, so we use read().
        try {
            return $api->read('media', [
                'item' => $itemId,
                'source' => $filename,
                'extension' => $extension,
            ])->getContent();
        } catch (\Omeka\Api\Exception\NotFoundException $e) {
        }
        return null;
    }

    /**
     * @todo Add parameter for xml storage path.
     * @todo Use a factory.
     */
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

        return [
            $basePath . '/original',
            $baseUri . '/original',
        ];
    }
}
