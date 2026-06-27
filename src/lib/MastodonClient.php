<?php

class MastodonClient
{
    private string $instance;
    private string $token;

    public function __construct(string $instance, string $token)
    {
        $this->instance = rtrim($instance, '/');
        $this->token = $token;
    }

    public function uploadMedia(string $filePath, string $description = ''): array
    {
        if (!file_exists($filePath)) {
            throw new Exception('File not found: ' . $filePath);
        }

        $mimeType = $this->getMimeType($filePath);
        
        $ch = curl_init();
        
        $postFields = [
            'file' => new CURLFile($filePath, $mimeType, basename($filePath)),
            'description' => $description
        ];
        
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://{$this->instance}/api/v2/media",
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token,
                'Accept: application/json'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 600,
            CURLOPT_CONNECTTIMEOUT => 60
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception('CURL error: ' . $error);
        }
        
        if ($httpCode === 401) {
            throw new Exception('Invalid access token');
        }
        
        if ($httpCode === 422) {
            throw new Exception('Media file is too large or invalid');
        }
        
        if ($httpCode >= 400) {
            throw new Exception('Upload failed with HTTP ' . $httpCode . ': ' . $response);
        }
        
        $data = json_decode($response, true);
        
        if (!$data || !isset($data['id'])) {
            throw new Exception('Invalid response from Mastodon: ' . $response);
        }
        
        $mediaId = $data['id'];
        
        $this->waitForMediaReady($mediaId);
        
        return [
            'id' => $mediaId,
            'url' => $data['url'] ?? null
        ];
    }
    
    private function waitForMediaReady(string $mediaId, int $maxAttempts = 90): void
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            $mediaInfo = $this->getMediaInfo($mediaId);
            
            if (!empty($mediaInfo['error'])) {
                throw new Exception('Media processing failed on Mastodon: ' . $mediaInfo['error']);
            }
            
            if (!empty($mediaInfo['url']) && !empty($mediaInfo['preview_url'])) {
                sleep(3);
                return;
            }
            
            sleep(2);
        }
        
        throw new Exception('Media processing timed out on Mastodon');
    }
    
    private function getMediaInfo(string $mediaId): array
    {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://{$this->instance}/api/v1/media/{$mediaId}",
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token,
                'Accept: application/json'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['error' => $error];
        }
        
        if ($httpCode >= 400) {
            return ['error' => "HTTP $httpCode"];
        }
        
        return json_decode($response, true) ?? ['error' => 'Invalid response'];
    }
             
    public function postStatus(string $status, array $mediaIds = [], string $visibility = 'private', bool $sensitive = true, ?string $inReplyToId = null): array
    {
        $ch = curl_init();
        
        // Build query string manually to force media_ids[] notation
        $parts = [
            'status='     . urlencode($status),
            'visibility=' . urlencode($visibility),
            'sensitive=' . ($sensitive ? 'true' : 'false'),
        ];
        if ($inReplyToId) {
            $parts[] = 'in_reply_to_id=' . urlencode($inReplyToId);
        }
        foreach ($mediaIds as $id) {
            $parts[] = 'media_ids[]=' . urlencode($id);
        }
        $postString = implode('&', $parts);
        
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://{$this->instance}/api/v1/statuses",
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postString,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token,
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception('CURL error: ' . $error);
        }
        
        if ($httpCode === 401) {
            throw new Exception('Invalid access token');
        }
        
        if ($httpCode >= 400) {
            throw new Exception('Post failed with HTTP ' . $httpCode . ': ' . $response);
        }
        
        $data = json_decode($response, true);
        
        if (!$data || !isset($data['id'])) {
            throw new Exception('Invalid response from Mastodon: ' . $response);
        }
        
        return [
            'id' => $data['id'],
            'url' => $data['url'] ?? "https://{$this->instance}/@{$data['account']['acct']}/{$data['id']}"
        ];
    }

    private function getMimeType(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        $mimeTypes = [
            // Videos
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            'm4v' => 'video/mp4',
            'mkv' => 'video/x-matroska',
            'avi' => 'video/x-msvideo',
            'wmv' => 'video/x-ms-wmv',
            'flv' => 'video/x-flv',
            '3gp' => 'video/3gpp',
            'mpg' => 'video/mpeg',
            'mpeg' => 'video/mpeg',
            'ogv' => 'video/ogg',
            // Audio
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'm4a' => 'audio/mp4',
            'aac' => 'audio/aac',
            'flac' => 'audio/flac'
        ];
        
        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }
}
