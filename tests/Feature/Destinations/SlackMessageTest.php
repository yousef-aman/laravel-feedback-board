<?php

use Illuminate\Support\Facades\Http;
use YousefAman\FeedbackBoard\Destinations\Drivers\SlackDriver;
use YousefAman\FeedbackBoard\Enums\{FeedbackPriority, FeedbackStatus, FeedbackType};
use YousefAman\FeedbackBoard\Events\{FeedbackItemCreated, FeedbackStatusChanged};
use YousefAman\FeedbackBoard\Facades\FeedbackBoard;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

beforeEach(function () {
    config()->set('feedback-board.destinations.slack.webhook_url', 'https://hooks.slack.test/services/T/B/X');
    Http::fake(['hooks.slack.test/*' => Http::response('ok', 200)]);
});

/** The message the Slack driver posts for $event. */
function hSlackMessage(object $event): array
{
    (new SlackDriver())->send($event->item, $event, 'dlv-message');

    return Http::recorded()->last()[0]->data();
}

/** The message's blocks of $type, in order. */
function hSlackBlocks(array $message, string $type): array
{
    return array_values(array_filter($message['blocks'], fn (array $block) => $block['type'] === $type));
}

/** The texts of the message's fields. */
function hSlackFields(array $message): array
{
    return array_column(collect(hSlackBlocks($message, 'section'))->firstWhere('fields')['fields'], 'text');
}

it('names the event and the report in readable words', function () {
    $item = FeedbackItem::factory()->create([
        'title' => 'Crash on save',
        'type' => FeedbackType::Bug,
        'priority' => FeedbackPriority::High,
        'status' => FeedbackStatus::New,
        'reporter_name' => 'Reem',
    ]);

    $message = hSlackMessage(new FeedbackItemCreated($item));

    expect($message['text'])->toBe('New report: Crash on save')
        ->and(hSlackBlocks($message, 'header')[0]['text']['text'])->toBe('Crash on save')
        ->and(hSlackBlocks($message, 'context')[0]['elements'][0]['text'])->toBe("*New report* · Report #{$item->id}")
        ->and(hSlackFields($message))->toBe([
            "*Type:*\nBug",
            "*Priority:*\nHigh",
            "*Status:*\nNew",
            "*Reporter:*\nReem",
        ]);
});

it('shows a dash for a report without a priority', function () {
    $item = FeedbackItem::factory()->create(['priority' => null]);

    expect(hSlackFields(hSlackMessage(new FeedbackItemCreated($item))))->toContain("*Priority:*\n—");
});

it('says which status a report moved from and to', function () {
    $item = FeedbackItem::factory()->create(['status' => FeedbackStatus::InProgress]);

    $message = hSlackMessage(new FeedbackStatusChanged($item, FeedbackStatus::New, FeedbackStatus::InProgress, null));

    expect($message['text'])->toBe('Status change: '.$item->title)
        ->and(hSlackFields($message))->toContain("*Status:*\nNew → In progress");
});

it("writes the message in the app's language", function () {
    app()->setLocale('ar');
    $item = FeedbackItem::factory()->create([
        'title' => 'زر الدفع لا يعمل',
        'type' => FeedbackType::Bug,
        'priority' => null,
        'status' => FeedbackStatus::InProgress,
        'reporter_name' => 'ريم',
    ]);

    $message = hSlackMessage(new FeedbackStatusChanged($item, FeedbackStatus::New, FeedbackStatus::InProgress, null));

    expect($message['text'])->toBe('تغيير الحالة: زر الدفع لا يعمل')
        ->and(hSlackBlocks($message, 'context')[0]['elements'][0]['text'])->toBe("*تغيير الحالة* · البلاغ رقم {$item->id}")
        ->and(hSlackFields($message))->toBe([
            "*النوع:*\nخلل",
            "*الأولوية:*\n—",
            "*الحالة:*\nمن جديد إلى قيد التنفيذ",
            "*المُبلِّغ:*\nريم",
        ]);
});

