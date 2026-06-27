<?php

require_once __DIR__ . '/ActionHandler.php';
require_once __DIR__ . '/../MastodonClient.php';

class ShareAction extends ActionHandler
{
    public function handle(): void
    {
        $file = $this->data['file'] ?? null;
        $status = $this->data['status'] ?? '';
        $visibility = $this->data['visibility'] ?? 'private';
        $sensitive = filter_var($this->data['sensitive'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $instance = $this->data['instance'] ?? '';
        $token = $this->data['token'] ?? '';
        $inReplyToId = $this->data['in_reply_to_id'] ?? null;

        if (!$file) {
            $this->error('Missing file parameter');
        }

        if (!$instance) {
            $this->error('Missing Mastodon instance');
        }

        if (!$token) {
            $this->error('Missing access token');
        }

        $validVisibilities = ['public', 'unlisted', 'private', 'direct'];
        if (!in_array($visibility, $validVisibilities)) {
            $this->error('Invalid visibility: ' . $visibility);
        }

        $fsPath = Utils::resolveWebPathForFile($file, $this->root);

        if (!$fsPath || !file_exists($fsPath)) {
            $this->error('Invalid file path');
        }

        if (!is_readable($fsPath)) {
            $this->error('File is not readable');
        }

        try {
            $client = new MastodonClient($instance, $token);

            $filename = basename($fsPath);
            
            $mediaResult = $client->uploadMedia($fsPath, $filename);
            $mediaId = $mediaResult['id'];
            
            $postResult = $client->postStatus($status, [$mediaId], $visibility, $sensitive, $inReplyToId);

            $this->json([
                'status' => 'ok',
                'postUrl' => $postResult['url'],
                'postId' => $postResult['id'],
                'mediaId' => $mediaId
            ]);
        } catch (Exception $e) {
            $this->error('Share failed: ' . $e->getMessage(), 500);
        }
    }
}
