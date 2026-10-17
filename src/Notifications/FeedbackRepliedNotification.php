<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use YousefAman\FeedbackBoard\Models\FeedbackComment;
use YousefAman\FeedbackBoard\Support\{MailLayout, MailText};

class FeedbackRepliedNotification extends Notification
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

        return MailLayout::message($item, 'reporter')
            ->subject(__('feedback-board::feedback-board.mail.reply_subject', ['title' => $item->title]))
            ->line(__('feedback-board::feedback-board.mail.reply_line', ['id' => $item->id]))
            ->line($body !== '' ? MailText::escape($body) : __('feedback-board::feedback-board.mail.reply_images'));
    }

    public function toArray(object $notifiable): array
    {
        $item = $this->comment->item;

        // Filament renders a notification title as (sanitized) HTML: the reporter's title must be text.
        return MailLayout::database(
            __('feedback-board::feedback-board.mail.reply_subject', ['title' => e($item->title)]),
            $item,
            'reporter',
            ['feedback_item_id' => $item->id, 'kind' => 'reply'],
        );
    }
}
