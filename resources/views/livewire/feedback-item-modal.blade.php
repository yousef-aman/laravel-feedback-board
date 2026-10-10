<div class="fb-scope fb-item" data-fb-theme="{{ \YousefAman\FeedbackBoard\Support\Ui::theme() }}" dir="{{ __('feedback-board::feedback-board.dir') }}">
    @include('feedback-board::partials.assets')
    <header class="fb-detail__header">
        <h2 id="fb-drawer-title" class="fb-detail__title" dir="auto">{{ $item->title }}</h2>
        <div class="fb-detail__meta">
            <span class="fb-badge fb-badge--{{ $item->status->color() }}">{{ $item->status->label() }}</span>
            @if ($item->created_at)
                <time datetime="{{ $item->created_at->toIso8601String() }}">{{ $item->created_at->diffForHumans() }}</time>
            @endif
        </div>
    </header>

    @if (filled($item->description))
        <p class="fb-prose" dir="auto">{{ $item->description }}</p>
    @endif

    @if ($comments->isNotEmpty())
        <section class="fb-section">
            <h3 class="fb-section-title">{{ __('feedback-board::feedback-board.board.thread') }}</h3>
            <div class="fb-thread">
                @foreach ($comments as $c)
                    <article wire:key="c-{{ $c->id }}" @class(['fb-comment', 'fb-comment--'.$c->author_role->value, 'fb-comment--internal' => $c->is_internal])>
                        <div class="fb-comment__head">
                            <span class="fb-comment__author">{{ $c->author_role->label() }}</span>
                            @if ($c->is_internal)
                                <span class="fb-badge fb-badge--warning">{{ __('feedback-board::feedback-board.board.internal') }}</span>
                            @endif
                            @if ($c->created_at)
                                <time class="fb-comment__time" datetime="{{ $c->created_at->toIso8601String() }}">{{ $c->created_at->diffForHumans() }}</time>
                            @endif
                        </div>
                        <p class="fb-prose" dir="auto">{{ $c->body }}</p>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    <form wire:submit="reply" class="fb-composer__panel">
        <label class="fb-field">
            <span class="fb-sr-only">{{ __('feedback-board::feedback-board.board.reply') }}</span>
            <textarea class="fb-textarea" wire:model="body" rows="3" dir="auto" placeholder="{{ __('feedback-board::feedback-board.board.reply') }}"></textarea>
            @error('body') <span class="fb-error">{{ $message }}</span> @enderror
        </label>
        <div class="fb-composer__actions">
            <button type="submit" class="fb-btn fb-btn--primary">{{ __('feedback-board::feedback-board.board.reply') }}</button>
        </div>
    </form>
</div>
