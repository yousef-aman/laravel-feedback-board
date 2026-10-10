<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Listeners;

use Closure;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use YousefAman\FeedbackBoard\Enums\{AuthorRole, FeedbackStatus};
use YousefAman\FeedbackBoard\Events\{FeedbackItemCreated, FeedbackReplied, FeedbackStatusChanged};
use YousefAman\FeedbackBoard\Notifications\{FeedbackRepliedNotification, FeedbackResolvedNotification, NewFeedbackNotification};
use YousefAman\FeedbackBoard\Support\DeveloperRecipients;

class SendFeedbackNotifications
{
    public function __construct(protected DeveloperRecipients $recipients) {}

    public function onCreated(FeedbackItemCreated $event): void
    {
        $this->sendAfterCommit(function () use ($event) {
            $this->notifyEach($this->recipients->resolve(), new NewFeedbackNotification($event->item));
        });
    }

    public function onReplied(FeedbackReplied $event): void
    {
        $comment = $event->comment;
        if ($comment->is_internal) {
            return;
        }

        // developer reply -> notify reporter; reporter reply -> notify developers
        if ($comment->author_role === AuthorRole::Reporter) {
            $this->sendAfterCommit(function () use ($comment) {
                $this->notifyEach($this->recipients->resolve(), new FeedbackRepliedNotification($comment));
            });

            return;
        }

        $this->sendAfterCommit(fn () => $comment->item->reporter?->notify(new FeedbackRepliedNotification($comment)));
    }

    public function onStatusChanged(FeedbackStatusChanged $event): void
    {
        if ($event->to !== FeedbackStatus::Resolved) {
            return;
        }

        $this->sendAfterCommit(fn () => $event->item->reporter?->notify(new FeedbackResolvedNotification($event->item)));
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            FeedbackItemCreated::class => 'onCreated',
            FeedbackReplied::class => 'onReplied',
            FeedbackStatusChanged::class => 'onStatusChanged',
        ];
    }

    /**
     * Send only once the action's transaction has committed (immediately when there is none),
     * and best-effort: a failure is reported to the host's exception handler, never thrown,
     * so it can neither roll back nor fail the user's report, reply or status change.
     */
    protected function sendAfterCommit(Closure $send): void
    {
        DB::afterCommit(fn () => rescue($send, report: true));
    }

    /**
     * Send to each recipient on its own, so one failing recipient (a malformed address,
     * a missing notifications table) cannot stop the notification for the others.
     *
     * @param  iterable<object>  $notifiables
     */
    protected function notifyEach(iterable $notifiables, Notification $notification): void
    {
        foreach ($notifiables as $notifiable) {
            rescue(fn () => NotificationFacade::send($notifiable, $notification), report: true);
        }
    }
}
