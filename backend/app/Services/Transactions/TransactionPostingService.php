<?php

namespace App\Services\Transactions;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TransactionPostingService
{
    public function postPurchase(string $organizationId, array $data): array
    {
        return DB::transaction(function () use ($organizationId, $data): array {
            $this->claimIdempotency($organizationId, 'purchase.post', $data['idempotency_key'] ?? null);

            $supplier = DB::table('suppliers')
                ->where('organization_id', $organizationId)
                ->where('id', $data['supplier_id'])
                ->where('active', true)
                ->firstOrFail();

            $location = DB::table('locations')
                ->where('organization_id', $organizationId)
                ->where('id', $data['location_id'])
                ->where('status', 'active')
                ->firstOrFail();

            $invoiceId = (string) Str::ulid();
            $documentNumber = $this->nextDocumentNumber($organizationId, 'purchase_invoice', 'PI');
            $subtotal = '0.0000';

            $items = [];
            foreach ($data['items'] as $line) {
                $product = DB::table('products')
                    ->where('organization_id', $organizationId)
                    ->where('id', $line['product_id'])
                    ->where('active', true)
                    ->firstOrFail();

                $conversion = (string) ($line['conversion_factor'] ?? 1);
                $enteredQty = (string) $line['quantity'];
                $quantityBase = bcmul($enteredQty, $conversion, 6);
                $unitCost = (string) $line['unit_cost'];
                $lineTotal = bcmul($enteredQty, $unitCost, 4);
                $subtotal = bcadd($subtotal, $lineTotal, 4);

                if (bccomp($quantityBase, '0', 6) <= 0 || bccomp($conversion, '0', 6) <= 0 || bccomp($unitCost, '0', 4) < 0) {
                    throw ValidationException::withMessages(['items' => ['Invalid purchase quantity, conversion or cost.']]);
                }

                $items[] = compact('product','conversion','enteredQty','quantityBase','unitCost','lineTotal');
            }

            $total = bcadd(
                bcsub($subtotal, (string) ($data['discount'] ?? 0), 4),
                (string) ($data['tax'] ?? 0),
                4
            );

            DB::table('purchase_invoices')->insert([
                'id'=>$invoiceId,'organization_id'=>$organizationId,'supplier_id'=>$supplier->id,
                'location_id'=>$location->id,'document_number'=>$documentNumber,
                'supplier_invoice_number'=>$data['supplier_invoice_number'] ?? null,'status'=>'posted',
                'invoice_date'=>$data['invoice_date'] ?? now()->toDateString(),'posted_at'=>now(),
                'subtotal'=>$subtotal,'discount'=>$data['discount'] ?? 0,'tax'=>$data['tax'] ?? 0,
                'total'=>$total,'paid_amount'=>0,'currency'=>$data['currency'] ?? 'EGP',
                'notes'=>$data['notes'] ?? null,'created_by'=>$data['created_by'] ?? null,
                'device_id'=>$data['device_id'] ?? null,'idempotency_key'=>$data['idempotency_key'] ?? null,
                'created_at'=>now(),'updated_at'=>now(),
            ]);

            $costOfInventory = '0.0000';
            foreach ($items as $item) {
                DB::table('purchase_invoice_items')->insert([
                    'id'=>(string) Str::ulid(),'organization_id'=>$organizationId,
                    'purchase_invoice_id'=>$invoiceId,'product_id'=>$item['product']->id,
                    'packaging_id'=>$data['packaging_id'] ?? null,'entered_unit_id'=>$data['unit_id'] ?? $item['product']->base_unit_id,
                    'entered_quantity'=>$item['enteredQty'],'conversion_factor_snapshot'=>$item['conversion'],
                    'quantity_base'=>$item['quantityBase'],'unit_cost_entered'=>$item['unitCost'],
                    'unit_cost_base'=>bcdiv($item['unitCost'],$item['conversion'],4),
                    'discount'=>0,'tax'=>0,'line_total'=>$item['lineTotal'],
                    'product_name_snapshot'=>$item['product']->name_ar,'sku_snapshot'=>$item['product']->sku,
                    'created_at'=>now(),'updated_at'=>now(),
                ]);

                $balance = DB::table('stock_balances')
                    ->where('organization_id',$organizationId)
                    ->where('product_id',$item['product']->id)
                    ->where('location_id',$location->id)
                    ->lockForUpdate()
                    ->first();

                $oldQty = (string) ($balance->quantity_base ?? 0);
                $oldAvg = (string) ($balance->average_cost ?? $item['unitCost']);
                $newQty = bcadd($oldQty, $item['quantityBase'], 6);
                $incomingCost = bcmul($item['unitCost'], $item['quantityBase'], 4);
                $oldValue = bcmul($oldAvg, $oldQty, 4);
                $newAvg = bccomp($newQty, '0', 6) === 0 ? '0.0000' : bcdiv(bcadd($oldValue, $incomingCost, 4), $newQty, 4);

                if ($balance) {
                    DB::table('stock_balances')->where('id',$balance->id)->update([
                        'quantity_base'=>$newQty,'average_cost'=>$newAvg,'updated_at'=>now(),
                    ]);
                } else {
                    DB::table('stock_balances')->insert([
                        'id'=>(string) Str::ulid(),'organization_id'=>$organizationId,
                        'product_id'=>$item['product']->id,'location_id'=>$location->id,
                        'quantity_base'=>$newQty,'reserved_quantity_base'=>0,'average_cost'=>$newAvg,
                        'updated_at'=>now(),
                    ]);
                }

                DB::table('stock_movements')->insert([
                    'id'=>(string) Str::ulid(),'organization_id'=>$organizationId,
                    'transaction_uuid'=>(string) Str::uuid(),'product_id'=>$item['product']->id,
                    'location_id'=>$location->id,'movement_type'=>'purchase_receipt',
                    'quantity_base'=>$item['quantityBase'],'unit_cost'=>$item['unitCost'],
                    'source_document_type'=>'purchase_invoice','source_document_id'=>$invoiceId,
                    'occurred_at'=>now(),'posted_at'=>now(),'created_by'=>$data['created_by'] ?? null,
                    'device_id'=>$data['device_id'] ?? null,'reference'=>$documentNumber,
                    'created_at'=>now(),'updated_at'=>now(),
                ]);

                $costOfInventory = bcadd($costOfInventory, $incomingCost, 4);
            }

            $accounts = $this->ensureLedgerAccounts($organizationId);
            $tx = (string) Str::uuid();
            $this->ledger($organizationId,$tx,$accounts['inventory'],$costOfInventory,0,'purchase_invoice',$invoiceId);
            $this->ledger($organizationId,$tx,$accounts['accounts_payable'],0,$total,'purchase_invoice',$invoiceId);

            return ['id'=>$invoiceId,'document_number'=>$documentNumber,'total'=>$total,'status'=>'posted'];
        }, attempts: 5);
    }

