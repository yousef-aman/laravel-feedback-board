@props(['comment'])
{{-- The AI assistant writes Markdown, rendered safely; what people write stays plain text. --}}
@if ($comment->author_role === \YousefAman\FeedbackBoard\Enums\AuthorRole::Ai)
    <div class="fb-md">{{ \YousefAman\FeedbackBoard\Support\Markdown::html($comment->body) }}</div>
@else
    <p class="fb-prose" dir="auto">{{ $comment->body }}</p>
@endif
