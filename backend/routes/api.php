<?php

use App\Http\Controllers\Api\MemecoinDetailController;
use App\Http\Controllers\Api\MemecoinDiscoveryController;
use App\Http\Controllers\Api\MemecoinDiscoveryStatusController;
use App\Http\Controllers\Api\MemecoinListController;
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

// Read-only token detail — PostgreSQL only, never calls DexScreener. Identity is
// (chainId, tokenAddress); dashboard qualification is NOT applied here.
Route::get('/memecoins/{chainId}/{tokenAddress}', MemecoinDetailController::class)
    ->where('chainId', '[A-Za-z0-9_-]{1,64}')
    ->where('tokenAddress', '[A-Za-z0-9._:-]{1,128}');
