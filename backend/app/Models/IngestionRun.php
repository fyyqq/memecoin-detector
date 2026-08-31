<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One execution of the discovery / persistence pipeline, whether triggered by
 * the HTTP endpoint (`trigger = manual`) or the scheduler (`trigger = scheduled`).
 *
 * Lightweight observability only — it does not drive any behaviour.
 */
class IngestionRun extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_SCHEDULED = 'scheduled';

    protected $fillable = [
        'started_at',
        'completed_at',
        'status',
        'trigger',
        'raw_candidates',
        'unique_candidates',
        'enriched_candidates',
        'age_eligible',
        'snapshots_written',
        'new_tokens',
        'peak_updated',
        'qualified',
        'error_message',
        // Step 14 coverage metrics.
        'selected_for_enrichment',
        'candidate_cap_dropped',
        'search_terms_used',
        'search_terms_with_results',
        'chains_discovered',
        // Step 19 trending-meta coverage metrics.
        'trending_meta_count',
        'trending_meta_pairs_seen',
        'trending_meta_unique_candidates',
        'pre_filtered_candidates',
        'discovery_source_counts',
        'trending_meta_slugs_used',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'chains_discovered' => 'array',
            'discovery_source_counts' => 'array',
            'trending_meta_slugs_used' => 'array',
        ];
    }
}
