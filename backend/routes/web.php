<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['app' => 'Time Jumper API', 'docs' => '/api']));
