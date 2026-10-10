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

it('labels triage suggestions with translated enum labels', function () {
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    $item = FeedbackItem::factory()->create(['priority' => FeedbackPriority::Low]);
    $item->ai_state = ['triage' => ['status' => 'completed', 'summary' => 'Looks urgent', 'suggested_type' => 'feature', 'suggested_priority' => 'urgent', 'model' => 'fake']];
    $item->save();

    Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item])
        ->assertSee(__('feedback-board::feedback-board.triage.apply_priority').': Urgent')
        ->assertSee(__('feedback-board::feedback-board.triage.apply_type').': Feature request')
        ->assertSeeHtml('<span dir="auto">Looks urgent</span>');
});

it('shows a disabled no-priority placeholder only while the item has no priority', function () {
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    $item = FeedbackItem::factory()->create(['priority' => null]);

    $component = Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item])
        ->assertSeeHtml('<option value="" disabled selected>');

    $component->call('setPriority', FeedbackPriority::High->value)
        ->assertDontSeeHtml('<option value="" disabled selected>');
});

it('links the captured page URL only when it is http(s)', function (string $url, bool $linked) {
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    $item = FeedbackItem::factory()->create(['meta' => ['url' => $url, 'user_agent' => 'Mozilla/5.0 Test', 'locale' => 'ar']]);

    $component = Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item])
        ->assertSee(__('feedback-board::feedback-board.board.context'))
        ->assertSee('Mozilla/5.0 Test')
        ->assertSee($url)
        ->assertSeeHtml('<span dir="ltr">Mozilla/5.0 Test</span>');

    $linked
        ? $component->assertSeeHtml('href="'.e($url).'"')
        : $component->assertDontSeeHtml('href="'.e($url).'"')->assertDontSeeHtml('href="javascript:');
})->with([
    ['https://app.test/orders/7', true],
    ['javascript:alert(1)', false],
    [' javascript:alert(1)', false],
]);

it('omits the context section when nothing was captured', function () {
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    $item = FeedbackItem::factory()->create(['meta' => []]);

    Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item])
        ->assertDontSee(__('feedback-board::feedback-board.board.context'));
});

it('shows reporter, assignee, role-labelled thread and translated activity', function () {
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    $item = FeedbackItem::factory()->create(['reporter_name' => 'Lena Client', 'status' => FeedbackStatus::New]);

    Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item])
        ->assertSee('Lena Client')
        ->assertSee(__('feedback-board::feedback-board.board.unassigned'))
        ->set('note', 'root cause: cache')->call('internalNote')
        ->call('assignToMe')
        ->call('changeStatus', FeedbackStatus::InProgress->value)
        ->assertSee('Dev')
        ->assertSee(AuthorRole::Developer->label())
        ->assertSeeHtml('fb-comment--internal')
        ->assertSee(__('feedback-board::feedback-board.board.internal'))
        ->assertSee('Status changed: In progress')
        ->assertSeeHtml('class="fb-scope fb-detail"')
        ->assertSeeHtml('id="fb-drawer-title"')
        ->assertSeeHtml('x-bind:aria-pressed="tab === \'note\'"');
});

it('records an unassignment as such in the Arabic timeline', function () {
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    $item = FeedbackItem::factory()->create();
    app()->setLocale('ar');

    Livewire::actingAs($dev)->test(FeedbackDetail::class, ['item' => $item])
        ->call('assignToMe')
        ->call('unassign')
        ->assertSee('أُلغي الإسناد')
        ->assertSeeHtml('dir="rtl"');
});
