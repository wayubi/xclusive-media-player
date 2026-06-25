<?php

require_once __DIR__ . '/ActionHandler.php';
require_once __DIR__ . '/../AuditDatabase.php';
require_once __DIR__ . '/../FavoritesDatabase.php';

class DeleteAction extends ActionHandler
{
    private ?AuditDatabase $auditDb = null;
    private ?FavoritesDatabase $favDb = null;

    private function getAuditDb(): AuditDatabase
    {
        if ($this->auditDb === null) {
            $this->auditDb = new AuditDatabase();
        }
        return $this->auditDb;
    }

    private function getFavDb(): FavoritesDatabase
    {
        if ($this->favDb === null) {
            $this->favDb = new FavoritesDatabase();
        }
        return $this->favDb;
    }

    public function handle(): void
    {
        $recursive = $this->data['recursive'] ?? false;
        $deleteFolder = $this->data['delete_folder'] ?? false;

        if ($recursive && $deleteFolder) {
            $this->handleRecursiveFolderDelete();
        } else {
            $this->handleFileDelete();
        }
    }

    private function handleRecursiveFolderDelete(): void
    {
        $folder = $this->data['folder'] ?? null;
        if (!$folder) {
            $this->error('No folder path provided for recursive deletion');
        }

        $fsPath = Utils::resolveWebPathForDir($folder, $this->root);

        if (!$fsPath || !str_starts_with($fsPath, $this->root) || !is_dir($fsPath)) {
            $this->error('Invalid folder path');
        }

        $parentPath = dirname($fsPath);
        $parentWebPath = '/volumes/' . ltrim(str_replace($this->root, '', $parentPath), '/');
        if ($parentWebPath === '/volumes/') {
            $parentWebPath = '/volumes';
        }

        // Check for favorited files that block deletion
        $favPaths = $this->getFavDb()->getFavoritedFilesInFolder($fsPath);

        if (!empty($favPaths)) {
            // Partial deletion — delete non-favorited files only
            $stats = ['files' => 0, 'hidden_files' => 0, 'subfolders' => 0, 'skipped_favorited' => count($favPaths)];
            $deletedFsPaths = [];
            $this->deleteRecursiveNonFavorited($fsPath, $favPaths, $stats, $deletedFsPaths);

            $deletedWebPaths = array_map(
                fn($p) => Utils::filesystemToWebPath($p, $this->root),
                $deletedFsPaths
            );

            $this->json([
                'status' => 'partial',
                'deleted' => $stats,
                'deleted_paths' => $deletedWebPaths,
                'skipped_favorited' => array_values($favPaths),
                'message' => "Deleted {$stats['files']} files, skipped {$stats['skipped_favorited']} favorited files"
            ]);
            return;
        }

        $stats = $this->countFolderContents($fsPath);
        $deleted = $this->deleteRecursive($fsPath);

        if ($deleted) {
            $this->json([
                'status' => 'success',
                'deleted' => $stats,
                'parent_path' => $parentWebPath,
                'message' => "Deleted {$stats['files']} files ({$stats['hidden_files']} hidden), {$stats['subfolders']} subfolders, and the folder"
            ]);
        } else {
            $this->error('Failed to delete folder', 500);
        }
    }

    private function handleFileDelete(): void
    {
        $files = $this->getFiles();
        if (empty($files)) {
            $this->error('No files provided');
        }

        $favDb = $this->getFavDb();
        $results = [];
        foreach ($files as $file) {
            $decodedFile = urldecode($file);
            $cleanFile = ltrim(preg_replace('#^/volumes/#i', '', $decodedFile), '/');

            $candidatePaths = [
                $this->root . '/' . $cleanFile,
                $this->root . '/' . urldecode($cleanFile),
            ];

            $fsPath = null;
            foreach ($candidatePaths as $candidate) {
                $resolved = realpath($candidate);
                if ($resolved && str_starts_with($resolved, $this->root)) {
                    $fsPath = $resolved;
                    break;
                }
            }

            if (!$fsPath || !str_starts_with($fsPath, $this->root) || !file_exists($fsPath)) {
                $results[$file] = 'not_found';
                continue;
            }

            // Check if file is favorited by any user
            if ($favDb->isFavoritedByAnyUser($fsPath)) {
                $results[$file] = 'skipped_favorited';
                continue;
            }

            $deleted = unlink($fsPath);
            if ($deleted) {
                $this->getAuditDb()->deleteFile($fsPath);
                $this->metaDb->deleteMetadata($fsPath);
            }
            $results[$file] = $deleted ? 'deleted' : 'failed';
        }

        $this->json(['status' => 'ok', 'results' => $results]);
    }

    private function countFolderContents(string $path): array
    {
        $stats = ['files' => 0, 'hidden_files' => 0, 'subfolders' => 0];

        if (!is_dir($path)) {
            return $stats;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            $pathname = $file->getPathname();
            $filename = $file->getFilename();

            $pathParts = explode('/', dirname($pathname));
            foreach ($pathParts as $part) {
                if (!empty($part) && $part[0] === '.') {
                    continue 2;
                }
            }

            if ($file->isFile()) {
                $stats['files']++;
                if ($filename[0] === '.') {
                    $stats['hidden_files']++;
                }
            } elseif ($file->isDir() && $filename[0] !== '.') {
                $stats['subfolders']++;
            }
        }

        if ($stats['subfolders'] > 0) {
            $stats['subfolders']--;
        }

        return $stats;
    }

    private function deleteRecursive(string $path): bool
    {
        if (!file_exists($path)) {
            return true;
        }

        if (is_file($path)) {
            $deleted = unlink($path);
            if ($deleted) {
                $this->getAuditDb()->deleteFile($path);
                $this->metaDb->deleteMetadata($path);
            }
            return $deleted;
        }

        if (is_dir($path)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $filePath = $file->getPathname();
                    unlink($filePath);
                    $this->getAuditDb()->deleteFile($filePath);
                    $this->metaDb->deleteMetadata($filePath);
                } elseif ($file->isDir()) {
                    rmdir($file->getPathname());
                }
            }

            return rmdir($path);
        }

        return false;
    }

    private function deleteRecursiveNonFavorited(string $path, array $favPaths, array &$stats, array &$deletedPaths): int
    {
        $count = 0;
        $favSet = array_flip($favPaths);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        $dirsToRemove = [];
        foreach ($iterator as $file) {
            $filePath = realpath($file->getPathname());
            if ($filePath === false) continue;
            $filename = $file->getFilename();

            if ($file->isFile()) {
                if (isset($favSet[$filePath])) {
                    continue;
                }

                if (unlink($filePath)) {
                    $this->getAuditDb()->deleteFile($filePath);
                    $this->metaDb->deleteMetadata($filePath);
                    $deletedPaths[] = $filePath;
                    $stats['files']++;
                    if ($filename[0] === '.') {
                        $stats['hidden_files']++;
                    }
                    $count++;
                }
            } elseif ($file->isDir() && $filename[0] !== '.') {
                $dirsToRemove[] = $filePath;
            }
        }

        // Remove empty directories (bottom-up, already ordered by CHILD_FIRST)
        foreach ($dirsToRemove as $dir) {
            if (count(scandir($dir)) === 2) {
                rmdir($dir);
                $stats['subfolders']++;
            }
        }

        return $count;
    }
}
