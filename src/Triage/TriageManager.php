<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Triage;

use Illuminate\Support\Manager;
use Illuminate\Support\Str;
use Throwable;
use UnexpectedValueException;
use YousefAman\FeedbackBoard\Triage\Contracts\ChecksConfiguration;
use YousefAman\FeedbackBoard\Triage\Contracts\TriageDriver;
use YousefAman\FeedbackBoard\Triage\Drivers\AnthropicDriver;
use YousefAman\FeedbackBoard\Triage\Drivers\FakeTriageDriver;
use YousefAman\FeedbackBoard\Triage\Drivers\GeminiDriver;
use YousefAman\FeedbackBoard\Triage\Drivers\OpenAiDriver;

class TriageManager extends Manager
{
    /** Whether a driver that failed to build has been reported, so a broken one cannot flood the error tracker. */
    private bool $reportedBrokenDriver = false;

    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('feedback-board.ai.default', 'anthropic');
    }

    /**
     * Whether the selected driver can triage: it exists (built in or added by the app) and, when it can
     * tell, its settings are complete. An unknown name is not configured; neither is a driver that fails
     * to build or is not a TriageDriver, which is reported once per manager (per request or worker).
     */
    public function configured(): bool
    {
        $name = $this->getDefaultDriver();
        if (! $this->knows($name)) {
            return false; // empty, unknown or misspelt driver name
        }

        try {
            $driver = $this->driver($name);
        } catch (Throwable $e) {
            $this->reportBrokenDriver($e);

            return false;
        }

        if (! $driver instanceof TriageDriver) {
            $this->reportBrokenDriver(new UnexpectedValueException("The triage driver [{$name}] is not a ".TriageDriver::class.'.'));

            return false;
        }

        return ! $driver instanceof ChecksConfiguration || $driver->configured();
    }

    /** A driver this manager can build: one of its own, or one an app added with extend(). */
    private function knows(string $name): bool
    {
        return $name !== ''
            && (isset($this->customCreators[$name]) || method_exists($this, 'create'.Str::studly($name).'Driver'));
    }

    private function reportBrokenDriver(Throwable $e): void
    {
        if (! $this->reportedBrokenDriver) {
            $this->reportedBrokenDriver = true;
            report($e);
        }
    }

    protected function createFakeDriver(): TriageDriver
    {
        return new FakeTriageDriver();
    }

    protected function createAnthropicDriver(): TriageDriver
    {
        return new AnthropicDriver();
    }

    protected function createGeminiDriver(): TriageDriver
    {
        return new GeminiDriver();
    }

    protected function createOpenaiDriver(): TriageDriver
    {
        return new OpenAiDriver();
    }
}
