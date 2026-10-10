<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use YousefAman\FeedbackBoard\Support\Ui;

class AssetController
{
    public function css(Request $request): Response
    {
        if (! Ui::stylesheetExists()) {
            return new Response('', 404, ['Cache-Control' => 'no-cache']);
        }

        $id = $request->query('id');
        $current = is_string($id) && hash_equals(Ui::stylesheetVersion(), $id);

        return new Response((string) file_get_contents(Ui::stylesheetPath()), 200, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            // An outdated or missing id must not pin stale CSS in caches for a year.
            'Cache-Control' => $current ? 'public, max-age=31536000, immutable' : 'no-cache',
        ]);
    }
}
