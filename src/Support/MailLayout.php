<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Support;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * The start of every package e-mail: the package's Markdown template (feedback-board::mail.notification)
 * inside the app's mail layout, and the app's mail theme with right-to-left alignment added
 * (feedback-board::mail.theme).
 */
final class MailLayout
{
    public static function message(): MailMessage
    {
        return (new MailMessage)
            ->markdown('feedback-board::mail.notification')
            ->theme('feedback-board::mail.theme');
    }
}
