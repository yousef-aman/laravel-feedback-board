<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\{Locked, On, Url};
use Livewire\Component;
use YousefAman\FeedbackBoard\Facades\FeedbackBoard;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

class MyReportsBoard extends Component
{
    /** @var list<string> */
    public const FILTERS = ['all', 'open', 'done'];

    /**
     * The open report (?report=). Untyped on purpose: Livewire 3 assigns query values without a
     * type guard, and #[Url] JSON-decodes "5" to an integer; reportId() normalises both.
     */
    #[Url(as: 'report')]
    public $report = null;

    #[Locked]
    public string $filter = 'all';

    /** Inside a Filament page, which owns the heading and the "New report" action. */
    #[Locked]
    public bool $inPanel = false;

    /** The read marker before this opening: replies after it are tagged "new". */
    #[Locked]
    public ?string $readBefore = null;

    public function mount(bool $inPanel = false): void
    {
        $this->inPanel = $inPanel;

        if (($id = self::reportId($this->report)) !== null) {
            $this->open($id);
        }
    }

    #[On('feedback-submitted')]
    public function refreshList(): void
    {
        // Re-render: the new report joins the list, and an open form keeps its sent state.
    }

    public function open(int $id): void
    {
        $item = $this->ownItems()->whereKey($id)->first();

        if ($item === null) {
            $this->close();

            return;
        }

        $this->readBefore = $item->readMarker()?->toIso8601String();
        $item->markReadBy(FeedbackBoard::user());
        $this->report = $item->id;
    }

    public function close(): void
    {
        $this->report = null;
        $this->readBefore = null;
    }

    public function filterBy(string $filter): void
    {
        $this->filter = in_array($filter, self::FILTERS, true) ? $filter : 'all';
    }

    public function render(): View
    {
        $items = $this->ownItems()
            ->withUnreadCount()
            ->withCount(['comments as replies_count' => fn (Builder $comments) => $comments->where('is_internal', false)])
            ->latestForReporter()
            ->get();

        $open = $items->filter(fn (FeedbackItem $item) => $item->status->isOpen());
        $id = self::reportId($this->report);

        return view('feedback-board::livewire.my-reports-board', [
            'uid' => 'fb-'.$this->getId(),
            'counts' => ['all' => $items->count(), 'open' => $open->count(), 'done' => $items->count() - $open->count()],
            'items' => match ($this->filter) {
                'open' => $open->values(),
                'done' => $items->reject(fn (FeedbackItem $item) => $item->status->isOpen())->values(),
                default => $items,
            },
            'emptyText' => __(match ($this->filter) {
                'open' => 'feedback-board::feedback-board.reports.empty_open',
                'done' => 'feedback-board::feedback-board.reports.empty_done',
                default => 'feedback-board::feedback-board.board.empty_reports',
            }),
            'selected' => $id === null ? null : $items->firstWhere('id', $id),
        ]);
    }

    /** Scoped to the reporter: another user's id, or none, opens nothing. */
    private function ownItems(): Builder
    {
        return FeedbackItem::query()->ownedBy(FeedbackBoard::user());
    }

    /** ctype_digit() must never see an int: it reads -128..255 as ASCII codes. */
    private static function reportId(mixed $value): ?int
    {
        return is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : null);
    }
}
