<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\PumpExplanation;

/**
 * Turns a stored {@see PumpExplanation} into UI-ready content.
 *
 * The prose is DERIVED from the structured result — never hardcoded per token.
 * It deliberately says "Most supported explanation", not "Confirmed reason",
 * and for UNKNOWN it says a catalyst was not established (not "we don't know").
 */
class PumpExplanationPresenter
{
    private const CATALYST_LABELS = [
        'OFFICIAL_ANNOUNCEMENT' => 'Official announcement',
        'CELEBRITY_INFLUENCER' => 'Celebrity / influencer activity',
        'NARRATIVE_ROTATION' => 'Narrative rotation',
        'EXCHANGE_LISTING' => 'Exchange listing',
        'COMMUNITY_TAKEOVER' => 'Community takeover',
        'AIRDROP_BUYBACK' => 'Airdrop / buyback',
        'WHALE_ACTIVITY' => 'Whale activity',
        'RELATED_TOKEN_SPILLOVER' => 'Related-token spillover',
        'LIQUIDITY_EVENT' => 'Liquidity event',
        'MARKET_ACTIVITY' => 'Market activity only',
        'UNKNOWN' => 'Not established',
    ];

    private const CONFIDENCE_LABELS = [
        'high' => 'High',
        'medium' => 'Medium',
        'low' => 'Low',
    ];

    /**
     * @return array<string,mixed>|null null when there is no completed explanation to present
     */
    public function present(PumpExplanation $explanation): ?array
    {
        if ($explanation->status !== PumpExplanation::STATUS_COMPLETED || ! is_array($explanation->explanation_json)) {
            return null;
        }

        $json = $explanation->explanation_json;
        $catalyst = (string) ($json['primary_catalyst'] ?? 'UNKNOWN');
        $isUnknown = $catalyst === 'UNKNOWN';

        return [
            'question' => 'Why did this coin pump?',
            'headline' => $isUnknown
                ? 'No verified catalyst was established from the available evidence.'
                : 'Most supported explanation: '.$this->catalystLabel($catalyst),
            'catalyst' => $catalyst,
            'catalyst_label' => $this->catalystLabel($catalyst),
            'summary' => (string) ($json['summary'] ?? ''),
            'evidence_lines' => array_values(array_map(
                static fn (array $e): array => [
                    'statement' => (string) ($e['statement'] ?? ''),
                    'evidence_ids' => isset($e['evidence_id']) ? [(int) $e['evidence_id']] : [],
                ],
                array_filter((array) ($json['evidence'] ?? []), 'is_array'),
            )),
            'secondary_signals' => array_map(
                fn (array $s): array => [
                    'label' => $this->catalystLabel((string) ($s['type'] ?? 'UNKNOWN')),
                    'statement' => (string) ($s['statement'] ?? ''),
                    'evidence_ids' => array_values(array_map('intval', (array) ($s['evidence_ids'] ?? []))),
                ],
                array_filter((array) ($json['secondary_signals'] ?? []), 'is_array'),
            ),
            'confidence' => (string) ($json['confidence'] ?? $explanation->confidence ?? 'low'),
            'confidence_label' => $this->confidenceLabel((string) ($json['confidence'] ?? $explanation->confidence ?? 'low')),
            'caveats' => array_values(array_filter(array_map('strval', (array) ($json['caveats'] ?? [])))),
            'unknowns' => array_values(array_filter(array_map('strval', (array) ($json['unknowns'] ?? [])))),
        ];
    }

    private function catalystLabel(string $catalyst): string
    {
        return self::CATALYST_LABELS[$catalyst] ?? ucfirst(mb_strtolower(str_replace('_', ' ', $catalyst)));
    }

    private function confidenceLabel(string $confidence): string
    {
        return self::CONFIDENCE_LABELS[$confidence] ?? ucfirst($confidence);
    }
}
