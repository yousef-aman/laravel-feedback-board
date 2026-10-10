<?php

it('renders the triage suggestion with a bidi-safe summary and translated labels', function () {
    $html = view('feedback-board::filament.triage-suggestion', ['triage' => [
        'summary' => 'Looks urgent',
        'suggested_type' => 'feature',
        'suggested_priority' => 'urgent',
    ]])->render();

    expect($html)->toContain('<span dir="auto">Looks urgent</span>')
        ->toContain('Feature request')
        ->toContain('Urgent');
});
