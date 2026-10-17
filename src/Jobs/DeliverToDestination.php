<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Jobs;

use DateTimeInterface;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;
use YousefAman\FeedbackBoard\Destinations\DestinationManager;
use YousefAman\FeedbackBoard\Destinations\Exceptions\DeliveryFailed;
use YousefAman\FeedbackBoard\Destinations\Exceptions\RetryAfter;
use YousefAman\FeedbackBoard\Destinations\Support\DeliveryError;
use YousefAman\FeedbackBoard\Destinations\Support\DestinationEvent;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Support\MailLayout;

class DeliverToDestination implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $maxExceptions = 4;

    public function __construct(
        public object $event,
        public string $driver,
        public string $deliveryId,
    ) {}

    /** Bound retries by time so a Retry-After release never marches toward MaxAttemptsExceeded. */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(15);
    }

    /**
     * Seconds before attempts 2, 3 and 4 (the last value repeats). Picked by attempt number, so a
     * RetryAfter release uses one up; with $maxExceptions = 4 a failing delivery gives up after its
     * 4th error. The job's backoff() overrides `queue:work --backoff`.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(DestinationManager $manager): void
    {
        $item = DestinationEvent::item($this->event);
        if ($item === null) {
            return; // item/comment deleted between enqueue and processing — no-op, no retry
        }

        // Resolved outside the try: an unknown driver name is a configuration error, not invalid_url.
        $driver = $manager->driver($this->driver);

        try {
            $driver->send($item, $this->event, $this->deliveryId);
        } catch (RetryAfter $e) {
            $this->release($e->seconds);
        } catch (ConnectionException) {
            // Its message ends with the URL: never let it reach a log or failed_jobs.
            throw new DeliveryFailed($this->driver, 'connection_error');
        } catch (TransferException) {
            // Before Laravel 12.34 other cURL errors arrive raw from Guzzle, their message ending with the URL too.
            throw new DeliveryFailed($this->driver, 'connection_error');
        } catch (InvalidArgumentException) {
            // An unparsable configured URL (Guzzle's MalformedUriException) names the whole URL too.
            throw new DeliveryFailed($this->driver, 'invalid_url');
        }
    }

    public function failed(Throwable $e): void
    {
        $item = DestinationEvent::item($this->event);
        if ($item === null) {
            return;
        }

        $code = DeliveryError::code($e);
        $event = DestinationEvent::name($this->event);

        // Write under the same per-item lock the GitHub driver uses, so a
        // failure write and a concurrent ref write cannot lost-update external_refs.
        $store = config('feedback-board.destinations.lock_store');
        Cache::store($store)->lock('feedback-board:github:'.$item->id, 10)->block(5, function () use ($item, $code, $event) {
            $fresh = FeedbackItem::find($item->id);
            if ($fresh === null) {
                return;
            }
            $refs = $fresh->external_refs ?? [];
            $refs[$this->driver.'_last_error'] = [
                'code' => $code,
                'event' => $event,
                'at' => now()->toIso8601String(),
            ];
            $fresh->external_refs = $refs;
            $fresh->save();
        });

        $this->notifyDevelopers($item, $code);
    }

    /** Best-effort Filament database notification; never throws, never leaks secrets. */
    protected function notifyDevelopers(FeedbackItem $item, string $code): void
    {
        if (! class_exists(\Filament\Notifications\Notification::class)) {
            return;
        }

        // Reuse the existing recipient resolver (it owns the
        // feedback-board.notifications.developers key — intentionally NOT under
        // destinations.*; do not "correct" it). Keep only DB-routable model
        // notifiables: the array-of-emails config yields AnonymousNotifiable
        // (mail route), which sendToDatabase() cannot persist.
        try {
            $notifiables = app(\YousefAman\FeedbackBoard\Support\DeveloperRecipients::class)
                ->resolve()
                ->filter(fn ($n) => $n instanceof \Illuminate\Database\Eloquent\Model);
            if ($notifiables->isEmpty()) {
                return; // email-only recipients have no database notification target
            }

        } catch (Throwable) {
            return; // best-effort: a notification failure must not mask the delivery failure
        }

        // One entry per developer, each with the link to the board they can open; one failing
        // recipient does not stop the others.
        foreach ($notifiables as $notifiable) {
            try {
                \Filament\Notifications\Notification::make()
                    ->title(__('feedback-board::feedback-board.destinations.failed_title', ['destination' => DeliveryError::destination($this->driver)]))
                    ->body(Str::ucfirst(DeliveryError::reason($code)))
                    ->danger()
                    ->actions(MailLayout::bellButton(app(\YousefAman\FeedbackBoard\FeedbackBoard::class)->reportUrl($item, 'developer', $notifiable)))
                    ->sendToDatabase($notifiable);
            } catch (Throwable) {
                // best-effort, as above
            }
        }
    }
}
