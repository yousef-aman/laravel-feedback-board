<?php

use Illuminate\Support\Facades\Gate;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Policies\FeedbackItemPolicy;
use YousefAman\FeedbackBoard\Tests\TestUser;

beforeEach(function () {
    config()->set('feedback-board.authorization.developer_resolver', fn ($u) => ($u->name ?? null) === 'Dev');
});

it('lets a developer manage but a reporter only see their own', function () {
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    $client = TestUser::create(['name' => 'Client', 'email' => 'c@x.com']);
    $mine = FeedbackItem::factory()->for($client, 'reporter')->create();
    $others = FeedbackItem::factory()->create();
    $policy = new FeedbackItemPolicy();

    expect($policy->viewAny($dev))->toBeTrue()
        ->and($policy->viewAny($client))->toBeFalse()
        ->and($policy->view($client, $mine))->toBeTrue()
        ->and($policy->view($client, $others))->toBeFalse()
        ->and($policy->view($dev, $others))->toBeTrue()
        ->and($policy->transition($client, $mine))->toBeFalse();
});

it('registers the manage gate', function () {
    $dev = TestUser::create(['name' => 'Dev', 'email' => 'd@x.com']);
    expect(Gate::forUser($dev)->allows('feedback-board.manage'))->toBeTrue();
});
