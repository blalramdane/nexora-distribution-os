<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Transactions\TransactionPostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesController extends Controller
{
    public function history(Request $request)
    {
        $data=$request->validate([
            'customer_id'=>['required','string','size:26'],
        ]);

        return response()->json(DB::table('sales_invoices')
            ->where('organization_id',$request->user()->organization_id)
            ->where('customer_id',$data['customer_id'])
            ->where('status','posted')
            ->orderByDesc('invoice_date')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get(['id','document_number','invoice_date','total','paid_amount','balance_due','trip_id']));
    }

    public function invoiceItems(Request $request, string $invoiceId)
    {
        $organizationId=$request->user()->organization_id;
        $invoice=DB::table('sales_invoices')
            ->where('organization_id',$organizationId)
            ->where('id',$invoiceId)
            ->where('status','posted')
            ->firstOrFail();

        $items=DB::table('sales_invoice_items as item')
            ->where('item.organization_id',$organizationId)
            ->where('item.sales_invoice_id',$invoice->id)
            ->select([
                'item.id','item.product_id','item.entered_quantity','item.quantity_base',
                'item.conversion_factor_snapshot','item.unit_price_entered',
                'item.product_name_snapshot','item.sku_snapshot',
            ])
            ->selectSub(DB::table('sales_return_items as returned')
                ->selectRaw('COALESCE(SUM(returned.quantity_base), 0)')
                ->whereColumn('returned.organization_id','item.organization_id')
                ->whereColumn('returned.original_sales_invoice_item_id','item.id'), 'returned_quantity_base')
            ->orderBy('item.created_at')
            ->get()
            ->map(static function (object $item): object {
                $item->remaining_quantity_base=max(0,(float)$item->quantity_base-(float)$item->returned_quantity_base);
                return $item;
            })
            ->filter(static fn (object $item): bool => $item->remaining_quantity_base>0)
            ->values();

        return response()->json($items);
    }

    public function openInvoices(Request $request)
    {
        $data=$request->validate([
            'customer_id'=>['required','string','size:26'],
        ]);

        return response()->json(DB::table('sales_invoices')
            ->where('organization_id',$request->user()->organization_id)
            ->where('customer_id',$data['customer_id'])
            ->where('status','posted')
            ->where('balance_due','>',0)
            ->orderByDesc('invoice_date')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get(['id','document_number','invoice_date','total','paid_amount','balance_due','trip_id']));
    }

    public function store(Request $request, TransactionPostingService $posting)
    {
        $data=$request->validate([
            'customer_id'=>['required','string','size:26'],
            'location_id'=>['required','string','size:26'],
            'trip_id'=>['nullable','string','size:26'],
            'invoice_date'=>['nullable','date'],
            'discount'=>['nullable','numeric','min:0'],
            'tax'=>['nullable','numeric','min:0'],
            'paid_amount'=>['nullable','numeric','min:0'],
            'currency'=>['nullable','string','size:3'],
            'notes'=>['nullable','string'],
            'idempotency_key'=>['required','string','max:255'],
            'items'=>['required','array','min:1'],
            'items.*.product_id'=>['required','string','size:26'],
            'items.*.quantity'=>['required','numeric','gt:0'],
            'items.*.conversion_factor'=>['nullable','numeric','gt:0'],
            'items.*.unit_price'=>['required','numeric','gte:0'],
            'items.*.packaging_id'=>['nullable','string','size:26'],
            'items.*.unit_id'=>['nullable','string','size:26'],
        ]);
        $data['created_by']=$request->user()->id;
        return response()->json($posting->postSale($request->user()->organization_id,$data),201);
    }
}
