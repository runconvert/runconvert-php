<?php

declare(strict_types=1);

namespace RunConvert;

/** Uploads to import/direct/upload tasks and downloads of results. */
final class Files
{
    public function __construct(private RunConvert $c)
    {
    }

    /**
     * PUTs a path or stream to the task's upload_url before its deadline.
     * @param array|string    $task An import/direct/upload task, or its upload_url.
     * @param string|resource $file
     */
    public function upload(array|string $task, $file, ?string $filename = null): void
    {
        $uploadUrl = is_string($task) ? $task : ($task['upload_url'] ?? '');
        if ($uploadUrl === '') {
            throw new \InvalidArgumentException('This task has no upload_url (is it an import/direct/upload task?)');
        }
        if ($filename === null && is_string($file)) {
            $filename = basename($file);
        }
        $parts = parse_url($uploadUrl);
        parse_str($parts['query'] ?? '', $query);
        if ($filename !== null) {
            $query['filename'] = $filename;
        }
        $url = strtok($uploadUrl, '?') . '?' . http_build_query($query);
        $headers = ['Content-Type' => 'application/octet-stream', 'User-Agent' => $this->c->userAgent()];
        $handle = is_string($file) ? fopen($file, 'rb') : $file;
        if ($handle === false) {
            throw new \InvalidArgumentException("Can't open $file");
        }
        $size = fstat($handle)['size'] ?? null;
        if ($size !== null) {
            $headers['Content-Length'] = (string) $size;
        }
        try {
            $res = $this->c->transfer('PUT', $url, $headers, $handle);
        } finally {
            if (is_string($file)) {
                fclose($handle);
            }
        }
        if ($res['status'] >= 300) {
            throw new RunConvertException("Upload failed (HTTP {$res['status']}): " . substr($res['body'], 0, 300), $res['status']);
        }
    }

    /** Saves a result file (or a URL) into $dir keeping its name; returns the path. */
    public function download(array|string $file, string $dir, ?string $filename = null): string
    {
        $url = is_string($file) ? $file : $file['url'];
        $name = $filename ?? (is_string($file) ? basename((string) parse_url($url, PHP_URL_PATH)) : $file['filename']);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("Can't create $dir");
        }
        $dest = rtrim($dir, '/') . '/' . basename($name);
        $res = $this->c->transfer('GET', $url, ['User-Agent' => $this->c->userAgent()], null, $dest);
        if ($res['status'] >= 300) {
            @unlink($dest);
            throw new RunConvertException("Download failed (HTTP {$res['status']})", $res['status']);
        }
        return $dest;
    }
}
