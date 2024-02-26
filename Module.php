<?php declare(strict_types=1);

namespace ExtractOcr;

use ExtractOcr\Form\ConfigForm;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Module\AbstractModule;
use Omeka\Module\Exception\ModuleCannotInstallException;
use Omeka\Stdlib\Message;

class Module extends AbstractModule
{
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function install(ServiceLocatorInterface $services): void
    {
        $this->setServiceLocator($services);
        $t = $services->get('MvcTranslator');

        // Don't install if the pdftotext command doesn't exist.
        // See: http://stackoverflow.com/questions/592620/check-if-a-program-exists-from-a-bash-script
        if ((int) shell_exec('hash pdftotext 2>&- || echo 1')) {
            throw new ModuleCannotInstallException(
                $t->translate('The pdftotext command-line utility is not installed. pdftotext must be installed to install this plugin.') //@translate
            );
        }

        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');

        if (!$this->checkDestinationDir($basePath . '/temp')) {
            $message = new Message(
                $t->translate('The directory "%s" is not writeable. Fix rights or create it manually.'), // @translate
                $basePath . '/temp'
            );
            throw new ModuleCannotInstallException($message);
        }

        if (!$this->checkDestinationDir($basePath . '/iiif-search')) {
            $message = new Message(
                $t->translate('The directory "%s" is not writeable. Fix rights or create it manually.'), // @translate
                $basePath . '/iiif-search'
            );
            throw new ModuleCannotInstallException($message);
        }

        $isOldOmeka = version_compare(\Omeka\Module::VERSION, '3.1', '<');
        $baseUri = $config['file_store']['local']['base_uri'];
        if (!$baseUri && $isOldOmeka) {
            $this->setServiceLocator($services);
            $baseUri = $this->getBaseUri();
            $message = new Message(
                $t->translate('The base uri "%s" is not set in the config file of Omeka "config/local.config.php". It must be set for technical reasons for now.'), //@translate
                $baseUri
            );
            throw new ModuleCannotInstallException($message);
        }

        $settings = $services->get('Omeka\Settings');
        $config = require __DIR__ . '/config/module.config.php';
        $config = $config['extractocr']['config'];
        foreach ($config as $name => $value) {
            $settings->set($name, $value);
        }

        $this->allowFileFormats();
    }

    public function uninstall(ServiceLocatorInterface $services): void
    {
        $settings = $services->get('Omeka\Settings');
        $config = require __DIR__ . '/config/module.config.php';
        $config = $config['extractocr']['config'];
        foreach (array_keys($config) as $name) {
            $settings->delete($name);
        }
    }

    public function upgrade($oldVersion, $newVersion, ServiceLocatorInterface $services)
    {
        $this->setServiceLocator($services);

        $plugins = $services->get('ControllerPluginManager');
        $settings = $services->get('Omeka\Settings');
        $messenger = $plugins->get('messenger');

        if (version_compare((string) $oldVersion, '3.4.5', '<')) {
            $message = new Message('A new option allows to create xml as alto multi-pages.'); // @translate
            // Default is alto on install, but pdf2xml during upgrade.
            $settings->set('extractocr_media_type', 'application/vnd.pdf2xml+xml');
            $messenger->addSuccess($message);
        }

        if (version_compare((string) $oldVersion, '3.4.6', '<')) {
            $settings->set('extractocr_create_empty_file', $settings->get('extractocr_create_empty_xml', false));
            $settings->delete('extractocr_create_empty_xml');
            $message = new Message('A new option allows to export OCR into tsv format for quicker search results. Data should be reindexed with format TSV.'); // @translate
            $messenger->addSuccess($message);
        }

        if (version_compare((string) $oldVersion, '3.4.7', '<')) {
            $this->setServiceLocator($services);
            $t = $services->get('MvcTranslator');
            $config = $services->get('Config');
            $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
            if (!$this->checkDestinationDir($basePath . '/iiif-search')) {
                $message = new Message(
                    $t->translate('The directory "%s" is not writeable. Fix rights or create it manually.'), // @translate
                    $basePath . '/iiif-search'
                );
                throw new ModuleCannotInstallException($message);
            }

            $contentStore = $settings->get('extractocr_content_store', []);
            $pos = array_search('media_xml', $contentStore);
            if ($pos !== false) {
                unset($contentStore[$pos]);
                $contentStore[] = 'media_extracted';
                $settings->set('extractocr_content_store', array_values($contentStore));
            }

            $settings->set('extractocr_create_media', true);
            $message = new Message(
                'A new option allows to store the file separately of the item. You can enable it by default.' // @translate
            );
            $messenger->addSuccess($message);
        }

        $this->allowFileFormats();
    }

