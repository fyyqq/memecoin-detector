<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\PumpEvent;
use App\Models\Token;
use App\Services\Evidence\Collectors\MarketEvidenceCollector;
use App\Services\Evidence\Collectors\RelatedTokenEvidenceCollector;
use App\Services\Evidence\EvidenceCollectionService;
use App\Services\Evidence\EvidenceWindow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Step 16B — evidence collection around detected pump events.
 *
 * Evidence is FACT, stored separately from interpretation. These tests pin the
 * "never claim causality" contract, the bounded windows, deterministic scoring,
 * entity-match guards, idempotency, and provider-failure resilience.
 *
 * The only external source (GDELT) is always HTTP-faked — never called live.
 */
class EvidenceCollectionTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-08-28T12:00:00Z');
        CarbonImmutable::setTestNow($this->now);
        Http::preventStrayRequests();

        config()->set('evidence.window.before_minutes', 60);
        config()->set('evidence.window.after_minutes', 30);
        config()->set('evidence.collection_cooldown_hours', 2);
        config()->set('evidence.recent_event_hours', 48);
        config()->set('evidence.max_events_per_run', 20);
        config()->set('evidence.related.lead_window_minutes', 60);
        config()->set('evidence.related.minimum_move_pct', 40);
        config()->set('evidence.related.max_related', 5);
        config()->set('evidence.related.cross_chain', false);
        config()->set('evidence.news.enabled', true);
        config()->set('evidence.news.provider', 'gdelt');
        config()->set('evidence.news.gdelt_base_url', 'https://api.gdeltproject.org/api/v2/doc/doc');
        config()->set('evidence.news.max_requests_per_run', 15);
        config()->set('evidence.news.max_results_per_event', 10);
        config()->set('evidence.news.minimum_symbol_length', 4);
        config()->set('evidence.news.trusted_domains', ['coindesk.com', 'cointelegraph.com']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow(null);
        parent::tearDown();
    }

    // ---- helpers ------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function token(array $overrides = []): Token
    {
        /** @var Token $token */
        $token = Token::query()->create(array_replace([
            'chain_id' => 'solana',
            'token_address' => 'Addr'.Str::random(24),
            'symbol' => 'MANLET',
            'name' => 'Manlet',
            'earliest_pair_created_at' => $this->now->subDays(4),
            'first_observed_at' => $this->now->subHours(6),
            'last_observed_at' => $this->now,
            'observed_peak_market_cap' => 8_000_000.0,
            'observed_peak_market_cap_at' => $this->now,
        ], $overrides));

        return $token;
    }

    /**
     * @param  array<string,mixed>  $attrs  min = minutes before now
     */
    private function snapshot(Token $token, int $min, array $attrs = []): void
    {
        $token->marketSnapshots()->create(array_replace([
            'observed_at' => $this->now->subMinutes($min),
            'price_usd' => 0.01,
            'market_cap' => 1_000_000.0,
            'fdv' => 1_000_000.0,
            'liquidity_usd' => 120_000.0,
            'volume_h24' => 100_000.0,
            'price_change_h24' => null,
            'txns_h24' => 400,
            'buys_h24' => 260,
            'sells_h24' => 140,
            'primary_pair_address' => 'pair'.Str::random(6),
            'primary_dex_id' => 'raydium',
            'earliest_pair_created_at' => $this->now->subDays(4),
        ], $attrs));
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function pumpEvent(Token $token, array $overrides = []): PumpEvent
    {
        /** @var PumpEvent $event */
        $event = $token->pumpEvents()->create(array_replace([
            'started_at' => $this->now->subMinutes(40),
            'peak_at' => $this->now->subMinutes(10),
            'ended_at' => null,
            'start_market_cap' => 1_000_000.0,
            'peak_market_cap' => 2_000_000.0,
            'start_price_usd' => 0.010,
            'peak_price_usd' => 0.020,
            'market_cap_change_pct' => 100.0,
            'price_change_pct' => 100.0,
            'volume_h24_change_ratio' => 3.0,
            'txns_h24_change_ratio' => 2.5,
            'duration_minutes' => 30,
            'detection_score' => 80,
            'confidence' => PumpEvent::CONFIDENCE_HIGH,
            'status' => PumpEvent::STATUS_ACTIVE,
        ], $overrides));

        return $event;
    }

    private function fakeGdelt(array $articles): void
    {
        Http::fake([
            'api.gdeltproject.org/*' => Http::response(['articles' => $articles], 200),
        ]);
    }

    private function service(): EvidenceCollectionService
    {
        return app(EvidenceCollectionService::class);
    }

    // ---- market collector -------------------------------------------------

    #[Test]
    public function market_evidence_is_created_from_existing_snapshots_without_any_http(): void
    {
        Http::fake();
        $token = $this->token();
        $this->snapshot($token, 45, ['market_cap' => 1_000_000.0, 'price_usd' => 0.01]);
        $this->snapshot($token, 25, ['market_cap' => 1_500_000.0, 'price_usd' => 0.015]);
        $this->snapshot($token, 8, ['market_cap' => 2_000_000.0, 'price_usd' => 0.02]);
        $event = $this->pumpEvent($token);

        // The market collector, in isolation, makes no external request.
        $candidates = app(MarketEvidenceCollector::class)->collect($event, $token, EvidenceWindow::for($event));
        Http::assertNothingSent();
        $this->assertNotEmpty($candidates);
        $this->assertFalse(app(MarketEvidenceCollector::class)->isExternal());

        $this->service()->collect();

        $market = Evidence::query()->where('category', Evidence::CATEGORY_MARKET)->get();
        $this->assertGreaterThanOrEqual(1, $market->count());
        $this->assertTrue($market->every(fn (Evidence $e) => $e->pump_event_id === $event->id));
        $this->assertTrue($market->contains(fn (Evidence $e) => $e->source === 'internal'));
    }

    #[Test]
    public function market_evidence_never_asserts_causality(): void
    {
        Http::fake();
        $token = $this->token();
        $this->snapshot($token, 45);
        $this->snapshot($token, 25);
        $this->snapshot($token, 8);
        $this->pumpEvent($token);

        $this->service()->collect();

        foreach (Evidence::query()->get() as $evidence) {
            $this->assertStringNotContainsStringIgnoringCase('caused', $evidence->summary);
            $this->assertStringNotContainsStringIgnoringCase('because of', $evidence->summary);
        }
    }

    // ---- related-token collector ----------------------------------------

    #[Test]
    public function related_token_evidence_detects_a_preceding_tracked_token_move(): void
    {
        Http::fake();
        $target = $this->token(['symbol' => 'MANLET', 'name' => 'Manlet']);
        $this->snapshot($target, 45);
        $this->snapshot($target, 25);
        $this->snapshot($target, 8);

        $mover = $this->token(['symbol' => 'ANSEM', 'name' => 'Ansem', 'token_address' => 'AddrANSEM'.Str::random(10)]);
        // +80% in the 30 minutes before the target event's start (started_at = now-40)
        $this->snapshot($mover, 70, ['market_cap' => 1_000_000.0]);
        $this->snapshot($mover, 45, ['market_cap' => 1_800_000.0]);

        $event = $this->pumpEvent($target);

        // The related-token collector, in isolation, makes no external request.
        app(RelatedTokenEvidenceCollector::class)->collect($event, $target, EvidenceWindow::for($event));
        Http::assertNothingSent();
        $this->assertFalse(app(RelatedTokenEvidenceCollector::class)->isExternal());

        $this->service()->collect();

        $related = Evidence::query()->where('category', Evidence::CATEGORY_RELATED_TOKEN)->get();
        $this->assertCount(1, $related);
        $this->assertStringContainsString('Ansem', $related->first()->summary);
        $this->assertStringContainsStringIgnoringCase('before this event', $related->first()->summary);
    }

    #[Test]
    public function related_token_evidence_never_claims_it_caused_the_pump_and_is_never_high_confidence(): void
    {
        Http::fake();
        $target = $this->token();
        $this->snapshot($target, 45);
        $this->snapshot($target, 25);
        $this->snapshot($target, 8);

        $mover = $this->token(['symbol' => 'ANSEM', 'name' => 'Ansem', 'token_address' => 'AddrANSEM'.Str::random(10)]);
        $this->snapshot($mover, 70, ['market_cap' => 1_000_000.0]);
        $this->snapshot($mover, 45, ['market_cap' => 3_000_000.0]);

        $this->pumpEvent($target);
        $this->service()->collect();

        $related = Evidence::query()->where('category', Evidence::CATEGORY_RELATED_TOKEN)->firstOrFail();
        $this->assertNotSame(Evidence::CONFIDENCE_HIGH, $related->confidence);
        $this->assertStringContainsStringIgnoringCase('does not indicate causation', $related->summary);
        $this->assertStringNotContainsStringIgnoringCase('caused', $related->summary);
    }

    #[Test]
    public function related_token_ignores_moves_that_are_too_small(): void
    {
        Http::fake();
        $target = $this->token();
        $this->snapshot($target, 45);
        $this->snapshot($target, 25);
        $this->snapshot($target, 8);

        $flat = $this->token(['symbol' => 'FLAT', 'name' => 'Flatline', 'token_address' => 'AddrFLAT'.Str::random(10)]);
        $this->snapshot($flat, 70, ['market_cap' => 1_000_000.0]);
        $this->snapshot($flat, 45, ['market_cap' => 1_050_000.0]); // +5%

        $this->pumpEvent($target);
        $this->service()->collect();

        $this->assertSame(0, Evidence::query()->where('category', Evidence::CATEGORY_RELATED_TOKEN)->count());
    }

    #[Test]
    public function related_token_stays_on_the_same_chain_by_default(): void
    {
        Http::fake();
        $target = $this->token(['chain_id' => 'solana']);
        $this->snapshot($target, 45);
        $this->snapshot($target, 25);
        $this->snapshot($target, 8);

        $otherChain = $this->token([
            'chain_id' => 'base',
            'symbol' => 'ANSEM',
            'name' => 'Ansem',
            'token_address' => 'AddrBASE'.Str::random(10),
        ]);
        $this->snapshot($otherChain, 70, ['market_cap' => 1_000_000.0]);
        $this->snapshot($otherChain, 45, ['market_cap' => 2_000_000.0]);

        $this->pumpEvent($target);
        $this->service()->collect();

        $this->assertSame(0, Evidence::query()->where('category', Evidence::CATEGORY_RELATED_TOKEN)->count());
    }

    // ---- news collector -------------------------------------------------

    #[Test]
    public function news_evidence_records_a_matching_article_inside_the_window_as_a_neutral_fact(): void
    {
        $this->fakeGdelt([
            [
                'url' => 'https://coindesk.com/manlet-rally',
                'title' => 'Manlet token jumps as traders pile in',
                'seendate' => $this->now->subMinutes(20)->format('Ymd\THis\Z'),
                'domain' => 'coindesk.com',
                'language' => 'English',
            ],
        ]);

        $token = $this->token(['name' => 'Manlet', 'symbol' => 'MANLET']);
        $this->snapshot($token, 45);
        $this->snapshot($token, 25);
        $this->snapshot($token, 8);
        $this->pumpEvent($token);

        $this->service()->collect();

        $news = Evidence::query()->where('category', Evidence::CATEGORY_NEWS)->firstOrFail();
        $this->assertSame('gdelt', $news->source);
        $this->assertSame('https://coindesk.com/manlet-rally', $news->source_url);
        $this->assertNotNull($news->published_at);
        $this->assertStringContainsString('before the observed pump peak', $news->summary);
        $this->assertStringNotContainsStringIgnoringCase('caused', $news->summary);
    }

    #[Test]
    public function news_articles_published_outside_the_window_are_ignored(): void
    {
        $this->fakeGdelt([
            [
                'url' => 'https://coindesk.com/old',
                'title' => 'Manlet token launches',
                'seendate' => $this->now->subHours(9)->format('Ymd\THis\Z'),
                'domain' => 'coindesk.com',
                'language' => 'English',
            ],
        ]);

        $token = $this->token(['name' => 'Manlet']);
        $this->snapshot($token, 45);
        $this->snapshot($token, 25);
        $this->snapshot($token, 8);
        $this->pumpEvent($token);

        $this->service()->collect();

        $this->assertSame(0, Evidence::query()->where('category', Evidence::CATEGORY_NEWS)->count());
    }

    #[Test]
    public function news_articles_that_only_collide_on_a_ticker_are_dropped(): void
    {
        $this->fakeGdelt([
            [
                'url' => 'https://example.com/unrelated',
                'title' => 'Solana meme coins broadly rally this week',
                'seendate' => $this->now->subMinutes(15)->format('Ymd\THis\Z'),
                'domain' => 'example.com',
                'language' => 'English',
            ],
        ]);

        $token = $this->token(['name' => 'Popcat', 'symbol' => 'POPCAT']);
        $this->snapshot($token, 45);
        $this->snapshot($token, 25);
        $this->snapshot($token, 8);
        $this->pumpEvent($token);

        $this->service()->collect();

        $this->assertSame(0, Evidence::query()->where('category', Evidence::CATEGORY_NEWS)->count());
    }

    #[Test]
    public function news_provider_failure_does_not_fail_the_run_and_records_no_news(): void
    {
        Http::fake([
            'api.gdeltproject.org/*' => Http::response('gateway timeout', 504),
        ]);

        $token = $this->token(['name' => 'Manlet']);
        $this->snapshot($token, 45);
        $this->snapshot($token, 25);
        $this->snapshot($token, 8);
        $this->pumpEvent($token);

        $result = $this->service()->collect();

        $this->assertSame(0, Evidence::query()->where('category', Evidence::CATEGORY_NEWS)->count());
        $this->assertGreaterThanOrEqual(1, $result->providerFailures);
        // Other collectors still ran.
        $this->assertGreaterThanOrEqual(1, Evidence::query()->where('category', Evidence::CATEGORY_MARKET)->count());
    }

    #[Test]
    public function news_requests_are_bounded_by_the_per_run_budget(): void
    {
        config()->set('evidence.news.max_requests_per_run', 2);
        $this->fakeGdelt([]);

        foreach (range(1, 5) as $i) {
            $token = $this->token([
                'name' => "Newsy{$i}",
                'symbol' => "NEWSY{$i}",
                'token_address' => "AddrN{$i}".Str::random(10),
            ]);
            $this->snapshot($token, 45);
            $this->snapshot($token, 25);
            $this->snapshot($token, 8);
            $this->pumpEvent($token, [
                'started_at' => $this->now->subMinutes(40 + $i),
                'peak_at' => $this->now->subMinutes(10 + $i),
            ]);
        }

        $this->service()->collect();

        Http::assertSentCount(2);
    }

    #[Test]
    public function news_collector_is_skipped_entirely_when_disabled(): void
    {
        config()->set('evidence.news.enabled', false);
        Http::fake();

        $token = $this->token(['name' => 'Manlet']);
        $this->snapshot($token, 45);
        $this->snapshot($token, 25);
        $this->snapshot($token, 8);
        $this->pumpEvent($token);

        $this->service()->collect();

        Http::assertNothingSent();
    }

    // ---- token-metadata collector --------------------------------------

    #[Test]
    public function token_metadata_evidence_reports_linked_resources_without_inferring_intent(): void
    {
        Http::fake();
        $token = $this->token([
            'website_url' => 'https://manlet.fun',
            'twitter_url' => 'https://x.com/manletcoin',
            'metadata_updated_at' => $this->now->subMinutes(5),
        ]);
        $this->snapshot($token, 45);
        $this->snapshot($token, 25);
        $this->snapshot($token, 8);
        $this->pumpEvent($token);

        $this->service()->collect();

        $origin = Evidence::query()->where('category', Evidence::CATEGORY_ORIGIN)->get();
        $this->assertGreaterThanOrEqual(1, $origin->count());
        foreach ($origin as $evidence) {
            $this->assertStringContainsStringIgnoringCase('metadata lists', $evidence->summary);
            $this->assertStringNotContainsStringIgnoringCase('created to', $evidence->summary);
        }
    }

    // ---- relevance + confidence ---------------------------------------

    #[Test]
    public function every_relevance_score_is_within_zero_to_one_hundred(): void
    {
        $this->fakeGdelt([
            [
                'url' => 'https://coindesk.com/x',
                'title' => 'Manlet surges',
                'seendate' => $this->now->subMinutes(12)->format('Ymd\THis\Z'),
                'domain' => 'coindesk.com',
                'language' => 'English',
            ],
        ]);

        $token = $this->token(['name' => 'Manlet']);
        $mover = $this->token(['symbol' => 'ANSEM', 'name' => 'Ansem', 'token_address' => 'AddrA'.Str::random(10)]);
        $this->snapshot($mover, 70, ['market_cap' => 1_000_000.0]);
        $this->snapshot($mover, 45, ['market_cap' => 2_500_000.0]);
        $this->snapshot($token, 45);
        $this->snapshot($token, 25);
        $this->snapshot($token, 8);
        $this->pumpEvent($token);

        $this->service()->collect();

        $this->assertGreaterThan(0, Evidence::query()->count());
        foreach (Evidence::query()->get() as $evidence) {
            $this->assertGreaterThanOrEqual(0, $evidence->relevance_score);
            $this->assertLessThanOrEqual(100, $evidence->relevance_score);
            $this->assertContains($evidence->confidence, [
                Evidence::CONFIDENCE_LOW,
                Evidence::CONFIDENCE_MEDIUM,
                Evidence::CONFIDENCE_HIGH,
            ]);
        }
    }

    #[Test]
    public function scoring_is_deterministic_across_repeated_runs(): void
    {
        Http::fake();
        $token = $this->token();
        $this->snapshot($token, 45, ['market_cap' => 1_000_000.0]);
        $this->snapshot($token, 25, ['market_cap' => 1_500_000.0]);
        $this->snapshot($token, 8, ['market_cap' => 2_000_000.0]);
        $this->pumpEvent($token);

        $this->service()->collect();
        $first = Evidence::query()->orderBy('dedupe_hash')->pluck('relevance_score', 'dedupe_hash')->all();

        $this->service()->collect(force: true);
        $second = Evidence::query()->orderBy('dedupe_hash')->pluck('relevance_score', 'dedupe_hash')->all();

        $this->assertSame($first, $second);
    }

    // ---- persistence + idempotency ----------------------------------

    #[Test]
    public function duplicate_evidence_is_not_created_on_a_forced_re_run(): void
    {
        $this->fakeGdelt([
            [
                'url' => 'https://coindesk.com/x',
                'title' => 'Manlet surges',
                'seendate' => $this->now->subMinutes(12)->format('Ymd\THis\Z'),
                'domain' => 'coindesk.com',
                'language' => 'English',
            ],
        ]);

        $token = $this->token(['name' => 'Manlet']);
        $this->snapshot($token, 45);
        $this->snapshot($token, 25);
        $this->snapshot($token, 8);
        $this->pumpEvent($token);

        $this->service()->collect();
        $countAfterFirst = Evidence::query()->count();
        $this->assertGreaterThan(0, $countAfterFirst);

        $this->service()->collect(force: true);

        $this->assertSame($countAfterFirst, Evidence::query()->count());
    }

    #[Test]
    public function a_second_run_within_the_cooldown_skips_the_event(): void
    {
        $this->fakeGdelt([]);
        $token = $this->token();
        $this->snapshot($token, 45);
        $this->snapshot($token, 25);
        $this->snapshot($token, 8);
        $this->pumpEvent($token);

        $first = $this->service()->collect();
        $this->assertSame(1, $first->eventsAnalyzed);

        CarbonImmutable::setTestNow($this->now->addMinutes(30));
        $second = $this->service()->collect();

        $this->assertSame(0, $second->eventsAnalyzed);
        $this->assertSame(1, $second->eventsSkippedByCooldown);
    }

    #[Test]
    public function force_re_investigates_an_event_still_inside_the_cooldown(): void
    {
        $this->fakeGdelt([]);
        $token = $this->token();
        $this->snapshot($token, 45);
        $this->snapshot($token, 25);
        $this->snapshot($token, 8);
        $this->pumpEvent($token);

        $this->service()->collect();

        CarbonImmutable::setTestNow($this->now->addMinutes(30));
        $forced = $this->service()->collect(force: true);

        $this->assertSame(1, $forced->eventsAnalyzed);
    }

    #[Test]
    public function events_older_than_the_recent_window_are_not_investigated(): void
    {
        $this->fakeGdelt([]);
        $token = $this->token();
        $this->snapshot($token, 45);
        $this->snapshot($token, 25);
        $this->snapshot($token, 8);
        $this->pumpEvent($token, [
            'started_at' => $this->now->subHours(80),
            'peak_at' => $this->now->subHours(79),
        ]);

        $result = $this->service()->collect();

        $this->assertSame(0, $result->eventsAnalyzed);
        $this->assertSame(0, Evidence::query()->count());
    }

    #[Test]
    public function evidence_belongs_to_the_correct_pump_event_and_token(): void
    {
        Http::fake();
        $tokenA = $this->token(['symbol' => 'AAA', 'name' => 'Alpha', 'token_address' => 'AddrA'.Str::random(10)]);
        $tokenB = $this->token(['symbol' => 'BBB', 'name' => 'Beta', 'token_address' => 'AddrB'.Str::random(10)]);
        foreach ([$tokenA, $tokenB] as $t) {
            $this->snapshot($t, 45);
            $this->snapshot($t, 25);
            $this->snapshot($t, 8);
        }
        $eventA = $this->pumpEvent($tokenA);
        $eventB = $this->pumpEvent($tokenB);

        $this->service()->collect();

        foreach (Evidence::query()->where('pump_event_id', $eventA->id)->get() as $evidence) {
            $this->assertSame($tokenA->id, $evidence->token_id);
        }
        foreach (Evidence::query()->where('pump_event_id', $eventB->id)->get() as $evidence) {
            $this->assertSame($tokenB->id, $evidence->token_id);
        }
    }

    // ---- command --------------------------------------------------------

    #[Test]
    public function the_command_collects_evidence_and_prints_a_summary(): void
    {
        $this->fakeGdelt([]);
        $token = $this->token();
        $this->snapshot($token, 45);
        $this->snapshot($token, 25);
        $this->snapshot($token, 8);
        $this->pumpEvent($token);

        $this->artisan('memecoins:collect-evidence')
            ->expectsOutputToContain('Evidence collection completed.')
            ->expectsOutputToContain('Pump events analyzed:')
            ->assertExitCode(0);

        $this->assertGreaterThan(0, Evidence::query()->count());
    }

    #[Test]
    public function the_command_skips_fresh_events_but_force_rechecks_them(): void
    {
        $this->fakeGdelt([]);
        $token = $this->token();
        $this->snapshot($token, 45);
        $this->snapshot($token, 25);
        $this->snapshot($token, 8);
        $this->pumpEvent($token);

        $this->artisan('memecoins:collect-evidence')->assertExitCode(0);

        CarbonImmutable::setTestNow($this->now->addMinutes(20));
        $this->artisan('memecoins:collect-evidence')
            ->expectsOutputToContain('Pump events analyzed:       0')
            ->assertExitCode(0);

        $this->artisan('memecoins:collect-evidence --force')
            ->expectsOutputToContain('Pump events analyzed:       1')
            ->assertExitCode(0);
    }

    // ---- isolation from other engines ---------------------------------

    #[Test]
    public function collection_does_not_touch_observed_peak_or_pump_event_metrics(): void
    {
        $this->fakeGdelt([
            [
                'url' => 'https://coindesk.com/x',
                'title' => 'Manlet surges',
                'seendate' => $this->now->subMinutes(12)->format('Ymd\THis\Z'),
                'domain' => 'coindesk.com',
                'language' => 'English',
            ],
        ]);

        $token = $this->token(['name' => 'Manlet', 'observed_peak_market_cap' => 8_000_000.0]);
        $this->snapshot($token, 45);
        $this->snapshot($token, 25);
        $this->snapshot($token, 8);
        $event = $this->pumpEvent($token);

        $peakBefore = $token->observed_peak_market_cap;
        $eventBefore = $event->only(['start_market_cap', 'peak_market_cap', 'detection_score', 'confidence']);

        $this->service()->collect();

        $this->assertSame($peakBefore, $token->fresh()->observed_peak_market_cap);
        $this->assertSame($eventBefore, $event->fresh()->only(['start_market_cap', 'peak_market_cap', 'detection_score', 'confidence']));
    }
}
