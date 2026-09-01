<?php

declare(strict_types=1);

namespace App\Services\Ranking;

/**
 * The input a {@see MonthlyChampionResearchProvider} needs to research one
 * month + chain bucket.
 */
final readonly class MonthlyResearchContext
{
    public function __construct(
        public MonthWindow $window,
        /** @var ChainBucket::* */
        public string $bucket,
        /**
         * Tokens we already track in this bucket + month, so a provider can
         * prefer corroborating a known token over inventing one.
         *
         * @var list<array{id:int,symbol:?string,name:?string,chain_id:string,token_address:string}>
         */
        public array $knownTokens = [],
    ) {}

    public function year(): int
    {
        return $this->window->year;
    }

    public function month(): int
    {
        return $this->window->month;
    }
}
