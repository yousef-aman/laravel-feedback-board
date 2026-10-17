<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use YousefAman\FeedbackBoard\Enums\FeedbackStatus;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Support\MailLayout;

/** Tells the reporter that an open report was closed or declined, so ended without a resolution. */
class FeedbackClosedNotification extends Notification
{
    use Queueable;

    /** @param  FeedbackStatus  $status  Closed or Declined */
    public function __construct(public FeedbackItem $item, public FeedbackStatus $status) {}

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
        return MailLayout::message($this->item, 'reporter', $notifiable)
            ->subject(__('feedback-board::feedback-board.mail.'.$this->kind().'_subject', ['title' => $this->item->title]))
            ->line(__('feedback-board::feedback-board.mail.'.$this->kind().'_line'));
    }

    public function toArray(object $notifiable): array
    {
        return MailLayout::database(
            __('feedback-board::feedback-board.mail.'.$this->kind().'_subject', ['title' => e($this->item->title)]),
            $this->item,
            'reporter',
            ['feedback_item_id' => $this->item->id, 'kind' => $this->kind()],
            $notifiable,
        );
    }

    /** 'declined', or 'closed' for any other status. */
    private function kind(): string
    {
        return $this->status === FeedbackStatus::Declined ? 'declined' : 'closed';
    }
}
