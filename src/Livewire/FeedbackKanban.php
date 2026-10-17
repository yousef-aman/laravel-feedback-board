<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\{On, Url};
use Livewire\Component;
use YousefAman\FeedbackBoard\Actions\TransitionStatus;
use YousefAman\FeedbackBoard\Enums\{FeedbackStatus, FeedbackType};
use YousefAman\FeedbackBoard\Facades\FeedbackBoard;
use YousefAman\FeedbackBoard\Models\{FeedbackAttachment, FeedbackItem};
use YousefAman\FeedbackBoard\Policies\FeedbackItemPolicy;
use YousefAman\FeedbackBoard\Support\Concerns\AuthorizesFeedbackUser;
use YousefAman\FeedbackBoard\Support\Person;

class FeedbackKanban extends Component
{
    use AuthorizesFeedbackUser;

    public ?int $selectedId = null;

    /**
     * The open card (?report=), so a link (a notification's) can open one. Untyped for the same
     * reason as $type; mount() keeps it only when it names a report.
     */
    #[Url(as: 'report')]
    public $report = null;

    /**
     * The type filter (?type=). Untyped on purpose: Livewire 3 assigns query values without a
     * type guard, and #[Url] JSON-decodes "5" to an integer and "true" to a boolean; under
     * strict_types FeedbackType::tryFrom() would throw for those, so typeFilter() guards it.
     */
    #[Url(as: 'type')]
    public $type = null;

    // Page access is enforced by the route's `can:viewAny` middleware (Step 4); each
    // write re-authorizes below. No mount() guard — that keeps the component mountable
    // in tests (Livewire re-throws auth exceptions raw on initial render) while the
    // real gate lives on the route + the per-action authorizeFeedback('transition').

    public function mount(): void
    {
        // #[Url] has already assigned the query values.
        $this->type = self::typeFilter($this->type)?->value;

        $id = self::reportId($this->report);
        $id !== null && FeedbackItem::whereKey($id)->exists() ? $this->open($id) : $this->close();
    }

    public function open(int $id): void
    {
        $this->selectedId = $id;
        $this->report = $id;
    }

    public function close(): void
    {
        $this->selectedId = null;
        $this->report = null;
    }

    /** "all", or anything that is not a type, clears the filter. */
    public function filterType(string $type): void
    {
        $this->type = FeedbackType::tryFrom($type)?->value;
    }

    /** The card dialog changed a report: redraw its column, counts and cover. */
    #[On('feedback-updated')]
    public function refreshBoard(): void
    {
    }

    public function moveTo(int $itemId, string $status, TransitionStatus $transition): void
    {
        $item = FeedbackItem::findOrFail($itemId);
        $this->authorizeFeedback('transition', $item);
        $s = FeedbackStatus::tryFrom($status) ?? abort(422);
        $transition->handle($item, $s, FeedbackBoard::user());
    }

    public function render(): View
    {
        // Defense-in-depth: if a non-developer somehow mounts this component outside the
        // gated route, return empty columns so item data does not leak. The per-action
        // authorizeFeedback('transition') in moveTo() remains the primary write guard.
        if (! FeedbackItemPolicy::isDeveloper(FeedbackBoard::user())) {
            return view('feedback-board::livewire.feedback-kanban', [
                'columns' => collect(), 'filters' => [], 'counts' => [], 'cards' => [], 'selected' => null,
            ]);
        }

        $type = self::typeFilter($this->type);

        // One query for every card, with its counts; the cover and the assignee are eager-loaded.
        // Nothing runs per card.
        $items = FeedbackItem::query()
            ->with(['coverAttachment', 'assignee'])
            ->withCount([
                'comments as replies_count' => fn (Builder $comments) => $comments->where('is_internal', false),
                'comments as internal_count' => fn (Builder $comments) => $comments->where('is_internal', true),
                'attachments',
            ])
            ->latest()
            ->orderByDesc('id')
            ->get();

        $counts = ['all' => $items->count()];

        foreach (FeedbackType::cases() as $case) {
            $counts[$case->value] = $items->filter(fn (FeedbackItem $item) => $item->type === $case)->count();
        }

        $shown = $type === null ? $items : $items->filter(fn (FeedbackItem $item) => $item->type === $type)->values();

        return view('feedback-board::livewire.feedback-kanban', [
            'columns' => collect(FeedbackStatus::cases())->mapWithKeys(fn (FeedbackStatus $status) => [
                $status->value => $shown->filter(fn (FeedbackItem $item) => $item->status === $status)->values(),
            ]),
            'filters' => array_map(fn (?FeedbackType $case) => [
                'value' => $case?->value ?? 'all',
                'type' => $case,
                'label' => $case?->label() ?? __('feedback-board::feedback-board.kanban.all'),
                'count' => $counts[$case?->value ?? 'all'],
                'active' => $case === $type,
            ], [null, ...FeedbackType::cases()]),
            'counts' => $counts,
            'cards' => $shown->mapWithKeys(fn (FeedbackItem $item) => [$item->id => $this->card($item)])->all(),
            'selected' => $this->selectedId ? FeedbackItem::find($this->selectedId) : null,
        ]);
    }

    /** @return array{cover: ?FeedbackAttachment, reporter: ?string, assignee: ?string} */
    private function card(FeedbackItem $item): array
    {
        $cover = $item->coverAttachment;

        return [
            // The relation filters by MIME type; isImage() also checks the extension.
            'cover' => $cover?->isImage() ? $cover : null,
            'reporter' => $item->reporterLabel(),
            'assignee' => $item->assignee_id !== null ? Person::name($item->assignee, $item->assignee_id) : null,
        ];
    }

    private static function typeFilter(mixed $value): ?FeedbackType
    {
        return is_string($value) ? FeedbackType::tryFrom($value) : null;
    }

    /** A positive report id from the query (#[Url] may decode "5" to an integer), else null. */
    private static function reportId(mixed $value): ?int
    {
        $id = is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : null);

        return $id !== null && $id > 0 ? $id : null;
    }
}
