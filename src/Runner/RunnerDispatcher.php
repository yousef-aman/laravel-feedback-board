<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Runner;

use Illuminate\Support\Facades\Http;
use Throwable;
use YousefAman\FeedbackBoard\Destinations\Support\SignsPayloads;
use YousefAman\FeedbackBoard\Destinations\Support\TransientRetry;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Runner\Exceptions\RunnerException;
use YousefAman\FeedbackBoard\Runner\Support\RunnerError;
use YousefAman\FeedbackBoard\Runner\Support\RunnerTaskPayload;

class RunnerDispatcher
{
    use SignsPayloads;

    public function send(FeedbackItem $item, string $taskId): void
    {
        $endpoint = config('feedback-board.runner.endpoint');
        $secret = config('feedback-board.runner.secret');
        if (! is_string($endpoint) || $endpoint === '' || ! is_string($secret) || $secret === '') {
            throw new RunnerException('not_configured');
        }

        $callbackUrl = config('feedback-board.runner.callback_url');
        // json_encode ONCE; sign those exact bytes; send the same bytes via withBody.
        $raw = json_encode(
            RunnerTaskPayload::build($item, $taskId, is_string($callbackUrl) ? $callbackUrl : null),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $ts = (string) now()->timestamp;
        $sig = $this->sign($ts, $raw, $secret);

        try {
            Http::timeout((int) config('feedback-board.runner.timeout', 8))
                ->connectTimeout((int) config('feedback-board.runner.connect_timeout', 4))
                ->retry(2, 200, TransientRetry::when(), throw: false)
                ->withHeaders([
                    'X-Feedback-Timestamp' => $ts,
                    'X-Feedback-Signature' => $sig,
                    'X-Feedback-Event' => 'runner.task',
                    'X-Feedback-Delivery' => $taskId,
                ])
                ->withBody($raw, 'application/json')
                ->post($endpoint)
                ->throw();
        } catch (RunnerException $e) {
            throw $e;
        } catch (Throwable $e) {
            // Sanitize: no $previous, so (string) cannot leak the secret/body/endpoint.
            throw new RunnerException(RunnerError::code($e));
        }
    }
}
