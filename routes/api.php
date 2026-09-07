<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\EmailSyncController;

// Auth
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

// Invoices — protected
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/invoices',         [InvoiceController::class, 'index']);
    Route::post('/invoices/upload', [InvoiceController::class, 'upload']);
    Route::put('/invoices/{id}',    [InvoiceController::class, 'update']);
    Route::delete('/invoices/{id}', [InvoiceController::class, 'destroy']);
    Route::get('/invoices/export',  [InvoiceController::class, 'export']);
    Route::get('/invoices/stats',   [InvoiceController::class, 'stats']);
    Route::post('/invoices/sync-email', [EmailSyncController::class, 'sync']);
});
