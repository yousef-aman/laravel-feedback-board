<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Destinations\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;
use Throwable;
use YousefAman\FeedbackBoard\Destinations\Exceptions\DeliveryFailed;
use YousefAman\FeedbackBoard\Support\Time;

final class DeliveryError
{
    /**
     * Map any throwable to a fixed, secret-free vocabulary. NEVER returns
     * the message, response body, or any URL (a Guzzle/Slack message embeds
     * the webhook URL, which is itself a secret).
     */
    public static function code(Throwable $e): string
    {
        if ($e instanceof DeliveryFailed) {
            return $e->errorCode; // connection_error or invalid_url, set by DeliverToDestination
        }

        if ($e instanceof ConnectionException) {
            return 'connection_error';
        }

        if ($e instanceof RequestException) {
            $status = $e->response?->status() ?? 0;

            return match (true) {
                $status === 401 || $status === 403 => 'auth_401',
                $status === 404 || $status === 410 => 'not_found_404', // Slack: 410 for an archived channel
                $status === 400 || $status === 422 => 'validation_422', // Slack: 400 for a message it cannot read
                $status === 429 => 'http_429',
                $status >= 500 => 'http_5xx',
                default => 'unknown',
            };
        }

        return 'unknown';
    }

    /**
     * A refusal that waiting cannot fix: a rejected key or permission (401, 403), a missing target
     * (404, 410) or a request the destination cannot read (400, 422). A 403 that says the rate
     * limit is spent (GitHub sends one with x-ratelimit-remaining: 0 or Retry-After) is not one.
     */
    public static function permanent(Throwable $e): bool
    {
        if (! $e instanceof RequestException || $e->response === null) {
            return false;
        }

        $response = $e->response;

        if ($response->status() === 403 && ($response->header('x-ratelimit-remaining') === '0' || $response->header('Retry-After') !== '')) {
            return false;
        }

        return in_array($response->status(), [400, 401, 403, 404, 410, 422], true);
    }

    /** A driver's name as people know it: GitHub, Slack, Webhook, else the name in title case. */
    public static function destination(string $driver): string
    {
        return match ($driver) {
            'github' => 'GitHub',
            'slack' => 'Slack',
            'webhook' => 'Webhook',
            default => Str::headline($driver),
        };
    }

    /** Why a delivery failed, in the app's language, from its code; a code with no wording reads as unknown. */
    public static function reason(string $code): string
    {
        return self::phrase('reasons.'.$code) ?? (string) __('feedback-board::feedback-board.destinations.reasons.unknown');
    }

    /**
     * A recorded failure (external_refs.{driver}_last_error) as the board shows it: the sentence, the
     * event and how long ago, and the time itself; null when the record is not one.
     *
     * @return array{text: string, when: string, at: ?string}|null
     */
    public static function describe(string $driver, mixed $record): ?array
    {
        if (! is_array($record) || ! is_string($record['code'] ?? null)) {
            return null;
        }

        $event = is_string($record['event'] ?? null) ? Str::after($record['event'], 'feedback.') : '';
        $eventLabel = self::phrase('events.'.$event) ?? $event;
        $at = Time::parse($record['at'] ?? null);

        return [
            'text' => __('feedback-board::feedback-board.destinations.failed', [
                'destination' => self::destination($driver),
                'reason' => self::reason($record['code']),
            ]),
            'when' => $at === null ? $eventLabel : __('feedback-board::feedback-board.destinations.failed_when', ['event' => $eventLabel, 'time' => Time::ago($at)]),
            'at' => $at?->toIso8601String(),
        ];
    }

    /** A destinations.* translation, or null when there is no string for the key. */
    private static function phrase(string $key): ?string
    {
        $key = 'feedback-board::feedback-board.destinations.'.$key;
        $text = __($key);

        return is_string($text) && $text !== $key ? $text : null;
    }
}
