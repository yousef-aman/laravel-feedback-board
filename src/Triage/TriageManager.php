<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Triage;

use Illuminate\Support\Manager;
use InvalidArgumentException;
use Throwable;
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
     * tell, its settings are complete. An unknown name, or a driver that fails to build, is not
     * configured; the failure is reported once.
     */
    public function configured(): bool
    {
        $name = $this->getDefaultDriver();
        if ($name === '') {
            return false;
        }

        try {
            $driver = $this->driver($name);
        } catch (InvalidArgumentException) {
            return false; // unknown or misspelt driver name
        } catch (Throwable $e) {
            if (! $this->reportedBrokenDriver) {
                $this->reportedBrokenDriver = true;
                report($e);
            }

            return false;
        }

        return ! $driver instanceof ChecksConfiguration || $driver->configured();
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
