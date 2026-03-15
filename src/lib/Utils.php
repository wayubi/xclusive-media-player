<?php

class Utils
{
    public static function executeCommand(string $command, ?string $cwd = null): string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $cwd);

        if (!is_resource($process)) {
            return '';
        }

        fclose($pipes[0]);

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        proc_close($process);

        return $output;
    }

    public static function normalizePath(string $path): string
    {
        $real = realpath($path);
        if ($real === false) {
            $real = str_replace('\\', '/', $path);
        } else {
            $real = str_replace('\\', '/', $real);
        }
        return $real;
    }

    public static function getExcludedFolders(): array
    {
        return ['.trash'];
    }

    public static function isInExcludedFolder(string $pathname): bool
    {
        static $excluded = ['.trash'];
        
        foreach ($excluded as $folder) {
            if (str_contains($pathname, "/$folder/")) {
                return true;
            }
        }
        return false;
    }

    public static function isInHiddenDirectory(string $pathname): bool
    {
        $dirname = dirname($pathname);
        $parts = explode('/', $dirname);
        foreach ($parts as $part) {
            if ($part !== '' && $part[0] === '.') {
                return true;
            }
        }
        return false;
    }

    public static function filesystemToWebPath(string $fsPath, string $rootFs, string $rootWeb = '/volumes'): string
    {
        static $rootFsReal = null;

        if ($rootFsReal === null) {
            $rootFsReal = realpath($rootFs);
        }

        $fsPath = str_replace('\\', '/', $fsPath);
        $relative = str_starts_with($fsPath, $rootFsReal)
            ? substr($fsPath, strlen($rootFsReal))
            : $fsPath;

        $segments = array_map('rawurlencode', explode('/', ltrim($relative, '/')));
        return rtrim($rootWeb, '/') . '/' . implode('/', $segments);
    }

    public static function webToFilesystemPath(string $webPath, string $root, string $webRoot = '/volumes'): string
    {
        $cleanFile = ltrim(preg_replace('#^' . preg_quote($webRoot, '#') . '/#i', '', $webPath), '/');
        $cleanFile = urldecode($cleanFile);
        return realpath($root . '/' . $cleanFile) ?: $root . '/' . $cleanFile;
    }

    public static function decodeBase64Path(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return $path;
        }
        $decoded = base64_decode($path, true);
        return $decoded !== false ? $decoded : $path;
    }

    public static function decodeBase64Paths(array $paths): array
    {
        return array_map([self::class, 'decodeBase64Path'], $paths);
    }

    public static function encodeArrayKeysBase64(array $arr): array
    {
        $result = [];
        foreach ($arr as $key => $value) {
            $result[base64_encode($key)] = $value;
        }
        return $result;
    }

    public static function getCurrentPath(string $root, string $selectedPath): string
    {
        static $cachedRoot = null;
        if ($cachedRoot === null) {
            $cachedRoot = realpath($root) ?: $root;
        }

        if (preg_match('#(^|/)\.\.(/|$)#', $selectedPath)) {
            return $cachedRoot;
        }

        $fullPath = $cachedRoot . DIRECTORY_SEPARATOR . ltrim($selectedPath, '/\\');
        $real = realpath($fullPath);
        return ($real && str_starts_with($real, $cachedRoot)) ? $real : $cachedRoot;
    }

    public static function resolveWebPath(string $webPath, string $root, string $webRoot = '/volumes'): ?string
    {
        $relativePath = ltrim(preg_replace('#^' . preg_quote($webRoot, '#') . '/?#i', '', $webPath), '/');
        
        $candidates = [
            $root . '/' . $relativePath,
            $root . '/' . urldecode($relativePath),
        ];

        foreach ($candidates as $candidate) {
            $resolved = realpath($candidate);
            if ($resolved && str_starts_with($resolved, $root)) {
                return $resolved;
            }
        }

        return null;
    }

    public static function resolveWebPathForDir(string $webPath, string $root, string $webRoot = '/volumes'): string
    {
        $resolved = self::resolveWebPath($webPath, $root, $webRoot);
        
        if ($resolved && is_dir($resolved)) {
            return $resolved;
        }
        
        return $root;
    }

    public static function resolveWebPathForFile(string $webPath, string $root, string $webRoot = '/volumes'): ?string
    {
        $resolved = self::resolveWebPath($webPath, $root, $webRoot);
        
        if ($resolved && is_file($resolved)) {
            return $resolved;
        }
        
        return null;
    }
}
