<?php

use YousefAman\FeedbackBoard\Enums\FeedbackStatus;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

function signed(array $payload): array {
    $body = json_encode($payload);
    $ts = (string) now()->timestamp;
    $sig = hash_hmac('sha256', $ts.'.'.$body, 'shhh');
    return [$body, ['X-Feedback-Timestamp' => $ts, 'X-Feedback-Signature' => $sig, 'Content-Type' => 'application/json']];
}

beforeEach(function () {
    config()->set('feedback-board.ingest.enabled', true);
    config()->set('feedback-board.ingest.secret', 'shhh');
});

it('rejects unsigned ingest calls', function () {
    $item = FeedbackItem::factory()->create();
    $this->postJson(route('feedback-board.ingest'), ['item_id' => $item->id, 'action' => 'status', 'status' => 'resolved'])
        ->assertStatus(403);
});

it('applies a signed status change', function () {
    $item = FeedbackItem::factory()->create(['status' => FeedbackStatus::New]);
    [$body, $headers] = signed(['item_id' => $item->id, 'action' => 'status', 'status' => 'resolved']);
    $this->call('POST', route('feedback-board.ingest'), [], [], [], $this->transformHeadersToServerVars($headers), $body)
        ->assertOk();
    expect($item->refresh()->status)->toBe(FeedbackStatus::Resolved);
});

it('rejects a replayed signature', function () {
    $item = FeedbackItem::factory()->create();
    [$body, $headers] = signed(['item_id' => $item->id, 'action' => 'comment', 'body' => 'hi']);
    $server = $this->transformHeadersToServerVars($headers);
    $this->call('POST', route('feedback-board.ingest'), [], [], [], $server, $body)->assertOk();
    $this->call('POST', route('feedback-board.ingest'), [], [], [], $server, $body)->assertStatus(403);
});

it('returns 404 when ingest is disabled', function () {
    config()->set('feedback-board.ingest.enabled', false);
    [$body, $headers] = signed(['item_id' => 1, 'action' => 'comment', 'body' => 'x']);
    $this->call('POST', route('feedback-board.ingest'), [], [], [], $this->transformHeadersToServerVars($headers), $body)
        ->assertNotFound();
});
