<?php

use Livewire\Livewire;
use YousefAman\FeedbackBoard\Enums\{AuthorRole, FeedbackPriority, FeedbackStatus};
use YousefAman\FeedbackBoard\Livewire\FeedbackDetail;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(fn () => config()->set('feedback-board.authorization.developer_resolver', fn ($u) => $u->name === 'Dev'));

it('lets a developer reply, add an internal note, assign, set priority and status', function () {
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    $item = FeedbackItem::factory()->create(['status' => FeedbackStatus::New]);

    Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item])
        ->set('body', 'we are on it')->call('reply')->assertHasNoErrors()
        ->set('note', 'root cause: cache')->call('internalNote')->assertHasNoErrors()
        ->call('assignToMe')->assertHasNoErrors()
        ->call('setPriority', FeedbackPriority::High->value)->assertHasNoErrors()
        ->call('changeStatus', FeedbackStatus::InProgress->value)->assertHasNoErrors();

    $item->refresh();
    expect($item->comments()->where('author_role', AuthorRole::Developer->value)->where('is_internal', false)->exists())->toBeTrue()
        ->and($item->comments()->where('is_internal', true)->exists())->toBeTrue()
        ->and($item->assignee->is($dev))->toBeTrue()
        ->and($item->priority)->toBe(FeedbackPriority::High)
        ->and($item->status)->toBe(FeedbackStatus::InProgress)
        ->and($item->activities()->count())->toBeGreaterThan(0);
});

it('forbids a non-developer from opening the detail panel', function () {
    $client = TestUser::create(['name' => 'Client', 'email' => 'c@x.com']);
    $item = FeedbackItem::factory()->create();
    Livewire::actingAs($client);
    // mount() calls abort(403); Livewire re-throws on the initial render, so assert the throw directly.
    expect(fn () => Livewire::test(FeedbackDetail::class, ['item' => $item]))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});