    public function postSale(string $organizationId, array $data): array
    {
        return DB::transaction(function () use ($organizationId, $data): array {
            $this->claimIdempotency($organizationId, 'sale.post', $data['idempotency_key'] ?? null);

            $customer = DB::table('customers')
                ->where('organization_id',$organizationId)->where('id',$data['customer_id'])
                ->where('status','active')->firstOrFail();
            $location = DB::table('locations')
                ->where('organization_id',$organizationId)->where('id',$data['location_id'])
                ->where('status','active')->firstOrFail();

            $invoiceId=(string) Str::ulid();
            $documentNumber=$this->nextDocumentNumber($organizationId,'sales_invoice','SI');
            $subtotal='0.0000';
            $cogs='0.0000';
            $items=[];

            foreach($data['items'] as $line){
                $product=DB::table('products')->where('organization_id',$organizationId)->where('id',$line['product_id'])->where('active',true)->firstOrFail();
                $conversion=(string)($line['conversion_factor'] ?? 1);
                $enteredQty=(string)$line['quantity'];
                $quantityBase=bcmul($enteredQty,$conversion,6);
                $unitPrice=(string)$line['unit_price'];
                $lineTotal=bcmul($enteredQty,$unitPrice,4);

                $balance=DB::table('stock_balances')->where('organization_id',$organizationId)
                    ->where('product_id',$product->id)->where('location_id',$location->id)
                    ->lockForUpdate()->first();
                $available=(string)($balance->quantity_base ?? 0);
                if(bccomp($available,$quantityBase,6)<0){
                    throw ValidationException::withMessages(['items'=>['رصيد المخزون غير كافٍ للصنف '.$product->name_ar.'.']]);
                }

                $cost=(string)($balance->average_cost ?? $product->default_cost ?? 0);
                $subtotal=bcadd($subtotal,$lineTotal,4);
                $cogs=bcadd($cogs,bcmul($cost,$quantityBase,4),4);
                $items[]=compact('product','conversion','enteredQty','quantityBase','unitPrice','lineTotal','cost','balance');
            }

            $total=bcadd(bcsub($subtotal,(string)($data['discount'] ?? 0),4),(string)($data['tax'] ?? 0),4);
            $paid=(string)($data['paid_amount'] ?? 0);
            if(bccomp($paid,$total,4)>0) throw ValidationException::withMessages(['paid_amount'=>['Paid amount cannot exceed invoice total.']]);
            $balanceDue=bcsub($total,$paid,4);

            DB::table('sales_invoices')->insert([
                'id'=>$invoiceId,'organization_id'=>$organizationId,'customer_id'=>$customer->id,
                'source_location_id'=>$location->id,'trip_id'=>$data['trip_id'] ?? null,
                'document_number'=>$documentNumber,'status'=>'posted','invoice_date'=>$data['invoice_date'] ?? now()->toDateString(),
                'posted_at'=>now(),'subtotal'=>$subtotal,'discount'=>$data['discount'] ?? 0,'tax'=>$data['tax'] ?? 0,
                'total'=>$total,'paid_amount'=>$paid,'balance_due'=>$balanceDue,'currency'=>$data['currency'] ?? 'EGP',
                'notes'=>$data['notes'] ?? null,'created_by'=>$data['created_by'] ?? null,'device_id'=>$data['device_id'] ?? null,
                'idempotency_key'=>$data['idempotency_key'] ?? null,'created_at'=>now(),'updated_at'=>now(),
            ]);

            foreach($items as $item){
                DB::table('sales_invoice_items')->insert([
                    'id'=>(string)Str::ulid(),'organization_id'=>$organizationId,'sales_invoice_id'=>$invoiceId,
                    'product_id'=>$item['product']->id,'packaging_id'=>$data['packaging_id'] ?? null,
                    'entered_unit_id'=>$data['unit_id'] ?? $item['product']->base_unit_id,'entered_quantity'=>$item['enteredQty'],
                    'conversion_factor_snapshot'=>$item['conversion'],'quantity_base'=>$item['quantityBase'],
                    'unit_price_entered'=>$item['unitPrice'],'unit_price_base'=>bcdiv($item['unitPrice'],$item['conversion'],4),
                    'discount'=>0,'tax'=>0,'line_total'=>$item['lineTotal'],'unit_cost_snapshot'=>$item['cost'],
                    'product_name_snapshot'=>$item['product']->name_ar,'sku_snapshot'=>$item['product']->sku,
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
                $newQty=bcsub((string)$item['balance']->quantity_base,$item['quantityBase'],6);
                DB::table('stock_balances')->where('id',$item['balance']->id)->update(['quantity_base'=>$newQty,'updated_at'=>now()]);
                DB::table('stock_movements')->insert([
                    'id'=>(string)Str::ulid(),'organization_id'=>$organizationId,'transaction_uuid'=>(string)Str::uuid(),
                    'product_id'=>$item['product']->id,'location_id'=>$location->id,'movement_type'=>'sale',
                    'quantity_base'=>bcsub('0',$item['quantityBase'],6),'unit_cost'=>$item['cost'],
                    'source_document_type'=>'sales_invoice','source_document_id'=>$invoiceId,'occurred_at'=>now(),
                    'posted_at'=>now(),'created_by'=>$data['created_by'] ?? null,'device_id'=>$data['device_id'] ?? null,
                    'trip_id'=>$data['trip_id'] ?? null,'reference'=>$documentNumber,'created_at'=>now(),'updated_at'=>now(),
                ]);
            }

            $accounts=$this->ensureLedgerAccounts($organizationId);
            $tx=(string)Str::uuid();
            $salesAccount=$paid==='0.0000' ? $accounts['accounts_receivable'] : $accounts['cash'];
            $this->ledger($organizationId,$tx,$salesAccount,$total,0,'sales_invoice',$invoiceId);
            $this->ledger($organizationId,$tx,$accounts['sales_revenue'],0,$total,'sales_invoice',$invoiceId);
            if(bccomp($cogs,'0.0000',4)>0){
                $this->ledger($organizationId,$tx,$accounts['cogs'],$cogs,0,'sales_invoice',$invoiceId);
                $this->ledger($organizationId,$tx,$accounts['inventory'],0,$cogs,'sales_invoice',$invoiceId);
            }

            return ['id'=>$invoiceId,'document_number'=>$documentNumber,'total'=>$total,'paid_amount'=>$paid,'balance_due'=>$balanceDue,'status'=>'posted'];
        }, attempts:5);
    }

    private function claimIdempotency(string $organizationId,string $type,?string $key): void
    {
        if(!$key) return;
        try{
            DB::table('idempotency_keys')->insert([
                'id'=>(string)Str::ulid(),'organization_id'=>$organizationId,'operation_type'=>$type,
                'idempotency_key'=>$key,'request_fingerprint'=>hash('sha256',json_encode(request()->all())),
                'status'=>'completed','created_at'=>now(),'completed_at'=>now(),
            ]);
        }catch(QueryException $e){
            throw ValidationException::withMessages(['idempotency_key'=>['This operation has already been submitted.']]);
        }
    }

    private function nextDocumentNumber(string $organizationId,string $type,string $prefix): string
    {
        $sequence=DB::table('document_sequences')->where('organization_id',$organizationId)->where('document_type',$type)->lockForUpdate()->first();
        if(!$sequence){
            $id=(string)Str::ulid();
            DB::table('document_sequences')->insert([
                'id'=>$id,'organization_id'=>$organizationId,'document_type'=>$type,'prefix'=>$prefix,
                'next_number'=>2,'padding'=>6,'reset_policy'=>'never','active'=>true,'created_at'=>now(),'updated_at'=>now(),
            ]);
            $number=1;$pad=6;$p=$prefix;
        }else{
            $number=(int)$sequence->next_number;$pad=(int)$sequence->padding;$p=$sequence->prefix ?: $prefix;
            DB::table('document_sequences')->where('id',$sequence->id)->update(['next_number'=>$number+1,'updated_at'=>now()]);
        }
        return $p.str_pad((string)$number,$pad,'0',STR_PAD_LEFT);
    }

    private function ensureLedgerAccounts(string $organizationId): array
    {
        $map=[
            'inventory'=>['1300','Inventory','asset'],
            'accounts_receivable'=>['1200','Accounts Receivable','asset'],
            'accounts_payable'=>['2100','Accounts Payable','liability'],
            'cash'=>['1000','Cash','asset'],
            'sales_revenue'=>['4000','Sales Revenue','revenue'],
            'cogs'=>['5000','Cost of Goods Sold','expense'],
        ];
        $ids=[];
        foreach($map as $key=>[$code,$name,$type]){
            $row=DB::table('ledger_accounts')->where('organization_id',$organizationId)->where('code',$code)->first();
            if(!$row){
                $id=(string)Str::ulid();
                DB::table('ledger_accounts')->insert([
                    'id'=>$id,'organization_id'=>$organizationId,'code'=>$code,'name'=>$name,'type'=>$type,
                    'currency'=>'EGP','active'=>true,'created_at'=>now(),'updated_at'=>now(),
                ]);
                $ids[$key]=$id;
            }else $ids[$key]=$row->id;
        }
        return $ids;
    }

    private function ledger(string $organizationId,string $tx,string $accountId,string $debit,string $credit,string $sourceType,string $sourceId): void
    {
        DB::table('ledger_entries')->insert([
            'id'=>(string)Str::ulid(),'organization_id'=>$organizationId,'transaction_uuid'=>$tx,'account_id'=>$accountId,
            'debit'=>$debit,'credit'=>$credit,'currency'=>'EGP','source_document_type'=>$sourceType,
            'source_document_id'=>$sourceId,'occurred_at'=>now(),'posted_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
        ]);
    }
}