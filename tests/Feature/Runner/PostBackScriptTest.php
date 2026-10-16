<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\{Notification, Process};
use YousefAman\FeedbackBoard\Models\FeedbackItem;

beforeEach(function () {
    foreach (['bash', 'jq', 'openssl'] as $tool) {
        if (trim((string) shell_exec('command -v '.$tool)) === '') {
            test()->markTestSkipped($tool.' is not installed.');
        }
    }
    config()->set('feedback-board.ingest.enabled', true);
    config()->set('feedback-board.ingest.secret', 'shhh');
    config()->set('feedback-board.ai.lock_store', 'array');
    config()->set('feedback-board.destinations.lock_store', 'array');
    Notification::fake();
});

/** Runs the shipped script in dry-run mode: it prints the timestamp, the signature and the body. */
function hPostBack(array $args, array $env): ProcessResult
{
    return Process::env([
        'INGEST_URL' => 'http://ingest.test', 'INGEST_SECRET' => 'shhh', 'TASK_ID' => 'T-1', 'FEEDBACK_DRY_RUN' => '1', ...$env,
    ])->run(['bash', dirname(__DIR__, 3).'/docs/examples/runner/post-back.sh', ...$args]);
}

it('signs exactly the bytes it sends, whatever the analysis contains', function () {
    $item = FeedbackItem::factory()->create();
    $other = FeedbackItem::factory()->create();
    $item->ai_state = ['runner' => ['status' => 'running', 'task_id' => 'T-1']];
    $item->save();
    $analysis = "He said \"hi\" \\ App\\Http\\Foo\nline two — عربي 😀 \",\"item_id\":{$other->id},\"action\":\"comment\",\"body\":\"x\n";
    $file = tempnam(sys_get_temp_dir(), 'fb-analysis');
    file_put_contents($file, $analysis);

    $result = hPostBack(['result', $file], ['ITEM_ID' => (string) $item->id, 'PR_URL' => 'https://github.com/o/r/pull/7']);
    unlink($file);

    expect($result->successful())->toBeTrue();
    [$ts, $sig, $body] = explode("\n", rtrim($result->output(), "\n"), 3);

    $this->call('POST', route('feedback-board.ingest'), [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_FEEDBACK_TIMESTAMP' => $ts, 'HTTP_X_FEEDBACK_SIGNATURE' => $sig,
    ], $body)->assertOk();

    $note = $item->comments()->latest('id')->first();
    expect($note->body)->toBe(trim($analysis)) // the server trims values
        ->and($note->is_internal)->toBeTrue()
        ->and($other->comments()->count())->toBe(0)
        ->and($item->fresh()->ai_state['runner']['pr_url'])->toBe('https://github.com/o/r/pull/7');
});

it('refuses an ITEM_ID that is not a positive integer', function (string $id) {
    $result = hPostBack(['status', 'running'], ['ITEM_ID' => $id]);

    expect($result->exitCode())->toBe(2)
        ->and($result->errorOutput())->toContain('ITEM_ID must be a positive integer');
})->with(['7.5', '"7"', 'null', '0', '1e2', '{"a":1}']);

it('refuses to run without a secret', function () {
    $result = hPostBack(['status', 'running'], ['ITEM_ID' => '7', 'INGEST_SECRET' => '']);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('INGEST_SECRET is required');
});

it('leaves a missing diff file out of the body', function () {
    $file = tempnam(sys_get_temp_dir(), 'fb-analysis');
    file_put_contents($file, 'Analysis');

    $result = hPostBack(['result', $file, '/nonexistent/diff.patch'], ['ITEM_ID' => '7']);
    unlink($file);

    [, , $body] = explode("\n", rtrim($result->output(), "\n"), 3);
    expect(json_decode($body, true))->toBe(['item_id' => 7, 'action' => 'runner_result', 'task_id' => 'T-1', 'analysis' => 'Analysis']);
});

it('posts a status with an error code', function () {
    $result = hPostBack(['status', 'failed', 'agent_failed'], ['ITEM_ID' => '7']);

    [, , $body] = explode("\n", rtrim($result->output(), "\n"), 3);
    expect(json_decode($body, true))->toBe(['item_id' => 7, 'action' => 'runner_status', 'task_id' => 'T-1', 'status' => 'failed', 'error_code' => 'agent_failed']);
});