it("uses destinations.slack.locale over the app's language, and leaves the app's language as it was", function () {
    app()->setLocale('en');
    config()->set('feedback-board.destinations.slack.locale', 'ar');
    $item = FeedbackItem::factory()->create(['title' => 'Crash']);

    $message = hSlackMessage(new FeedbackItemCreated($item));

    expect($message['text'])->toBe('بلاغ جديد: Crash')
        ->and(app()->getLocale())->toBe('en');
});

it('ignores a destinations.slack.locale that Laravel would refuse', function () {
    app()->setLocale('en');
    config()->set('feedback-board.destinations.slack.locale', 'ar/SA');
    $item = FeedbackItem::factory()->create(['title' => 'Crash']);

    expect(hSlackMessage(new FeedbackItemCreated($item))['text'])->toBe('New report: Crash');
});

it("keeps Slack from turning the reporter's words into links, channels or mentions", function () {
    $item = FeedbackItem::factory()->create(['reporter_name' => 'https://evil.example/reset #general @here']);

    $message = hSlackMessage(new FeedbackItemCreated($item));
    $mrkdwn = collect($message['blocks'])
        ->flatMap(fn (array $block) => [$block['text'] ?? null, ...($block['fields'] ?? []), ...($block['elements'] ?? [])])
        ->filter(fn (?array $text) => ($text['type'] ?? null) === 'mrkdwn');

    expect($mrkdwn)->toHaveCount(6)
        ->and($mrkdwn->every(fn (array $text) => ($text['verbatim'] ?? false) === true))->toBeTrue();
});

it('names the report by its number when it has no title', function () {
    $item = FeedbackItem::factory()->create(['title' => '  ']);

    expect(hSlackBlocks(hSlackMessage(new FeedbackItemCreated($item)), 'header')[0]['text']['text'])
        ->toBe("Report #{$item->id}");
});

it("links to the report on the developers' board", function () {
    $item = FeedbackItem::factory()->create();
    $url = FeedbackBoard::reportUrl($item, 'developer');

    $message = hSlackMessage(new FeedbackItemCreated($item));

    expect($url)->toStartWith('http')
        ->and(collect(hSlackBlocks($message, 'section'))->pluck('text.text'))->toContain("<{$url}|View report>");
});

it("escapes the app's own report link for Slack", function () {
    FeedbackBoard::linkReportsUsing(fn (FeedbackItem $item, string $audience) => "https://app.example/r?id={$item->id}&a={$audience}|x");
    $item = FeedbackItem::factory()->create();

    $message = hSlackMessage(new FeedbackItemCreated($item));

    expect(collect(hSlackBlocks($message, 'section'))->pluck('text.text'))
        ->toContain("<https://app.example/r?id={$item->id}&amp;a=developer%7Cx|View report>");
});

it('leaves the link out when the app gives none', function () {
    FeedbackBoard::linkReportsUsing(fn () => null);
    $item = FeedbackItem::factory()->create();

    $message = hSlackMessage(new FeedbackItemCreated($item));

    expect(json_encode($message))->not->toContain('View report');
});

it("shows the start of the description as plain text, on one line", function () {
    $item = FeedbackItem::factory()->create(['description' => "<!channel> first line\n\nsecond line ".str_repeat('x', 400)]);

    $description = collect(hSlackBlocks(hSlackMessage(new FeedbackItemCreated($item)), 'section'))
        ->first(fn (array $block) => ($block['text']['type'] ?? null) === 'plain_text')['text'];

    expect($description['text'])->toStartWith('<!channel> first line second line xxx')
        ->and($description['text'])->toEndWith('…')
        ->and(mb_strlen($description['text']))->toBeLessThanOrEqual(301);
});

it('has no description block for a report without one', function () {
    $item = FeedbackItem::factory()->create(['description' => '']);

    $message = hSlackMessage(new FeedbackItemCreated($item));

    expect(collect(hSlackBlocks($message, 'section'))->filter(fn (array $block) => ($block['text']['type'] ?? null) === 'plain_text'))
        ->toBeEmpty();
});
