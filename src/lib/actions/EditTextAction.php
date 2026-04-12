<?php

require_once __DIR__ . '/ActionHandler.php';
require_once __DIR__ . '/../Utils.php';
require_once __DIR__ . '/../MetadataExtractor.php';

class EditTextAction extends ActionHandler
{
    public function handle(): void
    {
        $file = $this->data['file'] ?? null;
        $content = $this->data['content'] ?? null;

        if (!$file) {
            $this->error('No file path provided');
        }

        if ($content === null) {
            $this->error('No content provided');
        }

        $fsPath = Utils::resolveWebPathForFile($file, $this->root);

        if (!$fsPath || !file_exists($fsPath)) {
            $this->error('File not found');
        }

        if (!is_file($fsPath)) {
            $this->error('Path is not a file');
        }

        $dir = dirname($fsPath);
        $base = basename($fsPath);
        $timestamp = time();
        $trashDir = $dir . '/.trash-' . $timestamp;

        $checkTrashDir = shell_exec('test -d ' . escapeshellarg($trashDir) . ' && echo 1 || echo 0');
        if (trim($checkTrashDir) !== '1') {
            $mkResult = shell_exec('mkdir -p ' . escapeshellarg($trashDir) . ' 2>&1');
            if (!empty($mkResult)) {
                $this->error('Failed to create trash directory', 500);
            }
        }

        $trashPath = $trashDir . '/' . $base;
        $checkExists = shell_exec('test -e ' . escapeshellarg($trashPath) . ' && echo 1 || echo 0');
        if (trim($checkExists) === '1') {
            $this->error('File already exists in trash - retry in a few seconds', 409);
        }

        $moveResult = shell_exec('mv ' . escapeshellarg($fsPath) . ' ' . escapeshellarg($trashPath) . ' 2>&1');
        if (!empty($moveResult)) {
            $this->error('Failed to move original file to trash: ' . $moveResult, 500);
        }

        $tempFile = $dir . '/.tmp.' . $base;
        $tempHandle = fopen($tempFile, 'w');
        if ($tempHandle === false) {
            shell_exec('mv ' . escapeshellarg($trashPath) . ' ' . escapeshellarg($fsPath));
            $this->error('Failed to create temp file', 500);
        }

        $written = fwrite($tempHandle, $content);
        fclose($tempHandle);

        if ($written === false) {
            unlink($tempFile);
            shell_exec('mv ' . escapeshellarg($trashPath) . ' ' . escapeshellarg($fsPath));
            $this->error('Failed to write new content', 500);
        }

        $moveResult = shell_exec('mv ' . escapeshellarg($tempFile) . ' ' . escapeshellarg($fsPath) . ' 2>&1');
        if (!empty($moveResult)) {
            $this->error('Failed to move new content: ' . $moveResult, 500);
        }

        $this->metaDb->deleteMetadata($fsPath);

        $webPath = Utils::filesystemToWebPath($fsPath, $this->root, '/volumes');
        $output = MetadataExtractor::extract($fsPath);
        $this->metaDb->saveMetadata($webPath, $fsPath, $output);

        $this->json([
            'status' => 'success',
            'message' => 'File saved successfully',
            'trash_path' => $trashDir
        ]);
    }
}