<?php declare(strict_types=1);
namespace ExtractOcr\Job;

use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\File\TempFile;
use Omeka\Job\AbstractJob;
use Omeka\Stdlib\Message;

class ExtractOcr extends AbstractJob
{
    /**
     * Limit for the loop to avoid heavy sql requests.
     *
     * @var int
     */
    const SQL_LIMIT = 25;

    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

    /**
     * @var \Omeka\File\TempFileFactory
     */
    protected $tempFileFactory;

    /**
     * @var \Omeka\Stdlib\Cli
     */
    protected $cli;

    /**
     * @var string
     */
    protected $basePath;

    /**
     * @var string
     */
    protected $baseUri;

    /**
     * @var array
     */
    protected $store = [
        'item' => false,
        'media_pdf' => false,
        'media_xml' => false,
    ];

    /**
     * @var \Omeka\Api\Representation\PropertyRepresentation|null
     */
    protected $property;

    /**
     * @var string
     */
    protected $language;

    /**
     * @var bool
     */
    protected $createEmptyXml;

    /**
     * @var array
     */
    protected $contentValue;

    /**
     * @brief Attach attracted ocr data from pdf with item
     */
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $this->logger = $services->get('Omeka\Logger');
        $this->api = $services->get('Omeka\ApiManager');
        $this->tempFileFactory = $services->get('Omeka\File\TempFileFactory');
        $this->cli = $services->get('Omeka\Cli');
        $this->baseUri = $this->getArg('baseUri');
        $this->basePath = $services->get('Config')['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        if (!$this->checkDir($this->basePath . '/temp')) {
            $this->logger->err(new Message(
                'The temporary directory "files/temp" is not writeable. Fix rights or create it manually.' // @translate
            ));
            return;
        }

        $settings = $services->get('Omeka\Settings');
        $override = $this->getArg('override');
        $itemId = $this->getArg('itemId');

        // TODO Manage the case where there are multiple pdf by item (rare).

        $contentStore = array_filter($settings->get('extractocr_content_store'));
        if ($contentStore) {
            $prop = $settings->get('extractocr_content_property');
            if ($prop) {
                $prop = $this->api->search('properties', ['term' => $prop])->getContent();
                if ($prop) {
                    $this->property = reset($prop);
                    $this->language = $settings->get('extractocr_content_language');
                    $this->store['item'] = in_array('item', $contentStore) && !$this->getArg('manual');
                    $this->store['media_pdf'] = in_array('media_pdf', $contentStore);
                    $this->store['media_xml'] = in_array('media_xml', $contentStore);
                }
            }
            if (!$this->property) {
                $this->logger->warn(new Message(
                    'The option to store text is set, but no property is defined.' // @translate
                ));
            }
        }

        $this->createEmptyXml = (bool) $settings->get('extractocr_create_empty_xml');

        // TODO The media type can be non-standard for pdf (text/pdf…) on old servers.
        $query = [
            'media_type' => 'application/pdf',
            'extension' => 'pdf',
        ];
        if ($itemId) {
            $query['item_id'] = $itemId;
        }

        /** @var \Omeka\Api\Representation\MediaRepresentation[] $medias */
        $response = $this->api->search('media', $query, ['returnScalar' => 'id']);
        $totalToProcess = $response->getTotalResults();
        if (empty($totalToProcess)) {
            $message = new Message('No item with a pdf to process.'); // @translate,
            $this->logger->notice($message);
            return;
        }

        $pdfMediaIds = $response->getContent();

        if ($override) {
            $message = new Message(sprintf(
                'Creating Extract OCR xml files for %s PDF, xml files will be overridden or created.', // @translate,
                $totalToProcess
            ));
        } else {
            $message = new Message(sprintf(
                'Creating Extract OCR xml files for %s PDF, without overriding existing xml.', // @translate,
                $totalToProcess
            ));
        }
        $this->logger->info($message);

        $countPdf = 0;
        $countSkipped = 0;
        $countFailed = 0;
        $countProcessed = 0;
        foreach ($pdfMediaIds as $pdfMediaId) {
            if ($this->shouldStop()) {
                if ($override) {
                    $this->logger->warn(new Message(
                        'The job "Extract OCR" was stopped: %1$d/%2$d resources processed, %3$d failed.', // @translate
                        $countProcessed, $totalToProcess, $countFailed
                    ));
                } else {
                    $this->logger->warn(new Message(
                        'The job "Extract OCR" was stopped: %1$d/%2$d resources processed, %3$d skipped, %4$d failed.', // @translate
                        $countProcessed, $totalToProcess, $countSkipped, $countFailed
                    ));
                }
                return;
            }

            $pdfMedia = $this->api->read('media', ['id' => $pdfMediaId])->getContent();
            $item = $pdfMedia->item();

            // Search if this item has already an xml file.
            $targetFilename = basename($pdfMedia->source(), '.pdf') . '.xml';
            // TODO Improve search of an existing xml, that can be imported separatly, or that can be another xml format with the same name.
            $searchXmlFile = $this->getMediaFromFilename($item->id(), $targetFilename, 'xml');

            ++$countPdf;
            $this->logger->info(sprintf('Index #%1$d/%2$d: Extracting OCR for item #%3$d, media #%4$d "%5$s".', // @translate
                $countPdf, $totalToProcess, $item->id(), $pdfMedia->id(), $pdfMedia->source()));

            if ($override) {
                if ($searchXmlFile) {
                    $this->api->delete('media', $searchXmlFile->id());
                    $this->logger->info('The existing XML was removed.'); // @translate
                }
            } elseif ($searchXmlFile) {
                $this->logger->info('An XML file already exists and override is not set. Item is skipped.'); // @translate
                ++$countSkipped;
                continue;
            }

            $this->contentValue = null;
            $xmlMedia = $this->extractOcrForMedia($pdfMedia);
            if ($xmlMedia) {
                $this->logger->info(sprintf('Media #%1$d created for xml file.', // @translate
                    $xmlMedia->id()));
                if ($this->store['item']) {
                    $this->storeContentInProperty($item);
                }
                ++$countProcessed;
            } else {
                ++$countFailed;
            }

            // Avoid memory issue.
            unset($pdfMedia);
            unset($xmlMedia);
            unset($item);
        }

