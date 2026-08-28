<?php

use App\Http\Controllers\Api\MemecoinDiscoveryController;
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
