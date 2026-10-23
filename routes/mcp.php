<?php

use App\Http\Controllers\Mcp\McpServerController;
use Illuminate\Support\Facades\Route;

Route::match(['GET', 'POST', 'DELETE', 'OPTIONS'], '/mcp', McpServerController::class)->name('mcp.server');
