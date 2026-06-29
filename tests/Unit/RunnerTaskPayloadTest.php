<?php

use YousefAman\FeedbackBoard\Enums\{FeedbackPriority, FeedbackStatus, FeedbackType};
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Runner\Support\RunnerTaskPayload;

it('builds a whitelist envelope with no reporter PII', function () {
    $item = FeedbackItem::factory()->create([
        'title' => 'Crash on save',
        'description' => 'Steps...',
        'type' => FeedbackType::Bug,
        'status' => FeedbackStatus::New,
        'priority' => FeedbackPriority::High,
        'reporter_name' => 'Secret Reporter',          // non-null so the assertion is meaningful
        'reporter_email' => 'secret@example.com',
    ]);

    $env = RunnerTaskPayload::build($item, 'task-123', 'https://app.test/feedback-board/ingest');

    expect($env['task_id'])->toBe('task-123')
        ->and($env['event'])->toBe('runner.task')
        ->and($env['item']['id'])->toBe($item->id)
        ->and($env['item']['title'])->toBe('Crash on save')
        ->and($env['item']['type'])->toBe('bug')
        ->and($env['callback'])->toBe(['ingest_url' => 'https://app.test/feedback-board/ingest', 'item_id' => $item->id, 'task_id' => 'task-123']);

    $flat = json_encode($env);
    expect($flat)->not->toContain('Secret Reporter')
        ->and($flat)->not->toContain('secret@example.com')
        ->and($flat)->not->toContain('reporter_');
});

it('includes the triage suggestion only when triage is completed', function () {
    $item = FeedbackItem::factory()->create();
    expect(RunnerTaskPayload::build($item, 't', null))->not->toHaveKey('triage');

    $item->ai_state = ['triage' => ['status' => 'completed', 'suggested_type' => 'bug', 'suggested_priority' => 'high', 'summary' => 'S']];
    $item->save();
    $env = RunnerTaskPayload::build($item->refresh(), 't', null);
    expect($env['triage'])->toBe(['suggested_type' => 'bug', 'suggested_priority' => 'high', 'summary' => 'S']);
});

it('omits callback.ingest_url when no callback_url is given', function () {
    $item = FeedbackItem::factory()->create();
    $env = RunnerTaskPayload::build($item, 't', null);
    expect($env['callback'])->toBe(['item_id' => $item->id, 'task_id' => 't']);
});
