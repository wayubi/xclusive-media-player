<?php

require_once __DIR__ . '/ActionHandler.php';

class MetadataAction extends ActionHandler
{
    public function handle(): void
    {
        $files = $this->getFiles();
        
        if (empty($files)) {
            $this->error('No files provided');
        }

        if (count($files) === 1) {
            $this->handleSingle($files[0]);
        } else {
            $this->handleBatch($files);
        }
    }

    private function handleSingle(string $file): void
    {
        $webPath = Utils::filesystemToWebPath($file, $this->root);
        $meta = $this->getMetadataForFile($webPath, $file);

        if ($meta === null) {
            $this->error('Invalid file path: ' . base64_encode($file));
        }

        // Return keyed format (like batch) - meta already has base64-encoded file from getMetadataForFile
        $key = base64_encode($file);
        $this->json([$key => $meta]);
    }

    private function handleBatch(array $files): void
    {
        $maxBatch = 36;
        $files = array_slice($files, 0, $maxBatch);

        $webPaths = [];
        $fsPaths = [];
        foreach ($files as $file) {
            $webPaths[] = Utils::filesystemToWebPath($file, $this->root);
            $fsPaths[] = $file;
        }

        $results = $this->metaDb->getMetadataBatch($webPaths, $fsPaths);

        // Map webPath results back to fsPath keys for JavaScript
        $fsKeyedResults = [];
        foreach ($fsPaths as $i => $fsPath) {
            $webPath = $webPaths[$i];
            $key = base64_encode($fsPath);  // Base64 for JS safety
            $fsKeyedResults[$key] = $results[$webPath] ?? null;
        }

        $this->json($fsKeyedResults);
    }

    private function getMetadataForFile(string $webPath, string $fsPath): ?array
    {
        if (!is_file($fsPath)) {
            return ['file' => base64_encode(basename($webPath)), 'folder' => '', 'optimizationStatus' => ['isOptimized' => true, 'issues' => []]];
        }

        $metadata = $this->metaDb->getMetadata($webPath, $fsPath);
        if ($metadata !== null) {
            // Database returns base64-encoded file already from rowToMetadata
            return $metadata;
        }

        // Fresh extraction - need to base64 encode the filename
        $output = MetadataExtractor::extract($fsPath);
        $this->metaDb->saveMetadata($webPath, $fsPath, $output);
        $output['file'] = base64_encode($output['file']);
        return $output;
    }
}
