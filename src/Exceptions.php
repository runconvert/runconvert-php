<?php

declare(strict_types=1);

namespace RunConvert;

/**
 * The API said no: {"success": false, "error": {"code", "message"}}. New codes can appear,
 * so branch on getStatus() for anything you don't recognise.
 */
class RunConvertException extends \RuntimeException
{
    public function __construct(string $message, private int $status = 0, private string $errorCode = '', private mixed $body = null)
    {
        parent::__construct($message, $status);
    }

    /** HTTP status, 0 when the request never got a response. */
    public function getStatus(): int
    {
        return $this->status;
    }

    /** API error code, e.g. BILLING_INSUFFICIENT_CREDITS ('' when none). */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getBody(): mixed
    {
        return $this->body;
    }
}

/** The job ended with status "error"; getTask() is the first failed task (code, reason, message). */
class JobFailedException extends RunConvertException
{
    public function __construct(private array $job, private ?array $task = null)
    {
        parent::__construct(($task['message'] ?? '') ?: "Job {$job['id']} failed", 0, (string) ($task['code'] ?? ''), $job);
    }

    public function getJob(): array
    {
        return $this->job;
    }

    public function getTask(): ?array
    {
        return $this->task;
    }
}

/** Jobs::wait() gave up before the job reached finished or error. */
class WaitTimeoutException extends RunConvertException
{
    public function __construct(private array $job, float $timeout)
    {
        parent::__construct("Job {$job['id']} still {$job['status']} after " . round($timeout) . 's', 0, 'WAIT_TIMEOUT', $job);
    }

    public function getJob(): array
    {
        return $this->job;
    }
}

/** A transport failure (no HTTP response). @internal */
class TransportException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $reachedServer = false)
    {
        parent::__construct($message);
    }
}

/** Result of RunConvert::convert(). */
final class ConvertResult
{
    /**
     * @param array    $job   The finished job, with its tasks.
     * @param array[]  $files The converted files: filename, url (temporary), size.
     * @param string[] $saved Local paths, when out_dir was given.
     */
    public function __construct(public readonly array $job, public readonly array $files, public readonly array $saved)
    {
    }
}
