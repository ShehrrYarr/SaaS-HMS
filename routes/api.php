<?php

use App\Http\Controllers\Api\LabDeviceController;
use Illuminate\Support\Facades\Route;

// Laboratory analyzer / middleware integration (token per device, see Lab → Devices).
Route::post('lab-devices/results', [LabDeviceController::class, 'store'])
    ->middleware('throttle:120,1')
    ->name('api.lab-devices.results');
