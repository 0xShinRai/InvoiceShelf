<?php

use App\Domains\Sales\Http\Controllers\Company\EInvoiceReadinessController;
use App\Domains\Sales\Http\Controllers\Company\EstimatesController;
use App\Domains\Sales\Http\Controllers\Company\EstimateTemplatesController;
use App\Domains\Sales\Http\Controllers\Company\InvoicesController;
use App\Domains\Sales\Http\Controllers\Company\InvoiceTemplatesController;
use App\Domains\Sales\Http\Controllers\Company\RecurringInvoiceController;
use App\Domains\Sales\Http\Controllers\Company\RecurringInvoiceFrequencyController;
use App\Domains\Sales\Http\Controllers\Company\SerialNumberController;
use Illuminate\Support\Facades\Route;

Route::get('/next-number', [SerialNumberController::class, 'nextNumber']);
Route::get('/number-placeholders', [SerialNumberController::class, 'placeholders']);

Route::get('/einvoice/readiness', [EInvoiceReadinessController::class, 'company']);

Route::get('/invoices/{invoice}/einvoice-readiness', [EInvoiceReadinessController::class, 'invoice']);
Route::get('/invoices/{invoice}/send/preview', [InvoicesController::class, 'sendPreview']);
Route::post('/invoices/{invoice}/send', [InvoicesController::class, 'send']);
Route::post('/invoices/{invoice}/clone', [InvoicesController::class, 'clone']);
Route::post('/invoices/{invoice}/convert-to-estimate', [InvoicesController::class, 'convertToEstimate']);
Route::post('/invoices/{invoice}/credit-note', [InvoicesController::class, 'createCreditNote']);
Route::post('/invoices/{invoice}/status', [InvoicesController::class, 'changeStatus']);
Route::post('/invoices/delete', [InvoicesController::class, 'delete']);
Route::get('/invoices/templates', InvoiceTemplatesController::class);
Route::apiResource('invoices', InvoicesController::class);

Route::get('/recurring-invoice-frequency', RecurringInvoiceFrequencyController::class);
Route::post('/recurring-invoices/delete', [RecurringInvoiceController::class, 'delete']);
Route::apiResource('recurring-invoices', RecurringInvoiceController::class);

Route::get('/estimates/{estimate}/send/preview', [EstimatesController::class, 'sendPreview']);
Route::post('/estimates/{estimate}/send', [EstimatesController::class, 'send']);
Route::post('/estimates/{estimate}/clone', [EstimatesController::class, 'clone']);
Route::post('/estimates/{estimate}/status', [EstimatesController::class, 'changeStatus']);
Route::post('/estimates/{estimate}/convert-to-invoice', [EstimatesController::class, 'convertToInvoice']);
Route::get('/estimates/templates', EstimateTemplatesController::class);
Route::post('/estimates/delete', [EstimatesController::class, 'delete']);
Route::apiResource('estimates', EstimatesController::class);
