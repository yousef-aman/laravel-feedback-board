{{-- The report button for a user who may report, a "My reports" link for one who may not but has reports, and nothing otherwise (Ui::reporterAccess()). A host may render this on pages guests see too: a Filament panel's BODY_END hook includes its login page. --}}
@php($fbAccess = \YousefAman\FeedbackBoard\Support\Ui::reporterAccess())
@if ($fbAccess === 'form')
<div class="fb-scope" data-fb-theme="{{ \YousefAman\FeedbackBoard\Support\Ui::theme() }}" dir="{{ __('feedback-board::feedback-board.dir') }}"
     x-data="{ open: false }">
    @include('feedback-board::partials.assets')
    <button type="button" class="fb-launcher" x-on:click="open = true; $dispatch('fb-launcher-opened')" aria-haspopup="dialog" x-bind:aria-expanded="open">
        <x-feedback-board::icon name="chat-bubble-oval-left-ellipsis" class="fb-launcher__icon" />
        <span class="fb-launcher__label">{{ __('feedback-board::feedback-board.launcher') }}</span>
    </button>
    <x-feedback-board::slide-over id="fb-launcher-panel" labelledby="fb-launcher-title">
        @include('feedback-board::partials.report-form-sheet', ['titleId' => 'fb-launcher-title'])
    </x-feedback-board::slide-over>
</div>
@elseif ($fbAccess === 'reports')
<div class="fb-scope" data-fb-theme="{{ \YousefAman\FeedbackBoard\Support\Ui::theme() }}" dir="{{ __('feedback-board::feedback-board.dir') }}">
    @include('feedback-board::partials.assets')
    <a class="fb-launcher" href="{{ \YousefAman\FeedbackBoard\Support\Ui::reportsUrl() }}">
        <x-feedback-board::icon name="chat-bubble-left-right" class="fb-launcher__icon" />
        <span class="fb-launcher__label">{{ __('feedback-board::feedback-board.board.my_reports') }}</span>
    </a>
</div>
@endif
