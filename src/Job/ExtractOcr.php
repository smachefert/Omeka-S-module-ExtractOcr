<?php
namespace ExtractOcr\Job;

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

        $fileIndex = 0;
        $data = [
            'o:ingester' => 'url',
            'file_index' => $fileIndex,
            'o:item' => [
                'o:id' => $itemId,
            ],
            'ingest_url' => sprintf('%s/%s', $this->baseUri, $storageId .'.xml'),
            'o:source' => $filename,
        ];

        $apiManager->create('media', $data);
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
}
