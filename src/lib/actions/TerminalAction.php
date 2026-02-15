<?php

require_once __DIR__ . '/ActionHandler.php';

class TerminalAction extends ActionHandler
{
    public function handle(): void
    {
        $commandLine = $this->data['command'] ?? '';
        $currentDir = $this->data['currentDir'] ?? '/volumes';

        // Special case: list_scripts
        if ($commandLine === 'list_scripts') {
            $this->handleListScripts();
            return;
        }

        if (empty($commandLine)) {
            $this->error('No command provided');
        }

        $fsDir = Utils::resolveWebPathForDir($currentDir, $this->root);
        $trimmedCmd = trim($commandLine);
        $isCdCommand = (strpos($trimmedCmd, 'cd ') === 0 || $trimmedCmd === 'cd');

        if ($isCdCommand) {
            $this->handleCdCommand($trimmedCmd, $fsDir);
        } else {
            $this->handleExecCommand($commandLine, $fsDir);
        }
    }

    private function handleListScripts(): void
    {
        $scriptsDir = __DIR__ . '/../../scripts';
        $scripts = [];
        
        if (is_dir($scriptsDir)) {
            $files = scandir($scriptsDir);
            foreach ($files as $file) {
                if ($file !== '.' && $file !== '..' && is_file("$scriptsDir/$file")) {
                    $scripts[] = $file;
                }
            }
        }
        
        $this->json([
            'status' => 'ok',
            'scripts' => $scripts
        ]);
    }

    private function handleCdCommand(string $trimmedCmd, string $fsDir): void
    {
        $output = '';
        $newDir = $fsDir;

        preg_match('/^cd\s*(.*)$/', $trimmedCmd, $matches);
        $target = isset($matches[1]) ? trim($matches[1]) : '';

        if (empty($target) || $target === '~') {
            $newDir = $this->root;
        } elseif ($target === '-') {
            $output = '';
        } elseif (str_starts_with($target, '/')) {
            $target = rawurldecode($target);
            $webPath = '/volumes' . $target;
            $targetPath = Utils::resolveWebPathForDir($webPath, $this->root);

            if ($targetPath && is_dir($targetPath)) {
                $newDir = $targetPath;
            } else {
                $output = "bash: cd: {$target}: No such file or directory";
            }
        } else {
            $target = rawurldecode($target);
            $relativeWebPath = Utils::filesystemToWebPath($fsDir, $this->root, '/volumes');
            $relativeWebPath = $relativeWebPath . '/' . $target;
            $targetPath = Utils::resolveWebPathForDir($relativeWebPath, $this->root);

            if ($targetPath && is_dir($targetPath)) {
                $newDir = $targetPath;
            } else {
                $output = "bash: cd: {$target}: No such file or directory";
            }
        }

        $relativePath = ltrim(str_replace($this->root, '', $newDir), '/');
        $webDir = '/volumes' . ($relativePath ? '/' . $relativePath : '');

        $this->json([
            'output' => base64_encode($output),
            'currentDir' => $webDir
        ]);
    }

    private function handleExecCommand(string $commandLine, string $fsDir): void
    {
        $scriptsDir = __DIR__ . '/../../scripts';
        $trimmed = trim($commandLine);
        
        // Check for & at end (with optional leading whitespace) - run in background
        if (preg_match('/\s+&$/', $trimmed)) {
            $cmd = rtrim($trimmed, '&');
            $cmd = trim($cmd);
            
            $bgCommand = "cd " . escapeshellarg($fsDir) . " && export PATH=" . escapeshellarg($scriptsDir) . ":\$PATH && nohup " . $cmd . " > /dev/null 2>&1 &";
            shell_exec($bgCommand);
            
            $relativePath = ltrim(str_replace($this->root, '', $fsDir), '/');
            $webDir = '/volumes' . ($relativePath ? '/' . $relativePath : '');
            
            $this->json([
                'output' => base64_encode("Started: $cmd (background)"),
                'currentDir' => $webDir
            ]);
            return;
        }
        
        $fullCommand = "cd " . escapeshellarg($fsDir) . " && export PATH=" . escapeshellarg($scriptsDir) . ":\$PATH && " . $commandLine . " 2>&1";

        $output = shell_exec($fullCommand) ?? '';

        $relativePath = ltrim(str_replace($this->root, '', $fsDir), '/');
        $webDir = '/volumes' . ($relativePath ? '/' . $relativePath : '');

        $this->json([
            'output' => base64_encode($output),
            'currentDir' => $webDir
        ]);
    }
}
