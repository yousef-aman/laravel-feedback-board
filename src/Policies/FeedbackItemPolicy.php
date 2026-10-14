<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Policies;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

class FeedbackItemPolicy
{
    public static function isDeveloper(?Model $user): bool
    {
        return $user !== null && Gate::forUser($user)->allows('feedback-board.manage');
    }

    protected function owns(?Model $user, FeedbackItem $item): bool
    {
        return $item->isReportedBy($user);
    }

    public function viewAny(?Model $user): bool { return self::isDeveloper($user); }
    public function create(?Model $user): bool { return $user !== null && Gate::forUser($user)->allows('feedback-board.report'); }
    // Ownership first: an owner never reaches the host's developer resolver.
    public function view(?Model $user, FeedbackItem $item): bool { return $this->owns($user, $item) || self::isDeveloper($user); }
    public function reply(?Model $user, FeedbackItem $item): bool { return $this->owns($user, $item) || self::isDeveloper($user); }
    public function transition(?Model $user, FeedbackItem $item): bool { return self::isDeveloper($user); }
    public function assign(?Model $user, FeedbackItem $item): bool { return self::isDeveloper($user); }
    public function update(?Model $user, FeedbackItem $item): bool { return self::isDeveloper($user); }
}
