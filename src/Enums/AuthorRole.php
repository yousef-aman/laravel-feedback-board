<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Enums;

enum AuthorRole: string
{
    case Reporter = 'reporter';
    case Developer = 'developer';
    case Ai = 'ai';
}
