<?php

use Illuminate\Support\Facades\Http;
use YousefAman\FeedbackBoard\Destinations\Drivers\GithubIssueDriver;
use YousefAman\FeedbackBoard\Destinations\Exceptions\RetryAfter;
use YousefAman\FeedbackBoard\Enums\FeedbackStatus;
use YousefAman\FeedbackBoard\Events\{FeedbackItemCreated, FeedbackReplied, FeedbackStatusChanged};
use YousefAman\FeedbackBoard\Models\{FeedbackComment, FeedbackItem};

beforeEach(function () {
    config()->set('feedback-board.destinations.lock_store', 'array');
    config()->set('feedback-board.destinations.github.token', 'ghp_test');
    config()->set('feedback-board.destinations.github.repo', 'acme/app');
    \Illuminate\Support\Sleep::fake(); // retrying tests (429) must not sleep real wall-clock
});

function githubCreated() {
    return Http::response(['number' => 7, 'node_id' => 'N_1', 'html_url' => 'https://github.com/acme/app/issues/7'], 201);
}

it('creates an issue on a created event then stores the ref', function () {
    Http::fake(['api.github.com/repos/acme/app/issues' => githubCreated()]);
    $item = FeedbackItem::factory()->create();

    (new GithubIssueDriver())->send($item, new FeedbackItemCreated($item), 'dlv-create');

    $ref = $item->refresh()->external_refs['github'];
    expect($ref['issue_number'])->toBe(7)
        ->and($ref['html_url'])->toBe('https://github.com/acme/app/issues/7')
        ->and($ref['repo'])->toBe('acme/app')
        ->and($ref['posted'])->toBe(['dlv-create']);
    Http::assertSentCount(1);
});

it('comments instead of creating when a ref already exists', function () {
    Http::fake([
        'api.github.com/repos/acme/app/issues/7/comments' => Http::response(['id' => 1], 201),
    ]);
    $item = FeedbackItem::factory()->create([
        'external_refs' => ['github' => ['issue_number' => 7, 'node_id' => 'N_1', 'html_url' => 'u', 'repo' => 'acme/app', 'posted' => []]],
    ]);
    $comment = FeedbackComment::factory()->create(['feedback_item_id' => $item->id, 'body' => 'a reply', 'is_internal' => false]);

    (new GithubIssueDriver())->send($item, new FeedbackReplied($comment), 'dlv-reply');

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/issues/7/comments') && str_contains(json_encode($r->data()), 'a reply'));
    expect($item->refresh()->external_refs['github']['posted'])->toBe(['dlv-reply']);
});

it('does not post a second comment when the same deliveryId retries', function () {
    Http::fake(['api.github.com/*' => Http::response(['id' => 1], 201)]);
    $item = FeedbackItem::factory()->create([
        'external_refs' => ['github' => ['issue_number' => 7, 'node_id' => 'N', 'html_url' => 'u', 'repo' => 'acme/app', 'posted' => []]],
    ]);
    $comment = FeedbackComment::factory()->create(['feedback_item_id' => $item->id, 'is_internal' => false]);
    $driver = new GithubIssueDriver();

    $driver->send($item, new FeedbackReplied($comment), 'dlv-x');
    $item->refresh();
    $driver->send($item, new FeedbackReplied($comment), 'dlv-x'); // retry, same id

    Http::assertSentCount(1);
});

it('never comments an internal note', function () {
    Http::fake(['api.github.com/*' => Http::response(['id' => 1], 201)]);
    $item = FeedbackItem::factory()->create([
        'external_refs' => ['github' => ['issue_number' => 7, 'node_id' => 'N', 'html_url' => 'u', 'repo' => 'acme/app', 'posted' => []]],
    ]);
    $comment = FeedbackComment::factory()->create(['feedback_item_id' => $item->id, 'is_internal' => true, 'body' => 'secret note']);

    (new GithubIssueDriver())->send($item, new FeedbackReplied($comment), 'dlv-int');

    Http::assertNothingSent();
});

it('no-ops a duplicate created send when a ref already exists', function () {
    Http::fake();
    $item = FeedbackItem::factory()->create([
        'external_refs' => ['github' => ['issue_number' => 7, 'node_id' => 'N', 'html_url' => 'u', 'repo' => 'acme/app', 'posted' => []]],
    ]);

    (new GithubIssueDriver())->send($item, new FeedbackItemCreated($item), 'dlv-dup');

    Http::assertNothingSent();
});

it('closes the issue on resolved when close_on_resolved is true', function () {
    config()->set('feedback-board.destinations.github.close_on_resolved', true);
    Http::fake(['api.github.com/*' => Http::response(['id' => 1], 200)]);
    $item = FeedbackItem::factory()->create([
        'external_refs' => ['github' => ['issue_number' => 7, 'node_id' => 'N', 'html_url' => 'u', 'repo' => 'acme/app', 'posted' => []]],
    ]);

    (new GithubIssueDriver())->send($item, new FeedbackStatusChanged($item, FeedbackStatus::InProgress, FeedbackStatus::Resolved, null), 'dlv-res');

    Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/issues/7') && ($r->data()['state'] ?? null) === 'closed');
});

it('throws RetryAfter on a 429 with Retry-After', function () {
    Http::fake(['api.github.com/*' => Http::response('', 429, ['Retry-After' => '12'])]);
    $item = FeedbackItem::factory()->create();

    expect(fn () => (new GithubIssueDriver())->send($item, new FeedbackItemCreated($item), 'dlv-rl'))
        ->toThrow(RetryAfter::class);
});

it('throws RetryAfter on a 403 secondary-limit with Retry-After', function () {
    Http::fake(['api.github.com/*' => Http::response('', 403, ['Retry-After' => '30'])]);
    $item = FeedbackItem::factory()->create();

    expect(fn () => (new GithubIssueDriver())->send($item, new FeedbackItemCreated($item), 'dlv-403'))
        ->toThrow(RetryAfter::class);
});

it('does not retry a permanent 422 and fails the job', function () {
    Http::fake(['api.github.com/repos/acme/app/issues' => Http::response(['message' => 'Validation Failed'], 422)]);
    $item = FeedbackItem::factory()->create();

    expect(fn () => (new GithubIssueDriver())->send($item, new FeedbackItemCreated($item), 'dlv-422'))
        ->toThrow(\Illuminate\Http\Client\RequestException::class);
    Http::assertSentCount(1); // TransientRetry: 422 is permanent, exactly one attempt
});

it('is a no-op when token or repo is missing', function () {
    config()->set('feedback-board.destinations.github.token', null);
    Http::fake();
    $item = FeedbackItem::factory()->create();

    (new GithubIssueDriver())->send($item, new FeedbackItemCreated($item), 'dlv-cfg');

    Http::assertNothingSent();
});

it('fails the job and persists no ref when a create response lacks an issue number', function () {
    Http::fake(['api.github.com/repos/acme/app/issues' => Http::response(['node_id' => 'N'], 201)]); // no 'number'
    $item = FeedbackItem::factory()->create();

    expect(fn () => (new GithubIssueDriver())->send($item, new FeedbackItemCreated($item), 'dlv-bad'))
        ->toThrow(RuntimeException::class);
    expect($item->refresh()->external_refs['github'] ?? null)->toBeNull();
});
