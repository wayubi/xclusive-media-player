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
        $sync = $this->data['sync'] ?? false;
        
        // cd always handled via handleCdCommand, never streamed
        $isCdCommand = (strpos($trimmedCmd, 'cd ') === 0 || $trimmedCmd === 'cd');
        if ($isCdCommand) {
            $this->handleCdCommand($trimmedCmd, $fsDir);
            return;
        }

        $this->handleExecCommand($commandLine, $fsDir, $sync);
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

    private function handleExecCommand(string $commandLine, string $fsDir, bool $sync = false): void
    {
        // Get current directory in web path format (e.g., /volumes/pocket)
        $relativePath = ltrim(str_replace($this->root, '', $fsDir), '/');
        $currentDir = '/volumes' . ($relativePath ? '/' . $relativePath : '');
        
        $scriptsDir = __DIR__ . '/../../scripts';

        if ($sync) {
            $fullCommand = "cd " . escapeshellarg($fsDir) . " && export PATH=" . escapeshellarg($scriptsDir) . ":\$PATH && " . $commandLine . " 2>&1";
            $output = shell_exec($fullCommand) ?? '';

            $this->json([
                'output' => base64_encode($output)
            ]);
            return;
        }
        
// All commands go through background job streaming
        $jobId = $this->startBackgroundJob($commandLine, $fsDir, $scriptsDir);
        
        $webDir = '/volumes' . ($relativePath ? '/' . $relativePath : '');
        
        $this->json([
            'output' => base64_encode("Started job: $jobId"),
            'currentDir' => $webDir,
            'jobId' => $jobId
        ]);
    }

    private function startBackgroundJob(string $command, string $fsDir, string $scriptsDir): string
    {
        // Use timestamp + microtime as job ID for natural sorting and uniqueness
        $jobId = time() . '_' . substr(str_replace('.', '', microtime(true)), -6);
        $jobDir = '/var/www/jobs';
        
        if (!is_dir($jobDir)) {
            mkdir($jobDir, 0777, true);
        }
        
        $outputFile = "$jobDir/$jobId.output";
        $doneFile = "$jobDir/$jobId.done";
        
        // Build the command - write command first, then output
        $command = str_replace("'", "'\\''", $command);
        $outputFileEscaped = escapeshellarg($outputFile);
        $doneFileEscaped = escapeshellarg($doneFile);
        
        $fullCommand = "cd " . escapeshellarg($fsDir) . " && export PATH=" . escapeshellarg($scriptsDir) . ":\$PATH && echo 'Command: " . $command . "' > " . $outputFileEscaped . " && echo '' >> " . $outputFileEscaped . " && (" . $command . " 2>&1 | tee -a " . $outputFileEscaped . "; echo \${PIPESTATUS[0]} > " . $doneFileEscaped . ")";
        
        // Run in background with nohup
        $bgCommand = "nohup bash -c " . escapeshellarg($fullCommand) . " > /dev/null 2>&1 &";
        shell_exec($bgCommand);
        
        return $jobId;
    }

    public function streamJob(string $jobId): void
    {
        $jobDir = '/var/www/jobs';
        
        // Validate jobId - only allow safe characters (timestamp_microtime format)
        if (!preg_match('/^\d+_\d+$/', $jobId)) {
            $this->json(['error' => 'Invalid job ID']);
            return;
        }
        
        $outputFile = "$jobDir/$jobId.output";
        $doneFile = "$jobDir/$jobId.done";
        
        $response = [
            'output' => '',
            'done' => false,
            'exitCode' => null
        ];
        
        // Read output file
        if (file_exists($outputFile)) {
            $content = file_get_contents($outputFile);
            if ($content) {
                $response['output'] = base64_encode($content);
            }
        }
        
        // Check if job is finished via .done file
        if (file_exists($doneFile)) {
            $response['done'] = true;
            $response['exitCode'] = trim(file_get_contents($doneFile));
            @unlink($doneFile);
        }
        
        $this->json($response);
    }
}
