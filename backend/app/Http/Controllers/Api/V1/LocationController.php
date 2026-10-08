<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LocationController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            DB::table('locations')
                ->where('organization_id', $request->user()->organization_id)
                ->where('active', true)
                ->orderBy('name')
                ->get()
        );
    }
}
