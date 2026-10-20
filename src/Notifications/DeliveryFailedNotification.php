<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use YousefAman\FeedbackBoard\Destinations\Support\DeliveryError;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Support\{MailLayout, MailText};

/**
 * Tells the developers whom no Filament bell reaches that a delivery to a destination failed. By
 * mail only, at most once a day per destination and reason (see
 * DeliverToDestination::mailDevelopers()). It carries only the destination and the error code,
 * never the error itself.
 */
class DeliveryFailedNotification extends Notification
{
    use Queueable;

    public function __construct(public FeedbackItem $item, public string $driver, public string $code) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $destination = DeliveryError::destination($this->driver);

        return MailLayout::message($this->item, 'developer', $notifiable)
            ->subject(__('feedback-board::feedback-board.destinations.failed_title', ['destination' => $destination]))
            ->line(__('feedback-board::feedback-board.destinations.failed', ['destination' => $destination, 'reason' => DeliveryError::reason($this->code)]))
            ->line(__('feedback-board::feedback-board.mail.failure_report', ['id' => $this->item->id]))
            ->line(MailText::line($this->item->title))
            ->line(__('feedback-board::feedback-board.mail.failure_pause', ['destination' => $destination]));
    }
}
