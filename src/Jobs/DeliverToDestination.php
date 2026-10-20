<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Jobs;

use DateTimeInterface;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;
use YousefAman\FeedbackBoard\Destinations\DestinationManager;
use YousefAman\FeedbackBoard\Destinations\Exceptions\DeliveryFailed;
use YousefAman\FeedbackBoard\Destinations\Exceptions\RetryAfter;
use YousefAman\FeedbackBoard\Destinations\Support\DeliveryError;
use YousefAman\FeedbackBoard\Destinations\Support\DestinationEvent;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Notifications\DeliveryFailedNotification;
use YousefAman\FeedbackBoard\Support\{DeveloperRecipients, MailLayout};

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
     * 4th error. A refusal that waiting cannot fix fails at its first (see handle()). The job's
     * backoff() overrides `queue:work --backoff`.
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
        } catch (RequestException $e) {
            // A revoked key or a wrong repository: fail now, so the card names the reason at once.
            // Rethrown either way, so the exception is reported as before and the worker retries
            // only what may still get through.
            if (DeliveryError::permanent($e)) {
                $this->fail($e);
            }

            throw $e;
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

    /**
     * Each developer hears of the failure once: a Filament bell entry for those who are user models,
     * when Filament is installed, and the day's e-mail for everyone else (mailDevelopers()): all of
     * them without Filament, the ones listed only by address with it. Best-effort: never throws,
     * never leaks secrets.
     */
    protected function notifyDevelopers(FeedbackItem $item, string $code): void
    {
        // The recipient resolver owns the feedback-board.notifications.developers key, which is
        // intentionally NOT under destinations.*; do not "correct" it.
        try {
            // A resolver callback may return anything: only objects can be notified.
            $developers = app(DeveloperRecipients::class)->resolve()->filter(fn ($developer): bool => is_object($developer));
        } catch (Throwable) {
            return; // best-effort: a notification failure must not mask the delivery failure
        }

        // sendToDatabase() persists only models: an address from the config (an AnonymousNotifiable,
        // mail route) has no bell.
        [$bell, $mail] = $this->usesFilamentBell()
            ? $developers->partition(fn (object $developer): bool => $developer instanceof Model)->all()
            : [collect(), $developers];

        $this->ringBell($bell, $item, $code);
        $this->mailDevelopers($mail, $item, $code);
    }

    /**
     * One bell entry per developer, each with the link to the board they can open; one failing
     * recipient does not stop the others.
     *
     * @param  Collection<int, object>  $developers
     */
    protected function ringBell(Collection $developers, FeedbackItem $item, string $code): void
    {
        foreach ($developers as $developer) {
            try {
                \Filament\Notifications\Notification::make()
                    ->title(__('feedback-board::feedback-board.destinations.failed_title', ['destination' => DeliveryError::destination($this->driver)]))
                    ->body(Str::ucfirst(DeliveryError::reason($code)))
                    ->danger()
                    ->actions(MailLayout::bellButton(app(\YousefAman\FeedbackBoard\FeedbackBoard::class)->reportUrl($item, 'developer', $developer)))
                    ->sendToDatabase($developer);
            } catch (Throwable) {
                // best-effort, as above
            }
        }
    }

    /** Whether Filament is installed, whose database notifications fill a panel's bell. */
    protected function usesFilamentBell(): bool
    {
        return class_exists(\Filament\Notifications\Notification::class);
    }

    /**
     * Tells these developers by e-mail, once a day per destination and reason: the day's first
     * failure of a kind sends it, and every failing report still shows its own on its card.
     * Nothing when the app sends its developers no mail (notifications.channels).
     *
     * @param  Collection<int, object>  $developers
     */
    protected function mailDevelopers(Collection $developers, FeedbackItem $item, string $code): void
    {
        if (! in_array('mail', (array) config('feedback-board.notifications.channels', ['mail']), true)) {
            return;
        }

        $notification = new DeliveryFailedNotification($item, $this->driver, $code);

        try {
            // Only developers with an address: one without would use up the day's e-mail.
            $recipients = $developers->filter(fn (object $recipient): bool => method_exists($recipient, 'routeNotificationFor')
                && filled($recipient->routeNotificationFor('mail', $notification)));
        } catch (Throwable) {
            return; // best-effort, as for the bell
        }

        if ($recipients->isEmpty()) {
            return;
        }

        // The cache that holds the delivery locks, so every worker counts the same day.
        $cache = Cache::store(config('feedback-board.destinations.lock_store'));
        $key = 'feedback-board:failure-mail:'.$this->driver.':'.$code;

        if (! rescue(fn () => $cache->add($key, true, now()->addDay()), false)) {
            return; // e-mailed already today, or the cache is down
        }

        $sent = false;

        foreach ($recipients as $recipient) {
            $sent = rescue(function () use ($recipient, $notification): bool {
                NotificationFacade::send($recipient, $notification);

                return true;
            }, false) || $sent;
        }

        // Nobody got it (the mail server is down?): try again in 15 minutes rather than on every
        // failure, so a mail server that hangs cannot hold up each failed delivery.
        if (! $sent) {
            rescue(fn () => $cache->put($key, true, now()->addMinutes(15)), report: false);
        }
    }
}
