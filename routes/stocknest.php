<?php

use App\Http\Controllers\StockNest\ReportController;
use App\Http\Controllers\StockNest\WorkspaceController;
use App\Http\Requests\StockNest\WorkflowRequest;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('/app/actions/{operation}', [WorkspaceController::class, 'dispatch'])->where('operation', implode('|', array_map(fn (string $operation): string => preg_quote($operation, '#'), WorkflowRequest::OPERATIONS)))->middleware('throttle:60,1')->name('stocknest.action');
    Route::get('/app/reports/{report}.{format}', ReportController::class)->where('format', 'csv|xlsx|pdf')->middleware('throttle:10,1')->name('stocknest.export');
    Route::get('/app/{section?}', [WorkspaceController::class, 'index'])->where('section', 'overview|funding|catalog|projections|requests|purchasing|inventory|counts|approvals|reports|audit|administration')->name('stocknest.workspace');
});
