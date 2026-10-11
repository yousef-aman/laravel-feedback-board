<?php

use YousefAman\FeedbackBoard\Filament\FeedbackBoardPlugin;
use YousefAman\FeedbackBoard\Filament\Resources\FeedbackItemResource;

it('exposes a Filament plugin wiring the resource', function () {
    if (! interface_exists(\Filament\Contracts\Plugin::class)) {
        $this->markTestSkipped('Filament not installed');
    }
    $plugin = FeedbackBoardPlugin::make();
    expect($plugin->getId())->toBe('feedback-board')
        ->and(FeedbackItemResource::getModel())->toBe(\YousefAman\FeedbackBoard\Models\FeedbackItem::class)
        ->and(method_exists(FeedbackItemResource::class, 'table'))->toBeTrue()
        ->and(class_exists(\YousefAman\FeedbackBoard\Filament\Pages\FeedbackBoardPage::class))->toBeTrue()
        ->and(class_exists(\YousefAman\FeedbackBoard\Filament\Pages\MyFeedbackPage::class))->toBeTrue()
        ->and($plugin->hasMyReports())->toBeTrue()
        ->and(FeedbackBoardPlugin::make()->myReports(false)->hasMyReports())->toBeFalse();
});