    /**
     * Attach listeners to events.
     *
     * @param SharedEventManagerInterface $sharedEventManager
     */
    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
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
     * Allow TSV and XML extensions and media types in omeka settings.
     */
    protected function allowFileFormats(): void
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');

        $extensionWhitelist = $settings->get('extension_whitelist', []);
        $extensions = [
            'tsv',
            'xml',
        ];
        $extensionWhitelist = array_unique(array_merge($extensionWhitelist, $extensions));
        $settings->set('extension_whitelist', $extensionWhitelist);

        $mediaTypeWhitelist = $settings->get('media_type_whitelist');
        $xmlMediaTypes = [
            'application/xml',
            'text/xml',
            'application/alto+xml',
            'application/vnd.pdf2xml+xml',
            'application/x-empty',
            'text/tab-separated-values',
        ];
        $mediaTypeWhitelist = array_unique(array_merge($mediaTypeWhitelist, $xmlMediaTypes));
        $settings->set('media_type_whitelist', $mediaTypeWhitelist);
    }

    public function getConfigForm(PhpRenderer $renderer)
    {
        $this->allowFileFormats();

        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $form = $services->get('FormElementManager')->get(ConfigForm::class);
        $form->init();

        $config = require __DIR__ . '/config/module.config.php';
        $config = $config['extractocr']['config'];
        $data = [];
        foreach ($config as $name => $value) {
            $data[$name] = $settings->get($name, $value);
        }
        $form->setData($data);

        $html = '<p>'
            . $renderer->translate('Options are used during edition of items and for bulk processing.') // @translate
            . $renderer->translate('The insertion of the text in the item properties is currently not supported.') // @translate
            . ' ' . $renderer->translate('XML files will be rebuilt for all PDF files of your Omeka install.') // @translate
            . '</p>';
        $html .= $renderer->formCollection($form);
        return $html;
    }

    public function handleConfigForm(AbstractController $controller)
    {
        $services = $this->getServiceLocator();
        $form = $services->get('FormElementManager')->get(ConfigForm::class);

        /** @var \Laminas\Stdlib\Parameters $params */
        $params = $controller->getRequest()->getPost();

        $form->init();
        $form->setData($params);
        if (!$form->isValid()) {
            $controller->messenger()->addErrors($form->getMessages());
            return false;
        }

        $data = $form->getData();

        $settings = $services->get('Omeka\Settings');
        $settings->set('extractocr_create_media', !empty($data['extractocr_create_media']));
        $settings->set('extractocr_media_type', $data['extractocr_media_type'] ?: 'text/tab-separated-values');
        $settings->set('extractocr_content_store', $data['extractocr_content_store']);
        $settings->set('extractocr_content_property', $data['extractocr_content_property']);
        $settings->set('extractocr_content_language', $data['extractocr_content_language']);
        $settings->set('extractocr_create_empty_file', !empty($data['extractocr_create_empty_file']));

        // Keep only values used in job.
        $params = array_intersect_key($params->getArrayCopy(), [
            'mode' => 'all',
            'item_ids' => '',
            'process' => null,
        ]);
        if (empty($params['process']) || $params['process'] !== $controller->translate('Process')) {
            $message = 'No job launched.'; // @translate
            $controller->messenger()->addWarning($message);
            return true;
        }

        $args = [];
        $args['mode'] = $params['mode'] ?? 'all';
        $args['baseUri'] = $this->getBaseUri();
        $args['item_ids'] = $params['item_ids'] ?? '';

        $dispatcher = $services->get(\Omeka\Job\Dispatcher::class);
        $job = $dispatcher->dispatch(\ExtractOcr\Job\ExtractOcr::class, $args);

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
                class_exists('Log\Module')
                    ? htmlspecialchars($controller->url()->fromRoute('admin/default', ['controller' => 'log'], ['query' => ['job_id' => $job->getId()]]))
                    : htmlspecialchars($controller->url()->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId(), 'action' => 'log']))
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
    public function extractOcr(Event $event): void
    {
        $services = $this->getServiceLocator();
        $response = $event->getParams()['response'];
        /** @var \Omeka\Entity\Item $item */
        $item = $response->getContent();

        $settings = $services->get('Omeka\Settings');
        $targetMediaType = $settings->get('extractocr_media_type') ?? 'text/tab-separated-values';
        $targetExtension = $targetMediaType === 'text/tab-separated-values' ? '.tsv' : '.xml';

        $hasPdf = false;
        $targetFilename = null;
        /** @var \Omeka\Entity\Media $media */
        foreach ($item->getMedia() as $media) {
            $mediaType = $media->getMediaType();
            $extension = strtolower((string) $media->getExtension());
            if ($mediaType === 'application/pdf' && $extension === 'pdf') {
                $hasPdf = true;
                $source = (string) $media->getSource();
                $filename = (string) parse_url($source, PHP_URL_PATH);
                $targetFilename = strlen($filename)
                    ? basename($filename, '.pdf')
                    : $media->id() . '-' . $media->getStorageId();
                $targetFilename .= $targetExtension;
                break;
            }
        }

        if (!$hasPdf || $targetFilename === '.tsv' || $targetFilename === '.xml') {
            return;
        }

        // Don't override an already processed pdf when updating an item.
        if ($this->getMediaFromFilename($item->getId(), $targetFilename, 'tsv', $targetMediaType)) {
            return;
        }

        // Don't override an already processed pdf when updating an item.
        if ($this->getMediaFromFilename($item->getId(), $targetFilename, 'xml', $targetMediaType)) {
            return;
        }

        $params = [
            'mode' => 'all',
            'base_uri' => $this->getBaseUri(),
            'item_id' => $item->getId(),
            // FIXME Currently impossible to save text with event api.update.post;
            'manual' => true,
        ];
        $services->get('Omeka\Job\Dispatcher')->dispatch(\ExtractOcr\Job\ExtractOcr::class, $params);

        $messenger = $services->get('ControllerPluginManager')->get('messenger');
        $message = new Message('Extracting OCR in background.'); // @translate
        $messenger->addNotice($message);
    }

    /**
     * Get the first media from item id, source name, extension and media type.
     *
     * @todo Improve search of ocr pdf2xml files.
     *
     * Copy:
     * @see \ExtractOcr\Module::getMediaFromFilename()
     * @see \ExtractOcr\Job\ExtractOcr::getMediaFromFilename()
     *
     * @param int $itemId
     * @param string $filename
     * @param string $extension
     * @param string $mediaType
     * @return \Omeka\Api\Representation\MediaRepresentation|null
     */
    protected function getMediaFromFilename($itemId, $filename, $extension, $mediaType)
    {
        $services = $this->getServiceLocator();
        $api = $services->get('Omeka\ApiManager');

        // The api search() doesn't allow to search a source, so we use read().
        try {
            return $api->read('media', [
                'item' => $itemId,
                'source' => $filename,
                'extension' => $extension,
                'mediaType' => $mediaType,
            ])->getContent();
        } catch (\Omeka\Api\Exception\NotFoundException $e) {
        }
        return null;
    }

    /**
     * @todo Add parameter for xml storage path.
     * @todo To get the base uri is useless now, since base uri is passed as job argument.
     */
    protected function getBaseUri()
    {
        $services = $this->getServiceLocator();
        $config = $services->get('Config');
        $baseUri = $config['file_store']['local']['base_uri'];
        if (!$baseUri) {
            $helpers = $services->get('ViewHelperManager');
            $serverUrlHelper = $helpers->get('serverUrl');
            $basePathHelper = $helpers->get('basePath');
            $baseUri = $serverUrlHelper($basePathHelper('files'));
            if ($baseUri === 'http:///files' || $baseUri === 'https:///files') {
                $t = $services->get('MvcTranslator');
                throw new \Omeka\Mvc\Exception\RuntimeException(
                    sprintf(
                        $t->translate('The base uri is not set (key [file_store][local][base_uri]) in the config file of Omeka "config/local.config.php". It must be set for now (key [file_store][local][base_uri]) in order to process background jobs.'), //@translate
                        $baseUri
                    )
                );
            }
        }
        return $baseUri;
    }

    /**
     * Check or create the destination folder.
     *
     * @param string $dirPath Absolute path.
     * @return string|null
     */
    protected function checkDestinationDir(string $dirPath): ?string
    {
        if (file_exists($dirPath)) {
            if (!is_dir($dirPath) || !is_readable($dirPath) || !is_writeable($dirPath)) {
                $this->getServiceLocator()->get('Omeka\Logger')->err(new Message(
                    'The directory "%s" is not writeable.', // @translate
                    $dirPath
                ));
                return null;
            }
            return $dirPath;
        }

        $result = @mkdir($dirPath, 0775, true);
        if (!$result) {
            $this->getServiceLocator()->get('Omeka\Logger')->err(new Message(
                'The directory "%1$s" is not writeable: %2$s.', // @translate
                $dirPath, error_get_last()['message']
            ));
            return null;
        }
        return $dirPath;
    }
}
