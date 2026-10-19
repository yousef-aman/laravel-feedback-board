<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Destinations\Drivers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Localizable;
use RuntimeException;
use YousefAman\FeedbackBoard\Destinations\Contracts\Destination;
use YousefAman\FeedbackBoard\Destinations\Support\DeliveryRecord;
use YousefAman\FeedbackBoard\Destinations\Support\DestinationEvent;
use YousefAman\FeedbackBoard\Destinations\Support\TransientRetry;
use YousefAman\FeedbackBoard\Events\FeedbackStatusChanged;
use YousefAman\FeedbackBoard\FeedbackBoard;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Support\SlackText;

class SlackDriver implements Destination
{
    use Localizable;

    /** How much of the description the message shows, in characters. */
    private const DESCRIPTION_LIMIT = 300;

    public function send(FeedbackItem $item, object $event, string $deliveryId): void
    {
        $url = (string) config('feedback-board.destinations.slack.webhook_url');

        if ($url === '') {
            Log::warning('feedback-board: slack driver not configured; skipping delivery.');

            return;
        }

        // Slack's own language setting, else the current one: the app's in a queue worker.
        $message = $this->withLocale(self::locale(), fn () => $this->message($item, $event));

        $response = Http::asJson()->timeout(8)->connectTimeout(4)
            ->retry(2, 200, TransientRetry::when(), throw: false)
            ->post($url, $message);

        // Slack Incoming Webhooks return HTTP 200 with the literal text "ok". A refusal (404 for a
        // deleted webhook or workspace, 403, 410 for an archived channel) throws a RequestException,
        // so the report names the reason. Thrown here, after post() returned, its trace never holds
        // the URL. A Slack 429 (after internal retries by TransientRetry) throws too, failing the
        // job: we do NOT soft-release with RetryAfter for Slack — a missed Slack ping is an
        // acceptable loss, and the simplicity of failing fast beats silently queuing retries.
        $response->throw();

        if ($response->status() !== 200 || trim($response->body()) !== 'ok') {
            throw new RuntimeException('feedback-board: slack delivery rejected (status '.$response->status().').');
        }

        DeliveryRecord::clearFailure($item, 'slack');
    }

    /**
     * destinations.slack.locale, or null for the current locale. One Laravel's translator would
     * refuse is left out here, so it is not taken for a bad URL (see DeliverToDestination).
     */
    private static function locale(): ?string
    {
        $locale = config('feedback-board.destinations.slack.locale');

        if ($locale === null || $locale === '') {
            return null;
        }

        if (! is_string($locale) || Str::contains($locale, ['/', '\\', '..', "\0"])) {
            Log::warning('feedback-board: destinations.slack.locale is not a valid locale; using the app\'s.');

            return null;
        }

        return $locale;
    }

    /**
     * The Block Kit message, in the current locale. Text a reporter wrote stays plain text (the
     * header and the description) or is escaped for mrkdwn, where `verbatim` also keeps Slack from
     * turning bare URLs, #channels and mentions into links; so it can never mention or link.
     *
     * @return array{text: string, blocks: list<array<string, mixed>>}
     */
    private function message(FeedbackItem $item, object $event): array
    {
        $eventLabel = __('feedback-board::feedback-board.destinations.events.'.Str::after(DestinationEvent::name($event), 'feedback.'));
        $number = __('feedback-board::feedback-board.destinations.slack.number', ['id' => $item->getKey()]);
        $column = fn (string $name) => __('feedback-board::feedback-board.resource.columns.'.$name);
        $mrkdwn = fn (string $text) => ['type' => 'mrkdwn', 'text' => $text, 'verbatim' => true];
        $field = fn (string $label, string $value) => $mrkdwn('*'.SlackText::escape($label).":*\n".SlackText::escape($value));

        $status = $event instanceof FeedbackStatusChanged
            ? __('feedback-board::feedback-board.destinations.slack.status_change', ['from' => $event->from->label(), 'to' => $event->to->label()])
            : ($item->status?->label() ?? '—');

        // Slack rejects a blank header, so a report without a title is named by its number.
        $title = trim($item->title) !== '' ? $item->title : $number;

        $blocks = [
            ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => mb_strimwidth($title, 0, 150, '…')]],
            ['type' => 'context', 'elements' => [
                $mrkdwn('*'.SlackText::escape($eventLabel).'* · '.SlackText::escape($number)),
            ]],
        ];

        $description = Str::limit(Str::squish((string) $item->description), self::DESCRIPTION_LIMIT, '…');

        if ($description !== '') {
            $blocks[] = ['type' => 'section', 'text' => ['type' => 'plain_text', 'text' => $description]];
        }

        $blocks[] = ['type' => 'section', 'fields' => [
            $field($column('type'), $item->type?->label() ?? '—'),
            $field($column('priority'), $item->priority?->label() ?? '—'),
            $field($column('status'), $status),
            $field($column('reporter'), $item->reporter_name ?? '—'),
        ]];

        $link = app(FeedbackBoard::class)->reportUrl($item, 'developer');

        if ($link !== null) {
            $blocks[] = ['type' => 'section', 'text' => $mrkdwn(SlackText::link($link, __('feedback-board::feedback-board.mail.view_report')))];
        }

        return ['text' => $eventLabel.': '.SlackText::escape($title), 'blocks' => $blocks];
    }
}
