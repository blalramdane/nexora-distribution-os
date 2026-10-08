<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $org=$request->user()->organization_id;
        $today=now()->toDateString();
        $sales=DB::table('sales_invoices')->where('organization_id',$org)->where('status','posted')->whereDate('invoice_date',$today)->sum('total');
        $purchases=DB::table('purchase_invoices')->where('organization_id',$org)->where('status','posted')->whereDate('invoice_date',$today)->sum('total');
        $collections=DB::table('payments')->where('organization_id',$org)->where('status','posted')->whereDate('payment_date',$today)->sum('amount');
        $expenses=DB::table('expenses')->where('organization_id',$org)->where('status','posted')->whereDate('expense_date',$today)->sum('amount');
        $receivables=DB::table('customer_balance_summaries')->where('organization_id',$org)->sum('outstanding');
        $payables=DB::table('supplier_balance_summaries')->where('organization_id',$org)->sum('outstanding');
        return response()->json(compact('sales','purchases','collections','expenses','receivables','payables','today'));
    }
}