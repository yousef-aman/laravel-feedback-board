<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Destinations;

use Illuminate\Support\Manager;
use YousefAman\FeedbackBoard\Destinations\Contracts\DestinationDriver;
use YousefAman\FeedbackBoard\Destinations\Drivers\MailDriver;

class DestinationManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return $this->config->get('feedback-board.destinations.default', 'mail');
    }

    protected function createMailDriver(): DestinationDriver
    {
        return new MailDriver($this->container);
    }
}
