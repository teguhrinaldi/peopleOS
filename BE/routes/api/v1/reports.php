<?php

use App\Http\Controllers\Api\V1\ReportController;
use Illuminate\Support\Facades\Route;

// Included inside the authenticated /api/v1 group in routes/api.php. Each report authorizes itself.
Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
Route::get('reports/{key}', [ReportController::class, 'show'])->where('key', '[a-z_.]+')->name('reports.show');
Route::get('reports/{key}/export/{format}', [ReportController::class, 'export'])->where('key', '[a-z_.]+')->name('reports.export');
