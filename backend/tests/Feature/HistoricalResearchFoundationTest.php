<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MonthlyRanking;
use App\Models\MonthlyRankingEvidence;
use App\Services\Historical\Research\HistoricalConfidence;
use App\Services\Historical\Research\HistoricalConfidenceCalculator;
use App\Services\Historical\Research\HistoricalConfidenceSignals;
use App\Services\Historical\Research\HistoricalMetric;
use App\Services\Historical\Research\HistoricalMetricResult;
use App\Services\Historical\Research\HistoricalResearchProvider;
use App\Services\Historical\Research\HistoricalResearchRequest;
use App\Services\Historical\Research\MetricBasis;
use App\Services\Historical\Research\SourceCredibility;
use App\Services\Ranking\MonthWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Step 26, Phase 1 — the typed historical-research foundation.
 *
 * Only the capability model + typed evidence + deterministic confidence + the
 * `monthly_ranking_evidence` child table. NO ranking behaviour, NO providers,
 * NO command, NO frontend.
 */
class HistoricalResearchFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function request(): HistoricalResearchRequest
    {
        return new HistoricalResearchRequest(
            chainId: 'solana',
            tokenAddress: 'So1111111111111111111111111111111111111111',
            window: MonthWindow::of(2026, 1),
            symbol: 'ANSEM',
        );
    }

    /** A capability-declaring provider: volume + market cap + identity, NOT holders. */
    private function provider(): HistoricalResearchProvider
    {
        return new class implements HistoricalResearchProvider
        {
            public function name(): string
            {
                return 'fake_history';
            }

            public function supportsMetric(HistoricalMetric $metric): bool
            {
                return in_array($metric, [
                    HistoricalMetric::Volume,
                    HistoricalMetric::MarketCap,
                    HistoricalMetric::Identity,
                    HistoricalMetric::Ohlcv,
                ], true);
            }

            public function searchToken(HistoricalResearchRequest $request): HistoricalMetricResult
            {
                return HistoricalMetricResult::resolved(
                    metric: HistoricalMetric::Identity,
                    value: null,
                    sourceName: 'CoinGecko',
                    sourceUrl: 'https://www.coingecko.com/en/coins/ansem',
                    observedAt: CarbonImmutable::parse('2026-01-15T00:00:00Z'),
                    methodology: 'contract lookup by chain + address',
                    basis: MetricBasis::Observed,
                    sourceCredibility: SourceCredibility::HistoricalProvider,
                    identityVerified: true,
                    metadata: ['chain_id' => 'solana', 'token_address' => $request->tokenAddress, 'symbol' => 'ANSEM'],
                );
            }

            public function getHistoricalMetric(HistoricalMetric $metric, HistoricalResearchRequest $request): HistoricalMetricResult
            {
                if (! $this->supportsMetric($metric)) {
                    return HistoricalMetricResult::unavailable($metric, 'not supported by fake_history');
                }

                return match ($metric) {
                    HistoricalMetric::MarketCap => HistoricalMetricResult::resolved(
                        metric: HistoricalMetric::MarketCap,
                        value: 55_000_000.0,
                        sourceName: 'CoinGecko',
                        sourceUrl: 'https://www.coingecko.com/en/coins/ansem',
                        observedAt: CarbonImmutable::parse('2026-01-20T00:00:00Z'),
                        methodology: 'max market_caps[] point in month',
                        basis: MetricBasis::Observed,
                        sourceCredibility: SourceCredibility::HistoricalProvider,
                        identityVerified: true,
                    ),
                    HistoricalMetric::Volume => HistoricalMetricResult::resolved(
                        metric: HistoricalMetric::Volume,
                        value: 180_000_000.0,
                        sourceName: 'CoinGecko',
                        sourceUrl: 'https://www.coingecko.com/en/coins/ansem',
                        observedAt: CarbonImmutable::parse('2026-01-31T00:00:00Z'),
                        methodology: 'sum of daily total_volumes[] in month',
                        basis: MetricBasis::Reconstructed,
                        sourceCredibility: SourceCredibility::HistoricalProvider,
                        identityVerified: true,
                        limitations: 'daily granularity',
                    ),
                    default => HistoricalMetricResult::unavailable($metric),
                };
            }

            public function getTokenMetadata(HistoricalResearchRequest $request): HistoricalMetricResult
            {
                return $this->searchToken($request);
            }

            public function getHistoricalOhlcv(HistoricalResearchRequest $request): HistoricalMetricResult
            {
                return HistoricalMetricResult::resolved(
                    metric: HistoricalMetric::Ohlcv,
                    value: null,
                    sourceName: 'GeckoTerminal',
                    sourceUrl: 'https://www.geckoterminal.com/solana/pools/xyz',
                    observedAt: null,
                    methodology: 'daily OHLCV candles for the window',
                    basis: MetricBasis::Observed,
                    sourceCredibility: SourceCredibility::PrimaryMarketData,
                    identityVerified: true,
                    metadata: ['granularity' => 'day', 'candle_count' => 31, 'peak_price' => 0.0123],
                );
            }
        };
    }

    #[Test]
    public function a_provider_reports_which_metrics_it_supports(): void
    {
        $provider = $this->provider();

        $this->assertTrue($provider->supportsMetric(HistoricalMetric::Volume));
        $this->assertTrue($provider->supportsMetric(HistoricalMetric::MarketCap));
        $this->assertTrue($provider->supportsMetric(HistoricalMetric::Identity));
    }

    #[Test]
    public function an_unsupported_metric_reports_false_and_returns_an_unavailable_result(): void
    {
        $provider = $this->provider();

        $this->assertFalse($provider->supportsMetric(HistoricalMetric::Holders));
        $this->assertFalse($provider->supportsMetric(HistoricalMetric::PoolDate));

        $result = $provider->getHistoricalMetric(HistoricalMetric::Holders, $this->request());

        $this->assertFalse($result->available);
        $this->assertNull($result->value);
        $this->assertNull($result->scalarValue());
        $this->assertSame(MetricBasis::None, $result->basis);
        $this->assertSame(HistoricalConfidence::Unknown, $result->confidence);
        $this->assertSame('not supported by fake_history', $result->limitations);
    }

    #[Test]
    public function a_metric_result_preserves_source_metadata(): void
    {
        $result = $this->provider()->getHistoricalMetric(HistoricalMetric::MarketCap, $this->request());

        $this->assertTrue($result->available);
        $this->assertSame(55_000_000.0, $result->value);
        $this->assertSame(55_000_000.0, $result->scalarValue());
        $this->assertSame('CoinGecko', $result->sourceName);
        $this->assertSame('https://www.coingecko.com/en/coins/ansem', $result->sourceUrl);
        $this->assertNotNull($result->observedAt);
        $this->assertSame('2026-01-20', $result->observedAt->toDateString());
        $this->assertSame('max market_caps[] point in month', $result->methodology);
    }

    #[Test]
    public function an_estimate_is_never_indistinguishable_from_an_observed_value(): void
    {
        $observed = HistoricalMetricResult::resolved(
            metric: HistoricalMetric::MarketCap,
            value: 55_000_000.0,
            sourceName: 'CoinGecko', sourceUrl: null,
            observedAt: CarbonImmutable::parse('2026-01-20T00:00:00Z'),
            methodology: 'market_caps[] point',
            basis: MetricBasis::Observed,
            sourceCredibility: SourceCredibility::HistoricalProvider,
            identityVerified: true,
        );
        $estimate = HistoricalMetricResult::resolved(
            metric: HistoricalMetric::MarketCap,
            value: 90_000_000.0,
            sourceName: 'GeckoTerminal', sourceUrl: null,
            observedAt: CarbonImmutable::parse('2026-01-20T00:00:00Z'),
            methodology: 'peak price x total supply',
            basis: MetricBasis::Estimate,
            sourceCredibility: SourceCredibility::PrimaryMarketData,
            identityVerified: true,
        );

        $this->assertTrue($observed->isObserved());
        $this->assertFalse($observed->isEstimate());
        $this->assertTrue($estimate->isEstimate());
        $this->assertFalse($estimate->isObserved());

        // An estimate is capped at LOW confidence no matter how strong the source.
        $this->assertSame(HistoricalConfidence::Low, $estimate->confidence);
        $this->assertSame('estimate', $estimate->toEvidenceAttributes()['basis']);
        $this->assertSame('observed', $observed->toEvidenceAttributes()['basis']);
    }

    #[Test]
    public function confidence_is_derived_deterministically_from_evidence_characteristics(): void
    {
        $calc = new HistoricalConfidenceCalculator;

        $strong = $calc->evaluate(new HistoricalConfidenceSignals(
            metricAvailable: true,
            sourceCredibility: SourceCredibility::HistoricalProvider,
            basis: MetricBasis::Observed,
            hasObservedTimestamp: true,
            identityVerified: true,
        ));
        $this->assertSame(HistoricalConfidence::High, $strong);

        // No timestamp AND unverified identity ⇒ two downgrades from High ⇒ Low.
        $weak = $calc->evaluate(new HistoricalConfidenceSignals(
            metricAvailable: true,
            sourceCredibility: SourceCredibility::HistoricalProvider,
            basis: MetricBasis::Observed,
            hasObservedTimestamp: false,
            identityVerified: false,
        ));
        $this->assertSame(HistoricalConfidence::Low, $weak);

        // Reconstructed ⇒ one downgrade.
        $reconstructed = $calc->evaluate(new HistoricalConfidenceSignals(
            metricAvailable: true,
            sourceCredibility: SourceCredibility::HistoricalProvider,
            basis: MetricBasis::Reconstructed,
            hasObservedTimestamp: true,
            identityVerified: true,
        ));
        $this->assertSame(HistoricalConfidence::Medium, $reconstructed);

        // Unavailable ⇒ always Unknown.
        $this->assertSame(HistoricalConfidence::Unknown, $calc->evaluate(new HistoricalConfidenceSignals(
            metricAvailable: false,
            sourceCredibility: SourceCredibility::PrimaryMarketData,
            basis: MetricBasis::None,
            hasObservedTimestamp: true,
            identityVerified: true,
        )));

        // Deterministic — identical inputs, identical output.
        $again = $calc->evaluate(new HistoricalConfidenceSignals(
            metricAvailable: true,
            sourceCredibility: SourceCredibility::HistoricalProvider,
            basis: MetricBasis::Observed,
            hasObservedTimestamp: true,
            identityVerified: true,
        ));
        $this->assertSame($strong, $again);
    }

    #[Test]
    public function every_confidence_band_is_a_valid_enum_value(): void
    {
        foreach ([MetricBasis::Observed, MetricBasis::Reconstructed, MetricBasis::Estimate, MetricBasis::None] as $basis) {
            foreach (SourceCredibility::cases() as $credibility) {
                $band = (new HistoricalConfidenceCalculator)->evaluate(new HistoricalConfidenceSignals(
                    metricAvailable: $basis !== MetricBasis::None,
                    sourceCredibility: $credibility,
                    basis: $basis,
                    hasObservedTimestamp: false,
                    identityVerified: false,
                ));
                $this->assertContains($band, HistoricalConfidence::cases());
            }
        }
    }

    #[Test]
    public function a_monthly_ranking_has_a_read_only_evidence_relation(): void
    {
        $ranking = MonthlyRanking::create([
            'year' => 2026, 'month' => 1, 'chain_bucket' => 'solana', 'rank' => 1,
            'status' => MonthlyRanking::STATUS_FINALIZED,
        ]);

        $result = $this->provider()->getHistoricalMetric(HistoricalMetric::MarketCap, $this->request());
        $attrs = $result->toEvidenceAttributes();

        $ranking->evidence()->create([
            ...$attrs,
            'dedupe_hash' => MonthlyRankingEvidence::dedupeHash('market_cap', $attrs['source_name'], $attrs['source_url']),
        ]);

        $ranking->refresh()->load('evidence');
        $this->assertCount(1, $ranking->evidence);
        $this->assertSame('market_cap', $ranking->evidence->first()->metric);
        $this->assertSame(55_000_000.0, $ranking->evidence->first()->value_numeric);
        $this->assertSame('observed', $ranking->evidence->first()->basis);
    }

    #[Test]
    public function duplicate_evidence_for_the_same_ranking_metric_and_source_is_prevented(): void
    {
        $ranking = MonthlyRanking::create([
            'year' => 2026, 'month' => 2, 'chain_bucket' => 'bsc', 'rank' => 1,
            'status' => MonthlyRanking::STATUS_FINALIZED,
        ]);

        $row = [
            'monthly_ranking_id' => $ranking->id,
            'metric' => 'volume',
            'source_name' => 'CoinGecko',
            'source_url' => 'https://www.coingecko.com/en/coins/foo',
            'value_numeric' => 12_000_000.0,
            'basis' => 'reconstructed',
            'confidence' => 'medium',
            'dedupe_hash' => MonthlyRankingEvidence::dedupeHash('volume', 'CoinGecko', 'https://www.coingecko.com/en/coins/foo'),
        ];

        MonthlyRankingEvidence::create($row);

        $this->expectException(QueryException::class);
        MonthlyRankingEvidence::create([...$row, 'value_numeric' => 99_000_000.0]);
    }

    #[Test]
    public function re_research_upserts_one_row_per_ranking_metric_and_source(): void
    {
        $ranking = MonthlyRanking::create([
            'year' => 2026, 'month' => 3, 'chain_bucket' => 'base', 'rank' => 2,
            'status' => MonthlyRanking::STATUS_FINALIZED,
        ]);

        $hash = MonthlyRankingEvidence::dedupeHash('holders', 'Etherscan', 'https://etherscan.io/token/0xabc');

        foreach ([18_000.0, 21_500.0] as $value) {
            MonthlyRankingEvidence::updateOrCreate(
                ['monthly_ranking_id' => $ranking->id, 'dedupe_hash' => $hash],
                [
                    'metric' => 'holders', 'source_name' => 'Etherscan',
                    'source_url' => 'https://etherscan.io/token/0xabc',
                    'value_numeric' => $value, 'basis' => 'observed', 'confidence' => 'high',
                ],
            );
        }

        $this->assertSame(1, $ranking->evidence()->count());
        $this->assertSame(21_500.0, $ranking->evidence()->first()->value_numeric);
    }

    #[Test]
    public function an_unavailable_metric_never_yields_a_scalar_for_scoring(): void
    {
        $unavailable = HistoricalMetricResult::unavailable(HistoricalMetric::Holders, 'no free API time series');

        $this->assertNull($unavailable->scalarValue());
        $this->assertNull($unavailable->toEvidenceAttributes()['value_numeric']);
        $this->assertSame('holders', $unavailable->toEvidenceAttributes()['metric']);
    }
}
