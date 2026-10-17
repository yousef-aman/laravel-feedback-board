<?php
// tests/Feature/Triage/TriageLivewireTest.php

use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use YousefAman\FeedbackBoard\Enums\FeedbackPriority;
use YousefAman\FeedbackBoard\Enums\FeedbackType;
use YousefAman\FeedbackBoard\Jobs\TriageFeedbackItem;
use YousefAman\FeedbackBoard\Livewire\FeedbackDetail;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(function () {
    config()->set('feedback-board.ai.enabled', true);
    config()->set('feedback-board.ai.default', 'fake');
    config()->set('feedback-board.authorization.developer_resolver', fn ($u) => ($u->name ?? null) === 'Dev');
});

it('requestTriage dispatches the job for a developer', function () {
    Bus::fake();
    $dev = TestUser::create(['name' => 'Dev']);
    $item = FeedbackItem::factory()->create();

    Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item])
        ->call('requestTriage');

    Bus::assertDispatched(TriageFeedbackItem::class);
});

it('applyTriage sets the canonical column and then hides the apply button', function () {
    $dev = TestUser::create(['name' => 'Dev']);
    $item = FeedbackItem::factory()->create(['priority' => FeedbackPriority::Low]);
    $item->ai_state = ['triage' => ['status' => 'completed', 'suggested_priority' => 'urgent', 'summary' => 's', 'model' => 'fake']];
    $item->save();

    $component = Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item])
        ->assertSee(__('feedback-board::feedback-board.triage.apply_priority')) // shown: suggestion differs
        ->call('applyTriage', 'priority');

    // Canonical column updated, and the difference-gated button is now gone (suggestion == column).
    expect($item->refresh()->priority)->toBe(FeedbackPriority::Urgent);
    $component->assertDontSee(__('feedback-board::feedback-board.triage.apply_priority'));
});

it('escapes a malicious summary in the rendered panel', function () {
    $dev = TestUser::create(['name' => 'Dev']);
    $item = FeedbackItem::factory()->create();
    $item->ai_state = ['triage' => ['status' => 'completed', 'suggested_type' => 'bug', 'summary' => '<script>alert(1)</script>', 'model' => 'fake']];
    $item->save();

    Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item])
        ->assertDontSee('<script>alert(1)</script>', false)   // raw markup absent
        ->assertSee('alert(1)');                              // escaped text present
});

it('shows wire:poll.5s while triage is pending', function () {
    $dev = TestUser::create(['name' => 'Dev']);
    $item = FeedbackItem::factory()->create();
    $item->ai_state = ['triage' => ['status' => 'pending']];
    $item->save();

    Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item])
        ->assertSee('wire:poll.5s', false); // raw attribute must appear in the markup
});

it('does not poll once triage reaches a terminal state', function () {
    $dev = TestUser::create(['name' => 'Dev']);
    $item = FeedbackItem::factory()->create();
    $item->ai_state = ['triage' => ['status' => 'completed', 'summary' => 'done', 'model' => 'fake']];
    $item->save();

    Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item])
        ->assertDontSee('wire:poll', false); // no poll attribute in any form
});

it('says why the triage failed', function () {
    $dev = TestUser::create(['name' => 'Dev']);
    $item = FeedbackItem::factory()->create();
    $item->ai_state = ['triage' => ['status' => 'failed', 'summary' => null, 'error_code' => 'auth_401']];
    $item->save();

    Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item])
        ->assertSee(__('feedback-board::feedback-board.triage.failed_reason', ['reason' => __('feedback-board::feedback-board.triage.reasons.auth_401')]));
});

it('reads a code it has no words for as an unknown error', function () {
    $dev = TestUser::create(['name' => 'Dev']);
    $item = FeedbackItem::factory()->create();
    $item->ai_state = ['triage' => ['status' => 'failed', 'summary' => null, 'error_code' => 'quota_gone']];
    $item->save();

    Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item])
        ->assertSee(__('feedback-board::feedback-board.triage.reasons.unknown'))
        ->assertDontSee('quota_gone');
});

it('keeps showing the previous suggestion, with a note, after a failed re-run', function () {
    $dev = TestUser::create(['name' => 'Dev']);
    $item = FeedbackItem::factory()->create(['priority' => FeedbackPriority::Low]);
    $item->ai_state = ['triage' => ['status' => 'completed', 'suggested_priority' => 'urgent', 'summary' => 'Old summary', 'model' => 'fake', 'error_code' => 'auth_401']];
    $item->save();

    Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item])
        ->assertSee('Old summary')
        ->assertSee(__('feedback-board::feedback-board.triage.apply_priority'))
        ->assertSee(__('feedback-board::feedback-board.triage.rerun_failed', ['reason' => __('feedback-board::feedback-board.triage.reasons.auth_401')]))
        ->assertSee(__('feedback-board::feedback-board.triage.previous_kept'));
});

it('shows no failure note on a clean suggestion', function () {
    $dev = TestUser::create(['name' => 'Dev']);
    $item = FeedbackItem::factory()->create();
    $item->ai_state = ['triage' => ['status' => 'completed', 'summary' => 'Fine', 'model' => 'fake', 'error_code' => null]];
    $item->save();

    Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item])
        ->assertSee('Fine')
        ->assertDontSee(__('feedback-board::feedback-board.triage.previous_kept'));
});
