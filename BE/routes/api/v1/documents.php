<?php

use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\DocumentTypeController;
use Illuminate\Support\Facades\Route;

// Included inside the authenticated /api/v1 group in routes/api.php (auth:sanctum, active, throttle:api).
// Ids are plain integers resolved inside the caller's visible set (no implicit model binding), so a
// foreign or unknown id is indistinguishable (404) and permission checks run before any lookup.

Route::get('document-types', [DocumentTypeController::class, 'index'])->name('document-types.index');
Route::post('document-types', [DocumentTypeController::class, 'store'])->name('document-types.store');
Route::patch('document-types/{id}', [DocumentTypeController::class, 'update'])->whereNumber('id')->name('document-types.update');

Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
Route::post('documents', [DocumentController::class, 'store'])->name('documents.store');
Route::get('documents/{id}', [DocumentController::class, 'show'])->whereNumber('id')->name('documents.show');
Route::get('documents/{id}/download', [DocumentController::class, 'download'])->whereNumber('id')->name('documents.download');
Route::patch('documents/{id}', [DocumentController::class, 'update'])->whereNumber('id')->name('documents.update');
Route::post('documents/{id}/archive', [DocumentController::class, 'archive'])->whereNumber('id')->name('documents.archive');
Route::post('documents/{id}/restore', [DocumentController::class, 'restore'])->whereNumber('id')->name('documents.restore');
Route::delete('documents/{id}', [DocumentController::class, 'destroy'])->whereNumber('id')->name('documents.destroy');
