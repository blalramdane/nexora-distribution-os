<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Transactions\TransactionPostingService;
use Illuminate\Http\Request;

class TripLoadController extends Controller
{
    public function store(Request $request, TransactionPostingService $posting)
    {
        $data=$request->validate([
            'trip_id'=>['required','string','size:26'],
            'from_location_id'=>['required','string','size:26'],
            'idempotency_key'=>['required','string','max:255'],
            'items'=>['required','array','min:1'],
            'items.*.product_id'=>['required','string','size:26'],
            'items.*.quantity_base'=>['required','numeric','gt:0'],
        ]);
        $data['created_by']=$request->user()->id;
        return response()->json($posting->postTripLoad($request->user()->organization_id,$data),201);
    }
}
