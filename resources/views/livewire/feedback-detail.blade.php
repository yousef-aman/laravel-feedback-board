{{-- resources/views/livewire/feedback-detail.blade.php --}}
<div class="fb-detail" dir="{{ __('feedback-board::feedback-board.dir') }}">
    <h3>{{ $item->title }}</h3>
    <p>{{ $item->description }}</p>

    <div class="fb-detail__controls">
        <select wire:change="changeStatus($event.target.value)">
            @foreach ($statuses as $s)
                <option value="{{ $s->value }}" @selected($item->status === $s)>{{ $s->label() }}</option>
            @endforeach
        </select>
        <select wire:change="setPriority($event.target.value)">
            @foreach ($priorities as $p)
                <option value="{{ $p->value }}" @selected($item->priority === $p)>{{ $p->label() }}</option>
            @endforeach
        </select>
        @if ($item->assignee_id)
            <button type="button" wire:click="unassign">{{ __('feedback-board::feedback-board.board.unassign') }}</button>
        @else
            <button type="button" wire:click="assignToMe">{{ __('feedback-board::feedback-board.board.assign_me') }}</button>
        @endif
    </div>

    <h4>{{ __('feedback-board::feedback-board.board.attachments') }}</h4>
    <ul>
        @foreach ($attachments as $att)
            <li><a href="{{ route('feedback-board.attachments.show', $att) }}">{{ $att->original_name }}</a></li>
        @endforeach
    </ul>

    <h4>{{ __('feedback-board::feedback-board.board.thread') }}</h4>
    @foreach ($comments as $c)
        <div wire:key="dc-{{ $c->id }}" class="fb-comment fb-comment--{{ $c->author_role->value }} @if($c->is_internal) fb-comment--internal @endif">
            {{ $c->body }}
        </div>
    @endforeach

    <form wire:submit="reply">
        <textarea wire:model="body" placeholder="{{ __('feedback-board::feedback-board.board.reply') }}"></textarea>
        @error('body') <span class="fb-error">{{ $message }}</span> @enderror
        <button type="submit">{{ __('feedback-board::feedback-board.board.reply') }}</button>
    </form>

    <form wire:submit="internalNote">
        <textarea wire:model="note" placeholder="{{ __('feedback-board::feedback-board.board.internal_note') }}"></textarea>
        @error('note') <span class="fb-error">{{ $message }}</span> @enderror
        <button type="submit">{{ __('feedback-board::feedback-board.board.internal_note') }}</button>
    </form>

    <h4>{{ __('feedback-board::feedback-board.board.activity') }}</h4>
    <ul class="fb-activity">
        @foreach ($activities as $a)
            <li wire:key="act-{{ $a->id }}">{{ $a->action->value }} — {{ $a->created_at }}</li>
        @endforeach
    </ul>
</div>
