<?php

use YousefAman\FeedbackBoard\Filament\Pages\FeedbackBoardPage;
use YousefAman\FeedbackBoard\Filament\Resources\FeedbackItemResource;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Tests\Filament\FilamentTestUser;

beforeEach(function () {
    config()->set('feedback-board.authorization.developer_resolver', fn ($user) => $user->name === 'Dev');
    FeedbackItem::factory()->create(['title' => 'Smoke item']);
});

it('serves the board page to a developer', function () {
    $this->actingAs(FilamentTestUser::create(['name' => 'Dev']))
        ->get(FeedbackBoardPage::getUrl())
        ->assertOk()
        ->assertSee('Smoke item');
});

it('serves the feedback items resource index to a developer', function () {
    $this->actingAs(FilamentTestUser::create(['name' => 'Dev']))
        ->get(FeedbackItemResource::getUrl('index'))
        ->assertOk()
        ->assertSee('Smoke item');
});

it('forbids the board page and the resource index to a non-developer', function () {
    $this->actingAs(FilamentTestUser::create(['name' => 'Client']));

    $this->get(FeedbackBoardPage::getUrl())->assertForbidden();
    $this->get(FeedbackItemResource::getUrl('index'))->assertForbidden();
});
