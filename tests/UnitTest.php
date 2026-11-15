<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use RunConvert\{JobFailedException, Jobs, RunConvert, RunConvertException, WaitTimeoutException, Webhooks};

/** A client whose HTTP goes to $handler(array $call, int $n): array{status, headers?, body?}. */
function fake(callable $handler, array &$calls, array $downloads = []): RunConvert
{
    $transport = function (string $method, string $url, array $headers, $body, ?int $timeout, ?string $sink) use ($handler, &$calls, $downloads) {
        $u = parse_url($url);
        parse_str($u['query'] ?? '', $query);
        $call = ['method' => $method, 'url' => $url, 'path' => preg_replace('#^/v3#', '', $u['path'] ?? ''), 'query' => $query, 'headers' => $headers, 'body' => is_resource($body) ? stream_get_contents($body) : $body];
        $calls[] = $call;
        if ($sink !== null) {
            if (!isset($downloads[$url])) { return ['status' => 404, 'headers' => [], 'body' => '']; }
            file_put_contents($sink, $downloads[$url]);
            return ['status' => 200, 'headers' => [], 'body' => ''];
        }
        return $handler($call, count($calls)) + ['headers' => [], 'body' => ''];
    };
    return new class ('rc_live_test', 'https://api.example/v3', 60, 2, $transport) extends RunConvert {
        public function pause(float $seconds): void {} // no real waiting in tests
    };
}
function ok($data, ?array $meta = null): array { return ['status' => 200, 'body' => json_encode(['success' => true, 'data' => $data] + ($meta ? ['meta' => $meta] : []))]; }
function failWith(int $status, string $code, string $msg, array $headers = []): array { return ['status' => $status, 'headers' => $headers, 'body' => json_encode(['success' => false, 'error' => ['code' => $code, 'message' => $msg]])]; }

test('sends the key and unwraps { success, data }', function () {
    $calls = [];
    $rc = fake(fn () => ok(['credits' => ['total_remaining' => 42]]), $calls);
    same($rc->me()['credits']['total_remaining'], 42);
    same($calls[0]['headers']['Authorization'], 'Bearer rc_live_test');
    check(str_starts_with($calls[0]['headers']['User-Agent'], 'runconvert-php/'));
});

test('API errors become RunConvertException with status and code', function () {
    $calls = [];
    $rc = fake(fn () => failWith(422, 'BILLING_INSUFFICIENT_CREDITS', 'Insufficient credits.'), $calls);
    $e = throws(RunConvertException::class, fn () => $rc->jobs->get('job_1'));
    same([$e->getStatus(), $e->getErrorCode(), $e->getMessage()], [422, 'BILLING_INSUFFICIENT_CREDITS', 'Insufficient credits.']);
});

test('GET retries 503; POST /jobs not retried after 500 but retried on 429', function () {
    $calls = [];
    $rc = fake(fn ($c, $n) => $n < 3 ? ['status' => 503, 'body' => 'busy'] : ok(['id' => 'job_1', 'status' => 'finished']), $calls);
    same($rc->jobs->get('job_1')['status'], 'finished');
    same(count($calls), 3);
    $calls2 = [];
    $rc2 = fake(fn () => failWith(500, 'INTERNAL', 'boom'), $calls2);
    throws(RunConvertException::class, fn () => $rc2->jobs->create([]));
    same(count($calls2), 1);
    $calls3 = [];
    $rc3 = fake(fn ($c, $n) => $n === 1 ? failWith(429, 'ACTIVE_JOB_LIMIT_REACHED', 'x', ['retry-after' => '0']) : ok(['id' => 'job_1', 'status' => 'waiting']), $calls3);
    same($rc3->jobs->create([])['id'], 'job_1');
    same(count($calls3), 2);
    same($calls3[1]['query']['include'], 'tasks');
});

test('list passes filters; all() walks every page', function () {
    $calls = [];
    $rc = fake(fn ($c) => ok([['id' => 'job_p' . $c['query']['page'], 'status' => 'finished']], ['page' => (int) $c['query']['page'], 'total_pages' => 2]), $calls);
    $page = $rc->jobs->list('finished', 'x', 1, 1);
    same($page['meta']['total_pages'], 2);
    same($calls[0]['query']['filter']['status'] ?? null, 'finished');
    same(array_map(fn ($j) => $j['id'], iterator_to_array($rc->jobs->all(perPage: 1), false)), ['job_p1', 'job_p2']);
});

