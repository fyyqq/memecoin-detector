<?php

use App\Http\Controllers\Api\ChainActivityController;
use App\Http\Controllers\Api\MemecoinDetailController;
use App\Http\Controllers\Api\MemecoinDiscoveryController;
use App\Http\Controllers\Api\MemecoinDiscoveryStatusController;
use App\Http\Controllers\Api\MemecoinListController;
use App\Http\Controllers\Api\MonthlyChampionsController;
use App\Http\Controllers\Api\RecentlyCrossedController;
use App\Http\Controllers\Api\TopVolumeController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});

// Read-only dashboard feed — PostgreSQL only, never calls DexScreener.
Route::get('/memecoins', MemecoinListController::class);

// Heavy ingestion pipeline — discovers + enriches + persists. Normally run by
// the scheduler; the HTTP route stays for manual/debug use.
Route::get('/memecoins/discover', MemecoinDiscoveryController::class);

// Read-only discovery-coverage report — PostgreSQL (ingestion_runs) only, never
// calls DexScreener.
Route::get('/memecoins/discovery-status', MemecoinDiscoveryStatusController::class);

// Read-only "Recently Crossed $5M" feed (Step 20) — PostgreSQL only, never calls
// DexScreener / CoinGecko / GeckoTerminal. Defined before the {chainId}/{tokenAddress}
// route so the literal segment always wins.
Route::get('/memecoins/recently-crossed', RecentlyCrossedController::class);

// Read-only "Monthly Top Memecoins" grid (Step 25 — Top 3) — reads
// monthly_rankings only, never recomputes, never queries snapshots, never calls
// a provider.
Route::get('/memecoins/monthly-champions', MonthlyChampionsController::class);

// Chain-level market views (PostgreSQL only — never DexScreener, never a
// security provider). All defined before the {chainId}/{tokenAddress} wildcard.
Route::get('/memecoins/top-volume', TopVolumeController::class);
Route::get('/memecoins/chain-activity', ChainActivityController::class);

// Read-only token detail — PostgreSQL only, never calls DexScreener. Identity is
// (chainId, tokenAddress); dashboard qualification is NOT applied here.
Route::get('/memecoins/{chainId}/{tokenAddress}', MemecoinDetailController::class)
    ->where('chainId', '[A-Za-z0-9_-]{1,64}')
    ->where('tokenAddress', '[A-Za-z0-9._:-]{1,128}');
