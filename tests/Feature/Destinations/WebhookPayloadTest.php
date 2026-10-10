<?php

use YousefAman\FeedbackBoard\Destinations\Support\WebhookPayload;
use YousefAman\FeedbackBoard\Enums\{AuthorRole, FeedbackStatus};
use YousefAman\FeedbackBoard\Events\{FeedbackItemCreated, FeedbackReplied, FeedbackStatusChanged};
use YousefAman\FeedbackBoard\Models\{FeedbackComment, FeedbackItem};

it('whitelists item fields and never leaks internal columns', function () {
    $item = FeedbackItem::factory()->create([
        'reporter_name' => 'Client A',
        'reporter_email' => 'client@example.com',
        'ai_state' => ['secret' => 'do-not-leak'],
        'external_refs' => ['github' => ['issue_number' => 9]],
    ]);

    $payload = WebhookPayload::build($item, new FeedbackItemCreated($item), 'dlv-1');

    expect($payload['item'])->toHaveKeys(['id', 'type', 'status', 'priority', 'title', 'description', 'project', 'reporter_name', 'created_at'])
        ->and($payload['item'])->not->toHaveKey('reporter_email')
        ->and($payload['item'])->not->toHaveKey('ai_state')
        ->and($payload['item'])->not->toHaveKey('external_refs')
        ->and(json_encode($payload))->not->toContain('do-not-leak')
        ->and(json_encode($payload))->not->toContain('client@example.com');
});

it('omits the change key for created and includes it for status_changed', function () {
    $item = FeedbackItem::factory()->create(['status' => FeedbackStatus::New]);

    $created = WebhookPayload::build($item, new FeedbackItemCreated($item), 'd');
    expect($created)->not->toHaveKey('change')
        ->and($created['event'])->toBe('feedback.created')
        ->and($created['delivery'])->toBe('d');

    $changed = WebhookPayload::build($item, new FeedbackStatusChanged($item, FeedbackStatus::New, FeedbackStatus::Resolved, null), 'd');
    expect($changed['change'])->toBe(['from' => 'new', 'to' => 'resolved']);
});

it('includes the reply body for a public reply', function () {
    $item = FeedbackItem::factory()->create();
    $comment = FeedbackComment::factory()->create([
        'feedback_item_id' => $item->id, 'body' => 'hello', 'is_internal' => false, 'author_role' => AuthorRole::Developer,
    ]);
    $payload = WebhookPayload::build($item, new FeedbackReplied($comment), 'd');
    expect($payload['change'])->toBe(['author_role' => 'developer', 'body' => 'hello']);
});

it('carries an empty body for an image-only reply', function () {
    $item = FeedbackItem::factory()->create();
    $comment = FeedbackComment::factory()->create([
        'feedback_item_id' => $item->id, 'body' => '', 'is_internal' => false, 'author_role' => AuthorRole::Developer,
    ]);

    expect(WebhookPayload::build($item, new FeedbackReplied($comment), 'd')['change'])
        ->toBe(['author_role' => 'developer', 'body' => '']);
});
