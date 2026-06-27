<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Destinations;

use Illuminate\Support\Manager;
use YousefAman\FeedbackBoard\Destinations\Contracts\Destination;
use YousefAman\FeedbackBoard\Destinations\Drivers\GithubIssueDriver;
use YousefAman\FeedbackBoard\Destinations\Drivers\MailDriver;
use YousefAman\FeedbackBoard\Destinations\Drivers\SlackDriver;
use YousefAman\FeedbackBoard\Destinations\Drivers\WebhookDriver;

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

    protected function createWebhookDriver(): Destination
    {
        return new WebhookDriver();
    }

    protected function createSlackDriver(): Destination
    {
        return new SlackDriver();
    }

    protected function createGithubDriver(): Destination
    {
        return new GithubIssueDriver();
    }
}
