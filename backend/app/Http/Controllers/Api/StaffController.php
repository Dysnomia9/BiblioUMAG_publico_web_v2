<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Staff;

class StaffController extends Controller
{
    public function index()
    {
        return response()->json(Staff::orderBy('nombre')->get(['id', 'nombre']));
    }
}
