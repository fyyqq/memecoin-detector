<?php

declare(strict_types=1);

namespace App\Services\Evidence;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Transport for the GDELT 2.1 DOC API — the ONLY external source the evidence
 * engine touches. Free, no API key.
 *
 * Every failure mode (timeout, non-200, non-JSON, malformed body) returns an
 * empty list and logs one concise line. It NEVER throws — a news outage must not
 * fail `memecoins:collect-evidence`. A per-command-run request budget bounds the
 * total network cost.
 */
class GdeltNewsClient
{
    private int $requestsRemaining;

    private bool $lastCallFailed = false;

    public function __construct()
    {
        $this->requestsRemaining = max(0, (int) config('evidence.news.max_requests_per_run', 15));
    }

    /** Reset the per-run request budget at the start of a collection run. */
    public function resetBudget(): void
    {
        $this->requestsRemaining = max(0, (int) config('evidence.news.max_requests_per_run', 15));
        $this->lastCallFailed = false;
    }

    public function budgetExhausted(): bool
    {
        return $this->requestsRemaining <= 0;
    }

    /** True when the most recent search() call hit a provider error. */
    public function lastCallFailed(): bool
    {
        return $this->lastCallFailed;
    }

    /**
     * @return list<GdeltArticle>
     */
    public function search(string $query, CarbonImmutable $start, CarbonImmutable $end, int $maxRecords): array
    {
        $this->lastCallFailed = false;

        if ($this->requestsRemaining <= 0) {
            return [];
        }
        $this->requestsRemaining--;

        $base = (string) config('evidence.news.gdelt_base_url', 'https://api.gdeltproject.org/api/v2/doc/doc');

        try {
            $response = Http::timeout((int) config('evidence.news.timeout', 8))
                ->connectTimeout((int) config('evidence.news.connect_timeout', 4))
                ->acceptJson()
                ->get($base, [
                    'query' => $query,
                    'mode' => 'ArtList',
                    'format' => 'json',
                    'startdatetime' => $start->utc()->format('YmdHis'),
                    'enddatetime' => $end->utc()->format('YmdHis'),
                    'maxrecords' => max(1, min(250, $maxRecords)),
                    'sort' => 'DateDesc',
                ]);
        } catch (Throwable $e) {
            $this->lastCallFailed = true;
            Log::warning('GDELT news lookup failed (transport)', ['error' => $e->getMessage()]);

            return [];
        }

        if ($response->failed()) {
            $this->lastCallFailed = true;
            Log::warning('GDELT news lookup failed (http)', ['status' => $response->status()]);

            return [];
        }

        // GDELT sometimes answers 200 with an HTML error page for a rejected
        // query — json() is then null.
        $articles = $response->json('articles');
        if (! is_array($articles)) {
            return [];
        }

        $out = [];
        foreach ($articles as $row) {
            if (is_array($row)) {
                $article = GdeltArticle::fromArray($row);
                if ($article !== null) {
                    $out[] = $article;
                }
            }
        }

        return $out;
    }
}
