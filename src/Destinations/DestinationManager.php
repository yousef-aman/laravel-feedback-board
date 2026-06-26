<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Destinations;

use Illuminate\Support\Manager;
use YousefAman\FeedbackBoard\Destinations\Contracts\Destination;
use YousefAman\FeedbackBoard\Destinations\Drivers\MailDriver;

class DestinationManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return $this->config->get('feedback-board.destinations.default', 'mail');
    }

    protected function createMailDriver(): Destination
    {
        return new MailDriver();
    }
}
