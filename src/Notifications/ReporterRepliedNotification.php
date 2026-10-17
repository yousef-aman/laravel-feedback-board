<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use YousefAman\FeedbackBoard\Models\FeedbackComment;
use YousefAman\FeedbackBoard\Support\{MailLayout, MailText};

/** Tells developers that a reporter answered, and on which report. */
class ReporterRepliedNotification extends Notification
{
    use Queueable;

    public function __construct(public FeedbackComment $comment) {}

    public function via(object $notifiable): array
    {
        $channels = config('feedback-board.notifications.channels', ['mail']);
        // on-demand (email-only) recipients can't receive the database channel
        return $notifiable instanceof \Illuminate\Notifications\AnonymousNotifiable
            ? array_values(array_diff($channels, ['database']))
            : $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $item = $this->comment->item;
        $body = trim((string) $this->comment->body);

        return MailLayout::message()
            ->subject(__('feedback-board::feedback-board.mail.reporter_reply_subject', ['title' => $item->title]))
            ->line(__('feedback-board::feedback-board.mail.reporter_reply_line', ['id' => $item->id]))
            ->line($body !== '' ? MailText::escape($body) : __('feedback-board::feedback-board.mail.reply_images'));
    }

    public function toArray(object $notifiable): array
    {
        $item = $this->comment->item;
        $payload = ['feedback_item_id' => $item->id, 'kind' => 'reporter_reply'];

        // Filament renders a notification title as (sanitized) HTML: the reporter's title must be text.
        if (class_exists(\Filament\Notifications\Notification::class)) {
            return \Filament\Notifications\Notification::make()
                ->title(__('feedback-board::feedback-board.mail.reporter_reply_subject', ['title' => e($item->title)]))
                ->getDatabaseMessage() + $payload;
        }

        return $payload;
    }
}
