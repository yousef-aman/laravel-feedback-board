<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Support\{MailLayout, MailText};

class NewFeedbackNotification extends Notification
{
    use Queueable;

    public function __construct(public FeedbackItem $item) {}

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
        return MailLayout::message($this->item, 'developer')
            ->subject(__('feedback-board::feedback-board.mail.new_subject', ['title' => $this->item->title]))
            ->line(__('feedback-board::feedback-board.mail.new_line'))
            ->line(MailText::escape($this->item->title));
    }

    public function toArray(object $notifiable): array
    {
        // `feedback_title`, not `title`: Filament's own `title` wins in `getDatabaseMessage() + $payload`.
        // Filament renders a notification title as (sanitized) HTML: the reporter's title must be text.
        return MailLayout::database(
            __('feedback-board::feedback-board.mail.new_subject', ['title' => e($this->item->title)]),
            $this->item,
            'developer',
            ['feedback_item_id' => $this->item->id, 'feedback_title' => $this->item->title, 'kind' => 'new'],
        );
    }
}
