<?php

declare(strict_types=1);

namespace RunConvert;

/**
 * Webhook signature checks. Every delivery has an X-RunConvert-Signature header:
 * "sha256=" + hex HMAC-SHA256 of the raw request body, keyed with the endpoint's secret.
 */
final class Webhooks
{
    /** True when the signature matches the raw body (pass it exactly as received). */
    public static function verify(string $rawBody, ?string $signature, string $secret): bool
    {
        if (!$signature || $secret === '') {
            return false;
        }
        $given = strtolower(trim($signature));
        if (str_starts_with($given, 'sha256=')) {
            $given = substr($given, 7);
        }
        if (preg_match('/^[0-9a-f]{64}$/', $given) !== 1) {
            return false;
        }
        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $given);
    }

    /** Verifies the signature, then parses the event. Throws if it doesn't match. */
    public static function constructEvent(string $rawBody, ?string $signature, string $secret): array
    {
        if (!self::verify($rawBody, $signature, $secret)) {
            throw new \UnexpectedValueException('Invalid RunConvert webhook signature');
        }
        return json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    }
}
