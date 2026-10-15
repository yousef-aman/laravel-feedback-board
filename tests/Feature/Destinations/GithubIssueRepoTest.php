<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use YousefAman\FeedbackBoard\Destinations\Drivers\GithubIssueDriver;
use YousefAman\FeedbackBoard\Enums\FeedbackStatus;
use YousefAman\FeedbackBoard\Events\{FeedbackReplied, FeedbackStatusChanged};
use YousefAman\FeedbackBoard\Models\{FeedbackComment, FeedbackItem};

beforeEach(function () {
    config()->set('feedback-board.destinations.lock_store', 'array');
    config()->set('feedback-board.destinations.github.token', 'ghp_test');
    config()->set('feedback-board.destinations.github.repo', 'acme/app');
    Sleep::fake();
    Http::preventStrayRequests(); // before the fix the driver calls acme/app, which no fake matches
});

it('comments on and closes the issue in the repo where it was opened', function () {
    config()->set('feedback-board.destinations.github.close_on_resolved', true);
    Http::fake(['api.github.com/repos/acme/sandbox/*' => Http::response(['id' => 1], 201)]);
    $item = FeedbackItem::factory()->create([
        'status' => FeedbackStatus::Resolved,
        'external_refs' => ['github' => ['issue_number' => 3, 'node_id' => 'N', 'html_url' => 'u', 'repo' => 'acme/sandbox', 'posted' => []]],
    ]);

    (new GithubIssueDriver())->send($item, new FeedbackStatusChanged($item, FeedbackStatus::InProgress, FeedbackStatus::Resolved, null), 'dlv-repo');

    Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->url() === 'https://api.github.com/repos/acme/sandbox/issues/3/comments');
    Http::assertSent(fn ($r) => $r->method() === 'PATCH' && $r->url() === 'https://api.github.com/repos/acme/sandbox/issues/3');
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'acme/app'));
});

it('uses the configured repo for a ref without a stored repo', function () {
    Http::fake(['api.github.com/repos/acme/app/issues/3/comments' => Http::response(['id' => 1], 201)]);
    $item = FeedbackItem::factory()->create(['external_refs' => ['github' => ['issue_number' => 3, 'posted' => []]]]);
    $comment = FeedbackComment::factory()->create(['feedback_item_id' => $item->id, 'body' => 'a reply', 'is_internal' => false]);

    (new GithubIssueDriver())->send($item, new FeedbackReplied($comment), 'dlv-legacy');

    Http::assertSent(fn ($r) => $r->url() === 'https://api.github.com/repos/acme/app/issues/3/comments');
});
