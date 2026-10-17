<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Support\MailLayout;

class FeedbackResolvedNotification extends Notification
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
        return MailLayout::message($this->item, 'reporter')
            ->subject(__('feedback-board::feedback-board.mail.resolved_subject', ['title' => $this->item->title]))
            ->line(__('feedback-board::feedback-board.mail.resolved_line'));
    }

    public function toArray(object $notifiable): array
    {
        return MailLayout::database(
            __('feedback-board::feedback-board.mail.resolved_subject', ['title' => e($this->item->title)]),
            $this->item,
            'reporter',
            ['feedback_item_id' => $this->item->id, 'kind' => 'resolved'],
        );
    }
}
