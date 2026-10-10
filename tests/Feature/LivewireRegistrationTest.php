<?php

use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use YousefAman\FeedbackBoard\Livewire\{FeedbackDetail, FeedbackForm, FeedbackItemModal, FeedbackKanban, MyReportsBoard};
use YousefAman\FeedbackBoard\Tests\TestUser;

it('resolves every feedback-board:: component name on the installed Livewire major', function (string $name, string $class) {
    // Livewire::new() exists on Livewire 3 and 4; Livewire::exists() is 4-only.
    expect(Livewire::new('feedback-board::'.$name))->toBeInstanceOf($class);
})->with([
    ['feedback-form', FeedbackForm::class],
    ['my-reports-board', MyReportsBoard::class],
    ['feedback-item-modal', FeedbackItemModal::class],
    ['feedback-kanban', FeedbackKanban::class],
    ['feedback-detail', FeedbackDetail::class],
]);

it('renders the launcher with the nested feedback form for an authenticated user', function () {
    $this->actingAs(TestUser::create(['name' => 'Client', 'email' => 'c@x.com']));

    $html = Blade::render('<x-feedback-board::launcher />');

    expect($html)->toContain('fb-launcher')
        ->and($html)->toContain('wire:submit="submit"');
});
