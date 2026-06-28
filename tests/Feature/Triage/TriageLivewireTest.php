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
