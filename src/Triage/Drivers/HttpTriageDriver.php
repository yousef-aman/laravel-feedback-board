<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Triage\Drivers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;
use YousefAman\FeedbackBoard\Destinations\Support\TransientRetry;
use YousefAman\FeedbackBoard\Enums\FeedbackPriority;
use YousefAman\FeedbackBoard\Enums\FeedbackType;
use YousefAman\FeedbackBoard\Triage\Contracts\ChecksConfiguration;
use YousefAman\FeedbackBoard\Triage\Contracts\TriageDriver;
use YousefAman\FeedbackBoard\Triage\Exceptions\TriageException;
use YousefAman\FeedbackBoard\Triage\Support\TriageError;
use YousefAman\FeedbackBoard\Triage\Support\TriageResult;

/**
 * A model provider reached over HTTP, configured under feedback-board.ai.{provider}: the key, the model,
 * the time budget, the retry and the sanitized errors are the same for every provider; the request and
 * the reading of its answer are each provider's own.
 */
abstract class HttpTriageDriver implements ChecksConfiguration, TriageDriver
{
    /**
     * Seconds the whole call may take, its retry included. It keeps the triage job, whose own timeout
     * is 60 seconds, clear of a worker's kill: each attempt's timeout is at most this, and a second
     * attempt starts only when it can finish within it.
     */
    public const TIME_BUDGET = 40;

    /** The key under feedback-board.ai that holds this provider's settings. */
    abstract protected function provider(): string;

    /** The model used when the settings have no model key at all (an older published config). */
    abstract protected function defaultModel(): string;

    /** Send one triage request through $http, which carries the timeout and the retry. */
    abstract protected function send(PendingRequest $http, string $key, string $model, string $title, ?string $description): Response;

    /**
     * The triage fields (type, priority, summary) from a successful answer, or null when it has none.
     *
     * @return array<string, mixed>|null
     */
    abstract protected function fields(Response $response): ?array;

    /** A sanitized error code for a refused request that its status alone would misname. */
    protected function errorCode(Response $response): ?string
    {
        return null;
    }

    public function configured(): bool
    {
        return $this->setting('api_key') !== '' && $this->model() !== '';
    }

    public function triage(string $title, ?string $description): TriageResult
    {
        if (! $this->configured()) {
            throw new TriageException('not_configured');
        }

        $model = $this->model();
        $timeout = min(max(1, (int) config($this->key('timeout'), 30)), self::TIME_BUDGET);
        $deadline = now()->addSeconds(self::TIME_BUDGET);
        $transient = TransientRetry::when();

        try {
            // retry() narrows to connection/429/5xx via TransientRetry, and to a second attempt that can end
            // within the budget; throw:false returns the final response so the checks below decide.
            $http = Http::timeout($timeout)
                ->retry(2, 200, fn (Throwable $e) => $transient($e) && now()->addSeconds($timeout)->lte($deadline), throw: false);

            $response = $this->send($http, $this->setting('api_key'), $model, $title, $description);

            if (! $response->successful()) {
                $code = $this->errorCode($response);
                if ($code !== null) {
                    throw new TriageException($code);
                }
                $response->throw();
            }

            return $this->result($this->fields($response), $model);
        } catch (TriageException $e) {
            throw $e; // already sanitized
        } catch (Throwable $e) {
            // Convert every transport-layer throwable (whose message/trace can embed the key's header)
            // into a sanitized code with NO previous.
            throw new TriageException(TriageError::code($e));
        }
    }

    /** A setting as a trimmed string; '' when it is missing or not a string. */
    protected function setting(string $name): string
    {
        $value = config($this->key($name));

        return is_string($value) ? trim($value) : '';
    }

    /** A positive whole-number setting, or null when it is unset (the provider's own limit applies). */
    protected function positiveInt(string $name): ?int
    {
        $value = config($this->key($name));

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function model(): string
    {
        return config($this->key('model')) === null ? $this->defaultModel() : $this->setting('model');
    }

    private function key(string $name): string
    {
        return 'feedback-board.ai.'.$this->provider().'.'.$name;
    }

    /** @param  array<string, mixed>|null  $fields */
    private function result(?array $fields, string $model): TriageResult
    {
        $summary = $fields['summary'] ?? null;
        if (! is_string($summary) || trim($summary) === '') {
            throw new TriageException('invalid_output');
        }

        $type = $fields['type'] ?? null;
        $priority = $fields['priority'] ?? null;

        return new TriageResult(
            is_string($type) ? FeedbackType::tryFrom($type) : null,
            is_string($priority) ? FeedbackPriority::tryFrom($priority) : null,
            $summary,
            $model,
        );
    }
}
