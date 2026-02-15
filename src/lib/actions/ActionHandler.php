<?php

abstract class ActionHandler
{
    protected array $data;
    protected string $root;
    protected MetadataDatabase $metaDb;

    public function __construct(array $data, string $root, MetadataDatabase $metaDb)
    {
        $this->data = $data;
        $this->root = $root;
        $this->metaDb = $metaDb;
    }

    abstract public function handle(): void;

    protected function json(mixed $data, int $code = 200): void
    {
        http_response_code($code);
        echo json_encode($data);
        exit;
    }

    protected function error(string $message, int $code = 400): void
    {
        http_response_code($code);
        echo json_encode(['error' => $message]);
        exit;
    }

    protected function getFiles(): array
    {
        $files = $this->data['files'] ?? $this->data['file'] ?? [];
        if (!is_array($files)) {
            $files = $files ? [$files] : [];
        }
        return Utils::decodeBase64Paths($files);
    }
}