        if ($override) {
            $message = new Message(sprintf(
                'Processed %1$d/%2$d pdf files, %3$d xml files created, %4$d failed.', // @translate,
                $countPdf, $totalToProcess, $countProcessed, $countFailed
            ));
        } else {
            $message = new Message(sprintf(
                'Processed %1$d/%2$d pdf files, %3$d skipped, %4$d xml files created, %5$d failed.', // @translate,
                $countPdf, $totalToProcess, $countSkipped, $countProcessed, $countFailed
            ));
        }
        $this->logger->notice($message);
    }

    /**
     * Get a media from item id, source name and extension.
     *
     * @todo Improve search of ocr pdf2xml files.
     *
     * @param int $itemId
     * @param string $filename
     * @param string $extension
     * @return MediaRepresentation|null
     */
    protected function getMediaFromFilename($itemId, $filename, $extension)
    {
        // The api search() doesn't allow to search a source, so we use read().
        try {
            return $this->api->read('media', [
                'item' => $itemId,
                'source' => $filename,
                'extension' => $extension,
            ])->getContent();
        } catch (\Omeka\Api\Exception\NotFoundException $e) {
        }
        return null;
    }

    /**
     * @param MediaRepresentation $pdfMedia
     * @return MediaRepresentation|null The xml media.
     */
    protected function extractOcrForMedia(MediaRepresentation $pdfMedia)
    {
        $pdfFilepath = $this->basePath . '/original/' . $pdfMedia->filename();
        if (!file_exists($pdfFilepath)) {
            $this->logger->err(sprintf('Missing pdf file (media #%1$d).', $pdfMedia->id())); // @translate
            return null;
        }

        // Do the conversion of the pdf to xml.
        $xmlTempFile = $this->pdfToText($pdfFilepath);
        if (empty($xmlTempFile)) {
            $this->logger->err(sprintf('Xml file was not created for media #%1$s.', $pdfMedia->id())); // @translate
            return null;
        }

        $content = file_get_contents($xmlTempFile->getTempPath());
        $content = trim(strip_tags($content));
        if (!$this->createEmptyXml && !strlen($content)) {
            $xmlTempFile->delete();
            $this->logger->notice(new Message('The xml for pdf #%1$d has no text content and is not created.', // @translate
                $pdfMedia->id()
            ));
            return null;
        }

        // It's not possible to save a local file via the "upload" ingester. So
        // the ingester "url" can be used, but it requires the file to be in the
        // omeka files directory. Else, use module FileSideload or inject sql.
        $xmlStoredFile = $this->makeTempFileDownloadable($xmlTempFile, '/extractocr');
        if (!$xmlStoredFile) {
            $xmlTempFile->delete();
            return null;
        }

        // This data is important to get the matching pdf and xml.
        $source = basename($pdfMedia->source(), '.pdf') . '.xml';

        $data = [
            'o:ingester' => 'url',
            'o:item' => [
                'o:id' => $pdfMedia->item()->id(),
            ],
            'o:source' => $source,
            'ingest_url' => $xmlStoredFile['url'],
            'file_index' => 0,
            'values_json' => '{}',
            'o:lang' => $this->language,
        ];

        if ($this->property && strlen($content)) {
            $this->contentValue = [
                'type' => 'literal',
                'property_id' => $this->property->id(),
                '@value' => $content,
                '@language' => $this->language,
            ];
            if ($this->store['media_pdf']) {
                $this->storeContentInProperty($pdfMedia);
            }
            if ($this->store['media_xml']) {
                $data[$this->property->term()][] = $this->contentValue;
                $data['dcterms:isFormatOf'][] = [
                    'type' => 'resource:media',
                    // dcterms:isFormatOf.
                    'property_id' => 37,
                    'value_resource_id' => $pdfMedia->id(),
                ];
            }
        }

        try {
            $media = $this->api->create('media', $data, [])->getContent();
        } catch (\Omeka\Api\Exception\ExceptionInterface $e) {
            // Generally a bad or missing pdf file.
            $this->logger->err($e->getMessage() ?: $e);
            return null;
        } catch (\Exception $e) {
            $this->logger->err($e);
            return null;
        } finally {
            $xmlTempFile->delete();
            @unlink($xmlStoredFile['filepath']);
        }

        // Move the xml file as the last media to avoid thumbnails issues.
        if ($media) {
            $this->reorderMedias($media);
            return $media;
        }

        return null;
    }

    /**
     * Extract and store OCR Data from pdf in .xml file
     *
     * @param string $pdfFilepath
     * @return \Omeka\File\TempFile|null
     */
    protected function pdfToText($pdfFilepath)
    {
        $tempFile = $this->tempFileFactory->build();
        $xmlFilepath = $tempFile->getTempPath() . '.xml';
        @unlink($tempFile->getTempPath());
        $tempFile->setTempPath($xmlFilepath);

        $pdfFilepath = escapeshellarg($pdfFilepath);
        $xmlFilepath = escapeshellarg($xmlFilepath);

        $command = "pdftohtml -i -c -hidden -xml $pdfFilepath $xmlFilepath";

        $result = $this->cli->execute($command);
        if ($result === false) {
            $tempFile->delete();
            return null;
        }

        return $tempFile;
    }

    /**
     * Append the content text to a resource.
     *
     * A check is done to avoid to duplicate content.
     *
     * @param AbstractResourceEntityRepresentation $resource
     */
    protected function storeContentInProperty(AbstractResourceEntityRepresentation $resource): void
    {
        if (empty($this->contentValue)) {
            return;
        }

        foreach ($resource->value($this->property->term(), ['all' => true]) as $v) {
            if ($v->value() === $this->contentValue['@value']) {
                return;
            }
        }

        $this->api->update(
            $resource->resourceName(),
            $resource->id(),
            [$this->property->term() => [$this->contentValue]],
            [],
            ['isPartial' => true, 'collectionAction' => 'append']
        );
    }

    /**
     * Move a media at the last position of the item.
     *
     * @see \CSVImport\Job\Import::reorderMedias()
     *
     * @todo Move this process in the core.
     *
     * @param MediaRepresentation $media
     */
    protected function reorderMedias(MediaRepresentation $media): void
    {
        // Note: the position is not available in representation.

        $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');
        $mediaRepository = $entityManager->getRepository(\Omeka\Entity\Media::class);
        $medias = $mediaRepository->findBy(['item' => $media->item()->id()]);
        if (count($medias) <= 1) {
            return;
        }

        $lastMedia = null;
        $lastMediaId = (int) $media->id();
        $key = 0;
        foreach ($medias as $itemMedia) {
            $itemMediaId = (int) $itemMedia->getId();
            if ($itemMediaId !== $lastMediaId) {
                $itemMedia->setPosition(++$key);
            } else {
                $lastMedia = $itemMedia;
            }
        }
        $lastMedia->setPosition(++$key);

        // Flush one time to use a transaction and to avoid a duplicate issue
        // with the index item_id/position.
        $entityManager->flush();
    }

    /**
     * Save a temp file into the files/temp directory.
     *
     * @see \Ebook\Mvc\Controller\Plugin\Ebook::saveFile()
     *
     * @param TempFile $source
     * @param string $base
     * @return array|null
     */
    protected function makeTempFileDownloadable(TempFile $tempFile, $base = '')
    {
        $baseDestination = '/temp';
        $destinationDir = $this->basePath . $baseDestination . $base;
        if (!$this->checkDir($destinationDir)) {
            return null;
        }

        $source = $tempFile->getTempPath();

        // Find a unique meaningful filename instead of a hash.
        $name = date('Ymd_His') . '_pdf2xml';
        $extension = 'xml';
        $i = 0;
        do {
            $filename = $name . ($i ? '-' . $i : '') . '.' . $extension;
            $destination = $destinationDir . '/' . $filename;
            if (!file_exists($destination)) {
                $result = @copy($source, $destination);
                if (!$result) {
                    $this->logger->err(new Message('File cannot be saved in temporary directory "%1$s" (temp file: "%2$s")', // @translate
                        $destination, $source));
                    return null;
                }
                $storageId = $base . $name . ($i ? '-' . $i : '');
                break;
            }
        } while (++$i);

        return [
            'filepath' => $destination,
            'filename' => $filename,
            'url' => $this->baseUri . $baseDestination . $base . '/' . $filename,
            'url_file' => $baseDestination . $base . '/' . $filename,
            'storageId' => $storageId,
        ];
    }

    /**
     * Check or create the destination folder.
     *
     * @param string $dirPath
     * @return bool
     */
    protected function checkDir($dirPath)
    {
        if (!file_exists($dirPath)) {
            if (!is_writeable($this->basePath)) {
                $this->logger->err(new Message('Temporary destination for XML can not be created : %1$s', // @translate
                $dirPath));
                return false;
            }
            @mkdir($dirPath, 0755, true);
        } elseif (!is_dir($dirPath) || !is_writeable($dirPath)) {
            return false;
        }
        return true;
    }
}
