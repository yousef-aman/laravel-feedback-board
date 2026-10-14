<?php

namespace YousefAman\FeedbackBoard\Tests\Guards;

use Closure;
use Illuminate\Http\Request;

/** Host middleware listed after feedback-board.auth: answers with the user it sees. */
class RecordsUser
{
    public function handle(Request $request, Closure $next): mixed
    {
        return response('seen: '.$request->user()?->name);
    }
}
