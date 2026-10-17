<?php

it('exposes triage UI strings in both locales', function () {
    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);
        foreach (['analyzing', 'failed', 'summary', 'suggested_type', 'suggested_priority', 'apply_type', 'apply_priority', 'triage', 'retriage', 'view_suggestion', 'failed_reason', 'rerun_failed', 'previous_kept',
            'reasons.auth_401', 'reasons.not_configured', 'reasons.invalid_output', 'reasons.connection_error', 'reasons.not_found_404',
            'reasons.validation_422', 'reasons.http_429', 'reasons.http_5xx', 'reasons.unknown'] as $key) {
            expect(__("feedback-board::feedback-board.triage.{$key}"))
                ->not->toBe("feedback-board::feedback-board.triage.{$key}");
        }
    }
});
