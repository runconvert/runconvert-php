<?php

declare(strict_types=1);

namespace RunConvert;

/**
 * The RunConvert API client (v3).
 *
 *     $rc = new RunConvert();                       // reads RUNCONVERT_API_KEY
 *     $rc->convert('report.docx', 'pdf', ['out_dir' => 'out'])->saved; // ['out/report.pdf']
 */
class RunConvert
{
    public const VERSION = '0.1.0';
    public const DEFAULT_BASE_URL = 'https://api.runconvert.com/v3';
    private const RETRY_STATUS = [408, 425, 429, 500, 502, 503, 504];

    public readonly Jobs $jobs;
    public readonly Operations $operations;
    public readonly Files $files;

    private string $apiKey;
    private string $baseUrl;
    /** @var callable */
    private $transport;

    /**
     * @param string|null $apiKey  Defaults to the RUNCONVERT_API_KEY environment variable.
     * @param int         $timeout Per API request, seconds (uploads and downloads have none).
     * @param callable|null $transport fn(string $method, string $url, array $headers, string|resource|null $body, ?int $timeout, ?string $sinkPath): array{status:int,headers:array,body:string}
     */
    public function __construct(
        ?string $apiKey = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
        private int $timeout = 60,
        private int $maxRetries = 2,
        ?callable $transport = null,
    ) {
        $this->apiKey = $apiKey ?? (getenv('RUNCONVERT_API_KEY') ?: '');
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->transport = $transport ?? [self::class, 'curlTransport'];
        $this->jobs = new Jobs($this);
        $this->operations = new Operations($this);
        $this->files = new Files($this);
    }

    public function userAgent(): string
    {
        return 'runconvert-php/' . self::VERSION . ' php/' . PHP_VERSION;
    }

    /** The account, team and credit balance behind the API key. */
    public function me(): array
    {
        return $this->request('GET', '/me');
    }

    /**
     * Converts one file in one call: upload (or import a URL), convert, wait, download.
     *
     * @param string|resource $file A path, an http(s) URL, or a stream.
     * @param array{from?:string,options?:array,out_dir?:string,filename?:string,tag?:string,timeout?:float,on_update?:callable} $opts
     */
    public function convert($file, string $to, array $opts = []): ConvertResult
    {
        $isUrl = is_string($file) && preg_match('#^https?://#i', $file) === 1;
        $name = $opts['filename'] ?? ($isUrl ? (string) parse_url($file, PHP_URL_PATH) : (is_string($file) ? $file : (stream_get_meta_data($file)['uri'] ?? '')));
        $from = strtolower($opts['from'] ?? pathinfo((string) $name, PATHINFO_EXTENSION));
        if ($from === '') {
            throw new \InvalidArgumentException("Can't tell the source format: pass 'from' => 'mp4' or 'filename' => 'video.mp4'.");
        }
        $convert = array_merge($opts['options'] ?? [], [
            'operation' => 'convert', 'input' => 'import-1',
            'input_format' => $from, 'output_format' => strtolower(ltrim($to, '.')),
        ]);
        $job = $this->jobs->create([
            'import-1' => $isUrl ? ['operation' => 'import/url', 'source_url' => $file] : ['operation' => 'import/direct/upload'],
            'convert-1' => $convert,
            'export-1' => ['operation' => 'export/url', 'input' => 'convert-1'],
        ], $opts['tag'] ?? null);
        if (!$isUrl) {
            $task = Jobs::task($job, 'import-1');
            if (!$task || empty($task['upload_url'])) {
                throw new RunConvertException('The job has no upload URL', 0, '', $job);
            }
            $this->files->upload($task, $file, $name !== '' ? basename((string) $name) : null);
        }
        $job = $this->jobs->wait($job['id'], $opts['timeout'] ?? null, $opts['on_update'] ?? null);
        $files = Jobs::exportedFiles($job);
        $saved = isset($opts['out_dir']) ? array_map(fn (array $f) => $this->files->download($f, $opts['out_dir']), $files) : [];
        return new ConvertResult($job, $files, $saved);
    }

    /** Sends a request and returns `data` from `{"success": true, "data": …}`. */
    public function request(string $method, string $path, ?array $body = null, array $query = []): mixed
    {
        return $this->requestRaw($method, $path, $body, $query)[0];
    }

