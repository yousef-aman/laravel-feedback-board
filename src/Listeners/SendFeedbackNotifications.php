<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Listeners;

use Closure;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
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
            $devs = $this->recipients->resolve();
            if ($devs->isNotEmpty()) {
                Notification::send($devs, new NewFeedbackNotification($event->item));
            }
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
                $devs = $this->recipients->resolve();
                if ($devs->isNotEmpty()) {
                    Notification::send($devs, new FeedbackRepliedNotification($comment));
                }
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
}
