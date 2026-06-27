<?php

use Illuminate\Support\Facades\{Bus, Gate};
use Livewire\Livewire;
use YousefAman\FeedbackBoard\Jobs\DeliverToDestination;
use YousefAman\FeedbackBoard\Livewire\FeedbackDetail;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Tests\TestUser;

it('the developer detail surface dispatches the github job', function () {
    config()->set('feedback-board.destinations.github.token', 'ghp');
    config()->set('feedback-board.destinations.github.repo', 'a/b');
    Bus::fake();
    $dev = TestUser::create(['name' => 'Dev']);
    Gate::define('feedback-board.manage', fn ($u) => true);
    $item = FeedbackItem::factory()->create();

    Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item])
        ->call('sendToGithub');

    Bus::assertDispatched(DeliverToDestination::class, fn ($j) => $j->driver === 'github');
});