    /** Like request(), returning [data, meta]. @internal */
    public function requestRaw(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $query = array_filter($query, static fn ($v) => $v !== null && $v !== '');
        $url = $this->baseUrl . $path . ($query ? '?' . http_build_query($query) : '');
        $headers = ['Accept' => 'application/json', 'User-Agent' => $this->userAgent()];
        if ($this->apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }
        $payload = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        // POST /jobs is only repeated when it never reached the server: a job is never created twice.
        $idempotent = $method !== 'POST' || str_ends_with($path, '/cancel');
        for ($attempt = 0; ; $attempt++) {
            try {
                $res = ($this->transport)($method, $url, $headers, $payload, $this->timeout, null);
            } catch (TransportException $e) {
                if ($attempt < $this->maxRetries && ($idempotent || !$e->reachedServer)) {
                    $this->pause(self::backoff($attempt));
                    continue;
                }
                throw new RunConvertException('Request to RunConvert failed: ' . $e->getMessage());
            }
            $status = $res['status'];
            if (in_array($status, self::RETRY_STATUS, true) && $attempt < $this->maxRetries && ($idempotent || $status === 429)) {
                $this->pause(self::retryAfter($res['headers']) ?? self::backoff($attempt));
                continue;
            }
            if ($status === 204) {
                return [null, []];
            }
            $data = $res['body'] !== '' ? json_decode($res['body'], true) : null;
            if ($status >= 400 || (is_array($data) && ($data['success'] ?? null) === false)) {
                $err = is_array($data) ? ($data['error'] ?? []) : [];
                throw new RunConvertException($err['message'] ?? "RunConvert API error (HTTP $status)", $status, (string) ($err['code'] ?? ''), $data ?? $res['body']);
            }
            return [$data['data'] ?? null, $data['meta'] ?? []];
        }
    }

    /** Raw HTTP for uploads and downloads. @internal */
    public function transfer(string $method, string $url, array $headers, $body, ?string $sinkPath = null): array
    {
        try {
            return ($this->transport)($method, $url, $headers, $body, null, $sinkPath);
        } catch (TransportException $e) {
            throw new RunConvertException(($sinkPath ? 'Download' : 'Upload') . ' failed: ' . $e->getMessage());
        }
    }

    /** Waits between retries and polls; replaced in tests. @internal */
    public function pause(float $seconds): void
    {
        usleep((int) round($seconds * 1_000_000));
    }

    private static function backoff(int $attempt): float
    {
        return min(8.0, 0.5 * 2 ** $attempt) * (0.75 + mt_rand() / mt_getrandmax() / 2);
    }

    private static function retryAfter(array $headers): ?float
    {
        foreach ($headers as $k => $v) {
            if (strcasecmp((string) $k, 'retry-after') === 0 && (float) $v > 0) {
                return min((float) $v, 30.0);
            }
        }
        return null;
    }

    /** The default transport, on ext-curl. */
    public static function curlTransport(string $method, string $url, array $headers, $body, ?int $timeout, ?string $sinkPath): array
    {
        $ch = curl_init($url);
        $responseHeaders = [];
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = "$k: $v";
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($timeout) {
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        }
        if (is_resource($body)) {
            curl_setopt($ch, CURLOPT_UPLOAD, true);
            curl_setopt($ch, CURLOPT_INFILE, $body);
            if (isset($headers['Content-Length'])) {
                curl_setopt($ch, CURLOPT_INFILESIZE, (int) $headers['Content-Length']);
            }
        } elseif ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $sink = null;
        if ($sinkPath !== null) {
            $sink = fopen($sinkPath, 'wb');
            curl_setopt($ch, CURLOPT_FILE, $sink);
        } else {
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        }
        $out = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($sink) {
            fclose($sink);
        }
        if ($errno !== 0) {
            // A timeout may have reached the server; connection failures did not.
            throw new TransportException($error, $errno === CURLE_OPERATION_TIMEDOUT);
        }
        return ['status' => $status, 'headers' => $responseHeaders, 'body' => is_string($out) ? $out : ''];
    }
}
