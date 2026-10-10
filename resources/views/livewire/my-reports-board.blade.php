<div class="fb-scope fb-reports" data-fb-theme="{{ \YousefAman\FeedbackBoard\Support\Ui::theme() }}" dir="{{ __('feedback-board::feedback-board.dir') }}"
     x-data="{ formOpen: false }"
     x-on:fb-open-report-form.window="formOpen = true; $dispatch('fb-launcher-opened')">
    @include('feedback-board::partials.assets')

    @unless ($inPanel)
        <header class="fb-head">
            <div class="fb-head__titles">
                <h1 class="fb-head__title">{{ __('feedback-board::feedback-board.board.my_reports') }}</h1>
                <p class="fb-head__sub">{{ __('feedback-board::feedback-board.reports.subheading') }}</p>
            </div>
            <button type="button" class="fb-btn fb-btn--primary" x-on:click="$dispatch('fb-open-report-form')">
                <x-feedback-board::icon name="plus" />{{ __('feedback-board::feedback-board.board.new_report') }}
            </button>
        </header>
    @endunless

    <div class="fb-segmented" role="group" aria-label="{{ __('feedback-board::feedback-board.reports.filter_label') }}">
        @foreach (\YousefAman\FeedbackBoard\Livewire\MyReportsBoard::FILTERS as $option)
            <button type="button" @class(['fb-segmented__btn', 'fb-tint' => $filter === $option]) aria-pressed="{{ $filter === $option ? 'true' : 'false' }}" wire:click="filterBy('{{ $option }}')">
                {{ __('feedback-board::feedback-board.reports.filter.'.$option) }}
                <span class="fb-count">{{ $counts[$option] }}</span>
            </button>
        @endforeach
    </div>

    <div class="fb-rows">
        <div class="fb-rows__head" aria-hidden="true">
            <span>{{ __('feedback-board::feedback-board.reports.columns.report') }}</span>
            <span>{{ __('feedback-board::feedback-board.reports.columns.status') }}</span>
            <span>{{ __('feedback-board::feedback-board.reports.columns.updated') }}</span>
            <span>{{ __('feedback-board::feedback-board.reports.columns.replies') }}</span>
        </div>
        @forelse ($items as $item)
            <button type="button" class="fb-row" wire:key="row-{{ $item->id }}" wire:click="open({{ $item->id }})">
                <span class="fb-row__main">
                    <x-feedback-board::type-icon :type="$item->type" />
                    <span class="fb-row__text">
                        <span class="fb-row__title" dir="auto">{{ $item->title }}</span>
                        <span class="fb-row__ref">#{{ $item->id }} · {{ $item->type->label() }}</span>
                    </span>
                </span>
                <span class="fb-badge fb-badge--{{ $item->status->color() }}">{{ $item->status->label() }}</span>
                <time class="fb-row__time" datetime="{{ $item->reporterActivity()?->toIso8601String() }}">{{ $item->reporterActivity()?->diffForHumans() }}</time>
                <span @class(['fb-row__replies', 'fb-row__replies--new' => $item->unread_count > 0])>
                    <x-feedback-board::icon name="chat-bubble-left-right" />
                    <span class="fb-row__count">{{ $item->replies_count }}</span>
                    @if ($item->unread_count > 0)
                        <span class="fb-unread" aria-hidden="true"></span>
                        <span class="fb-sr-only">{{ __('feedback-board::feedback-board.reports.new_reply') }}</span>
                    @endif
                </span>
            </button>
        @empty
            <p class="fb-empty">{{ $emptyText }}</p>
        @endforelse
    </div>

    <x-feedback-board::slide-over :id="$uid.'-form'" :labelledby="$uid.'-form-title'" show="formOpen" close="formOpen = false">
        @include('feedback-board::partials.report-form-sheet', ['titleId' => $uid.'-form-title'])
    </x-feedback-board::slide-over>

    @if ($selected)
        <x-feedback-board::slide-over :id="$uid.'-report'" :labelledby="$uid.'-report-title'" wide show="true" close="$wire.close()">
            @livewire('feedback-board::feedback-item-modal', ['item' => $selected, 'readBefore' => $readBefore, 'titleId' => $uid.'-report-title'], key('report-'.$selected->id))
        </x-feedback-board::slide-over>
    @endif
</div>
