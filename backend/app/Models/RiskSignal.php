<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One structured risk signal behind a {@see RiskAssessment} (Step 24).
 *
 * TRI-STATE (+ NOT_AVAILABLE):
 *   MEASURED       a real value was read from a provider / our own data
 *   BAD            a measured value that is dangerous, or a positive hard flag
 *   UNKNOWN        null / "" / missing / unsupported chain — contributes 0 to
 *                  the score and is NEVER read as "no"
 *   NOT_AVAILABLE  can never be obtained from a free official API (top traders)
 *
 * `explanation` is a short PRE-WRITTEN label — never LLM-generated. No provider
 * payloads are ever stored.
 */
class RiskSignal extends Model
{
    public const STATE_MEASURED = 'MEASURED';

    public const STATE_BAD = 'BAD';

    public const STATE_UNKNOWN = 'UNKNOWN';

    public const STATE_NOT_AVAILABLE = 'NOT_AVAILABLE';

    public const SEVERITY_NONE = 'none';

    public const SEVERITY_LOW = 'low';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_CRITICAL = 'critical';

    public const GROUP_CONTRACT_SECURITY = 'contract_security';

    public const GROUP_EXIT_SAFETY = 'exit_safety';

    public const GROUP_HOLDER_DISTRIBUTION = 'holder_distribution';

    public const GROUP_LIQUIDITY = 'liquidity';

    public const GROUP_PUMP_DUMP = 'pump_dump';

    public const GROUP_MARKET_STRUCTURE = 'market_structure';

    public const GROUP_AGE = 'age';

    protected $fillable = [
        'risk_assessment_id',
        'token_id',
        'signal_key',
        'signal_group',
        'state',
        'value',
        'numeric_value',
        'unit',
        'severity',
        'source',
        'source_checked_at',
        'explanation',
    ];

    protected function casts(): array
    {
        return [
            'numeric_value' => 'float',
            'source_checked_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<RiskAssessment, $this> */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(RiskAssessment::class, 'risk_assessment_id');
    }

    /** @return BelongsTo<Token, $this> */
    public function token(): BelongsTo
    {
        return $this->belongsTo(Token::class);
    }

    public function isFailing(): bool
    {
        return $this->state === self::STATE_BAD;
    }
}
