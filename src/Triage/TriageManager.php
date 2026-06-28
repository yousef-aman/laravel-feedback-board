<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Triage;

use Illuminate\Support\Manager;
use YousefAman\FeedbackBoard\Triage\Contracts\TriageDriver;
use YousefAman\FeedbackBoard\Triage\Drivers\AnthropicDriver;
use YousefAman\FeedbackBoard\Triage\Drivers\FakeTriageDriver;

class TriageManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('feedback-board.ai.default', 'anthropic');
    }

    protected function createFakeDriver(): TriageDriver
    {
        return new FakeTriageDriver();
    }

    protected function createAnthropicDriver(): TriageDriver
    {
        return new AnthropicDriver();
    }
}
