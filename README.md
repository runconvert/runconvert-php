# RunConvert SDK for PHP

> **Not published yet.** This SDK launches on Packagist soon, so the install command below won't work until then. Updates: https://www.runconvert.com/sdks

Convert files between 240+ formats (3,000+ conversions) with the [RunConvert API](https://www.runconvert.com/docs):
documents, images, video, audio, ebooks, archives, fonts and CAD. PHP 8.1+, no dependencies (ext-curl, ext-json).
Using Laravel? See [runconvert-laravel](https://github.com/runconvert/runconvert-laravel).

```bash
composer require runconvert/runconvert-php
```

```php
use RunConvert\RunConvert;

$rc = new RunConvert(); // reads RUNCONVERT_API_KEY

// One call: upload, convert, wait, download.
$result = $rc->convert('report.docx', 'pdf', ['out_dir' => 'out']);
print_r($result->saved); // ['out/report.pdf']
```

Get an API key in **Dashboard → API Keys** on [runconvert.com](https://www.runconvert.com). Keep keys on the server.

## Convert

```php
// From a URL (imported by RunConvert, nothing to upload)
$rc->convert('https://example.com/talk.mp4', 'mp3', ['options' => ['audioBitrate' => '192k']]);

// From a stream: give a filename (or 'from')
$rc->convert(fopen('photo.heic', 'rb'), 'jpg', ['filename' => 'photo.heic', 'out_dir' => 'out']);

// Progress and a time limit (seconds)
$rc->convert('movie.mov', 'mp4', ['out_dir' => 'out', 'timeout' => 600, 'on_update' => fn ($job) => print($job['status'] . "\n")]);
```

## Jobs with your own tasks

```php
use RunConvert\Jobs;

// Merge two PDFs
$job = $rc->jobs->create([
    'a' => ['operation' => 'import/url', 'source_url' => 'https://example.com/cover.pdf'],
    'b' => ['operation' => 'import/url', 'source_url' => 'https://example.com/body.pdf'],
    'merge' => ['operation' => 'convert', 'input' => ['a', 'b'], 'input_format' => 'pdf', 'output_format' => 'pdf'],
    'export' => ['operation' => 'export/url', 'input' => 'merge'],
], tag: 'merge');
$done = $rc->jobs->wait($job['id']);
foreach (Jobs::exportedFiles($done) as $file) {
    $rc->files->download($file, 'out');
}

// Upload your own file
$job = $rc->jobs->create([
    'upload' => ['operation' => 'import/direct/upload'],
    'convert' => ['operation' => 'convert', 'input' => 'upload', 'input_format' => 'png', 'output_format' => 'webp', 'image_quality' => 80],
    'export' => ['operation' => 'export/url', 'input' => 'convert'],
]);
$rc->files->upload(Jobs::task($job, 'upload'), 'image.png');
```

| Method | What it does |
|---|---|
| `$rc->jobs->create / get / list / all / wait / cancel / delete` | Jobs, with paging (`list`, `all`) and polling (`wait`) |
| `$rc->files->upload($task, $pathOrStream)` / `download($file, $dir)` | Transfers |
| `$rc->operations->list()` / `forInput('mp4')` / `forPair('mp4', 'mp3')` | Formats, targets, engines (no key needed) |
| `$rc->me()` | Account, team and credit balance |

## Webhooks

```php
use RunConvert\Webhooks;

$body = file_get_contents('php://input');
try {
    $event = Webhooks::constructEvent($body, $_SERVER['HTTP_X_RUNCONVERT_SIGNATURE'] ?? null, getenv('RUNCONVERT_WEBHOOK_SECRET'));
} catch (UnexpectedValueException $e) {
    http_response_code(400);
    exit;
}
if ($event['event'] === 'job.finished') {
    error_log('done ' . $event['job']['id']);
}
```

## Errors

| Exception | When |
|---|---|
| `RunConvertException` | The API said no: `getStatus()`, `getErrorCode()` (e.g. `BILLING_INSUFFICIENT_CREDITS`), `getMessage()` |
| `JobFailedException` | The job ended in `error`; `getTask()` has `code`, `reason`, `message` |
| `WaitTimeoutException` | `wait` ran past its timeout |

Network errors, `429` and `5xx` are retried twice with backoff (`maxRetries`). Creating a job is only
retried when the request never reached the API, so a job is never created twice.

## Development

```bash
php tests/UnitTest.php                                # no network
RUNCONVERT_API_KEY=rc_live_… php tests/LiveTest.php   # real API, about 2 credits
```

## License

MIT
