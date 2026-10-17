<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Support;

use YousefAman\FeedbackBoard\FeedbackBoard;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

/**
 * The start of every package e-mail: the package's Markdown template (feedback-board::mail.notification)
 * inside the app's mail layout, the app's mail theme with right-to-left alignment added
 * (feedback-board::mail.theme), and a button that opens the report for the e-mail's audience
 * ('reporter' or 'developer', see FeedbackBoard::reportUrl()) when there is a link.
 */
final class MailLayout
{
    public static function message(FeedbackItem $item, string $audience): ReportMailMessage
    {
        return (new ReportMailMessage(app(FeedbackBoard::class)->reportUrl($item, $audience)))
            ->markdown('feedback-board::mail.notification')
            ->theme('feedback-board::mail.theme');
    }

    /**
     * A database notification: Filament's bell format, with a button that opens the report, when
     * Filament is installed; otherwise the payload alone. Either way the payload keeps its keys and
     * gains `feedback_url`.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function database(string $title, FeedbackItem $item, string $audience, array $payload): array
    {
        $url = app(FeedbackBoard::class)->reportUrl($item, $audience);
        $payload['feedback_url'] = $url;

        if (! class_exists(\Filament\Notifications\Notification::class)) {
            return $payload;
        }

        $notification = \Filament\Notifications\Notification::make()->title($title);

        if ($url !== null) {
            $notification->actions([
                \Filament\Actions\Action::make('view')
                    ->label(__('feedback-board::feedback-board.mail.view_report'))
                    ->url($url)
                    ->markAsRead(),
            ]);
        }

        // Filament's bell only renders rows carrying format:'filament'; its own keys win over the payload's.
        return $notification->getDatabaseMessage() + $payload;
    }
}
