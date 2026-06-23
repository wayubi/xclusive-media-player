<?php

require_once __DIR__ . '/ActionHandler.php';

class FavoritesAction extends ActionHandler
{
    public function handle(): void
    {
        $mode = $this->data['mode'] ?? 'toggle';
        
        switch ($mode) {
            case 'toggle':
                $this->handleToggle();
                break;
            case 'status_batch':
                $this->handleStatusBatch();
                break;
            case 'get_count':
                $this->handleCount();
                break;
            default:
                $this->error('Unknown favorites mode');
        }
    }

    private function handleToggle(): void
    {
        $files = $this->getFiles();
        $file = $files[0] ?? null;

        if (!$file) {
            $this->error('Missing file');
        }

        $fsPath = urldecode($file);

        if (!str_starts_with($fsPath, $this->root) || !file_exists($fsPath)) {
            $this->error('Invalid file path: ' . base64_encode($file));
        }

        try {
            $favDb = new FavoritesDatabase();
            $isFavorited = $favDb->toggleFavorite($fsPath);

            $this->json([
                'status' => 'ok',
                'favorited' => $isFavorited,
                'file' => base64_encode($file)
            ]);
        } catch (Exception $e) {
            $this->error('Failed to toggle favorite: ' . $e->getMessage(), 500);
        }
    }

    private function handleStatusBatch(): void
    {
        $filePaths = $this->data['file_paths'] ?? [];

        if (!is_array($filePaths)) {
            $this->error('Invalid file_paths');
        }

        $filePaths = Utils::decodeBase64Paths($filePaths);

        try {
            $favDb = new FavoritesDatabase();
            $statuses = $favDb->getFavoriteStatusBatch($filePaths);

            $this->json([
                'status' => 'ok',
                'favorite_statuses' => Utils::encodeArrayKeysBase64($statuses)
            ]);
        } catch (Exception $e) {
            $this->error('Failed to get favorite status: ' . $e->getMessage(), 500);
        }
    }

    private function handleCount(): void
    {
        $folderPath = $this->data['folder_path'] ?? null;

        if (!$folderPath) {
            $this->error('Missing folder_path');
        }

        $fsFolderPath = Utils::resolveWebPathForDir($folderPath, $this->root);

        try {
            $favDb = new FavoritesDatabase();
            $count = $favDb->getFavoritesCountInFolder($fsFolderPath);

            $this->json([
                'status' => 'ok',
                'count' => $count
            ]);
        } catch (Exception $e) {
            $this->error('Failed to get favorites count: ' . $e->getMessage(), 500);
        }
    }
}
