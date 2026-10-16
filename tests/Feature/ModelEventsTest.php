<?php

use YousefAman\FeedbackBoard\Enums\ActivityAction;
use YousefAman\FeedbackBoard\Models\{FeedbackActivity, FeedbackItem};

// booted() runs lazily: instantiate the model first so the package's listener registers before the
// host's, as it does in an app where the model was used before the host's listener was added.

it('lets a host creating listener registered after the package run for reports', function () {
    new FeedbackItem;
    $ran = false;
    FeedbackItem::creating(function () use (&$ran) {
        $ran = true;
    });

    FeedbackItem::factory()->create();

    expect($ran)->toBeTrue();
});

it('lets a host creating listener registered after the package run for activities', function () {
    $item = FeedbackItem::factory()->create();
    new FeedbackActivity;
    $ran = false;
    FeedbackActivity::creating(function () use (&$ran) {
        $ran = true;
    });

    $item->activities()->create(['action' => ActivityAction::Created]);

    expect($ran)->toBeTrue();
});
