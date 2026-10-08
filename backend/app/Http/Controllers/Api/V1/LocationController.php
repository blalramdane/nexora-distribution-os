<?php

namespace AppHttpControllersApi\V1;

use AppHttpControllersController;
use IlluminateHttpRequest;
use IlluminateSupportFacadesDB;

class LocationController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            DB::table('locations')->where('organization_id',$request->user()->organization_id)
              ->where('active',true)->orderBy('name')->get()
        );
    }
}
