<?php

declare(strict_types=1);

namespace YousefAman\FeedbackBoard\Destinations\Drivers;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use YousefAman\FeedbackBoard\Destinations\Contracts\Destination;
use YousefAman\FeedbackBoard\Destinations\Exceptions\RetryAfter;
use YousefAman\FeedbackBoard\Destinations\Support\DestinationEvent;
use YousefAman\FeedbackBoard\Enums\{AuthorRole, FeedbackStatus};
use YousefAman\FeedbackBoard\Events\FeedbackItemCreated;
use YousefAman\FeedbackBoard\Events\FeedbackReplied;
use YousefAman\FeedbackBoard\Events\FeedbackStatusChanged;
use YousefAman\FeedbackBoard\Models\FeedbackItem;

class GithubIssueDriver implements Destination
{
    public function send(FeedbackItem $item, object $event, string $deliveryId): void
    {
        $token = (string) config('feedback-board.destinations.github.token');
        $repo = (string) config('feedback-board.destinations.github.repo');
        if ($token === '' || $repo === '') {
            Log::warning('feedback-board: github driver not configured; skipping delivery.');
            return;
        }

        // Internal notes never leave the app (defense in depth; the listener also drops them).
        if ($event instanceof FeedbackReplied && $event->comment->is_internal) {
            return;
        }

        // GitHub rejects an empty comment (422); an image-only reply has no text to mirror.
        if ($event instanceof FeedbackReplied && trim((string) $event->comment->body) === '') {
            return;
        }

        $store = config('feedback-board.destinations.lock_store');
        try {
            Cache::store($store)->lock('feedback-board:github:'.$item->id, 60)->block(8, function () use ($item, $event, $deliveryId, $token, $repo) {
                $fresh = FeedbackItem::find($item->id);
                if ($fresh === null) {
                    return;
                }
                $ref = $fresh->external_refs['github'] ?? null;

                if ($ref === null) {
                    // Create path — reachable only for the manual created event.
                    if (! $event instanceof FeedbackItemCreated) {
                        return;
                    }
                    $resp = $this->client($token)->post($this->base($repo).'/issues', [
                        'title' => mb_strimwidth($fresh->title, 0, 250, '…'),
                        'body' => $this->issueBody($fresh),
                    ]);
                    $this->guardResponse($resp);
                    $data = $resp->json();
                    if (! isset($data['number'])) {
                        throw new \RuntimeException('feedback-board: github create returned no issue number');
                    }
                    $refs = $fresh->external_refs ?? [];
                    $refs['github'] = [
                        'issue_number' => $data['number'],
                        'node_id' => $data['node_id'] ?? null,
                        'html_url' => $data['html_url'] ?? null,
                        'repo' => $repo,
                        'posted' => [$deliveryId],
                    ];
                    $fresh->external_refs = $refs;
                    $fresh->save();
                    return;
                }

                // Ref exists.
                if ($event instanceof FeedbackItemCreated) {
                    return; // duplicate manual send — server-side no-op guard
                }

                $number = $ref['issue_number'];
                // The issue lives where it was opened; FEEDBACK_GITHUB_REPO may have changed since.
                $issueRepo = is_string($ref['repo'] ?? null) && $ref['repo'] !== '' ? $ref['repo'] : $repo;
                $posted = $ref['posted'] ?? [];
                if (! in_array($deliveryId, $posted, true)) {
                    $resp = $this->client($token)->post($this->base($issueRepo)."/issues/{$number}/comments", [
                        'body' => $this->commentBody($event),
                    ]);
                    $this->guardResponse($resp);
                    $ref['posted'] = [...$posted, $deliveryId];
                    $refs = $fresh->external_refs ?? [];
                    $refs['github'] = $ref;
                    $fresh->external_refs = $refs;
                    $fresh->save();
                }

                if ($event instanceof FeedbackStatusChanged
                    && $event->to === FeedbackStatus::Resolved
                    && config('feedback-board.destinations.github.close_on_resolved')) {
                    $resp = $this->client($token)->patch($this->base($issueRepo)."/issues/{$number}", ['state' => 'closed']);
                    $this->guardResponse($resp);
                }
            });
        } catch (LockTimeoutException) {
            throw new RetryAfter(30); // contention — soft queue retry
        }
    }

    private function client(string $token)
    {
        // retry() retries connection errors AND any non-2xx; TransientRetry narrows
        // it to connection/429/5xx so a permanent 422/404 is NOT retried. throw:false
        // returns the final response; guardResponse() then decides RetryAfter vs throw.
        return Http::withToken($token)
            ->timeout(8)->connectTimeout(4)
            ->retry(2, 200, \YousefAman\FeedbackBoard\Destinations\Support\TransientRetry::when(), throw: false)
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ]);
    }

    private function base(string $repo): string
    {
        return 'https://api.github.com/repos/'.$repo;
    }

    /** Throw RetryAfter on a rate-limited response; fail on other 4xx/5xx; pass on 2xx. */
    private function guardResponse(Response $resp): void
    {
        if (($resp->status() === 429 || $resp->status() === 403) && $resp->header('Retry-After') !== '') {
            throw new RetryAfter((int) $resp->header('Retry-After'));
        }
        $resp->throw(); // permanent 4xx (422/404) and unrecovered 5xx fail the job
    }

    private function issueBody(FeedbackItem $item): string
    {
        return trim(($item->description ?? '')."\n\n—\nfeedback-board #{$item->id} ({$item->project})");
    }

    private function commentBody(object $event): string
    {
        if ($event instanceof FeedbackReplied) {
            // By role only, in English like the status lines: no reporter or developer name leaves the app.
            $author = match ($event->comment->author_role) {
                AuthorRole::Reporter => 'Reporter',
                AuthorRole::Ai => 'AI',
                default => 'Developer', // the column's default
            };

            return "**{$author}** replied:\n\n".($event->comment->body ?? '');
        }
        if ($event instanceof FeedbackStatusChanged) {
            return "Status: {$event->from->value} → {$event->to->value}";
        }
        return DestinationEvent::name($event);
    }
}
