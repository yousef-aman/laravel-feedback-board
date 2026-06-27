<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Destinations\Support;

trait SignsPayloads
{
    /**
     * HMAC-SHA256 over "{timestamp}.{rawBody}" as bare lowercase hex.
     * Identical to the inbound ingest verification formula.
     */
    protected function sign(string $ts, string $body, string $secret): string
    {
        return hash_hmac('sha256', $ts.'.'.$body, $secret);
    }

    protected function verifySignature(string $expected, string $actual): bool
    {
        return hash_equals($expected, $actual);
    }
}
