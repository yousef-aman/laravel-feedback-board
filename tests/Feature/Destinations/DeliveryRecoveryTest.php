<?php

use Illuminate\Support\Facades\{Exceptions, Http};
use Illuminate\Support\Sleep;
use YousefAman\FeedbackBoard\Destinations\DestinationManager;
use YousefAman\FeedbackBoard\Enums\{AuthorRole, FeedbackStatus};
use YousefAman\FeedbackBoard\Events\{FeedbackItemCreated, FeedbackReplied, FeedbackStatusChanged};
use YousefAman\FeedbackBoard\Jobs\DeliverToDestination;
use YousefAman\FeedbackBoard\Models\{FeedbackComment, FeedbackItem};

beforeEach(function () {
    Sleep::fake();
    config()->set('feedback-board.destinations.lock_store', 'array');
    config()->set('feedback-board.destinations.webhook.url', 'https://hooks.test/fb');
    config()->set('feedback-board.destinations.webhook.secret', 'whsec');
    config()->set('feedback-board.destinations.slack.webhook_url', 'https://hooks.slack.test/services/T/B/X');
    config()->set('feedback-board.destinations.github.token', 'ghp_test');
    config()->set('feedback-board.destinations.github.repo', 'acme/app');
    $this->failure = ['code' => 'connection_error', 'event' => 'feedback.created', 'at' => '2026-10-17T10:00:00+00:00'];
});

function hDeliver(object $event, string $driver): void
{
    (new DeliverToDestination($event, $driver, 'dlv-'.uniqid()))->handle(app(DestinationManager::class));
}

function hIssue(array $more = []): array
{
    return ['github' => ['issue_number' => 7, 'html_url' => 'https://github.com/acme/app/issues/7', 'repo' => 'acme/app', 'posted' => []]] + $more;
}

it("clears the webhook's failure once a delivery gets through, and leaves the others", function () {
    Http::fake(['hooks.test/*' => Http::response('', 200)]);
    $item = FeedbackItem::factory()->create(['external_refs' => ['webhook_last_error' => $this->failure, 'slack_last_error' => $this->failure]]);

    hDeliver(new FeedbackItemCreated($item), 'webhook');

    expect($item->refresh()->external_refs)->toBe(['slack_last_error' => $this->failure]);
});

it("clears Slack's failure once a message gets through", function () {
    Http::fake(['hooks.slack.test/*' => Http::response('ok', 200)]);
    $item = FeedbackItem::factory()->create(['external_refs' => ['slack_last_error' => $this->failure]]);

    hDeliver(new FeedbackItemCreated($item), 'slack');

    expect($item->refresh()->external_refs)->toBe([]);
});

it('keeps the failure when the webhook is not configured and nothing is sent', function () {
    config()->set('feedback-board.destinations.webhook.url', '');
    Http::fake();
    $item = FeedbackItem::factory()->create(['external_refs' => ['webhook_last_error' => $this->failure]]);

    hDeliver(new FeedbackItemCreated($item), 'webhook');

    Http::assertNothingSent();
    expect($item->refresh()->external_refs)->toBe(['webhook_last_error' => $this->failure]);
});

it("clears GitHub's failure once a comment is posted", function () {
    Http::fake(['api.github.com/*' => Http::response(['id' => 1], 201)]);
    $item = FeedbackItem::factory()->create(['external_refs' => hIssue(['github_last_error' => $this->failure])]);
    $comment = FeedbackComment::factory()->create(['feedback_item_id' => $item->id, 'author_role' => AuthorRole::Reporter, 'body' => 'Still broken']);

    hDeliver(new FeedbackReplied($comment), 'github');

    expect($item->refresh()->external_refs)->not->toHaveKey('github_last_error')
        ->and($item->external_refs['github']['posted'])->toHaveCount(1);
});

it("clears GitHub's failure once the issue is created", function () {
    Http::fake(['api.github.com/*' => Http::response(['number' => 7, 'html_url' => 'https://github.com/acme/app/issues/7'], 201)]);
    $item = FeedbackItem::factory()->create(['external_refs' => ['github_last_error' => $this->failure]]);

    hDeliver(new FeedbackItemCreated($item), 'github');

    expect($item->refresh()->external_refs)->not->toHaveKey('github_last_error')
        ->and($item->external_refs['github']['issue_number'])->toBe(7);
});

it('keeps GitHub\'s failure when the driver sends nothing', function (Closure $event) {
    Http::fake();
    $item = FeedbackItem::factory()->create(['external_refs' => hIssue(['github_last_error' => $this->failure])]);

    hDeliver($event($item), 'github');

    Http::assertNothingSent();
    expect($item->refresh()->external_refs['github_last_error'])->toBe($this->failure);
})->with([
    'an image-only reply' => [fn (FeedbackItem $item) => new FeedbackReplied(FeedbackComment::factory()->create(['feedback_item_id' => $item->id, 'body' => '']))],
    'an internal note' => [fn (FeedbackItem $item) => new FeedbackReplied(FeedbackComment::factory()->create(['feedback_item_id' => $item->id, 'body' => 'x', 'is_internal' => true]))],
    'a second "send to GitHub"' => [fn (FeedbackItem $item) => new FeedbackItemCreated($item)],
]);

it('reports, and does not throw, when clearing the failure after a delivered webhook fails', function () {
    Exceptions::fake();
    Http::fake(['hooks.test/*' => Http::response('', 200)]);
    $item = FeedbackItem::factory()->create(['external_refs' => ['webhook_last_error' => $this->failure]]);
    FeedbackItem::saving(fn () => throw new RuntimeException('database is read-only'));

    hDeliver(new FeedbackItemCreated($item), 'webhook');

    Http::assertSentCount(1);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'database is read-only');
});
