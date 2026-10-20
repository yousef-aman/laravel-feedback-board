<?php

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\{Cache, Notification};
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

beforeEach(function () {
    config()->set('feedback-board.ingest.enabled', true);
    config()->set('feedback-board.ingest.secret', 'shhh');
    config()->set('feedback-board.destinations.lock_store', 'array');
    Notification::fake();
});

/** A signed ingest POST with no Accept header, like curl or a runner script sends it. */
function hIngestPost(array $payload, string $secret = 'shhh'): TestResponse
{
    $body = json_encode($payload);
    $ts = (string) now()->timestamp;

    return test()->call('POST', route('feedback-board.ingest'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_FEEDBACK_TIMESTAMP' => $ts,
        'HTTP_X_FEEDBACK_SIGNATURE' => hash_hmac('sha256', $ts.'.'.$body, $secret),
    ], $body);
}

function hRunningItem(): FeedbackItem
{
    $item = FeedbackItem::factory()->create();
    $item->ai_state = ['runner' => ['status' => 'running', 'task_id' => 'T1']];
    $item->save();

    return $item;
}

it('answers a rejected runner result with 422 JSON, not a redirect', function () {
    $item = hRunningItem();

    hIngestPost(['item_id' => $item->id, 'action' => 'runner_result', 'task_id' => 'T1', 'analysis' => str_repeat('a', 10001)])
        ->assertStatus(422)
        ->assertJsonValidationErrors('analysis');
});

it('refuses a comment longer than an analysis may be', function () {
    $item = FeedbackItem::factory()->create();

    hIngestPost(['item_id' => $item->id, 'action' => 'comment', 'body' => str_repeat('a', 10001)])
        ->assertStatus(422)
        ->assertJsonValidationErrors('body');

    hIngestPost(['item_id' => $item->id, 'action' => 'comment', 'body' => str_repeat('a', 10000)])->assertOk();
});

it('answers 422 JSON even when the host renders exceptions as HTML for this path', function () {
    app(ExceptionHandler::class)->shouldRenderJsonWhen(fn () => false);
    $item = hRunningItem();

    hIngestPost(['item_id' => $item->id, 'action' => 'runner_result', 'task_id' => 'T1', 'analysis' => str_repeat('a', 10001)])
        ->assertStatus(422)
        ->assertJsonValidationErrors('analysis');
});

it('answers a bad signature with 403 JSON', function () {
    hIngestPost(['item_id' => 1, 'action' => 'comment', 'body' => 'x'], 'wrong')
        ->assertStatus(403)
        ->assertJsonStructure(['message']);
});

it('answers 404 JSON when the ingest is disabled', function () {
    config()->set('feedback-board.ingest.enabled', false);

    hIngestPost(['item_id' => 1, 'action' => 'comment', 'body' => 'x'])
        ->assertNotFound()
        ->assertJsonStructure(['message']);
});

it('answers a runner task mismatch with 409 JSON', function () {
    $item = hRunningItem();

    hIngestPost(['item_id' => $item->id, 'action' => 'runner_status', 'task_id' => 'OLD', 'status' => 'running'])
        ->assertStatus(409)
        ->assertHeader('Content-Type', 'application/json');
});

it('refuses external_ref keys that are malformed or reserved', function (string $key) {
    $item = FeedbackItem::factory()->create();

    hIngestPost(['item_id' => $item->id, 'action' => 'external_ref', 'ref' => ['key' => $key, 'value' => 'x']])
        ->assertStatus(422)
        ->assertJsonValidationErrors('ref.key');
})->with(['github', 'pr_url', 'slack_last_error', "github\n", '123', 'Bad Key', 'Github', str_repeat('a', 65)]);

it('stores a valid external_ref and keeps the existing keys', function () {
    $github = ['issue_number' => 7, 'repo' => 'acme/app', 'posted' => []];
    // A numeric key written by a host: array_merge() would renumber it to 0.
    $item = FeedbackItem::factory()->create(['external_refs' => ['github' => $github, '42' => 'legacy']]);

    hIngestPost(['item_id' => $item->id, 'action' => 'external_ref', 'ref' => ['key' => 'jira', 'value' => 'FB-1']])->assertOk();
    hIngestPost(['item_id' => $item->id, 'action' => 'external_ref', 'ref' => ['key' => 'jira.key', 'value' => 'FB-2']])->assertOk();

    expect($item->fresh()->external_refs)->toBe(['github' => $github, 42 => 'legacy', 'jira' => 'FB-1', 'jira.key' => 'FB-2']);
});

it("answers 503 while another writer holds the report's lock", function () {
    Sleep::fake(syncWithCarbon: true); // Lock::block() waits on the clock; the faked sleep advances it
    $item = FeedbackItem::factory()->create();
    Cache::store('array')->lock('feedback-board:github:'.$item->id, 10)->acquire();

    hIngestPost(['item_id' => $item->id, 'action' => 'external_ref', 'ref' => ['key' => 'jira', 'value' => 'FB-1']])
        ->assertStatus(503);
});
