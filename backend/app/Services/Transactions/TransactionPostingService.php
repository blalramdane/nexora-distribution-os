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
            if ($replay = $this->claimIdempotency($organizationId, 'purchase.post', $data['idempotency_key'] ?? null, $data)) {
                return $this->replayPurchase($organizationId, $replay);
            }

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
                    'packaging_id'=>$data['items'][array_search($item['product']->id, array_column($data['items'], 'product_id'))]['packaging_id'] ?? null,'entered_unit_id'=>$data['items'][array_search($item['product']->id, array_column($data['items'], 'product_id'))]['unit_id'] ?? $item['product']->base_unit_id,
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
                $unitCostBase = bcdiv($item['unitCost'], $item['conversion'], 4);
                $incomingCost = bcmul($unitCostBase, $item['quantityBase'], 4);
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
                    'quantity_base'=>$item['quantityBase'],'unit_cost'=>$unitCostBase,
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

            $this->adjustSupplierBalanceSummary($organizationId,$supplier->id,[
                'total_purchases'=>$total,'outstanding'=>$total,
            ],true);

            $result = ['id'=>$invoiceId,'document_number'=>$documentNumber,'total'=>$total,'status'=>'posted'];
            $this->completeIdempotency($organizationId, 'purchase.post', $data['idempotency_key'] ?? null, 'purchase_invoice:'.$invoiceId);
            return $result;
        }, attempts: 5);
    }

    public function postSale(string $organizationId, array $data): array
    {
        return DB::transaction(function () use ($organizationId, $data): array {
            if ($replay = $this->claimIdempotency($organizationId, 'sale.post', $data['idempotency_key'] ?? null, $data)) {
                return $this->replaySale($organizationId, $replay);
            }

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
                    'product_id'=>$item['product']->id,'packaging_id'=>$data['items'][array_search($item['product']->id, array_column($data['items'], 'product_id'))]['packaging_id'] ?? null,
                    'entered_unit_id'=>$data['items'][array_search($item['product']->id, array_column($data['items'], 'product_id'))]['unit_id'] ?? $item['product']->base_unit_id,'entered_quantity'=>$item['enteredQty'],
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

            // A partially-paid sale must split the receivable from the cash collected at posting.
            // paid_amount is the cash collected at sale time; balance_due remains the AR portion.
            if (bccomp($paid, '0.0000', 4) > 0) {
                $this->ledger($organizationId,$tx,$accounts['cash'],$paid,0,'sales_invoice',$invoiceId);
            }
            if (bccomp($balanceDue, '0.0000', 4) > 0) {
                $this->ledger($organizationId,$tx,$accounts['accounts_receivable'],$balanceDue,0,'sales_invoice',$invoiceId);
            }
            $this->ledger($organizationId,$tx,$accounts['sales_revenue'],0,$total,'sales_invoice',$invoiceId);
            if(bccomp($cogs,'0.0000',4)>0){
                $this->ledger($organizationId,$tx,$accounts['cogs'],$cogs,0,'sales_invoice',$invoiceId);
                $this->ledger($organizationId,$tx,$accounts['inventory'],0,$cogs,'sales_invoice',$invoiceId);
            }

            $this->adjustCustomerBalanceSummary($organizationId,$customer->id,[
                'total_sales'=>$total,'total_paid'=>$paid,'outstanding'=>$balanceDue,
            ],true);

            $result = ['id'=>$invoiceId,'document_number'=>$documentNumber,'total'=>$total,'paid_amount'=>$paid,'balance_due'=>$balanceDue,'status'=>'posted'];
            $this->completeIdempotency($organizationId, 'sale.post', $data['idempotency_key'] ?? null, 'sales_invoice:'.$invoiceId);
            return $result;
        }, attempts:5);
    }

    public function postTripLoad(string $organizationId, array $data): array
    {
        return DB::transaction(function () use ($organizationId, $data): array {
            if ($replay = $this->claimIdempotency($organizationId,'trip_load.post',$data['idempotency_key'] ?? null,$data)) {
                return $this->replayTripLoad($organizationId,$replay);
            }

            $trip=DB::table('trips')->where('organization_id',$organizationId)->where('id',$data['trip_id'])->lockForUpdate()->first();
            if(!$trip) throw ValidationException::withMessages(['trip_id'=>['Trip was not found.']]);
            if(in_array($trip->status,['completed','cancelled'],true)) throw ValidationException::withMessages(['trip_id'=>['Completed or cancelled trips cannot be loaded.']]);

            $vehicle=DB::table('vehicles')->where('organization_id',$organizationId)->where('id',$trip->vehicle_id)->where('active',true)->first();
            $source=DB::table('locations')->where('organization_id',$organizationId)->where('id',$data['from_location_id'])->where('status','active')->first();
            if(!$vehicle || !$source) throw ValidationException::withMessages(['from_location_id'=>['Vehicle or source location is invalid.']]);

            $loadId=(string)Str::ulid();
            $loadNumber=$this->nextDocumentNumber($organizationId,'trip_load','LD');
            DB::table('trip_loads')->insert([
                'id'=>$loadId,'organization_id'=>$organizationId,'trip_id'=>$trip->id,
                'from_location_id'=>$source->id,'to_vehicle_id'=>$vehicle->id,'status'=>'loaded',
                'loaded_at'=>now(),'created_by'=>$data['created_by'] ?? null,
                'idempotency_key'=>$data['idempotency_key'] ?? null,'created_at'=>now(),'updated_at'=>now(),
            ]);

            foreach($data['items'] as $line){
                $product=DB::table('products')->where('organization_id',$organizationId)->where('id',$line['product_id'])->where('active',true)->firstOrFail();
                $qty=(string)$line['quantity_base'];
                if(bccomp($qty,'0',6)<=0) throw ValidationException::withMessages(['items'=>['Load quantity must be greater than zero.']]);

                $sourceBalance=DB::table('stock_balances')->where('organization_id',$organizationId)->where('product_id',$product->id)->where('location_id',$source->id)->lockForUpdate()->first();
                $available=(string)($sourceBalance->quantity_base ?? 0);
                if(bccomp($available,$qty,6)<0) throw ValidationException::withMessages(['items'=>['Stock is insufficient for '.$product->name_ar.'.']]);

                $vehicleBalance=DB::table('stock_balances')->where('organization_id',$organizationId)->where('product_id',$product->id)->where('location_id',$vehicle->location_id)->lockForUpdate()->first();
                $cost=(string)($sourceBalance->average_cost ?? $product->default_cost ?? 0);
                $tx=(string)Str::uuid();

                $outMovementId=(string)Str::ulid();
                DB::table('stock_movements')->insert([
                    'id'=>$outMovementId,'organization_id'=>$organizationId,'transaction_uuid'=>$tx,'product_id'=>$product->id,
                    'location_id'=>$source->id,'movement_type'=>'transfer_out','quantity_base'=>bcsub('0',$qty,6),
                    'unit_cost'=>$cost,'source_document_type'=>'trip_load','source_document_id'=>$loadId,'occurred_at'=>now(),
                    'posted_at'=>now(),'created_by'=>$data['created_by'] ?? null,'device_id'=>$data['device_id'] ?? null,
                    'trip_id'=>$trip->id,'reference'=>$loadNumber,'created_at'=>now(),'updated_at'=>now(),
                ]);

                DB::table('trip_load_items')->insert([
                    'id'=>(string)Str::ulid(),'organization_id'=>$organizationId,'trip_load_id'=>$loadId,
                    'product_id'=>$product->id,'quantity_base'=>$qty,'source_stock_movement_id'=>$outMovementId,
                    'created_at'=>now(),'updated_at'=>now(),
                ]);

                DB::table('stock_movements')->insert([
                    'id'=>(string)Str::ulid(),'organization_id'=>$organizationId,'transaction_uuid'=>$tx,'product_id'=>$product->id,
                    'location_id'=>$vehicle->location_id,'movement_type'=>'vehicle_load','quantity_base'=>$qty,
                    'unit_cost'=>$cost,'source_document_type'=>'trip_load','source_document_id'=>$loadId,'occurred_at'=>now(),
                    'posted_at'=>now(),'created_by'=>$data['created_by'] ?? null,'device_id'=>$data['device_id'] ?? null,
                    'trip_id'=>$trip->id,'reference'=>$loadNumber,'created_at'=>now(),'updated_at'=>now(),
                ]);

                $newSourceQty=bcsub($available,$qty,6);
                DB::table('stock_balances')->where('id',$sourceBalance->id)->update(['quantity_base'=>$newSourceQty,'updated_at'=>now()]);

                $oldVehicleQty=(string)($vehicleBalance->quantity_base ?? 0);
                $newVehicleQty=bcadd($oldVehicleQty,$qty,6);
                $oldVehicleCost=(string)($vehicleBalance->average_cost ?? '0.0000');
                $vehicleValue=bcadd(bcmul($oldVehicleQty,$oldVehicleCost,4),bcmul($qty,$cost,4),4);
                $vehicleAverage=bccomp($newVehicleQty,'0',6)===0?'0.0000':bcdiv($vehicleValue,$newVehicleQty,4);
                if($vehicleBalance){
                    DB::table('stock_balances')->where('id',$vehicleBalance->id)->update(['quantity_base'=>$newVehicleQty,'average_cost'=>$vehicleAverage,'updated_at'=>now()]);
                }else{
                    DB::table('stock_balances')->insert([
                        'id'=>(string)Str::ulid(),'organization_id'=>$organizationId,'product_id'=>$product->id,
                        'location_id'=>$vehicle->location_id,'quantity_base'=>$newVehicleQty,'reserved_quantity_base'=>0,
                        'average_cost'=>$vehicleAverage,'updated_at'=>now(),
                    ]);
                }
            }

            DB::table('trips')->where('id',$trip->id)->update([
                'status'=>in_array($trip->status,['planned','ready'],true) ? 'loaded' : $trip->status,
                'updated_at'=>now(),
            ]);

            $result=['id'=>$loadId,'load_number'=>$loadNumber,'trip_id'=>$trip->id,'vehicle_id'=>$vehicle->id,'status'=>'loaded'];
            $this->completeIdempotency($organizationId,'trip_load.post',$data['idempotency_key'] ?? null,'trip_load:'.$loadId.':'.$loadNumber);
            return $result;
        }, attempts:5);
    }

    public function settleTrip(string $organizationId, array $data): array
    {
        return DB::transaction(function () use ($organizationId, $data): array {
            $trip = DB::table('trips')->where('organization_id',$organizationId)->where('id',$data['trip_id'])->lockForUpdate()->first();
            if (!$trip) throw ValidationException::withMessages(['trip_id'=>['Trip was not found.']]);
            if (in_array($trip->status, ['completed','cancelled'], true)) {
                throw ValidationException::withMessages(['trip_id'=>['This trip is already closed.']]);
            }

            $productIds = DB::table('stock_movements')->where('organization_id',$organizationId)->where('trip_id',$trip->id)->distinct()->pluck('product_id');
            $movementRows = collect();
            foreach ($productIds as $productId) {
                $loaded = (string)DB::table('stock_movements')->where('organization_id',$organizationId)->where('trip_id',$trip->id)->where('product_id',$productId)->where('movement_type','vehicle_load')->sum('quantity_base');
                $sold = (string)DB::table('stock_movements')->where('organization_id',$organizationId)->where('trip_id',$trip->id)->where('product_id',$productId)->where('movement_type','sale')->sum('quantity_base');
                $returned = (string)DB::table('stock_movements')->where('organization_id',$organizationId)->where('trip_id',$trip->id)->where('product_id',$productId)->where('movement_type','sales_return')->sum('quantity_base');
                $transferred = (string)DB::table('stock_movements')->where('organization_id',$organizationId)->where('trip_id',$trip->id)->where('product_id',$productId)->whereIn('movement_type',['transfer_out','transfer_in'])->sum('quantity_base');
                $expected = bcadd(bcadd($loaded,$sold,6),bcadd($returned,$transferred,6),6);
                $movementRows->push((object)['product_id'=>$productId,'loaded'=>$loaded,'sold'=>$sold,'returned'=>$returned,'transferred'=>$transferred,'expected_closing'=>$expected]);
            }

            $actual = collect($data['closing_items'] ?? [])->keyBy('product_id');
            $productIds = $movementRows->pluck('product_id')->merge($actual->keys())->unique()->values();
            $settlementId = (string) Str::ulid();

            // Do not use the mutable invoice paid_amount here: it includes later collections
            // allocated through postPayment(). Count only the cash actually posted with the sale.
            $salesCashAccountId = $this->ensureLedgerAccounts($organizationId)['cash'];
            $salesCash = (string) DB::table('ledger_entries')
                ->where('organization_id',$organizationId)
                ->where('account_id',$salesCashAccountId)
                ->where('source_document_type','sales_invoice')
                ->whereIn('source_document_id', function ($query) use ($organizationId, $trip): void {
                    $query->from('sales_invoices')
                        ->select('id')
                        ->where('organization_id',$organizationId)
                        ->where('trip_id',$trip->id)
                        ->where('status','posted');
                })
                ->sum('debit');
            $collections = (string) DB::table('payments')
                ->where('organization_id',$organizationId)
                ->where('trip_id',$trip->id)
                ->where('party_type','customer')
                ->where('direction','inbound')
                ->where('status','posted')
                ->sum('amount');
            $expenses = (string) DB::table('expenses')->where('organization_id',$organizationId)->where('trip_id',$trip->id)->where('status','posted')->sum('amount');
            $openingCash = (string)($data['opening_cash'] ?? 0);
            $expectedCash = bcsub(bcadd(bcadd($openingCash,$salesCash,4),$collections,4),$expenses,4);
            $actualCash = (string)($data['actual_cash'] ?? 0);
            $cashVariance = bcsub($actualCash,$expectedCash,4);

            DB::table('trip_settlements')->insert([
                'id'=>$settlementId,'organization_id'=>$organizationId,'trip_id'=>$trip->id,'status'=>'settled',
                'opening_cash'=>$openingCash,'expected_cash'=>$expectedCash,'actual_cash'=>$actualCash,
                'cash_variance'=>$cashVariance,'settled_by'=>$data['settled_by'] ?? null,'settled_at'=>now(),
                'created_at'=>now(),'updated_at'=>now(),
            ]);

            $stockVarianceValue='0.0000';
            foreach($productIds as $productId){
                $row=$movementRows->firstWhere('product_id',$productId);
                $expected=(string)($row->expected_closing ?? 0);
                $actualQty=(string)($actual->get($productId)['quantity_base'] ?? 0);
                $variance=bcsub($actualQty,$expected,6);
                $balance=DB::table('stock_balances')->where('organization_id',$organizationId)->where('product_id',$productId)->where('location_id',DB::table('vehicles')->where('id',$trip->vehicle_id)->value('location_id'))->first();
                $cost=(string)($balance->average_cost ?? 0);
                $stockVarianceValue=bcadd($stockVarianceValue,bcmul($variance,$cost,4),4);
                DB::table('trip_settlement_lines')->insert([
                    'id'=>(string)Str::ulid(),'organization_id'=>$organizationId,'trip_settlement_id'=>$settlementId,'product_id'=>$productId,
                    'opening_quantity_base'=>0,'loaded_quantity_base'=>$row->loaded,
                    'sold_quantity_base'=>bcsub('0',(string)$row->sold,6),'returned_quantity_base'=>$row->returned,'transferred_quantity_base'=>$row->transferred,'adjustment_quantity_base'=>0,
                    'expected_closing_quantity_base'=>$expected,'actual_closing_quantity_base'=>$actualQty,'variance_quantity_base'=>$variance,
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
            }

            DB::table('trip_settlements')->where('id',$settlementId)->update(['stock_variance_value'=>$stockVarianceValue,'updated_at'=>now()]);
            DB::table('trips')->where('id',$trip->id)->update(['status'=>'completed','ended_at'=>now(),'updated_at'=>now()]);

            return [
                'id'=>$settlementId,'trip_id'=>$trip->id,'status'=>'settled',
                'expected_cash'=>$expectedCash,'actual_cash'=>$actualCash,'cash_variance'=>$cashVariance,
                'stock_variance_value'=>$stockVarianceValue,
            ];
        }, attempts:5);
    }

    public function postSalesReturn(string $organizationId, array $data): array
    {
        return DB::transaction(function () use ($organizationId, $data): array {
            if ($replay = $this->claimIdempotency($organizationId,'sales_return.post',$data['idempotency_key'] ?? null,$data)) {
                return $this->replayReturn($organizationId,$replay,'sales_return');
            }

            $customer = DB::table('customers')->where('organization_id',$organizationId)->where('id',$data['customer_id'])->where('status','active')->firstOrFail();
            $location = DB::table('locations')->where('organization_id',$organizationId)->where('id',$data['location_id'])->where('status','active')->firstOrFail();

            $returnId=(string)Str::ulid();
            $documentNumber=$this->nextDocumentNumber($organizationId,'sales_return','SR');
            $subtotal='0.0000';
            $items=[];

            foreach($data['items'] as $line){
                $product=DB::table('products')->where('organization_id',$organizationId)->where('id',$line['product_id'])->where('active',true)->firstOrFail();
                $conversion=(string)($line['conversion_factor'] ?? 1);
                $qty=(string)$line['quantity'];
                $qtyBase=bcmul($qty,$conversion,6);
                $unitPrice=(string)$line['unit_price'];
                $lineTotal=bcmul($qty,$unitPrice,4);
                $cost=(string)($product->default_cost ?? 0);

                if (!empty($line['original_sales_invoice_item_id'])) {
                    $original=DB::table('sales_invoice_items')->where('organization_id',$organizationId)->where('id',$line['original_sales_invoice_item_id'])->first();
                    if(!$original || $original->product_id !== $product->id){
                        throw ValidationException::withMessages(['items'=>['Original sales invoice item is invalid.']]);
                    }
                    $returned=DB::table('sales_return_items')->where('organization_id',$organizationId)->where('original_sales_invoice_item_id',$original->id)->sum('quantity_base');
                    $remaining=bcsub((string)$original->quantity_base,(string)$returned,6);
                    if(bccomp($qtyBase,$remaining,6)>0) throw ValidationException::withMessages(['items'=>['Return quantity exceeds the sold quantity.']]);
                    $cost=(string)$original->unit_cost_snapshot;
                }

                $items[]=compact('product','conversion','qty','qtyBase','unitPrice','lineTotal','cost','line');
                $subtotal=bcadd($subtotal,$lineTotal,4);
            }

            $total=bcadd(bcsub($subtotal,(string)($data['discount'] ?? 0),4),(string)($data['tax'] ?? 0),4);

            DB::table('sales_returns')->insert([
                'id'=>$returnId,'organization_id'=>$organizationId,'customer_id'=>$customer->id,
                'source_location_id'=>$location->id,'original_sales_invoice_id'=>$data['original_sales_invoice_id'] ?? null,
                'trip_id'=>$data['trip_id'] ?? null,'document_number'=>$documentNumber,'status'=>'posted',
                'return_date'=>$data['return_date'] ?? now()->toDateString(),'posted_at'=>now(),
                'subtotal'=>$subtotal,'discount'=>$data['discount'] ?? 0,'tax'=>$data['tax'] ?? 0,'total'=>$total,
                'currency'=>$data['currency'] ?? 'EGP','created_by'=>$data['created_by'] ?? null,
                'device_id'=>$data['device_id'] ?? null,'idempotency_key'=>$data['idempotency_key'] ?? null,
                'created_at'=>now(),'updated_at'=>now(),
            ]);

            foreach($items as $item){
                DB::table('sales_return_items')->insert([
                    'id'=>(string)Str::ulid(),'organization_id'=>$organizationId,'sales_return_id'=>$returnId,
                    'product_id'=>$item['product']->id,'original_sales_invoice_item_id'=>$item['line']['original_sales_invoice_item_id'] ?? null,
                    'packaging_id'=>$item['line']['packaging_id'] ?? null,'entered_quantity'=>$item['qty'],
                    'conversion_factor_snapshot'=>$item['conversion'],'quantity_base'=>$item['qtyBase'],
                    'unit_price_base'=>bcdiv($item['unitPrice'],$item['conversion'],4),'unit_cost_snapshot'=>$item['cost'],
                    'line_total'=>$item['lineTotal'],'created_at'=>now(),'updated_at'=>now(),
                ]);

                $balance=DB::table('stock_balances')->where('organization_id',$organizationId)->where('product_id',$item['product']->id)->where('location_id',$location->id)->lockForUpdate()->first();
                if($balance){
                    DB::table('stock_balances')->where('id',$balance->id)->update(['quantity_base'=>bcadd((string)$balance->quantity_base,$item['qtyBase'],6),'updated_at'=>now()]);
                }else{
                    DB::table('stock_balances')->insert([
                        'id'=>(string)Str::ulid(),'organization_id'=>$organizationId,'product_id'=>$item['product']->id,
                        'location_id'=>$location->id,'quantity_base'=>$item['qtyBase'],'reserved_quantity_base'=>0,'average_cost'=>$item['cost'],'updated_at'=>now(),
                    ]);
                }
                DB::table('stock_movements')->insert([
                    'id'=>(string)Str::ulid(),'organization_id'=>$organizationId,'transaction_uuid'=>(string)Str::uuid(),
                    'product_id'=>$item['product']->id,'location_id'=>$location->id,'movement_type'=>'sales_return',
                    'quantity_base'=>$item['qtyBase'],'unit_cost'=>$item['cost'],'source_document_type'=>'sales_return',
                    'source_document_id'=>$returnId,'occurred_at'=>now(),'posted_at'=>now(),'created_by'=>$data['created_by'] ?? null,
                    'device_id'=>$data['device_id'] ?? null,'trip_id'=>$data['trip_id'] ?? null,'reference'=>$documentNumber,
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
            }

            $accounts=$this->ensureLedgerAccounts($organizationId);
            $tx=(string)Str::uuid();
            $this->ledger($organizationId,$tx,$accounts['sales_returns'],$total,0,'sales_return',$returnId);
            $this->ledger($organizationId,$tx,$accounts['accounts_receivable'],0,$total,'sales_return',$returnId);
            $this->ledger($organizationId,$tx,$accounts['inventory'],$this->returnCost($items),0,'sales_return',$returnId);

            if (!empty($data['original_sales_invoice_id'])) {
                $invoice = DB::table('sales_invoices')
                    ->where('organization_id',$organizationId)
                    ->where('id',$data['original_sales_invoice_id'])
                    ->lockForUpdate()
                    ->first();
                if (!$invoice || $invoice->customer_id !== $customer->id) {
                    throw ValidationException::withMessages(['original_sales_invoice_id'=>['Original sales invoice is invalid.']]);
                }
                $returnedBefore = (string) DB::table('sales_returns')
                    ->where('organization_id',$organizationId)
                    ->where('original_sales_invoice_id',$invoice->id)
                    ->where('status','posted')
                    ->where('id','<>',$returnId)
                    ->sum('total');
                $remainingDue = bcsub(bcsub((string)$invoice->total,(string)$invoice->paid_amount,4),$returnedBefore,4);
                $newBalanceDue = bcsub($remainingDue,$total,4);
                DB::table('sales_invoices')->where('id',$invoice->id)->update([
                    'balance_due'=>$newBalanceDue,
                    'updated_at'=>now(),
                ]);
            }
            $this->ledger($organizationId,$tx,$accounts['cogs'],0,$this->returnCost($items),'sales_return',$returnId);

            $this->adjustCustomerBalanceSummary($organizationId,$customer->id,[
                'total_returns'=>$total,'outstanding'=>bcsub('0',$total,4),
            ],false);

            $result=['id'=>$returnId,'document_number'=>$documentNumber,'total'=>$total,'status'=>'posted'];
            $this->completeIdempotency($organizationId,'sales_return.post',$data['idempotency_key'] ?? null,'sales_return:'.$returnId);
            return $result;
        }, attempts:5);
    }

    public function postPurchaseReturn(string $organizationId, array $data): array
    {
        return DB::transaction(function () use ($organizationId, $data): array {
            if ($replay = $this->claimIdempotency($organizationId,'purchase_return.post',$data['idempotency_key'] ?? null,$data)) {
                return $this->replayReturn($organizationId,$replay,'purchase_return');
            }

            $supplier=DB::table('suppliers')->where('organization_id',$organizationId)->where('id',$data['supplier_id'])->where('active',true)->firstOrFail();
            $location=DB::table('locations')->where('organization_id',$organizationId)->where('id',$data['location_id'])->where('status','active')->firstOrFail();

            $returnId=(string)Str::ulid();
            $documentNumber=$this->nextDocumentNumber($organizationId,'purchase_return','PR');
            $subtotal='0.0000';
            $items=[];

            foreach($data['items'] as $line){
                $product=DB::table('products')->where('organization_id',$organizationId)->where('id',$line['product_id'])->where('active',true)->firstOrFail();
                $conversion=(string)($line['conversion_factor'] ?? 1);
                $qty=(string)$line['quantity'];
                $qtyBase=bcmul($qty,$conversion,6);
                $unitCost=(string)$line['unit_cost'];
                $lineTotal=bcmul($qty,$unitCost,4);

                $balance=DB::table('stock_balances')->where('organization_id',$organizationId)->where('product_id',$product->id)->where('location_id',$location->id)->lockForUpdate()->first();
                $available=(string)($balance->quantity_base ?? 0);
                if(bccomp($available,$qtyBase,6)<0) throw ValidationException::withMessages(['items'=>['Stock is insufficient for this purchase return.']]);

                $items[]=compact('product','conversion','qty','qtyBase','unitCost','lineTotal','balance','line');
                $subtotal=bcadd($subtotal,$lineTotal,4);
            }

            $total=bcadd(bcsub($subtotal,(string)($data['discount'] ?? 0),4),(string)($data['tax'] ?? 0),4);

            DB::table('purchase_returns')->insert([
                'id'=>$returnId,'organization_id'=>$organizationId,'supplier_id'=>$supplier->id,'location_id'=>$location->id,
                'original_purchase_invoice_id'=>$data['original_purchase_invoice_id'] ?? null,'document_number'=>$documentNumber,
                'status'=>'posted','return_date'=>$data['return_date'] ?? now()->toDateString(),'posted_at'=>now(),
                'subtotal'=>$subtotal,'discount'=>$data['discount'] ?? 0,'tax'=>$data['tax'] ?? 0,'total'=>$total,
                'currency'=>$data['currency'] ?? 'EGP','created_by'=>$data['created_by'] ?? null,'device_id'=>$data['device_id'] ?? null,
                'idempotency_key'=>$data['idempotency_key'] ?? null,'created_at'=>now(),'updated_at'=>now(),
            ]);

            foreach($items as $item){
                DB::table('purchase_return_items')->insert([
                    'id'=>(string)Str::ulid(),'organization_id'=>$organizationId,'purchase_return_id'=>$returnId,
                    'product_id'=>$item['product']->id,'original_purchase_invoice_item_id'=>$item['line']['original_purchase_invoice_item_id'] ?? null,
                    'packaging_id'=>$item['line']['packaging_id'] ?? null,'entered_quantity'=>$item['qty'],
                    'conversion_factor_snapshot'=>$item['conversion'],'quantity_base'=>$item['qtyBase'],
                    'unit_cost_base'=>$item['unitCost'],'line_total'=>$item['lineTotal'],'created_at'=>now(),'updated_at'=>now(),
                ]);

                DB::table('stock_balances')->where('id',$item['balance']->id)->update([
                    'quantity_base'=>bcsub((string)$item['balance']->quantity_base,$item['qtyBase'],6),'updated_at'=>now()
                ]);
                DB::table('stock_movements')->insert([
                    'id'=>(string)Str::ulid(),'organization_id'=>$organizationId,'transaction_uuid'=>(string)Str::uuid(),
                    'product_id'=>$item['product']->id,'location_id'=>$location->id,'movement_type'=>'purchase_return',
                    'quantity_base'=>bcsub('0',$item['qtyBase'],6),'unit_cost'=>$item['unitCost'],
                    'source_document_type'=>'purchase_return','source_document_id'=>$returnId,'occurred_at'=>now(),
                    'posted_at'=>now(),'created_by'=>$data['created_by'] ?? null,'device_id'=>$data['device_id'] ?? null,
                    'reference'=>$documentNumber,'created_at'=>now(),'updated_at'=>now(),
                ]);
            }

            $accounts=$this->ensureLedgerAccounts($organizationId);
            $tx=(string)Str::uuid();
            $this->ledger($organizationId,$tx,$accounts['accounts_payable'],$total,0,'purchase_return',$returnId);
            $this->ledger($organizationId,$tx,$accounts['inventory'],0,$this->returnCost($items,true),'purchase_return',$returnId);

            $this->adjustSupplierBalanceSummary($organizationId,$supplier->id,[
                'total_returns'=>$total,'outstanding'=>bcsub('0',$total,4),
            ],false);

            $result=['id'=>$returnId,'document_number'=>$documentNumber,'total'=>$total,'status'=>'posted'];
            $this->completeIdempotency($organizationId,'purchase_return.post',$data['idempotency_key'] ?? null,'purchase_return:'.$returnId);
            return $result;
        }, attempts:5);
    }

    private function returnCost(array $items, bool $purchase=false): string
    {
        $total='0.0000';
        foreach($items as $item){
            $cost=$purchase ? $item['unitCost'] : $item['cost'];
            $total=bcadd($total,bcmul($cost,$item['qtyBase'],4),4);
        }
        return $total;
    }

    public function postPayment(string $organizationId, array $data): array
    {
        return DB::transaction(function () use ($organizationId, $data): array {
            if ($replay = $this->claimIdempotency($organizationId, 'payment.post', $data['idempotency_key'] ?? null, $data)) {
                return $this->replayPayment($organizationId, $replay);
            }

            $partyType = $data['party_type'];
            $partyId = $data['party_id'];
            $direction = $data['direction'];
            $amount = (string) $data['amount'];

            if (!in_array($partyType, ['customer','supplier'], true)) {
                throw ValidationException::withMessages(['party_type'=>['Unsupported payment party type.']]);
            }
            if (!in_array($direction, ['inbound','outbound'], true)) {
                throw ValidationException::withMessages(['direction'=>['Unsupported payment direction.']]);
            }
            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount'=>['Payment amount must be greater than zero.']]);
            }

            $partyTable = $partyType === 'customer' ? 'customers' : 'suppliers';
            $party = DB::table($partyTable)
                ->where('organization_id',$organizationId)
                ->where('id',$partyId)
                ->first();

            if (!$party) {
                throw ValidationException::withMessages(['party_id'=>['Party was not found in this organization.']]);
            }

            $account = DB::table('financial_accounts')
                ->where('organization_id',$organizationId)
                ->where('id',$data['financial_account_id'])
                ->where('active',true)
                ->first();

            if (!$account) {
                throw ValidationException::withMessages(['financial_account_id'=>['Financial account was not found or is inactive.']]);
            }

            $method = DB::table('payment_methods')
                ->where(function ($query) use ($organizationId) {
                    $query->where('organization_id',$organizationId)->orWhereNull('organization_id');
                })
                ->where('id',$data['payment_method_id'])
                ->where('active',true)
                ->first();

            if (!$method) {
                throw ValidationException::withMessages(['payment_method_id'=>['Payment method was not found or is inactive.']]);
            }

            if ($method->requires_reference && empty($data['reference'])) {
                throw ValidationException::withMessages(['reference'=>['A reference is required for this payment method.']]);
            }

            $remaining = $amount;
            $validatedAllocations = [];
            foreach (($data['allocations'] ?? []) as $allocation) {
                $allocationAmount = (string) $allocation['amount'];
                if (bccomp($allocationAmount,'0',4) <= 0) {
                    throw ValidationException::withMessages(['allocations'=>['Allocation amount must be greater than zero.']]);
                }
                if (bccomp($allocationAmount,$remaining,4) > 0) {
                    throw ValidationException::withMessages(['allocations'=>['Allocated amount exceeds payment amount.']]);
                }

                $documentTable = match ($partyType.':'.$allocation['document_type']) {
                    'customer:sales_invoice' => 'sales_invoices',
                    'supplier:purchase_invoice' => 'purchase_invoices',
                    default => null,
                };

                if (!$documentTable) {
                    throw ValidationException::withMessages(['allocations'=>['Invalid allocation document for this party.']]);
                }

                $document = DB::table($documentTable)
                    ->where('organization_id',$organizationId)
                    ->where('id',$allocation['document_id'])
                    ->lockForUpdate()
                    ->first();

                if (!$document || ($partyType === 'customer' ? $document->customer_id !== $partyId : $document->supplier_id !== $partyId)) {
                    throw ValidationException::withMessages(['allocations'=>['Allocation document does not belong to this party.']]);
                }

                $due = $partyType === 'customer'
                    ? (string) $document->balance_due
                    : bcsub((string)$document->total,(string)$document->paid_amount,4);

                if (bccomp($allocationAmount,$due,4) > 0) {
                    throw ValidationException::withMessages(['allocations'=>['Allocation exceeds document outstanding balance.']]);
                }

                $validatedAllocations[] = [
                    'document_type'=>$allocation['document_type'],
                    'document_id'=>$document->id,
                    'amount'=>$allocationAmount,
                ];

                $remaining = bcsub($remaining,$allocationAmount,4);
            }

            $paymentId = (string) Str::ulid();
            DB::table('payments')->insert([
                'id'=>$paymentId,
                'organization_id'=>$organizationId,
                'party_type'=>$partyType,
                'party_id'=>$partyId,
                'financial_account_id'=>$account->id,
                'payment_method_id'=>$method->id,
                'direction'=>$direction,
                'amount'=>$amount,
                'currency'=>$data['currency'] ?? 'EGP',
                'payment_date'=>$data['payment_date'] ?? now()->toDateString(),
                'reference'=>$data['reference'] ?? null,
                'status'=>'posted',
                'trip_id'=>$data['trip_id'] ?? null,
                'created_by'=>$data['created_by'] ?? null,
                'device_id'=>$data['device_id'] ?? null,
                'idempotency_key'=>$data['idempotency_key'] ?? null,
                'created_at'=>now(),
                'updated_at'=>now(),
            ]);

            foreach ($validatedAllocations as $allocation) {
                $documentTable = $allocation['document_type'] === 'sales_invoice' ? 'sales_invoices' : 'purchase_invoices';
                $document = DB::table($documentTable)->where('organization_id',$organizationId)->where('id',$allocation['document_id'])->lockForUpdate()->first();

                DB::table('payment_allocations')->insert([
                    'id'=>(string)Str::ulid(),
                    'organization_id'=>$organizationId,
                    'payment_id'=>$paymentId,
                    'document_type'=>$allocation['document_type'],
                    'document_id'=>$allocation['document_id'],
                    'amount'=>$allocation['amount'],
                    'created_at'=>now(),
                    'updated_at'=>now(),
                ]);

                $newPaid = bcadd((string)$document->paid_amount,(string)$allocation['amount'],4);

                if ($allocation['document_type'] === 'sales_invoice') {
                    DB::table('sales_invoices')->where('id',$document->id)->update([
                        'paid_amount'=>$newPaid,
                        'balance_due'=>bcsub((string)$document->total,$newPaid,4),
                        'updated_at'=>now(),
                    ]);
                } else {
                    DB::table('purchase_invoices')->where('id',$document->id)->update([
                        'paid_amount'=>$newPaid,
                        'updated_at'=>now(),
                    ]);
                }
            }

            $accounts = $this->ensureLedgerAccounts($organizationId);
            $tx = (string) Str::uuid();

            if ($partyType === 'customer' && $direction === 'inbound') {
                $financialLedger = $this->ledgerAccountForFinancialAccount($organizationId, $account);
                $this->ledger($organizationId,$tx,$financialLedger, $amount,0,'payment',$paymentId);
                $this->ledger($organizationId,$tx,$accounts['accounts_receivable'],0,$amount,'payment',$paymentId);
                $this->adjustCustomerBalanceSummary($organizationId,$partyId,[
                    'total_paid'=>$amount,'outstanding'=>bcsub('0',$amount,4),
                ],false,'last_payment_at');
            } elseif ($partyType === 'supplier' && $direction === 'outbound') {
                $this->ledger($organizationId,$tx,$accounts['accounts_payable'],$amount,0,'payment',$paymentId);
                $financialLedger = $this->ledgerAccountForFinancialAccount($organizationId, $account);
                $this->ledger($organizationId,$tx,$financialLedger,0,$amount,'payment',$paymentId);
                $this->adjustSupplierBalanceSummary($organizationId,$partyId,[
                    'total_paid'=>$amount,'outstanding'=>bcsub('0',$amount,4),
                ],false,'last_payment_at');
            } else {
                throw ValidationException::withMessages(['direction'=>['Payment direction does not match party type.']]);
            }

            $result = [
                'id'=>$paymentId,
                'party_type'=>$partyType,
                'party_id'=>$partyId,
                'amount'=>$amount,
                'direction'=>$direction,
                'status'=>'posted',
            ];
            $this->completeIdempotency($organizationId,'payment.post',$data['idempotency_key'] ?? null,'payment:'.$paymentId);
            return $result;
        }, attempts:5);
    }

    private function adjustCustomerBalanceSummary(string $organizationId,string $customerId,array $deltas,bool $sale,string $timestampColumn='last_sale_at'): void
    {
        DB::table('customer_balance_summaries')->insertOrIgnore([
            'id'=>(string)Str::ulid(),'organization_id'=>$organizationId,'customer_id'=>$customerId,
            'total_sales'=>0,'total_returns'=>0,'total_paid'=>0,'outstanding'=>0,'updated_at'=>now(),
        ]);
        foreach ($deltas as $column=>$delta) {
            if (bccomp((string)$delta,'0',4) >= 0) {
                DB::table('customer_balance_summaries')->where('organization_id',$organizationId)->where('customer_id',$customerId)->increment($column,(float)$delta);
            } else {
                DB::table('customer_balance_summaries')->where('organization_id',$organizationId)->where('customer_id',$customerId)->decrement($column,(float)bcsub('0',(string)$delta,4));
            }
        }
        DB::table('customer_balance_summaries')->where('organization_id',$organizationId)->where('customer_id',$customerId)->update([
            $timestampColumn=>now(),'updated_at'=>now(),
        ]);
    }

    private function adjustSupplierBalanceSummary(string $organizationId,string $supplierId,array $deltas,bool $purchase,string $timestampColumn='last_purchase_at'): void
    {
        DB::table('supplier_balance_summaries')->insertOrIgnore([
            'id'=>(string)Str::ulid(),'organization_id'=>$organizationId,'supplier_id'=>$supplierId,
            'total_purchases'=>0,'total_returns'=>0,'total_paid'=>0,'outstanding'=>0,'updated_at'=>now(),
        ]);
        foreach ($deltas as $column=>$delta) {
            if (bccomp((string)$delta,'0',4) >= 0) {
                DB::table('supplier_balance_summaries')->where('organization_id',$organizationId)->where('supplier_id',$supplierId)->increment($column,(float)$delta);
            } else {
                DB::table('supplier_balance_summaries')->where('organization_id',$organizationId)->where('supplier_id',$supplierId)->decrement($column,(float)bcsub('0',(string)$delta,4));
            }
        }
        DB::table('supplier_balance_summaries')->where('organization_id',$organizationId)->where('supplier_id',$supplierId)->update([
            $timestampColumn=>now(),'updated_at'=>now(),
        ]);
    }

    private function claimIdempotency(string $organizationId, string $type, ?string $key, array $data): ?string
    {
        if (!$key) return null;

        $fingerprint = hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        try {
            DB::table('idempotency_keys')->insert([
                'id'=>(string) Str::ulid(),
                'organization_id'=>$organizationId,
                'operation_type'=>$type,
                'idempotency_key'=>$key,
                'request_fingerprint'=>$fingerprint,
                'status'=>'processing',
                'created_at'=>now(),
            ]);
            return null;
        } catch (QueryException $e) {
            $existing = DB::table('idempotency_keys')
                ->where('organization_id',$organizationId)
                ->where('operation_type',$type)
                ->where('idempotency_key',$key)
                ->first();

            if (!$existing) throw $e;

            if (!hash_equals($existing->request_fingerprint, $fingerprint)) {
                throw ValidationException::withMessages(['idempotency_key'=>['The same idempotency key was reused with a different request.']]);
            }

            if ($existing->status !== 'completed' || !$existing->response_reference) {
                throw ValidationException::withMessages(['idempotency_key'=>['This operation is already being processed.']]);
            }

            return $existing->response_reference;
        }
    }

    private function completeIdempotency(string $organizationId, string $type, ?string $key, string $responseReference): void
    {
        if (!$key) return;

        DB::table('idempotency_keys')
            ->where('organization_id',$organizationId)
            ->where('operation_type',$type)
            ->where('idempotency_key',$key)
            ->update([
                'status'=>'completed',
                'response_reference'=>$responseReference,
                'completed_at'=>now(),
            ]);
    }

    private function replayPurchase(string $organizationId, string $reference): array
    {
        [$type, $id] = array_pad(explode(':',$reference,2),2,null);
        if ($type !== 'purchase_invoice' || !$id) {
            throw ValidationException::withMessages(['idempotency_key'=>['Stored idempotency response is invalid.']]);
        }

        $invoice = DB::table('purchase_invoices')
            ->where('organization_id',$organizationId)->where('id',$id)->first();

        if (!$invoice) {
            throw ValidationException::withMessages(['idempotency_key'=>['Stored purchase response could not be replayed.']]);
        }

        return ['id'=>$invoice->id,'document_number'=>$invoice->document_number,'total'=>$invoice->total,'status'=>$invoice->status];
    }

    private function replayTripLoad(string $organizationId, string $reference): array
    {
        [$type,$id,$loadNumber]=array_pad(explode(':',$reference,3),3,null);
        if($type !== 'trip_load' || !$id || !$loadNumber) throw ValidationException::withMessages(['idempotency_key'=>['Stored trip load response is invalid.']]);
        $load=DB::table('trip_loads')->where('organization_id',$organizationId)->where('id',$id)->first();
        if(!$load) throw ValidationException::withMessages(['idempotency_key'=>['Stored trip load response could not be replayed.']]);
        return ['id'=>$load->id,'load_number'=>$loadNumber,'trip_id'=>$load->trip_id,'vehicle_id'=>$load->to_vehicle_id,'status'=>$load->status];
    }

    private function replayReturn(string $organizationId, string $reference, string $type): array
    {
        [$storedType,$id]=array_pad(explode(':',$reference,2),2,null);
        if($storedType !== $type || !$id) throw ValidationException::withMessages(['idempotency_key'=>['Stored return response is invalid.']]);
        $table=$type === 'sales_return' ? 'sales_returns' : 'purchase_returns';
        $row=DB::table($table)->where('organization_id',$organizationId)->where('id',$id)->first();
        if(!$row) throw ValidationException::withMessages(['idempotency_key'=>['Stored return response could not be replayed.']]);
        return ['id'=>$row->id,'document_number'=>$row->document_number,'total'=>$row->total,'status'=>$row->status];
    }

    private function replayPayment(string $organizationId, string $reference): array
    {
        [$type,$id] = array_pad(explode(':',$reference,2),2,null);
        if ($type !== 'payment' || !$id) {
            throw ValidationException::withMessages(['idempotency_key'=>['Stored idempotency response is invalid.']]);
        }
        $payment = DB::table('payments')->where('organization_id',$organizationId)->where('id',$id)->first();
        if (!$payment) {
            throw ValidationException::withMessages(['idempotency_key'=>['Stored payment response could not be replayed.']]);
        }
        return ['id'=>$payment->id,'party_type'=>$payment->party_type,'party_id'=>$payment->party_id,'amount'=>$payment->amount,'direction'=>$payment->direction,'status'=>$payment->status];
    }

    private function replaySale(string $organizationId, string $reference): array
    {
        [$type, $id] = array_pad(explode(':',$reference,2),2,null);
        if ($type !== 'sales_invoice' || !$id) {
            throw ValidationException::withMessages(['idempotency_key'=>['Stored idempotency response is invalid.']]);
        }

        $invoice = DB::table('sales_invoices')
            ->where('organization_id',$organizationId)->where('id',$id)->first();

        if (!$invoice) {
            throw ValidationException::withMessages(['idempotency_key'=>['Stored sales response could not be replayed.']]);
        }

        return [
            'id'=>$invoice->id,
            'document_number'=>$invoice->document_number,
            'total'=>$invoice->total,
            'paid_amount'=>$invoice->paid_amount,
            'balance_due'=>$invoice->balance_due,
            'status'=>$invoice->status,
        ];
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

    private function ledgerAccountForFinancialAccount(string $organizationId, object $financialAccount): string
    {
        $code = 'FA-'.$financialAccount->code;
        $existing = DB::table('ledger_accounts')
            ->where('organization_id',$organizationId)
            ->where('code',$code)
            ->first();

        if ($existing) return $existing->id;

        $id=(string)Str::ulid();
        DB::table('ledger_accounts')->insert([
            'id'=>$id,
            'organization_id'=>$organizationId,
            'code'=>$code,
            'name'=>$financialAccount->name,
            'type'=>'asset',
            'currency'=>$financialAccount->currency ?? 'EGP',
            'active'=>true,
            'created_at'=>now(),
            'updated_at'=>now(),
        ]);

        return $id;
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
            'sales_returns'=>['4100','Sales Returns','revenue'],
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