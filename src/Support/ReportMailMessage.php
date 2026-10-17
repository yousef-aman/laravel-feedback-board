<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Support;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * A mail message whose button opens the report. The button is added when the message is rendered,
 * not when it is built: MailMessage turns every line added after action() into an outro line, so
 * the lines stay intro lines and the button follows them. A button the notification sets itself wins.
 */
final class ReportMailMessage extends MailMessage
{
    public function __construct(private readonly ?string $reportUrl) {}

    /** @return array<string, mixed> */
    public function toArray()
    {
        if ($this->actionText === null && $this->reportUrl !== null) {
            return (clone $this)->action(__('feedback-board::feedback-board.mail.view_report'), $this->reportUrl)->toArray();
        }

        return parent::toArray();
    }
}
