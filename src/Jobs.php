<?php

declare(strict_types=1);

namespace RunConvert;

/** Create, read, wait for and stop jobs. */
final class Jobs
{
    public function __construct(private RunConvert $c)
    {
    }

    /** Creates a job from named tasks. Returned with its tasks (an upload task carries upload_url). */
    public function create(array $tasks, ?string $tag = null, ?array $metadata = null): array
    {
        $body = ['tasks' => $tasks];
        if ($tag !== null) {
            $body['tag'] = $tag;
        }
        if ($metadata) {
            $body['metadata'] = $metadata;
        }
        return $this->c->request('POST', '/jobs', $body, ['include' => 'tasks']);
    }

    /** A job with its tasks. */
    public function get(string $id): array
    {
        return $this->c->request('GET', '/jobs/' . rawurlencode($id), null, ['include' => 'tasks']);
    }

    /**
     * One page of jobs, newest first.
     * @return array{data: array, meta: array}
     */
    public function list(?string $status = null, ?string $tag = null, ?int $page = null, ?int $perPage = null, bool $includeTasks = false): array
    {
        [$data, $meta] = $this->c->requestRaw('GET', '/jobs', null, [
            'filter[status]' => $status, 'filter[tag]' => $tag, 'page' => $page, 'per_page' => $perPage,
            'include' => $includeTasks ? 'tasks' : null,
        ]);
        return ['data' => $data ?? [], 'meta' => $meta];
    }

    /** Every job, page by page: foreach ($rc->jobs->all(status: 'finished') as $job). */
    public function all(?string $status = null, ?string $tag = null, ?int $perPage = null): \Generator
    {
        for ($page = 1; ; $page++) {
            $result = $this->list($status, $tag, $page, $perPage);
            yield from $result['data'];
            $total = $result['meta']['total_pages'] ?? null;
            if (!$result['data'] || ($total !== null && $page >= $total)) {
                return;
            }
        }
    }

    /** Cancels pending tasks; credits for unfinished tasks are refunded. */
    public function cancel(string $id): array
    {
        return $this->c->request('POST', '/jobs/' . rawurlencode($id) . '/cancel');
    }

    /** Deletes a job. */
    public function delete(string $id): void
    {
        $this->c->request('DELETE', '/jobs/' . rawurlencode($id));
    }

    /**
     * Polls until finished or error (0.7 s, slowing to 3 s).
     * Throws JobFailedException for a failed job unless $throwOnError is false.
     */
    public function wait(string $id, ?float $timeout = null, ?callable $onUpdate = null, bool $throwOnError = true): array
    {
        $started = microtime(true);
        $delay = 0.7;
        while (true) {
            $job = $this->get($id);
            if ($onUpdate) {
                $onUpdate($job);
            }
            if (($job['status'] ?? '') === 'finished') {
                return $job;
            }
            if (($job['status'] ?? '') === 'error') {
                if (!$throwOnError) {
                    return $job;
                }
                $failed = null;
                foreach ($job['tasks'] ?? [] as $t) {
                    if (($t['status'] ?? '') === 'error') {
                        $failed = $t;
                        break;
                    }
                }
                throw new JobFailedException($job, $failed);
            }
            if ($timeout !== null && microtime(true) - $started + $delay > $timeout) {
                throw new WaitTimeoutException($job, $timeout);
            }
            $this->c->pause($delay);
            $delay = min($delay + 0.3, 3.0);
        }
    }

    /** The task with this name, or null. */
    public static function task(array $job, string $name): ?array
    {
        foreach ($job['tasks'] ?? [] as $t) {
            if (($t['name'] ?? null) === $name) {
                return $t;
            }
        }
        return null;
    }

    /** The files produced by the job's export/url tasks. */
    public static function exportedFiles(array $job): array
    {
        $files = [];
        foreach ($job['tasks'] ?? [] as $t) {
            if (($t['operation'] ?? '') === 'export/url') {
                array_push($files, ...($t['result']['files'] ?? []));
            }
        }
        return $files;
    }
}
