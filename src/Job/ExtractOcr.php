<?php
namespace ExtractOcr\Job;

use Omeka\Api\Representation\MediaRepresentation;
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
     * @var \Zend\Log\Logger
     */
    protected $logger;

    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

    /**
     * @var string
     */
    protected $basePath;

    /**
     * @var string
     */
    protected $baseUri;

    /**
     * @brief Attach attracted ocr data from pdf with item
     */
    public function perform()
    {
        $this->basePath = $this->getArg('basePath');
        $this->baseUri = $this->getArg('baseUri');

        $services = $this->getServiceLocator();
        $this->logger = $services->get('Omeka\Logger');
        $this->api = $services->get('Omeka\ApiManager');
        $override = $this->getArg('override');
        $itemId = $this->getArg('itemId');

        // TODO Manage the case where there are multiple pdf by item (rare).

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
        $countProcessed = 0;
        foreach ($pdfMediaIds as $pdfMediaId) {
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

            $this->extractOcrForMedia($pdfMedia, $targetFilename);
            ++$countProcessed;

            // Avoid memory issue.
            unset($pdfMedia);
            unset($item);
        }

        if ($override) {
            $message = new Message(sprintf(
                'Processed %1$d/%2$d pdf files, %3$d xml files created.', // @translate,
                $countPdf, $totalToProcess, $countProcessed
            ));
        } else {
            $message = new Message(sprintf(
                'Processed %1$d/%2$d pdf files, %3$d skipped, %4$d xml files created.', // @translate,
                $countPdf, $totalToProcess, $countSkipped, $countProcessed
            ));
        }
        $this->logger->notice($message);
    }

    protected function extractOcrForMedia(MediaRepresentation $pdfMedia, $filename)
    {
        $filePath = sprintf('%s/%s', $this->basePath, $pdfMedia->storageId() . '.' . $pdfMedia->extension());

        // Do the conversion of the pdf to xml.
        $this->pdfToText($filePath, $pdfMedia->storageId());

        $data = [
            'o:ingester' => 'url',
            'file_index' => 0,
            'o:item' => [
                'o:id' => $pdfMedia->item()->id(),
            ],
            'ingest_url' => $this->baseUri . '/' . $pdfMedia->storageId() . '.xml',
            'o:source' => $filename,
        ];

        $media = $this->api->create('media', $data)->getContent();

        // Save the xml file as the last media to avoid thumbnails issues.
        if ($media) {
            $this->reorderMedias($media);
        }
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
     * Extract and store OCR Data from pdf in .xml file
     *
     * @todo Use Omeka CLI service.
     *
     * @param string $path File's path of the pdf
     * @param string $filename File name on Omeka after import
     */
    protected function pdfToText($path, $filename)
    {
        $path = escapeshellarg($path);
        $xmlFilePath = $this->basePath . '/' . $filename;
        $xmlFilePath = escapeshellarg($xmlFilePath);

        $cmd = "pdftohtml -i -c -hidden -xml $path $xmlFilePath";

        shell_exec($cmd);
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
    protected function reorderMedias(MediaRepresentation $media)
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
}
