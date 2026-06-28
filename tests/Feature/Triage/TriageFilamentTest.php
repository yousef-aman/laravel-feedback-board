<?php

use YousefAman\FeedbackBoard\Filament\Resources\FeedbackItemResource;

it('registers the triage record actions on the resource table', function () {
    if (! class_exists(\Filament\Actions\Action::class)) {
        $this->markTestSkipped('Filament not installed');
    }

    // Table::make() requires a HasTable livewire instance. A bare Component throws a
    // TypeError, so the double implements HasTable via InteractsWithTable. We never
    // mount/render — table() only configures the Table object, so the trait methods
    // are not invoked.
    $livewire = new class extends \Livewire\Component implements \Filament\Tables\Contracts\HasTable {
        use \Filament\Tables\Concerns\InteractsWithTable;
        public function render() { return view('feedback-board::filament.triage-suggestion', ['triage' => []]); }
        public function makeFilamentTranslatableContentDriver(): ?\Filament\Support\Contracts\TranslatableContentDriver { return null; }
    };

    $table = FeedbackItemResource::table(\Filament\Tables\Table::make($livewire));
    $names = array_map(fn ($a) => $a->getName(), $table->getRecordActions());

    expect($names)->toContain('triage')
        ->and($names)->toContain('applyType')
        ->and($names)->toContain('applyPriority')
        ->and($names)->toContain('viewSuggestion');
});

it('renders the suggestion modal body with the summary HTML-escaped', function () {
    $html = view('feedback-board::filament.triage-suggestion', [
        'triage' => [
            'status' => 'completed',
            'suggested_type' => 'bug',
            'suggested_priority' => 'high',
            'summary' => '<script>alert(1)</script>',
        ],
    ])->render();

    expect($html)->not->toContain('<script>alert(1)</script>') // raw markup escaped away
        ->and($html)->toContain('alert(1)');                   // escaped text present
});