test('wait: finished, failed (with reason), timeout', function () {
    $states = ['waiting', 'processing', 'finished'];
    $calls = [];
    $rc = fake(fn ($c, $n) => ok(['id' => 'job_1', 'status' => $states[min($n - 1, 2)]]), $calls);
    $seen = [];
    same($rc->jobs->wait('job_1', null, function ($j) use (&$seen) { $seen[] = $j['status']; })['status'], 'finished');
    same($seen, $states);
    $failed = ['id' => 'job_1', 'status' => 'error', 'tasks' => [['name' => 'c', 'operation' => 'convert', 'status' => 'error', 'code' => 'INVALID_DATA', 'reason' => 'INPUT_EMPTY', 'message' => 'The file is empty.']]];
    $c2 = [];
    $rc2 = fake(fn () => ok($failed), $c2);
    $e = throws(JobFailedException::class, fn () => $rc2->jobs->wait('job_1'));
    same([$e->getTask()['reason'], $e->getMessage()], ['INPUT_EMPTY', 'The file is empty.']);
    same($rc2->jobs->wait('job_1', throwOnError: false)['status'], 'error');
    $c3 = [];
    $rc3 = fake(fn () => ok(['id' => 'job_1', 'status' => 'processing']), $c3);
    throws(WaitTimeoutException::class, fn () => $rc3->jobs->wait('job_1', 0.001));
});

test('delete handles 204; operations queries are lower-cased', function () {
    $calls = [];
    $rc = fake(fn ($c) => $c['method'] === 'DELETE' ? ['status' => 204] : ok(['engines' => []]), $calls);
    $rc->jobs->delete('job_1');
    $rc->operations->forPair('MP4', 'MP3');
    same($calls[1]['query'], ['input' => 'mp4', 'output' => 'mp3']);
});

test('webhook signatures: valid, tampered, malformed', function () {
    $body = json_encode(['event' => 'job.finished', 'job' => ['id' => 'job_1']]);
    $sig = 'sha256=' . hash_hmac('sha256', $body, 'whsec_x');
    check(Webhooks::verify($body, $sig, 'whsec_x'));
    check(!Webhooks::verify($body . ' ', $sig, 'whsec_x'));
    check(!Webhooks::verify($body, $sig, 'other'));
    check(!Webhooks::verify($body, 'sha256=nothex', 'whsec_x'));
    check(!Webhooks::verify($body, null, 'whsec_x'));
    same(Webhooks::constructEvent($body, $sig, 'whsec_x')['job']['id'], 'job_1');
    throws(\UnexpectedValueException::class, fn () => Webhooks::constructEvent($body, 'sha256=' . str_repeat('0', 64), 'whsec_x'));
});

test('convert(): uploads a local file, waits, downloads the result', function () {
    $dir = sys_get_temp_dir() . '/rc-php-' . bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents("$dir/notes.txt", 'hello');
    $polls = 0;
    $calls = [];
    $rc = fake(function ($c) use (&$polls) {
        if ($c['method'] === 'POST' && $c['path'] === '/jobs') {
            return ok(['id' => 'job_1', 'status' => 'waiting', 'tasks' => [['name' => 'import-1', 'operation' => 'import/direct/upload', 'status' => 'waiting', 'upload_url' => 'https://up.example/u/1?token=t']]]);
        }
        if ($c['method'] === 'PUT') { return ['status' => 200]; }
        $polls++;
        return ok(['id' => 'job_1', 'status' => $polls > 1 ? 'finished' : 'processing', 'tasks' => [['name' => 'export-1', 'operation' => 'export/url', 'status' => 'finished', 'result' => ['files' => [['filename' => 'notes.pdf', 'url' => 'https://dl.example/notes.pdf']]]]]]);
    }, $calls, ['https://dl.example/notes.pdf' => 'PDF']);
    $res = $rc->convert("$dir/notes.txt", 'pdf', ['out_dir' => "$dir/out", 'options' => ['page_size' => 'a4']]);
    same(json_decode($calls[0]['body'], true)['tasks']['convert-1'], ['page_size' => 'a4', 'operation' => 'convert', 'input' => 'import-1', 'input_format' => 'txt', 'output_format' => 'pdf']);
    $put = array_values(array_filter($calls, fn ($c) => $c['method'] === 'PUT'))[0];
    same([$put['query']['filename'], $put['query']['token'], $put['body'], $put['headers']['Content-Length']], ['notes.txt', 't', 'hello', '5']);
    same(file_get_contents($res->saved[0]), 'PDF');
});

test('convert(): a URL is imported; a stream needs a filename', function () {
    $calls = [];
    $rc = fake(fn ($c) => ok(['id' => 'job_2', 'status' => $c['method'] === 'GET' ? 'finished' : 'waiting']), $calls);
    $rc->convert('https://example.com/a/talk.MP4?x=1', 'mp3');
    $tasks = json_decode($calls[0]['body'], true)['tasks'];
    same($tasks['import-1'], ['operation' => 'import/url', 'source_url' => 'https://example.com/a/talk.MP4?x=1']);
    same($tasks['convert-1']['input_format'], 'mp4');
    $mem = fopen('php://memory', 'r+');
    throws(\InvalidArgumentException::class, fn () => $rc->convert($mem, 'pdf'));
});

exit(run_tests());
