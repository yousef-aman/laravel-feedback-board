{{-- resources/views/livewire/feedback-item-modal.blade.php --}}
<div dir="{{ __('feedback-board::feedback-board.dir') }}">
    <h3>{{ $item->title }}</h3>
    <p>{{ $item->description }}</p>
    @foreach ($comments as $c)
        <div wire:key="c-{{ $c->id }}" class="fb-comment fb-comment--{{ $c->author_role->value }}">{{ $c->body }}</div>
    @endforeach
    <form wire:submit="reply">
        <textarea wire:model="body"></textarea>
        @error('body') <span class="fb-error">{{ $message }}</span> @enderror
        <button type="submit">{{ __('feedback-board::feedback-board.board.reply') }}</button>
    </form>
</div>
