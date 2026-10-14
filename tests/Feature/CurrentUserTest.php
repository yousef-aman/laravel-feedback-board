<?php

use YousefAman\FeedbackBoard\FeedbackBoard as Board;
use YousefAman\FeedbackBoard\Facades\FeedbackBoard;
use YousefAman\FeedbackBoard\Tests\TestUser;

it('reads the default guard, as auth()->user() does, in an app without Filament', function () {
    $user = TestUser::create(['name' => 'Me']);
    expect(FeedbackBoard::user())->toBeNull();

    $this->actingAs($user);

    expect(FeedbackBoard::user())->toBe($user)
        ->and(app(Board::class)->candidateGuards())->toBe(['web']);
});

it('reads an explicit list in an app without Filament', function () {
    $user = TestUser::create(['name' => 'Me']);
    config()->set('feedback-board.auth.guards', ['web']);
    $this->actingAs($user);

    expect(app(Board::class)->resolveUser())->toBe(['guard' => 'web', 'user' => $user]);
});
