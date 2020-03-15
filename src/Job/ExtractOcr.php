<?php
namespace ExtractOcr\Job;

use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Job\AbstractJob;
use Omeka\Stdlib\Message;

class ExtractOcr extends AbstractJob
{
    protected $basePath;

    protected $baseUri;

    /**
     * @brief Attach attracted ocr data from pdf with item
     */
    public function perform()
    {
        $this->basePath = $this->getArg('basePath');
        $this->baseUri = $this->getArg('baseUri');

        $services = $this->getServiceLocator();
        $apiManager = $this->getServiceLocator()->get('Omeka\ApiManager');
        $itemId = $this->getArg('itemId');
        $filename = $this->getArg('filename');
        $storageId = $this->getArg('storageId');
        $extension = $this->getArg('extension');

        $logger = $services->get('Omeka\Logger');
        $logger->info(new Message(
            'Extracting OCR from item #%s.', //  @translate
            $itemId
        ));

        $filePath = sprintf('%s/%s', $this->basePath, $storageId . '.' . $extension);

        $this->pdfToText($filePath, $storageId);

        $data = [
            'o:ingester' => 'url',
            'file_index' => 0,
            'o:item' => [
                'o:id' => $itemId,
            ],
            'ingest_url' => sprintf('%s/%s', $this->baseUri, $storageId .'.xml'),
            'o:source' => $filename,
        ];

        $media = $apiManager->create('media', $data)->getContent();

        // Save the xml file as the last media to avoid thumbnails issues.
        if ($media) {
            $this->reorderMedias($media);
        }
    }

    /**
     * Extract and store OCR Data from pdf in .xml file
     *
     * @param $path pdf file's path
     * @param $filename pdf filename on omeka after import
     */
    protected function pdfToText($path, $filename)
    {
        $path = escapeshellarg($path);
        $xmlFilePath = sprintf('%s/%s', $this->basePath, $filename);
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
