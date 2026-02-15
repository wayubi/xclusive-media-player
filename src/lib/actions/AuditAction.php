<?php

require_once __DIR__ . '/ActionHandler.php';

class AuditAction extends ActionHandler
{
    public function handle(): void
    {
        $filePaths = $this->data['file_paths'] ?? [];

        if (!is_array($filePaths) || empty($filePaths)) {
            $this->error('Invalid or missing file_paths');
        }

        $filePaths = Utils::decodeBase64Paths($filePaths);

        try {
            $auditDb = new AuditDatabase();

            $absolutePaths = array_filter($filePaths, 'file_exists');

            if (empty($absolutePaths)) {
                $this->json(['status' => 'ok', 'count' => 0, 'total_audited' => 0, 'total_files' => 0]);
            }

            $auditedCount = $auditDb->auditBatch($absolutePaths);
            $stats = $auditDb->getStats();

            $this->json([
                'status' => 'ok',
                'date' => date('ymd'),
                'count' => $auditedCount,
                'total_audited' => $stats['audited_files'],
                'total_files' => $stats['total_files']
            ]);
        } catch (Exception $e) {
            $this->error('Audit failed: ' . $e->getMessage(), 500);
        }
    }
}
