<?php

use Filament\Facades\Filament;
use Livewire\Livewire;
use YousefAman\FeedbackBoard\Facades\FeedbackBoard;
use YousefAman\FeedbackBoard\Filament\Resources\FeedbackItemResource\Pages\ListFeedbackItems;
use YousefAman\FeedbackBoard\Models\FeedbackItem;
use YousefAman\FeedbackBoard\Tests\Filament\FilamentTestUser;

it('opens the GitHub issue from All reports, in a new tab, only when there is one at an http(s) address', function () {
    FeedbackBoard::resolveDevelopersUsing(fn ($user) => $user->name === 'Dev');
    $this->actingAs(FilamentTestUser::create(['name' => 'Dev', 'email' => 'dev@x.com']));
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $sent = FeedbackItem::factory()->create(['external_refs' => ['github' => ['issue_number' => 7, 'html_url' => 'https://github.com/acme/app/issues/7']]]);
    $unsafe = FeedbackItem::factory()->create(['external_refs' => ['github' => ['issue_number' => 8, 'html_url' => 'javascript:alert(1)']]]);
    $notSent = FeedbackItem::factory()->create();

    Livewire::test(ListFeedbackItems::class)
        ->assertTableActionVisible('openGithubIssue', $sent)
        ->assertTableActionHasUrl('openGithubIssue', 'https://github.com/acme/app/issues/7', $sent)
        ->assertTableActionShouldOpenUrlInNewTab('openGithubIssue', $sent)
        ->assertTableActionHidden('openGithubIssue', $unsafe)
        ->assertTableActionHidden('openGithubIssue', $notSent);
});
