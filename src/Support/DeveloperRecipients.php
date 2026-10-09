<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Support;

use Illuminate\Support\Collection;
use YousefAman\FeedbackBoard\FeedbackBoard;

class DeveloperRecipients
{
    public function resolve(): Collection
    {
        return app(FeedbackBoard::class)->developerRecipients();
    }
}
