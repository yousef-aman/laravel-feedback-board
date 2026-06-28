<?php

it('exposes triage UI strings in both locales', function () {
    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);
        foreach (['analyzing', 'failed', 'summary', 'suggested_type', 'suggested_priority', 'apply_type', 'apply_priority', 'triage', 'retriage', 'view_suggestion'] as $key) {
            expect(__("feedback-board::feedback-board.triage.{$key}"))
                ->not->toBe("feedback-board::feedback-board.triage.{$key}");
        }
    }
});
