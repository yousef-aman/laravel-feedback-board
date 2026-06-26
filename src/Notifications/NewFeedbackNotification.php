<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

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
        return (new MailMessage)
            ->subject(__('feedback-board::feedback-board.mail.new_subject', ['title' => $this->item->title]))
            ->line(__('feedback-board::feedback-board.mail.new_line'))
            ->line($this->item->title);
    }

    public function toArray(object $notifiable): array
    {
        $payload = ['feedback_item_id' => $this->item->id, 'title' => $this->item->title, 'kind' => 'new'];

        // Filament's bell only renders rows carrying format:'filament' (see Global Constraints).
        if (class_exists(\Filament\Notifications\Notification::class)) {
            return \Filament\Notifications\Notification::make()
                ->title(__('feedback-board::feedback-board.mail.new_subject', ['title' => $this->item->title]))
                ->getDatabaseMessage() + $payload;
        }

        return $payload;
    }
}
