<?php

require_once __DIR__ . '/ActionHandler.php';

class AuditStatusAction extends ActionHandler
{
    public function handle(): void
    {
        $filePaths = $this->data['file_paths'] ?? [];

        if (!is_array($filePaths)) {
            $this->error('Invalid file_paths');
        }

        $filePaths = Utils::decodeBase64Paths($filePaths);

        try {
            $auditDb = new AuditDatabase();
            $statuses = $auditDb->getAuditStatusBatch($filePaths);

            $this->json([
                'status' => 'ok',
                'audit_statuses' => Utils::encodeArrayKeysBase64($statuses)
            ]);
        } catch (Exception $e) {
            $this->error('Failed to get audit status: ' . $e->getMessage(), 500);
        }
    }
}
