<?php

use Livewire\Livewire;
use YousefAman\FeedbackBoard\Actions\{ApplyTriageSuggestion, AssignFeedback, SetPriority};
use YousefAman\FeedbackBoard\Enums\{ActivityAction, FeedbackPriority, FeedbackStatus, FeedbackType};
use YousefAman\FeedbackBoard\Livewire\FeedbackDetail;
use YousefAman\FeedbackBoard\Models\{FeedbackActivity, FeedbackItem};
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(fn () => config()->set('feedback-board.authorization.developer_resolver', fn ($u) => $u->name === 'Dev'));

it('logs a priority change and a clear, and nothing when the value is unchanged', function () {
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    $item = FeedbackItem::factory()->create(['priority' => null]);
    $before = $item->fresh()->reporter_activity_at;
    $action = app(SetPriority::class);

    $this->travel(1)->hours();
    $action->handle($item, FeedbackPriority::High, $dev);
    $action->handle($item, FeedbackPriority::High, $dev);
    $action->handle($item, null, $dev);

    $rows = $item->activities()->where('action', ActivityAction::PriorityChanged)->reorder('id')->get();

    expect($item->fresh()->priority)->toBeNull()
        ->and($rows)->toHaveCount(2)
        ->and($rows[0]->properties)->toBe(['from' => null, 'to' => 'high'])
        ->and($rows[1]->properties)->toBe(['from' => 'high', 'to' => null])
        ->and($item->fresh()->reporter_activity_at)->toEqual($before);
});

it('sets and clears the priority from the developer detail', function () {
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    $item = FeedbackItem::factory()->create(['priority' => FeedbackPriority::Low]);

    Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item])
        ->call('setPriority', '')->assertHasNoErrors()
        ->call('setPriority', 'nope')->assertStatus(422);

    expect($item->fresh()->priority)->toBeNull()
        ->and($item->activities()->where('action', ActivityAction::PriorityChanged)->count())->toBe(1);
});

it('logs type and priority changes applied from a triage suggestion, once', function () {
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    $item = FeedbackItem::factory()->create(['type' => FeedbackType::Bug, 'priority' => null]);
    $item->forceFill(['ai_state' => ['triage' => [
        'status' => 'completed', 'suggested_type' => 'feature', 'suggested_priority' => 'urgent',
    ]]])->save();

    app(ApplyTriageSuggestion::class)->handle($item, $dev, ['type', 'priority']);
    app(ApplyTriageSuggestion::class)->handle($item, $dev, ['type', 'priority']);

    $type = $item->activities()->where('action', ActivityAction::TypeChanged)->get();

    expect($type)->toHaveCount(1)
        ->and($type[0]->properties)->toBe(['from' => 'bug', 'to' => 'feature'])
        ->and($item->activities()->where('action', ActivityAction::PriorityChanged)->count())->toBe(1)
        ->and($item->fresh()->type)->toBe(FeedbackType::Feature)
        ->and($item->fresh()->priority)->toBe(FeedbackPriority::Urgent);
});

it('stores the assignee type with an assignment', function () {
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    $item = FeedbackItem::factory()->create();

    app(AssignFeedback::class)->handle($item, $dev, $dev);
    app(AssignFeedback::class)->handle($item, null, $dev);

    $rows = $item->activities()->where('action', ActivityAction::Assigned)->reorder('id')->get();

    expect($rows[0]->properties)->toBe(['assignee_id' => $dev->id, 'assignee_type' => $dev->getMorphClass()])
        ->and($rows[1]->properties)->toBe(['assignee_id' => null, 'assignee_type' => null]);
});

it('writes full activity sentences, with a fallback for a missing actor', function () {
    $item = FeedbackItem::factory()->create();
    $make = fn (array $attributes) => FeedbackActivity::create(['feedback_item_id' => $item->id, ...$attributes]);

    expect($make(['action' => ActivityAction::Created])->sentence('Lena'))->toBe('Lena created the report')
        ->and($make(['action' => ActivityAction::Created])->sentence(null))->toBe('Someone created the report')
        ->and($make(['action' => ActivityAction::StatusChanged, 'from_status' => FeedbackStatus::New, 'to_status' => FeedbackStatus::InReview])->sentence('Dev'))
            ->toBe('Dev moved the report from “New” to “In review”')
        ->and($make(['action' => ActivityAction::StatusChanged, 'to_status' => FeedbackStatus::Resolved])->sentence('Dev'))
            ->toBe('Dev moved the report to “Resolved”')
        ->and($make(['action' => ActivityAction::PriorityChanged, 'properties' => ['from' => null, 'to' => 'high']])->sentence('Dev'))
            ->toBe('Dev changed the priority to “High”')
        ->and($make(['action' => ActivityAction::PriorityChanged, 'properties' => ['from' => 'high', 'to' => null]])->sentence('Dev'))
            ->toBe('Dev cleared the priority')
        ->and($make(['action' => ActivityAction::TypeChanged, 'properties' => ['from' => 'bug', 'to' => 'feature']])->sentence('Dev'))
            ->toBe('Dev changed the type to “Feature request”')
        ->and($make(['action' => ActivityAction::Assigned, 'properties' => ['assignee_id' => null]])->sentence('Dev'))
            ->toBe('Dev removed the assignee')
        ->and($make(['action' => ActivityAction::Replied])->sentence('Dev'))->toBe('Dev replied');

    app()->setLocale('ar');

    expect($make(['action' => ActivityAction::Assigned, 'properties' => ['assignee_id' => null]])->sentence('Dev'))
        ->toBe('ألغى Dev الإسناد');
});

it('resolves assignee names, and for old rows only when the actor assigned themselves', function () {
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    $sam = TestUser::create(['name' => null, 'email' => 'sam@x.com']);
    $item = FeedbackItem::factory()->create();
    $make = fn (array $properties) => FeedbackActivity::create([
        'feedback_item_id' => $item->id, 'action' => ActivityAction::Assigned,
        'actor_type' => $dev->getMorphClass(), 'actor_id' => $dev->id, 'properties' => $properties,
    ]);

    $typed = $make(['assignee_id' => $sam->id, 'assignee_type' => $sam->getMorphClass()]);
    $oldSelf = $make(['assignee_id' => $dev->id]);
    $oldOther = $make(['assignee_id' => 99]);
    $gone = $make(['assignee_id' => 98, 'assignee_type' => $dev->getMorphClass()]);

    $names = FeedbackActivity::assigneeNames(FeedbackActivity::with('actor')->whereKey([$typed->id, $oldSelf->id, $oldOther->id, $gone->id])->get());

    expect($names)->toEqual([$typed->id => 'sam@x.com', $oldSelf->id => 'Dev', $oldOther->id => '#99', $gone->id => '#98'])
        ->and($typed->sentence('Dev', $names[$typed->id]))->toBe('Dev assigned the report to sam@x.com');
});
