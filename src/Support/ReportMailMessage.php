<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Support;

use Illuminate\Notifications\Action;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A mail message whose button opens the report. The button is set at once, so actionText and
 * actionUrl show it, yet lines added after it stay intro lines and come before it: MailMessage
 * would make them outro lines. A button the notification sets itself replaces it, and lines after
 * that one become outro lines as usual.
 */
final class ReportMailMessage extends MailMessage
{
    private bool $reportButton = false;

    public function __construct(?string $reportUrl)
    {
        if ($reportUrl !== null) {
            parent::action(__('feedback-board::feedback-board.mail.view_report'), $reportUrl);
            $this->reportButton = true;
        }
    }

    public function action($text, $url)
    {
        $this->reportButton = false;

        return parent::action($text, $url);
    }

    public function with($line)
    {
        if ($this->reportButton && ! $line instanceof Action) {
            $this->introLines[] = $this->formatLine($line);

            return $this;
        }

        return parent::with($line);
    }
}
