<div class="fb-reply-inline" dir="{{ __('feedback-board::feedback-board.dir') }}">
    <form wire:submit="reply">
        <textarea wire:model="body" placeholder="{{ __('feedback-board::feedback-board.board.reply') }}"></textarea>
        @error('body') <span class="fb-error">{{ $message }}</span> @enderror
        <button type="submit">{{ __('feedback-board::feedback-board.board.reply') }}</button>
    </form>
</div>
