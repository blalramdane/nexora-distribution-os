<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Transactions\TransactionPostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReturnController extends Controller
{
    public function sales(Request $request, TransactionPostingService $posting)
    {
        $data=$request->validate([
            'customer_id'=>['required','string','size:26'],
            'location_id'=>['required','string','size:26'],
            'original_sales_invoice_id'=>['required','string','size:26'],
            'trip_id'=>['nullable','string','size:26'],
            'return_date'=>['nullable','date'],
            'discount'=>['nullable','numeric','min:0'],
            'tax'=>['nullable','numeric','min:0'],
            'currency'=>['nullable','string','size:3'],
            'idempotency_key'=>['required','string','max:255'],
            'items'=>['required','array','min:1'],
            'items.*.product_id'=>['required','string','size:26'],
            'items.*.quantity'=>['required','numeric','gt:0'],
            'items.*.conversion_factor'=>['nullable','numeric','gt:0'],
            'items.*.unit_price'=>['required','numeric','gte:0'],
            'items.*.packaging_id'=>['nullable','string','size:26'],
            'items.*.original_sales_invoice_item_id'=>['required','string','size:26','distinct'],
        ]);
        $user = $request->user();
        $isFieldRep = $user->roles()
            ->where('roles.organization_id', $user->organization_id)
            ->where('roles.key', 'field_rep')
            ->exists();
        if ($isFieldRep) {
            if (empty($data['trip_id'])) {
                throw \Illuminate\Validation\ValidationException::withMessages(['trip_id' => ['المرتجع الميداني لازم يكون مرتبطًا برحلة المندوب.']]);
            }
            $sourceInvoice = DB::table('sales_invoices')
                ->where('organization_id', $user->organization_id)
                ->where('id', $data['original_sales_invoice_id'])
                ->where('customer_id', $data['customer_id'])
                ->where('trip_id', $data['trip_id'])
                ->where('status', 'posted')
                ->first();
            $authorizedTrip = DB::table('trips as t')
                ->join('vehicles as v', function ($join): void {
                    $join->on('v.id', '=', 't.vehicle_id')->on('v.organization_id', '=', 't.organization_id');
                })
                ->join('trip_customers as tc', function ($join): void {
                    $join->on('tc.trip_id', '=', 't.id')->on('tc.organization_id', '=', 't.organization_id');
                })
                ->where('t.organization_id', $user->organization_id)
                ->where('t.id', $data['trip_id'])
                ->where('t.rep_user_id', $user->id)
                ->where('tc.customer_id', $data['customer_id'])
                ->where('v.location_id', $data['location_id'])
                ->whereNotIn('t.status', ['completed', 'cancelled'])
                ->exists();
            if (! $sourceInvoice || ! $authorizedTrip) {
                abort(404, 'المرتجع أو الرحلة غير مسندين لحساب المندوب.');
            }
        }
        $data['created_by']=$request->user()->id;
        return response()->json($posting->postSalesReturn($request->user()->organization_id,$data),201);
    }

    public function purchases(Request $request, TransactionPostingService $posting)
    {
        $data=$request->validate([
            'supplier_id'=>['required','string','size:26'],
            'location_id'=>['required','string','size:26'],
            'original_purchase_invoice_id'=>['required','string','size:26'],
            'return_date'=>['nullable','date'],
            'discount'=>['nullable','numeric','min:0'],
            'tax'=>['nullable','numeric','min:0'],
            'currency'=>['nullable','string','size:3'],
            'idempotency_key'=>['required','string','max:255'],
            'items'=>['required','array','min:1'],
            'items.*.product_id'=>['required','string','size:26'],
            'items.*.quantity'=>['required','numeric','gt:0'],
            'items.*.conversion_factor'=>['nullable','numeric','gt:0'],
            'items.*.unit_cost'=>['required','numeric','gte:0'],
            'items.*.packaging_id'=>['nullable','string','size:26'],
            'items.*.original_purchase_invoice_item_id'=>['required','string','size:26','distinct'],
        ]);
        $data['created_by']=$request->user()->id;
        return response()->json($posting->postPurchaseReturn($request->user()->organization_id,$data),201);
    }
}
