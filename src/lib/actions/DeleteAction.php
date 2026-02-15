<?php

require_once __DIR__ . '/ActionHandler.php';

class DeleteAction extends ActionHandler
{
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

            $results[$file] = unlink($fsPath) ? 'deleted' : 'failed';
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
            return unlink($path);
        }

        if (is_dir($path)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    unlink($file->getPathname());
                } elseif ($file->isDir()) {
                    rmdir($file->getPathname());
                }
            }

            return rmdir($path);
        }

        return false;
    }
}
