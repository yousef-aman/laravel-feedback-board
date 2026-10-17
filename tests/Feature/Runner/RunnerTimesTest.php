<?php

use Carbon\CarbonInterface;
use Livewire\Livewire;
use YousefAman\FeedbackBoard\Livewire\FeedbackDetail;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Runner\Support\RunnerState;
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(function () {
    config()->set('feedback-board.ai.lock_store', 'array');
    config()->set('feedback-board.runner.enabled', true);
    config()->set('feedback-board.runner.endpoint', 'https://runner.test/task');
    config()->set('feedback-board.runner.secret', 'rsecret');
    config()->set('feedback-board.ingest.enabled', true);
    config()->set('feedback-board.ingest.secret', 'ingsecret');
    config()->set('feedback-board.authorization.developer_resolver', fn ($u) => ($u->name ?? null) === 'Dev');
    $this->actingAs(TestUser::firstOrCreate(['name' => 'Dev']));
});

function hTimesItem(array $runner): FeedbackItem
{
    $item = FeedbackItem::factory()->create();
    $item->ai_state = ['runner' => $runner];
    $item->save();

    return $item;
}

it('says how long ago the task was sent and how long it has been silent, not the raw timestamps', function () {
    $sent = now()->subMinutes(40)->toIso8601String();
    $updated = now()->subMinutes(35)->toIso8601String();
    $item = hTimesItem(['status' => 'running', 'task_id' => 'old', 'dispatched_at' => $sent, 'updated_at' => $updated]);

    $component = Livewire::test(FeedbackDetail::class, ['item' => $item])
        ->assertSeeHtml('<time datetime="'.$sent.'" title="'.$sent.'">40 minutes ago</time>')
        ->assertSee('No update for 35 minutes — the agent may have stopped.');

    expect(strip_tags($component->html()))->not->toContain($sent)->not->toContain($updated);
});

it('says both times in Arabic', function () {
    app()->setLocale('ar');
    $item = hTimesItem([
        'status' => 'running',
        'task_id' => 'old',
        'dispatched_at' => now()->subMinutes(40)->toIso8601String(),
        'updated_at' => now()->subHours(2)->toIso8601String(),
    ]);

    Livewire::test(FeedbackDetail::class, ['item' => $item])
        ->assertSeeHtml('">منذ 40 دقيقة</time>')
        ->assertSee('لا تحديث منذ ساعتين — ربما توقف الوكيل.');
});

it('falls back to English for a locale Carbon does not know', function () {
    app()->setLocale('zz');
    $item = hTimesItem(['status' => 'running', 'task_id' => 'old', 'dispatched_at' => now()->subMinutes(40)->toIso8601String()]);

    Livewire::test(FeedbackDetail::class, ['item' => $item])->assertSeeHtml('">40 minutes ago</time>');
});

it('keeps showing a timestamp it cannot read, without failing', function () {
    $item = hTimesItem(['status' => 'running', 'task_id' => 'old', 'dispatched_at' => 'garbage', 'updated_at' => 'garbage']);

    Livewire::test(FeedbackDetail::class, ['item' => $item])
        ->assertSee('garbage')
        ->assertSee(__('feedback-board::feedback-board.runner.resend'));
});

it('reads a runner timestamp only when it is a non-empty string it can parse', function () {
    expect(RunnerState::parseTime(now()->subMinute()->toIso8601String()))->toBeInstanceOf(CarbonInterface::class)
        ->and(RunnerState::parseTime(''))->toBeNull()
        ->and(RunnerState::parseTime('garbage'))->toBeNull()
        ->and(RunnerState::parseTime(null))->toBeNull()
        ->and(RunnerState::parseTime(1792000000))->toBeNull()
        ->and(RunnerState::isStale(['status' => 'running', 'updated_at' => '']))->toBeTrue();
});
