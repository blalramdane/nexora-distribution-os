<?php

namespace App\Services\Transactions;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Services\Outbox\MessageOutboxPublisher;

final class TransactionPostingService
{
    public function postPurchase(string $organizationId, array $data): array
    {
        return DB::transaction(function () use ($organizationId, $data): array {
            if ($replay = $this->claimIdempotency($organizationId, 'purchase.post', $data['idempotency_key'] ?? null, $data)) {
                return $this->replayPurchase($organizationId, $replay);
            }

            $baseCurrency = strtoupper((string) DB::table('organizations')->where('id', $organizationId)->value('default_currency'));
            $requestedCurrency = strtoupper((string) ($data['currency'] ?? $baseCurrency));
            if ($requestedCurrency !== $baseCurrency) {
                throw ValidationException::withMessages(['currency' => ['Transaction currency must match the organization base currency until foreign-exchange accounting is enabled.']]);
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

            $importDocument = null;
            if (! empty($data['import_document_id'])) {
                $importDocument = DB::table('purchase_invoice_imports')
                    ->where('organization_id', $organizationId)
                    ->where('id', $data['import_document_id'])
                    ->lockForUpdate()
                    ->first();
                if (! $importDocument || $importDocument->status !== 'needs_review' || $importDocument->purchase_invoice_id) {
                    throw ValidationException::withMessages(['import_document_id' => ['مستند الاستيراد غير موجود أو لم يكتمل فحص OCR أو تم ترحيله بالفعل.']]);
                }
            }

            $invoiceId = (string) Str::ulid();
            $documentNumber = $this->nextDocumentNumber($organizationId, 'purchase_invoice', 'PI');
            $subtotal = '0.0000';
            $invoicePayableAmount = '0.0000';
            $deferredSupplierPayableCost = '0.0000';

            $items = [];
            $createdProducts = [];
            foreach ($data['items'] as $lineIndex => $line) {
                $productId = $line['product_id'] ?? null;

                // New catalog items are created in this same transaction as the
                // purchase invoice, stock receipt and supplier ledger. If any
                // later line or posting invariant fails, the new catalog rows
                // roll back too; no orphan products are left behind.
                if ((! $productId || trim((string) $productId) === '') && isset($line['new_product']) && is_array($line['new_product'])) {
                    $newProduct = $line['new_product'];
                    $nameAr = trim((string) ($newProduct['name_ar'] ?? ''));
                    $baseUnitId = (string) ($newProduct['base_unit_id'] ?? '');
                    $categoryId = trim((string) ($newProduct['category_id'] ?? '')) ?: null;
                    $purchaseConversion = (string) ($newProduct['purchase_conversion_to_base'] ?? '1');
                    if (bccomp($purchaseConversion, '0', 6) <= 0) {
                        throw ValidationException::withMessages([
                            "items.{$lineIndex}.new_product.purchase_conversion_to_base" => ['معامل تحويل وحدة الشراء لازم يكون أكبر من صفر.'],
                        ]);
                    }
                    $purchasePackagingId = null;
                    $purchasePackagingName = trim((string) ($newProduct['purchase_packaging_name'] ?? ''));
                    $purchasePackagingUnitId = trim((string) ($newProduct['purchase_packaging_unit_id'] ?? ''));
                    $hasPurchasePackaging = bccomp($purchaseConversion, '1', 6) > 0;
                    if ($hasPurchasePackaging) {
                        if ($purchasePackagingName === '' || $purchasePackagingUnitId === '') {
                            throw ValidationException::withMessages([
                                "items.{$lineIndex}.new_product.purchase_packaging_name" => ['اكتب اسم وحدة الشراء وحدد وحدتها قبل استلام الكراتين.'],
                            ]);
                        }
                        $purchasePackagingUnit = DB::table('units')
                            ->where('id', $purchasePackagingUnitId)
                            ->where('active', true)
                            ->where(function ($query) use ($organizationId): void {
                                $query->whereNull('organization_id')->orWhere('organization_id', $organizationId);
                            })
                            ->first();
                        if (! $purchasePackagingUnit) {
                            throw ValidationException::withMessages([
                                "items.{$lineIndex}.new_product.purchase_packaging_unit_id" => ['وحدة الشراء غير موجودة أو لا تخص هذه المؤسسة.'],
                            ]);
                        }
                    }
                    $unit = DB::table('units')
                        ->where('id', $baseUnitId)
                        ->where('active', true)
                        ->where(function ($query) use ($organizationId): void {
                            $query->whereNull('organization_id')->orWhere('organization_id', $organizationId);
                        })
                        ->first();
                    if (! $unit) {
                        throw ValidationException::withMessages([
                            "items.{$lineIndex}.new_product.base_unit_id" => ['الوحدة الأساسية غير موجودة أو لا تخص هذه المؤسسة.'],
                        ]);
                    }

                    if ($categoryId !== null) {
                        $category = DB::table('categories')
                            ->where('organization_id', $organizationId)
                            ->where('id', $categoryId)
                            ->where('active', true)
                            ->first();
                        if (! $category) {
                            throw ValidationException::withMessages([
                                "items.{$lineIndex}.new_product.category_id" => ['التصنيف المختار غير موجود أو لا يخص هذه المؤسسة.'],
                            ]);
                        }
                    }

                    $sku = trim((string) ($newProduct['sku'] ?? ''));
                    if ($sku === '') {
                        do {
                            $sku = 'NEW-'.Str::upper(Str::random(12));
                        } while (DB::table('products')->where('organization_id', $organizationId)->where('sku', $sku)->exists());
                    } elseif (DB::table('products')->where('organization_id', $organizationId)->where('sku', $sku)->exists()) {
                        throw ValidationException::withMessages([
                            "items.{$lineIndex}.new_product.sku" => ['الكود الداخلي مستخدم بالفعل. ابحث عنه واختر الصنف الموجود، أو أدخل كودًا مختلفًا.'],
                        ]);
                    }

                    if ($nameAr === '') {
                        throw ValidationException::withMessages([
                            "items.{$lineIndex}.new_product.name_ar" => ['اسم الصنف الجديد مطلوب.'],
                        ]);
                    }

                    $productId = (string) Str::ulid();
                    $unitCostForNewProduct = (string) ($line['unit_cost'] ?? 0);
                    $baseUnitCostForNewProduct = bcdiv($unitCostForNewProduct, $purchaseConversion, 4);
                    DB::table('products')->insert([
                        'id' => $productId,
                        'organization_id' => $organizationId,
                        'category_id' => $categoryId,
                        'base_unit_id' => $baseUnitId,
                        'sku' => $sku,
                        'name_ar' => $nameAr,
                        'name_en' => trim((string) ($newProduct['name_en'] ?? '')) ?: null,
                        'brand' => trim((string) ($newProduct['brand'] ?? '')) ?: null,
                        'default_cost' => $baseUnitCostForNewProduct,
                        'default_piece_price' => $newProduct['default_piece_price'] ?? 0,
                        'active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    // New invoice products can arrive in cartons while stock is
                    // counted in pieces. Persist the supplier pack inside the
                    // same transaction, then post the invoice line in that pack
                    // so quantity, extended cost and base cost stay consistent.
                    if ($hasPurchasePackaging) {
                        $purchasePackagingId = (string) Str::ulid();
                        DB::table('product_packagings')->insert([
                            'id' => $purchasePackagingId,
                            'organization_id' => $organizationId,
                            'product_id' => $productId,
                            'unit_id' => $purchasePackagingUnitId,
                            'name_ar' => mb_substr($purchasePackagingName, 0, 255),
                            'conversion_to_base' => $purchaseConversion,
                            'sale_price' => null,
                            'purchase_price' => $unitCostForNewProduct,
                            'is_default_sale_unit' => false,
                            'is_default_purchase_unit' => true,
                            'active' => true,
                            'effective_from' => null,
                            'effective_to' => null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    $supplierSku = trim((string) ($newProduct['supplier_sku'] ?? '')) ?: null;
                    $supplierName = trim((string) ($newProduct['supplier_name'] ?? '')) ?: $nameAr;
                    DB::table('supplier_products')->insert([
                        'id' => (string) Str::ulid(),
                        'organization_id' => $organizationId,
                        'supplier_id' => $supplier->id,
                        'product_id' => $productId,
                        'supplier_sku' => $supplierSku,
                        'supplier_name' => mb_substr($supplierName, 0, 255),
                        'preferred_packaging_id' => $purchasePackagingId,
                        'last_cost' => $baseUnitCostForNewProduct,
                        'last_purchase_at' => now(),
                        'active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    // Save supplier's invoice description as an alias so future
                    // OCR imports can suggest this product when names differ.
                    $normalizedAlias = mb_strtolower(preg_replace('/\s+/u', ' ', $supplierName) ?? $supplierName);
                    $normalizedProductName = mb_strtolower(preg_replace('/\s+/u', ' ', $nameAr) ?? $nameAr);
                    if ($normalizedAlias !== '' && $normalizedAlias !== $normalizedProductName) {
                        DB::table('product_aliases')->insert([
                            'id' => (string) Str::ulid(),
                            'organization_id' => $organizationId,
                            'product_id' => $productId,
                            'alias' => mb_substr($supplierName, 0, 255),
                            'normalized_alias' => mb_substr($normalizedAlias, 0, 255),
                            'source' => 'purchase_invoice_import',
                            'active' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    $createdProducts[] = ['id' => $productId, 'sku' => $sku, 'name_ar' => $nameAr];
                    $line['product_id'] = $productId;
                    $line['unit_id'] = $hasPurchasePackaging ? $purchasePackagingUnitId : $baseUnitId;
                    $line['packaging_id'] = $purchasePackagingId;
                }

                if (! $productId || trim((string) $productId) === '') {
                    throw ValidationException::withMessages([
                        "items.{$lineIndex}.product_id" => ['اختار صنفًا موجودًا أو أدخل بيانات الصنف الجديد.'],
                    ]);
                }

                $product = DB::table('products')
                    ->where('organization_id', $organizationId)
                    ->where('id', $productId)
                    ->where('active', true)
                    ->firstOrFail();

                $packagingId = $line['packaging_id'] ?? null;
                $packaging = null;
                if ($packagingId !== null) {
                    $packaging = DB::table('product_packagings')
                        ->where('organization_id', $organizationId)
                        ->where('product_id', $product->id)
                        ->where('id', $packagingId)
                        ->where('active', true)
                        ->first();

                    if (! $packaging) {
                        throw ValidationException::withMessages(['items' => ['The selected packaging is not active for this product and organization.']]);
                    }
                }

                $unitId = $line['unit_id'] ?? ($packaging->unit_id ?? $product->base_unit_id);
                $unit = DB::table('units')
                    ->where('id', $unitId)
                    ->where('active', true)
                    ->where(function ($query) use ($organizationId): void {
                        $query->whereNull('organization_id')->orWhere('organization_id', $organizationId);
                    })
                    ->first();

                if (! $unit) {
                    throw ValidationException::withMessages(['items' => ['The selected unit is not active in this organization.']]);
                }
                if ($packaging && $unitId !== $packaging->unit_id) {
                    throw ValidationException::withMessages(['items' => ['The selected unit does not match the product packaging.']]);
                }
                if (! $packaging && $unitId !== $product->base_unit_id) {
                    throw ValidationException::withMessages(['items' => ['A non-base unit must use a valid product packaging.']]);
                }

                // Packaging conversion is server-authoritative. A client cannot
                // change stock quantities by submitting a different factor.
                $conversion = $packaging
                    ? (string) $packaging->conversion_to_base
                    : '1';
                $enteredQty = (string) $line['quantity'];
                $quantityBase = bcmul($enteredQty, $conversion, 6);
                $unitCost = (string) $line['unit_cost'];
                $lineTotal = bcmul($enteredQty, $unitCost, 4);
                $subtotal = bcadd($subtotal, $lineTotal, 4);

                if (bccomp($quantityBase, '0', 6) <= 0 || bccomp($conversion, '0', 6) <= 0 || bccomp($unitCost, '0', 4) < 0) {
                    throw ValidationException::withMessages(['items' => ['Invalid purchase quantity, conversion or cost.']]);
                }

                $supplierProduct = DB::table('supplier_products')
                    ->where('organization_id', $organizationId)
                    ->where('supplier_id', $supplier->id)
                    ->where('product_id', $product->id)
                    ->where('active', true)
                    ->first(['settlement_method', 'settlement_unit_price']);
                $settlementMethod = (string) ($supplierProduct->settlement_method ?? 'purchase_debt');
                if (! in_array($settlementMethod, ['purchase_debt', 'sold_goods'], true)) {
                    throw ValidationException::withMessages(["items.{$lineIndex}.product_id" => ['سياسة تسوية المورد للصنف غير صالحة.']]);
                }
                $settlementUnitPrice = $supplierProduct?->settlement_unit_price !== null
                    ? (string) $supplierProduct->settlement_unit_price
                    : null;
                if ($settlementUnitPrice !== null && bccomp($settlementUnitPrice, '0.0000', 4) < 0) {
                    throw ValidationException::withMessages(["items.{$lineIndex}.product_id" => ['سعر التسوية المتفق عليه لا يمكن أن يكون سالبًا.']]);
                }
                $linePayableAmount = $settlementMethod === 'purchase_debt' ? $lineTotal : '0.0000';
                $invoicePayableAmount = bcadd($invoicePayableAmount, $linePayableAmount, 4);
                if ($settlementMethod === 'sold_goods') {
                    $deferredSupplierPayableCost = bcadd(
                        $deferredSupplierPayableCost,
                        bcmul(bcdiv($unitCost, $conversion, 4), $quantityBase, 4),
                        4
                    );
                }

                $invoiceItemId = (string) Str::ulid();
                $items[] = compact('product','conversion','enteredQty','quantityBase','unitCost','lineTotal','linePayableAmount','settlementMethod','settlementUnitPrice','packagingId','unitId','invoiceItemId');
            }

            $discount = (string) ($data['discount'] ?? 0);
            $tax = (string) ($data['tax'] ?? 0);
            $this->validateHeaderAdjustments('purchase', $discount, $tax);
            if (bccomp($discount, $subtotal, 4) > 0) {
                throw ValidationException::withMessages(['discount' => ['Discount cannot exceed the invoice subtotal.']]);
            }
            $total = bcadd(bcsub($subtotal, $discount, 4), $tax, 4);

            DB::table('purchase_invoices')->insert([
                'id'=>$invoiceId,'organization_id'=>$organizationId,'supplier_id'=>$supplier->id,
                'location_id'=>$location->id,'document_number'=>$documentNumber,
                'supplier_invoice_number'=>$data['supplier_invoice_number'] ?? null,'status'=>'posted',
                'invoice_date'=>$data['invoice_date'] ?? now()->toDateString(),'posted_at'=>now(),
                'subtotal'=>$subtotal,'discount'=>$data['discount'] ?? 0,'tax'=>$data['tax'] ?? 0,
                'total'=>$total,'payable_amount'=>$invoicePayableAmount,'paid_amount'=>0,'currency'=>$baseCurrency,
                'notes'=>$data['notes'] ?? null,'created_by'=>$data['created_by'] ?? null,
                'device_id'=>$data['device_id'] ?? null,'idempotency_key'=>$data['idempotency_key'] ?? null,
                'created_at'=>now(),'updated_at'=>now(),
            ]);

            $costOfInventory = '0.0000';
            foreach ($items as $item) {
                DB::table('purchase_invoice_items')->insert([
                    'id'=>$item['invoiceItemId'],'organization_id'=>$organizationId,
                    'purchase_invoice_id'=>$invoiceId,'product_id'=>$item['product']->id,
                    'packaging_id'=>$item['packagingId'],'entered_unit_id'=>$item['unitId'],
                    'entered_quantity'=>$item['enteredQty'],'conversion_factor_snapshot'=>$item['conversion'],
                    'quantity_base'=>$item['quantityBase'],'unit_cost_entered'=>$item['unitCost'],
                    'unit_cost_base'=>bcdiv($item['unitCost'],$item['conversion'],4),
                    'discount'=>0,'tax'=>0,'line_total'=>$item['lineTotal'],'payable_amount'=>$item['linePayableAmount'],
                    'settlement_method_snapshot'=>$item['settlementMethod'],
                    'settlement_unit_price_snapshot'=>$item['settlementUnitPrice'],
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
                $this->ensureSourceLayerCoverage($organizationId, $item['product']->id, $location->id, $oldQty, $oldAvg);
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

                $movementId = (string) Str::ulid();
                DB::table('stock_movements')->insert([
                    'id'=>$movementId,'organization_id'=>$organizationId,
                    'transaction_uuid'=>(string) Str::uuid(),'product_id'=>$item['product']->id,
                    'location_id'=>$location->id,'movement_type'=>'purchase_receipt',
                    'quantity_base'=>$item['quantityBase'],'unit_cost'=>$unitCostBase,
                    'source_document_type'=>'purchase_invoice','source_document_id'=>$invoiceId,
                    'occurred_at'=>now(),'posted_at'=>now(),'created_by'=>$data['created_by'] ?? null,
                    'device_id'=>$data['device_id'] ?? null,'reference'=>$documentNumber,
                    'created_at'=>now(),'updated_at'=>now(),
                ]);

                $this->createSourceLayer(
                    $organizationId,
                    $item['product']->id,
                    $location->id,
                    $invoiceId,
                    $item['invoiceItemId'],
                    null,
                    'purchase_receipt',
                    'purchase_invoice',
                    $invoiceId,
                    $item['quantityBase'],
                    $unitCostBase
                );

                $costOfInventory = bcadd($costOfInventory, $incomingCost, 4);
            }

            $accounts = $this->ensureLedgerAccounts($organizationId);
            $tx = (string) Str::uuid();
            $this->ledger($organizationId,$tx,$accounts['inventory'],$costOfInventory,0,'purchase_invoice',$invoiceId);
            if (bccomp($invoicePayableAmount, '0.0000', 4) > 0) {
                $this->ledger($organizationId,$tx,$accounts['accounts_payable'],0,$invoicePayableAmount,'purchase_invoice',$invoiceId);
            }
            if (bccomp($deferredSupplierPayableCost, '0.0000', 4) > 0) {
                $this->ledger($organizationId,$tx,$accounts['deferred_supplier_payables'],0,$deferredSupplierPayableCost,'purchase_invoice',$invoiceId);
            }

            $this->adjustSupplierBalanceSummary($organizationId,$supplier->id,[
                'total_purchases'=>$invoicePayableAmount,'outstanding'=>$invoicePayableAmount,
            ],true);

            if ($importDocument) {
                DB::table('purchase_invoice_imports')
                    ->where('organization_id', $organizationId)
                    ->where('id', $importDocument->id)
                    ->update([
                        'status' => 'posted',
                        'purchase_invoice_id' => $invoiceId,
                        'reviewed_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            $result = [
                'id'=>$invoiceId,'document_number'=>$documentNumber,'total'=>$total,
                'payable_amount'=>$invoicePayableAmount,'deferred_supplier_payable_cost'=>$deferredSupplierPayableCost,
                'status'=>'posted','created_products'=>$createdProducts,
            ];
            $this->completeIdempotency($organizationId, 'purchase.post', $data['idempotency_key'] ?? null, 'purchase_invoice:'.$invoiceId);
            $this->publishDomainEvent($organizationId, 'purchase.invoice.posted', 'purchase_invoice', $invoiceId, [
                'document_number' => $documentNumber, 'supplier_id' => $supplier->id,
                'total' => $total, 'payable_amount' => $invoicePayableAmount, 'currency' => $baseCurrency,
                'created_product_count' => count($createdProducts),
            ]);
            return $result;
        }, attempts: 5);
    }

    public function postStockAdjustment(string $organizationId, array $data): array
    {
        return DB::transaction(function () use ($organizationId, $data): array {
            if ($replay = $this->claimIdempotency($organizationId, 'stock.adjust', $data['idempotency_key'] ?? null, $data)) {
                [$type, $id] = array_pad(explode(':', $replay, 2), 2, null);
                if ($type !== 'stock_adjustment' || !$id) {
                    throw ValidationException::withMessages(['idempotency_key' => ['Stored stock adjustment response is invalid.']]);
                }
                $movement = DB::table('stock_movements')->where('organization_id', $organizationId)->where('id', $id)->first();
                if (!$movement) throw ValidationException::withMessages(['idempotency_key' => ['Stored stock adjustment could not be replayed.']]);
                return ['id'=>$movement->id,'product_id'=>$movement->product_id,'location_id'=>$movement->location_id,'quantity_delta'=>$movement->quantity_base,'status'=>'posted'];
            }

            $location = DB::table('locations')->where('organization_id',$organizationId)->where('id',$data['location_id'])->where('status','active')->firstOrFail();
            $product = DB::table('products')->where('organization_id',$organizationId)->where('id',$data['product_id'])->where('active',true)->firstOrFail();
            $trip = null;
            if (! empty($data['trip_id'])) {
                $trip = DB::table('trips')->where('organization_id', $organizationId)->where('id', $data['trip_id'])->lockForUpdate()->first();
                if (! $trip || in_array($trip->status, ['completed', 'cancelled'], true)) {
                    throw ValidationException::withMessages(['trip_id' => ['لا يمكن ترحيل تسوية مخزون إلى رحلة مغلقة أو غير موجودة.']]);
                }
                $vehicle = DB::table('vehicles')->where('organization_id', $organizationId)->where('id', $trip->vehicle_id)->where('active', true)->lockForUpdate()->first();
                if (! $vehicle || $vehicle->location_id !== $location->id) {
                    throw ValidationException::withMessages(['location_id' => ['تسوية مخزون الرحلة يجب أن تخص مخزن العربية المسندة إليها.']]);
                }
                $this->captureTripOpeningStockSnapshot($organizationId, $trip, $vehicle, $data['created_by'] ?? null);
            }
            $delta = (string)$data['quantity_delta'];
            $balance = DB::table('stock_balances')->where('organization_id',$organizationId)->where('product_id',$product->id)->where('location_id',$location->id)->lockForUpdate()->first();
            $oldQty = (string)($balance->quantity_base ?? 0);
            $newQty = bcadd($oldQty,$delta,6);
            if (bccomp($newQty,'0',6) < 0) throw ValidationException::withMessages(['quantity_delta'=>['Stock adjustment cannot make the balance negative.']]);
            $averageCost = (string)($balance->average_cost ?? $product->default_cost ?? 0);
            if (bccomp($delta, '0', 6) === 0) throw ValidationException::withMessages(['quantity_delta'=>['Stock adjustment must change the quantity.']]);
            $movementId=(string)Str::ulid();
            $documentNumber=$this->nextDocumentNumber($organizationId,'stock_adjustment','SA');
            $sourceDocumentType=(string)($data['source_document_type'] ?? 'stock_adjustment');
            $sourceDocumentId=(string)($data['source_document_id'] ?? $movementId);
            DB::table('stock_movements')->insert([
                'id'=>$movementId,'organization_id'=>$organizationId,'transaction_uuid'=>(string)Str::uuid(),'product_id'=>$product->id,'location_id'=>$location->id,
                'movement_type'=>'stock_adjustment','quantity_base'=>$delta,'unit_cost'=>$averageCost,'source_document_type'=>$sourceDocumentType,'source_document_id'=>$sourceDocumentId,
                'occurred_at'=>now(),'posted_at'=>now(),'created_by'=>$data['created_by'] ?? null,'device_id'=>$data['device_id'] ?? null,'trip_id'=>$trip?->id,'reference'=>$documentNumber,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            if (bccomp($delta, '0', 6) > 0) {
                $this->ensureSourceLayerCoverage($organizationId, $product->id, $location->id, $oldQty, $averageCost);
                $this->createSourceLayer($organizationId, $product->id, $location->id, null, null, null, 'stock_adjustment_in', 'stock_adjustment', $movementId, $delta, $averageCost);
            } else {
                $this->consumeSourceLayers(
                    $organizationId, $product->id, $location->id, $oldQty,
                    bcsub('0', $delta, 6), $movementId, 'stock_adjustment', $movementId,
                    null, 'adjustment_out', null, $averageCost
                );
            }
            if ($balance) {
                DB::table('stock_balances')->where('id',$balance->id)->update(['quantity_base'=>$newQty,'updated_at'=>now()]);
            } else {
                DB::table('stock_balances')->insert(['id'=>(string)Str::ulid(),'organization_id'=>$organizationId,'product_id'=>$product->id,'location_id'=>$location->id,'quantity_base'=>$newQty,'reserved_quantity_base'=>0,'average_cost'=>$averageCost,'updated_at'=>now()]);
            }
            DB::table('stock_movements')->where('id',$movementId)->update(['reference'=>substr($documentNumber.' | '.$data['reason'],0,255),'updated_at'=>now()]);
            $result=['id'=>$movementId,'document_number'=>$documentNumber,'product_id'=>$product->id,'location_id'=>$location->id,'quantity_delta'=>$delta,'balance_after'=>$newQty,'status'=>'posted'];
            $this->completeIdempotency($organizationId,'stock.adjust',$data['idempotency_key'] ?? null,'stock_adjustment:'.$movementId);
            $this->publishDomainEvent($organizationId, 'inventory.stock_adjustment.posted', 'stock_adjustment', $movementId, [
                'document_number' => $documentNumber, 'product_id' => $product->id, 'location_id' => $location->id,
                'quantity_delta' => $delta, 'balance_after' => $newQty, 'trip_id' => $trip?->id,
                'source_document_type' => $data['source_document_type'] ?? 'stock_adjustment',
                'source_document_id' => $data['source_document_id'] ?? $movementId,
            ]);
            return $result;
        }, attempts: 5);
    }

    /**
     * Transfer stock between two active locations without changing company-wide
     * quantity/value or creating a new purchase/sale. Both movement rows and the
     * source-lot trail are committed atomically under one idempotency key.
     */
    public function postStockTransfer(string $organizationId, array $data): array
    {
        return DB::transaction(function () use ($organizationId, $data): array {
            if ($replay = $this->claimIdempotency($organizationId, 'stock.transfer', $data['idempotency_key'] ?? null, $data)) {
                return $this->replayStockTransfer($organizationId, $replay);
            }

            $from = DB::table('locations')->where('organization_id', $organizationId)
                ->where('id', $data['from_location_id'])->where('status', 'active')->lockForUpdate()->first();
            $to = DB::table('locations')->where('organization_id', $organizationId)
                ->where('id', $data['to_location_id'])->where('status', 'active')->lockForUpdate()->first();
            if (! $from || ! $to) {
                throw ValidationException::withMessages(['locations' => ['اختار مخزنين نشطين تابعين لنفس المؤسسة.']]);
            }
            if ($from->id === $to->id) {
                throw ValidationException::withMessages(['to_location_id' => ['مخزن الصرف ومخزن الاستلام لازم يكونوا مختلفين.']]);
            }

            $transferId = (string) Str::ulid();
            $documentNumber = $this->nextDocumentNumber($organizationId, 'stock_transfer', 'ST');
            $reason = mb_substr(trim((string) $data['reason']), 0, 255);
            DB::table('stock_transfers')->insert([
                'id' => $transferId,
                'organization_id' => $organizationId,
                'document_number' => $documentNumber,
                'from_location_id' => $from->id,
                'to_location_id' => $to->id,
                'status' => 'posted',
                'reason' => $reason,
                'idempotency_key' => (string) $data['idempotency_key'],
                'created_by' => $data['created_by'] ?? null,
                'posted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $postedItems = [];
            $totalQuantity = '0.000000';
            foreach ($data['items'] as $index => $line) {
                $product = DB::table('products')->where('organization_id', $organizationId)
                    ->where('id', $line['product_id'])->where('active', true)->first();
                if (! $product) {
                    throw ValidationException::withMessages(["items.{$index}.product_id" => ['الصنف غير نشط أو لا يتبع المؤسسة.']]);
                }
                $qty = bcadd((string) $line['quantity_base'], '0', 6);
                if (bccomp($qty, '0.000000', 6) <= 0) {
                    throw ValidationException::withMessages(["items.{$index}.quantity_base" => ['كمية التحويل لازم تكون أكبر من صفر.']]);
                }

                $sourceBalance = DB::table('stock_balances')->where('organization_id', $organizationId)
                    ->where('product_id', $product->id)->where('location_id', $from->id)->lockForUpdate()->first();
                $sourceQty = (string) ($sourceBalance->quantity_base ?? '0.000000');
                if (bccomp($sourceQty, $qty, 6) < 0) {
                    throw ValidationException::withMessages([
                        "items.{$index}.quantity_base" => ["رصيد {$product->name_ar} في {$from->name} أقل من كمية التحويل المطلوبة."],
                    ]);
                }

                $destinationBalance = DB::table('stock_balances')->where('organization_id', $organizationId)
                    ->where('product_id', $product->id)->where('location_id', $to->id)->lockForUpdate()->first();
                $destinationQty = (string) ($destinationBalance->quantity_base ?? '0.000000');
                $sourceCost = (string) ($sourceBalance->average_cost ?? $product->default_cost ?? '0.0000');
                $transactionUuid = (string) Str::uuid();
                $outMovementId = (string) Str::ulid();
                $inMovementId = (string) Str::ulid();
                $reference = mb_substr($documentNumber.' | '.$reason, 0, 255);

                DB::table('stock_movements')->insert([
                    'id' => $outMovementId, 'organization_id' => $organizationId,
                    'transaction_uuid' => $transactionUuid, 'product_id' => $product->id,
                    'location_id' => $from->id, 'movement_type' => 'transfer_out',
                    'quantity_base' => bcsub('0.000000', $qty, 6), 'unit_cost' => $sourceCost,
                    'source_document_type' => 'stock_transfer', 'source_document_id' => $transferId,
                    'occurred_at' => now(), 'posted_at' => now(), 'created_by' => $data['created_by'] ?? null,
                    'reference' => $reference, 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('stock_movements')->insert([
                    'id' => $inMovementId, 'organization_id' => $organizationId,
                    'transaction_uuid' => $transactionUuid, 'product_id' => $product->id,
                    'location_id' => $to->id, 'movement_type' => 'transfer_in',
                    'quantity_base' => $qty, 'unit_cost' => $sourceCost,
                    'source_document_type' => 'stock_transfer', 'source_document_id' => $transferId,
                    'occurred_at' => now(), 'posted_at' => now(), 'created_by' => $data['created_by'] ?? null,
                    'reference' => $reference, 'created_at' => now(), 'updated_at' => now(),
                ]);

                // Move the lot genealogy with the units so source-purchase trace
                // and supplier sell-through remain correct after a warehouse transfer.
                $this->ensureSourceLayerCoverage($organizationId, $product->id, $from->id, $sourceQty, $sourceCost);
                $this->ensureSourceLayerCoverage($organizationId, $product->id, $to->id, $destinationQty, (string) ($destinationBalance->average_cost ?? '0.0000'));
                $layers = DB::table('stock_source_layers')->where('organization_id', $organizationId)
                    ->where('product_id', $product->id)->where('location_id', $from->id)
                    ->whereRaw('quantity_remaining_base > 0')->orderBy('created_at')->orderBy('id')->lockForUpdate()->get();
                $remainingToMove = $qty;
                foreach ($layers as $layer) {
                    if (bccomp($remainingToMove, '0.000000', 6) <= 0) break;
                    $availableLayerQty = (string) $layer->quantity_remaining_base;
                    $take = bccomp($availableLayerQty, $remainingToMove, 6) <= 0 ? $availableLayerQty : $remainingToMove;
                    if (bccomp($take, '0.000000', 6) <= 0) continue;
                    DB::table('stock_source_layers')->where('organization_id', $organizationId)->where('id', $layer->id)
                        ->update(['quantity_remaining_base' => bcsub($availableLayerQty, $take, 6), 'updated_at' => now()]);
                    $destinationLayerId = $this->createSourceLayer(
                        $organizationId, $product->id, $to->id,
                        $layer->source_purchase_invoice_id, $layer->source_purchase_invoice_item_id, $layer->id,
                        'stock_transfer', 'stock_transfer', $transferId, $take, (string) $layer->unit_cost_base
                    );
                    DB::table('stock_source_allocations')->insert([
                        'id' => (string) Str::ulid(), 'organization_id' => $organizationId,
                        'source_layer_id' => $layer->id, 'destination_layer_id' => $destinationLayerId,
                        'stock_movement_id' => $outMovementId, 'reverses_allocation_id' => null,
                        'source_purchase_invoice_id' => $layer->source_purchase_invoice_id,
                        'source_purchase_invoice_item_id' => $layer->source_purchase_invoice_item_id,
                        'source_document_type' => 'stock_transfer', 'source_document_id' => $transferId,
                        'source_document_item_id' => null, 'allocation_type' => 'transfer',
                        'quantity_base' => $take, 'unit_cost_base' => $layer->unit_cost_base,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $remainingToMove = bcsub($remainingToMove, $take, 6);
                }
                if (bccomp($remainingToMove, '0.000000', 6) > 0) {
                    throw ValidationException::withMessages(['inventory' => ['تعذر مطابقة طبقات مصدر البضاعة مع الرصيد. أوقفنا التحويل لحماية التتبع.']]);
                }

                $sourceAfter = bcsub($sourceQty, $qty, 6);
                DB::table('stock_balances')->where('id', $sourceBalance->id)
                    ->update(['quantity_base' => $sourceAfter, 'updated_at' => now()]);
                $destinationAfter = bcadd($destinationQty, $qty, 6);
                $destinationValue = bcadd(
                    bcmul($destinationQty, (string) ($destinationBalance->average_cost ?? '0.0000'), 4),
                    bcmul($qty, $sourceCost, 4), 4
                );
                $destinationAverage = bccomp($destinationAfter, '0.000000', 6) === 0
                    ? '0.0000' : bcdiv($destinationValue, $destinationAfter, 4);
                if ($destinationBalance) {
                    DB::table('stock_balances')->where('id', $destinationBalance->id)->update([
                        'quantity_base' => $destinationAfter, 'average_cost' => $destinationAverage, 'updated_at' => now(),
                    ]);
                } else {
                    DB::table('stock_balances')->insert([
                        'id' => (string) Str::ulid(), 'organization_id' => $organizationId,
                        'product_id' => $product->id, 'location_id' => $to->id,
                        'quantity_base' => $destinationAfter, 'reserved_quantity_base' => 0,
                        'average_cost' => $destinationAverage, 'updated_at' => now(),
                    ]);
                }
                $itemId = (string) Str::ulid();
                DB::table('stock_transfer_items')->insert([
                    'id' => $itemId, 'organization_id' => $organizationId, 'stock_transfer_id' => $transferId,
                    'product_id' => $product->id, 'quantity_base' => $qty, 'unit_cost' => $sourceCost,
                    'outbound_movement_id' => $outMovementId, 'inbound_movement_id' => $inMovementId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $postedItems[] = ['product_id' => $product->id, 'sku' => $product->sku, 'name_ar' => $product->name_ar,
                    'quantity_base' => (float) $qty, 'unit_cost' => (float) $sourceCost,
                    'source_balance_after' => (float) $sourceAfter, 'destination_balance_after' => (float) $destinationAfter];
                $totalQuantity = bcadd($totalQuantity, $qty, 6);
            }

            $result = ['id' => $transferId, 'document_number' => $documentNumber, 'status' => 'posted',
                'from_location_id' => $from->id, 'from_location_name' => $from->name,
                'to_location_id' => $to->id, 'to_location_name' => $to->name,
                'reason' => $reason, 'total_quantity_base' => (float) $totalQuantity, 'items' => $postedItems];
            $this->completeIdempotency($organizationId, 'stock.transfer', $data['idempotency_key'] ?? null,
                'stock_transfer:'.$transferId.':'.$documentNumber);
            $this->publishDomainEvent($organizationId, 'inventory.stock_transfer.posted', 'stock_transfer', $transferId, [
                'document_number' => $documentNumber, 'from_location_id' => $from->id, 'to_location_id' => $to->id,
                'total_quantity_base' => $totalQuantity, 'item_count' => count($postedItems),
            ]);
            return $result;
        }, attempts: 5);
    }

    public function postSale(string $organizationId, array $data): array
    {
        return DB::transaction(function () use ($organizationId, $data): array {
            if ($replay = $this->claimIdempotency($organizationId, 'sale.post', $data['idempotency_key'] ?? null, $data)) {
                return $this->replaySale($organizationId, $replay);
            }

            $isFieldRep = ! empty($data['created_by']) && DB::table('user_roles as ur')
                ->join('roles as r', function ($join): void {
                    $join->on('r.id', '=', 'ur.role_id')->on('r.organization_id', '=', 'ur.organization_id');
                })
                ->where('ur.organization_id', $organizationId)
                ->where('ur.user_id', $data['created_by'])
                ->where('r.key', 'field_rep')
                ->exists();
            if ($isFieldRep) {
                $paidAtSale = (string) ($data['paid_amount'] ?? '0');
                if (! is_numeric($paidAtSale) || abs((float) $paidAtSale - round((float) $paidAtSale)) > 0.000001) {
                    throw ValidationException::withMessages([
                        'paid_amount' => ['المبلغ المدفوع وقت البيع الميداني لازم يكون بالجنيه الصحيح من غير كسور.'],
                    ]);
                }
                foreach ($data['items'] as $index => $line) {
                    foreach (['quantity' => 'كمية البيع', 'unit_price' => 'سعر البيع للعميل'] as $field => $label) {
                        $value = $line[$field] ?? null;
                        if (! is_numeric($value) || abs((float) $value - round((float) $value)) > 0.000001) {
                            throw ValidationException::withMessages([
                                "items.{$index}.{$field}" => ["{$label} لازم يكون رقمًا صحيحًا من غير كسور في البيع الميداني."],
                            ]);
                        }
                    }
                }
            }

            $baseCurrency = strtoupper((string) DB::table('organizations')->where('id', $organizationId)->value('default_currency'));
            $requestedCurrency = strtoupper((string) ($data['currency'] ?? $baseCurrency));
            if ($requestedCurrency !== $baseCurrency) {
                throw ValidationException::withMessages(['currency' => ['Transaction currency must match the organization base currency until foreign-exchange accounting is enabled.']]);
            }

            $customer = DB::table('customers')
                ->where('organization_id',$organizationId)->where('id',$data['customer_id'])
                ->where('status','active')->firstOrFail();
            $location = DB::table('locations')
                ->where('organization_id',$organizationId)->where('id',$data['location_id'])
                ->where('status','active')->firstOrFail();

            if (!empty($data['trip_id'])) {
                $trip = DB::table('trips')->where('organization_id', $organizationId)->where('id', $data['trip_id'])->lockForUpdate()->first();
                if (!$trip || in_array($trip->status, ['completed', 'cancelled'], true)) {
                    throw ValidationException::withMessages(['trip_id' => ['The selected trip is missing or closed.']]);
                }
                $vehicle = DB::table('vehicles')->where('organization_id', $organizationId)->where('id', $trip->vehicle_id)->where('active', true)->lockForUpdate()->first();
                $vehicleLocationId = $vehicle?->location_id;
                if ($vehicleLocationId !== $location->id) {
                    throw ValidationException::withMessages(['location_id' => ['A trip sale must use the selected trip vehicle location.']]);
                }
                $this->captureTripOpeningStockSnapshot($organizationId, $trip, $vehicle, $data['created_by'] ?? null);
            }

            $invoiceId=(string) Str::ulid();
            $documentNumber=$this->nextDocumentNumber($organizationId,'sales_invoice','SI');
            $subtotal='0.0000';
            $cogs='0.0000';
            $items=[];
            $requiredByProduct=[];

            foreach($data['items'] as $line){
                $product=DB::table('products')->where('organization_id',$organizationId)->where('id',$line['product_id'])->where('active',true)->firstOrFail();
                $packagingId = $line['packaging_id'] ?? null;
                $packaging = null;
                if ($packagingId !== null) {
                    $packaging = DB::table('product_packagings')
                        ->where('organization_id', $organizationId)
                        ->where('product_id', $product->id)
                        ->where('id', $packagingId)
                        ->where('active', true)
                        ->first();

                    if (! $packaging) {
                        throw ValidationException::withMessages(['items' => ['The selected packaging is not active for this product and organization.']]);
                    }
                }

                $unitId = $line['unit_id'] ?? ($packaging->unit_id ?? $product->base_unit_id);
                $unit = DB::table('units')
                    ->where('id', $unitId)
                    ->where('active', true)
                    ->where(function ($query) use ($organizationId): void {
                        $query->whereNull('organization_id')->orWhere('organization_id', $organizationId);
                    })
                    ->first();

                if (! $unit) {
                    throw ValidationException::withMessages(['items' => ['The selected unit is not active in this organization.']]);
                }
                if ($packaging && $unitId !== $packaging->unit_id) {
                    throw ValidationException::withMessages(['items' => ['The selected unit does not match the product packaging.']]);
                }
                if (! $packaging && $unitId !== $product->base_unit_id) {
                    throw ValidationException::withMessages(['items' => ['A non-base unit must use a valid product packaging.']]);
                }

                // Conversion is server-authoritative for sales as it is for
                // purchasing; client factors must never control stock movement.
                $conversion = $packaging ? (string) $packaging->conversion_to_base : '1';
                $enteredQty=(string)$line['quantity'];
                $quantityBase=bcmul($enteredQty,$conversion,6);
                $unitPrice=(string)$line['unit_price'];
                $lineTotal=bcmul($enteredQty,$unitPrice,4);

                $balance=DB::table('stock_balances')->where('organization_id',$organizationId)
                    ->where('product_id',$product->id)->where('location_id',$location->id)
                    ->lockForUpdate()->first();
                $available=(string)($balance->quantity_base ?? 0);
                $requiredQuantity=bcadd((string)($requiredByProduct[$product->id] ?? '0.000000'),$quantityBase,6);
                if(bccomp($available,$requiredQuantity,6)<0){
                    throw ValidationException::withMessages(['items'=>['رصيد المخزون غير كافٍ للصنف '.$product->name_ar.'.']]);
                }
                $requiredByProduct[$product->id]=$requiredQuantity;

                $cost=(string)($balance->average_cost ?? $product->default_cost ?? 0);
                $subtotal=bcadd($subtotal,$lineTotal,4);
                $cogs=bcadd($cogs,bcmul($cost,$quantityBase,4),4);
                $salesItemId = (string) Str::ulid();
                $items[]=compact('product','conversion','enteredQty','quantityBase','unitPrice','lineTotal','cost','balance','packagingId','unitId','salesItemId');
            }

            $discount=(string)($data['discount'] ?? 0);
            $tax=(string)($data['tax'] ?? 0);
            $this->validateHeaderAdjustments('sale', $discount, $tax);
            if(bccomp($discount,$subtotal,4)>0) throw ValidationException::withMessages(['discount'=>['Discount cannot exceed the invoice subtotal.']]);
            $total=bcadd(bcsub($subtotal,$discount,4),$tax,4);
            $paid=(string)($data['paid_amount'] ?? 0);
            if(bccomp($paid,$total,4)>0) throw ValidationException::withMessages(['paid_amount'=>['Paid amount cannot exceed invoice total.']]);
            $balanceDue=bcsub($total,$paid,4);

            // A non-zero amount received at invoice issue must identify the real
            // financial account and payment method. Do not post it to a generic
            // cash GL account without a receipt that accounting can reconcile.
            $atSaleAccount = null;
            $atSaleMethod = null;
            $atSaleReference = trim((string) ($data['payment_reference'] ?? '')) ?: null;
            if (bccomp($paid, '0.0000', 4) > 0) {
                $accountId = (string) ($data['financial_account_id'] ?? '');
                $methodId = (string) ($data['payment_method_id'] ?? '');
                if ($accountId === '' || $methodId === '') {
                    throw ValidationException::withMessages(['financial_account_id' => ['اختار حساب التحصيل وطريقة الدفع للمبلغ المستلم وقت الفاتورة.']]);
                }
                $atSaleAccount = DB::table('financial_accounts')
                    ->where('organization_id', $organizationId)
                    ->where('id', $accountId)
                    ->where('active', true)
                    ->lockForUpdate()
                    ->first();
                if (! $atSaleAccount) {
                    throw ValidationException::withMessages(['financial_account_id' => ['حساب التحصيل غير موجود أو غير نشط داخل المؤسسة.']]);
                }
                if (strtoupper((string) ($atSaleAccount->currency ?? $baseCurrency)) !== $baseCurrency) {
                    throw ValidationException::withMessages(['financial_account_id' => ['عملة حساب التحصيل يجب أن تطابق عملة المؤسسة.']]);
                }
                $atSaleMethod = DB::table('payment_methods')
                    ->where(function ($query) use ($organizationId): void {
                        $query->where('organization_id', $organizationId)->orWhereNull('organization_id');
                    })
                    ->where('id', $methodId)
                    ->where('active', true)
                    ->first();
                if (! $atSaleMethod) {
                    throw ValidationException::withMessages(['payment_method_id' => ['طريقة الدفع غير موجودة أو غير نشطة.']]);
                }
                if ($atSaleMethod->requires_reference && $atSaleReference === null) {
                    throw ValidationException::withMessages(['payment_reference' => ['أدخل رقم مرجع التحصيل لطريقة الدفع المختارة.']]);
                }
            }

            DB::table('sales_invoices')->insert([
                'id'=>$invoiceId,'organization_id'=>$organizationId,'customer_id'=>$customer->id,
                'source_location_id'=>$location->id,'trip_id'=>$data['trip_id'] ?? null,
                'document_number'=>$documentNumber,'status'=>'posted','invoice_date'=>$data['invoice_date'] ?? now()->toDateString(),
                'posted_at'=>now(),'subtotal'=>$subtotal,'discount'=>$data['discount'] ?? 0,'tax'=>$data['tax'] ?? 0,
                'total'=>$total,'paid_amount'=>$paid,'balance_due'=>$balanceDue,'currency'=>$baseCurrency,
                'notes'=>$data['notes'] ?? null,'created_by'=>$data['created_by'] ?? null,'device_id'=>$data['device_id'] ?? null,
                'idempotency_key'=>$data['idempotency_key'] ?? null,'created_at'=>now(),'updated_at'=>now(),
            ]);

            foreach($items as $item){
                DB::table('sales_invoice_items')->insert([
                    'id'=>$item['salesItemId'],'organization_id'=>$organizationId,'sales_invoice_id'=>$invoiceId,
                    'product_id'=>$item['product']->id,'packaging_id'=>$item['packagingId'],
                    'entered_unit_id'=>$item['unitId'],'entered_quantity'=>$item['enteredQty'],
                    'conversion_factor_snapshot'=>$item['conversion'],'quantity_base'=>$item['quantityBase'],
                    'unit_price_entered'=>$item['unitPrice'],'unit_price_base'=>bcdiv($item['unitPrice'],$item['conversion'],4),
                    'discount'=>0,'tax'=>0,'line_total'=>$item['lineTotal'],'unit_cost_snapshot'=>$item['cost'],
                    'product_name_snapshot'=>$item['product']->name_ar,'sku_snapshot'=>$item['product']->sku,
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
                // Multiple invoice lines may reference the same product. Read
                // the latest balance for each deduction so one line cannot
                // overwrite another line's stock update.
                $currentBalance = DB::table('stock_balances')
                    ->where('organization_id', $organizationId)
                    ->where('product_id', $item['product']->id)
                    ->where('location_id', $location->id)
                    ->lockForUpdate()
                    ->first();
                if (! $currentBalance) {
                    throw ValidationException::withMessages(['items' => ['Stock balance disappeared during sale posting.']]);
                }
                $movementId = (string) Str::ulid();
                DB::table('stock_movements')->insert([
                    'id'=>$movementId,'organization_id'=>$organizationId,'transaction_uuid'=>(string)Str::uuid(),
                    'product_id'=>$item['product']->id,'location_id'=>$location->id,'movement_type'=>'sale',
                    'quantity_base'=>bcsub('0',$item['quantityBase'],6),'unit_cost'=>$item['cost'],
                    'source_document_type'=>'sales_invoice','source_document_id'=>$invoiceId,'occurred_at'=>now(),
                    'posted_at'=>now(),'created_by'=>$data['created_by'] ?? null,'device_id'=>$data['device_id'] ?? null,
                    'trip_id'=>$data['trip_id'] ?? null,'reference'=>$documentNumber,'created_at'=>now(),'updated_at'=>now(),
                ]);
                $this->consumeSourceLayers(
                    $organizationId,
                    $item['product']->id,
                    $location->id,
                    (string) $currentBalance->quantity_base,
                    $item['quantityBase'],
                    $movementId,
                    'sales_invoice',
                    $invoiceId,
                    $item['salesItemId'],
                    'sale',
                    null,
                    $item['cost']
                );
                $newQty=bcsub((string)$currentBalance->quantity_base,$item['quantityBase'],6);
                DB::table('stock_balances')->where('id',$currentBalance->id)->update(['quantity_base'=>$newQty,'updated_at'=>now()]);
            }

            $accounts=$this->ensureLedgerAccounts($organizationId);
            $tx=(string)Str::uuid();

            // The invoice source posts the full sale to revenue and receivables.
            // Any money collected at issue is a separate, source-linked payment
            // that debits the selected real account and credits receivables. This
            // keeps both source documents balanced and makes the receipt auditable.
            $this->ledger($organizationId, $tx, $accounts['accounts_receivable'], $total, 0, 'sales_invoice', $invoiceId);
            $this->ledger($organizationId,$tx,$accounts['sales_revenue'],0,$total,'sales_invoice',$invoiceId);
            if(bccomp($cogs,'0.0000',4)>0){
                $this->ledger($organizationId,$tx,$accounts['cogs'],$cogs,0,'sales_invoice',$invoiceId);
                $this->ledger($organizationId,$tx,$accounts['inventory'],0,$cogs,'sales_invoice',$invoiceId);
            }

            if (bccomp($paid, '0.0000', 4) > 0) {
                $paymentId = (string) Str::ulid();
                DB::table('payments')->insert([
                    'id' => $paymentId,
                    'organization_id' => $organizationId,
                    'party_type' => 'customer',
                    'party_id' => $customer->id,
                    'financial_account_id' => $atSaleAccount->id,
                    'payment_method_id' => $atSaleMethod->id,
                    'direction' => 'inbound',
                    'amount' => $paid,
                    'currency' => $baseCurrency,
                    'payment_date' => $data['payment_date'] ?? now()->toDateString(),
                    'reference' => $atSaleReference ?: $documentNumber,
                    'status' => 'posted',
                    'trip_id' => $data['trip_id'] ?? null,
                    'trip_supplier_settlement_id' => null,
                    'source_document_type' => 'sale_at_issue',
                    'source_document_id' => $invoiceId,
                    'created_by' => $data['created_by'] ?? null,
                    'device_id' => $data['device_id'] ?? null,
                    'idempotency_key' => 'sale-issue-payment-'.substr(hash('sha256', (string) ($data['idempotency_key'] ?? $invoiceId)), 0, 48),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('payment_allocations')->insert([
                    'id' => (string) Str::ulid(),
                    'organization_id' => $organizationId,
                    'payment_id' => $paymentId,
                    'document_type' => 'sales_invoice',
                    'document_id' => $invoiceId,
                    'amount' => $paid,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $paymentTx = (string) Str::uuid();
                $financialLedger = $this->ledgerAccountForFinancialAccount($organizationId, $atSaleAccount);
                $this->ledger($organizationId, $paymentTx, $financialLedger, $paid, '0.0000', 'payment', $paymentId);
                $this->ledger($organizationId, $paymentTx, $accounts['accounts_receivable'], '0.0000', $paid, 'payment', $paymentId);
            }

            $this->adjustCustomerBalanceSummary($organizationId,$customer->id,[
                'total_sales'=>$total,'total_paid'=>$paid,'outstanding'=>$balanceDue,
            ],true);

            $result = ['id'=>$invoiceId,'document_number'=>$documentNumber,'total'=>$total,'paid_amount'=>$paid,'balance_due'=>$balanceDue,'status'=>'posted'];
            $this->completeIdempotency($organizationId, 'sale.post', $data['idempotency_key'] ?? null, 'sales_invoice:'.$invoiceId);
            $this->publishDomainEvent($organizationId, 'sales.invoice.posted', 'sales_invoice', $invoiceId, [
                'document_number' => $documentNumber, 'customer_id' => $customer->id,
                'trip_id' => $data['trip_id'] ?? null, 'total' => $total, 'paid_amount' => $paid,
                'balance_due' => $balanceDue, 'currency' => $baseCurrency,
            ]);
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

            $vehicle=DB::table('vehicles')->where('organization_id',$organizationId)->where('id',$trip->vehicle_id)->where('active',true)->lockForUpdate()->first();
            $source=DB::table('locations')->where('organization_id',$organizationId)->where('id',$data['from_location_id'])->where('status','active')->first();
            if(!$vehicle || !$source) throw ValidationException::withMessages(['from_location_id'=>['Vehicle or source location is invalid.']]);
            $this->captureTripOpeningStockSnapshot($organizationId, $trip, $vehicle, $data['created_by'] ?? null);

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

                $loadItemId = (string) Str::ulid();
                DB::table('trip_load_items')->insert([
                    'id'=>$loadItemId,'organization_id'=>$organizationId,'trip_load_id'=>$loadId,
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

                $oldVehicleQty=(string)($vehicleBalance->quantity_base ?? '0.000000');
                $this->transferSourceLayers(
                    $organizationId,
                    $product->id,
                    $source->id,
                    $vehicle->location_id,
                    $available,
                    $oldVehicleQty,
                    $qty,
                    $outMovementId,
                    $loadId,
                    $loadItemId,
                    $cost
                );

                $newSourceQty=bcsub($available,$qty,6);
                DB::table('stock_balances')->where('id',$sourceBalance->id)->update(['quantity_base'=>$newSourceQty,'updated_at'=>now()]);

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
            $this->publishDomainEvent($organizationId, 'distribution.trip_load.posted', 'trip_load', $loadId, [
                'load_number' => $loadNumber, 'trip_id' => $trip->id, 'vehicle_id' => $vehicle->id,
                'item_count' => count($data['items']),
            ]);
            return $result;
        }, attempts:5);
    }

    public function postExpense(string $organizationId, array $data): array
    {
        return DB::transaction(function () use ($organizationId, $data): array {
            if ($replay = $this->claimIdempotency($organizationId, 'expense.post', $data['idempotency_key'] ?? null, $data)) {
                return $this->replayExpense($organizationId, $replay);
            }

            $baseCurrency = strtoupper((string) DB::table('organizations')->where('id', $organizationId)->value('default_currency'));

            $account = DB::table('financial_accounts')
                ->where('organization_id', $organizationId)
                ->where('id', $data['financial_account_id'])
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (! $account) {
                throw ValidationException::withMessages(['financial_account_id' => ['The financial account is not active in this organization.']]);
            }
            if (strtoupper((string) ($account->currency ?? $baseCurrency)) !== $baseCurrency) {
                throw ValidationException::withMessages(['financial_account_id' => ['Expense account currency must match the organization base currency until foreign-exchange accounting is enabled.']]);
            }

            $trip = null;
            $vehicle = null;

            if (! empty($data['trip_id'])) {
                $trip = DB::table('trips')
                    ->where('organization_id', $organizationId)
                    ->where('id', $data['trip_id'])
                    ->lockForUpdate()
                    ->first();

                if (! $trip) {
                    throw ValidationException::withMessages(['trip_id' => ['The selected trip does not belong to this organization.']]);
                }
                if (in_array($trip->status, ['completed', 'cancelled'], true)) {
                    throw ValidationException::withMessages(['trip_id' => ['Expenses cannot be posted to a completed or cancelled trip.']]);
                }

                $vehicle = DB::table('vehicles')
                    ->where('organization_id', $organizationId)
                    ->where('id', $trip->vehicle_id)
                    ->where('active', true)
                    ->first();

                if (! $vehicle) {
                    throw ValidationException::withMessages(['trip_id' => ['The trip vehicle is missing or inactive.']]);
                }
                if (! empty($data['vehicle_id']) && $data['vehicle_id'] !== $vehicle->id) {
                    throw ValidationException::withMessages(['vehicle_id' => ['The expense vehicle must match the selected trip vehicle.']]);
                }
            } elseif (! empty($data['vehicle_id'])) {
                $vehicle = DB::table('vehicles')
                    ->where('organization_id', $organizationId)
                    ->where('id', $data['vehicle_id'])
                    ->where('active', true)
                    ->first();

                if (! $vehicle) {
                    throw ValidationException::withMessages(['vehicle_id' => ['The vehicle is not active in this organization.']]);
                }
            }

            $expenseId = (string) Str::ulid();
            $amount = bcadd((string) $data['amount'], '0', 4);
            $category = trim($data['category']);

            DB::table('expenses')->insert([
                'id' => $expenseId,
                'organization_id' => $organizationId,
                'category' => $category,
                'amount' => $amount,
                'financial_account_id' => $account->id,
                'expense_date' => $data['expense_date'] ?? now()->toDateString(),
                'trip_id' => $trip?->id,
                'vehicle_id' => $vehicle?->id,
                'notes' => $data['notes'] ?? null,
                'status' => 'posted',
                'created_by' => $data['created_by'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($trip) {
                // Settlement totals expenses from the source `expenses` rows only;
                // this pivot expresses association and must never be summed again.
                DB::table('trip_expenses')->insert([
                    'id' => (string) Str::ulid(),
                    'organization_id' => $organizationId,
                    'trip_id' => $trip->id,
                    'expense_id' => $expenseId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $accounts = $this->ensureLedgerAccounts($organizationId);
            $financialLedger = $this->ledgerAccountForFinancialAccount($organizationId, $account);
            $transactionUuid = (string) Str::uuid();
            $this->ledger($organizationId, $transactionUuid, $accounts['operating_expenses'], $amount, '0.0000', 'expense', $expenseId);
            $this->ledger($organizationId, $transactionUuid, $financialLedger, '0.0000', $amount, 'expense', $expenseId);

            $this->completeIdempotency($organizationId, 'expense.post', $data['idempotency_key'] ?? null, 'expense:'.$expenseId);
            $this->publishDomainEvent($organizationId, 'finance.expense.posted', 'expense', $expenseId, [
                'category' => $category, 'amount' => $amount, 'currency' => $baseCurrency,
                'financial_account_id' => $account->id, 'trip_id' => $trip?->id, 'vehicle_id' => $vehicle?->id,
            ]);

            return [
                'id' => $expenseId,
                'category' => $category,
                'amount' => $amount,
                'financial_account_id' => $account->id,
                'trip_id' => $trip?->id,
                'vehicle_id' => $vehicle?->id,
                'expense_date' => $data['expense_date'] ?? now()->toDateString(),
                'status' => 'posted',
            ];
        }, attempts: 5);
    }

    public function postCashboxTransaction(string $organizationId, array $data): array
    {
        return DB::transaction(function () use ($organizationId, $data): array {
            if ($replay = $this->claimIdempotency($organizationId, 'cashbox.post', $data['idempotency_key'] ?? null, $data)) {
                return $this->replayCashboxTransaction($organizationId, $replay);
            }

            $type = (string) $data['transaction_type'];
            $amount = bcadd((string) $data['amount'], '0', 4);
            $date = $data['transaction_date'] ?? now()->toDateString();
            $generalCash = (bool) ($data['source_is_general_cash'] ?? false);
            $baseCurrency = strtoupper((string) DB::table('organizations')->where('id', $organizationId)->value('default_currency'));
            if (bccomp($amount, '0.0000', 4) <= 0) {
                throw ValidationException::withMessages(['amount' => ['المبلغ يجب أن يكون أكبر من صفر.']]);
            }
            if ($generalCash && ! in_array($type, ['opening_balance', 'owner_withdrawal', 'salary_payment', 'other_expense', 'account_transfer', 'custody_issue', 'custody_return'], true)) {
                throw ValidationException::withMessages(['source_is_general_cash' => ['الخزنة العامة لا تدعم نوع الحركة المختار.']]);
            }

            $sourceAccount = null;
            if (! $generalCash && $type !== 'other_income') {
                $sourceAccount = DB::table('financial_accounts')->where('organization_id', $organizationId)
                    ->where('id', $data['financial_account_id'] ?? null)->where('active', true)->lockForUpdate()->first();
                if (! $sourceAccount) throw ValidationException::withMessages(['financial_account_id' => ['اختر حسابًا ماليًا نشطًا تابعًا للمؤسسة.']]);
                if (strtoupper((string) $sourceAccount->currency) !== $baseCurrency) throw ValidationException::withMessages(['financial_account_id' => ['عملة الحساب يجب أن تطابق عملة المؤسسة.']]);
            }

            $destinationAccount = null;
            if ($type === 'other_income') {
                if ($generalCash) throw ValidationException::withMessages(['source_is_general_cash' => ['حدد حساب استقبال الإيراد.']]);
                $destinationAccount = DB::table('financial_accounts')->where('organization_id', $organizationId)
                    ->where('id', $data['financial_account_id'] ?? null)->where('active', true)->lockForUpdate()->first();
                if (! $destinationAccount) throw ValidationException::withMessages(['financial_account_id' => ['حدد الحساب الذي سيستقبل الإيراد.']]);
                if (strtoupper((string) $destinationAccount->currency) !== $baseCurrency) throw ValidationException::withMessages(['financial_account_id' => ['عملة الحساب يجب أن تطابق عملة المؤسسة.']]);
            }
            if ($type === 'account_transfer') {
                $destinationAccount = DB::table('financial_accounts')->where('organization_id', $organizationId)
                    ->where('id', $data['destination_financial_account_id'] ?? null)->where('active', true)->lockForUpdate()->first();
                if (! $destinationAccount) throw ValidationException::withMessages(['destination_financial_account_id' => ['حدد الحساب المستلم للتحويل.']]);
                if (strtoupper((string) $destinationAccount->currency) !== $baseCurrency) throw ValidationException::withMessages(['destination_financial_account_id' => ['عملة الحساب المستلم يجب أن تطابق عملة المؤسسة.']]);
                if ($sourceAccount && $sourceAccount->id === $destinationAccount->id) throw ValidationException::withMessages(['destination_financial_account_id' => ['لا يمكن التحويل إلى نفس الحساب.']]);
            }

            if ($type === 'opening_balance') {
                if (! $generalCash && bccomp((string) ($sourceAccount->opening_balance ?? '0'), '0.0000', 4) !== 0) {
                    throw ValidationException::withMessages(['transaction_type' => ['هذا الحساب له رصيد افتتاحي مُسجل بالفعل في بيانات الحساب. راجع الرصيد الموجود بدل تسجيله مرة أخرى.']]);
                }
                $openingQuery = DB::table('cashbox_transactions')->where('organization_id', $organizationId)
                    ->where('transaction_type', 'opening_balance')->where('status', 'posted');
                $openingQuery = $generalCash ? $openingQuery->where('source_is_general_cash', true) : $openingQuery->where('financial_account_id', $sourceAccount?->id);
                if ($openingQuery->exists()) throw ValidationException::withMessages(['transaction_type' => ['تم تسجيل رصيد افتتاحي لهذا الحساب بالفعل. لا تضف رصيدًا ثانيًا.']]);
            }

            $beneficiaryType = $data['beneficiary_type'] ?? null;
            $beneficiaryUserId = $data['beneficiary_user_id'] ?? null;
            $beneficiaryName = trim((string) ($data['beneficiary_name'] ?? ''));
            if (in_array($type, ['custody_issue', 'custody_return'], true)) {
                if (empty($beneficiaryUserId)) throw ValidationException::withMessages(['beneficiary_user_id' => ['اختار الموظف المسؤول عن العهدة.']]);
                $beneficiaryType = 'employee';
                if ($beneficiaryName === '') throw ValidationException::withMessages(['beneficiary_name' => ['اسم الموظف مطلوب للعهدة.']]);
            }
            if ($type === 'owner_withdrawal') {
                $beneficiaryType = 'owner';
                if ($beneficiaryName === '') throw ValidationException::withMessages(['beneficiary_name' => ['اكتب اسم صاحب الشركة أو الشريك مستلم المسحوبات.']]);
            }
            if ($type === 'salary_payment') {
                if (! in_array($beneficiaryType, ['owner', 'driver', 'employee'], true)) throw ValidationException::withMessages(['beneficiary_type' => ['حدد نوع مستلم الراتب.']]);
                if ($beneficiaryName === '' && ! $beneficiaryUserId) throw ValidationException::withMessages(['beneficiary_name' => ['حدد اسم مستلم الراتب.']]);
            }
            if ($beneficiaryUserId) {
                $beneficiary = DB::table('users')->where('organization_id', $organizationId)->where('id', $beneficiaryUserId)->first(['id', 'name']);
                if (! $beneficiary) throw ValidationException::withMessages(['beneficiary_user_id' => ['المستلم غير موجود في المؤسسة.']]);
                $beneficiaryName = (string) $beneficiary->name;
            }
            $beneficiaryName = $beneficiaryName !== '' ? mb_substr($beneficiaryName, 0, 255) : null;

            $trip = null;
            if (! empty($data['trip_id'])) {
                $trip = DB::table('trips')->where('organization_id', $organizationId)->where('id', $data['trip_id'])->lockForUpdate()->first();
                if (! $trip || in_array($trip->status, ['completed', 'cancelled'], true)) throw ValidationException::withMessages(['trip_id' => ['لا يمكن ربط حركة برحلة غير موجودة أو مقفولة.']]);
                if (! empty($data['vehicle_id']) && (string) $trip->vehicle_id !== (string) $data['vehicle_id']) throw ValidationException::withMessages(['vehicle_id' => ['السيارة لا تطابق سيارة الرحلة المختارة.']]);
            }
            $vehicleId = $data['vehicle_id'] ?? ($trip->vehicle_id ?? null);
            if ($vehicleId && ! DB::table('vehicles')->where('organization_id', $organizationId)->where('id', $vehicleId)->exists()) throw ValidationException::withMessages(['vehicle_id' => ['السيارة غير موجودة في المؤسسة.']]);
            if ($type === 'account_transfer' && $beneficiaryName !== null) throw ValidationException::withMessages(['beneficiary_name' => ['التحويل الداخلي لا يحتاج إلى مستلم.']]);

            $id = (string) Str::ulid();
            $documentNumber = $this->nextDocumentNumber($organizationId, 'cashbox_transaction', 'CBX-');
            $direction = match ($type) {
                'opening_balance', 'other_income', 'custody_return' => 'inbound',
                'account_transfer' => 'transfer',
                default => 'outbound',
            };
            $accountId = $type === 'other_income' ? $destinationAccount?->id : $sourceAccount?->id;
            DB::table('cashbox_transactions')->insert([
                'id' => $id, 'organization_id' => $organizationId, 'document_number' => $documentNumber,
                'transaction_type' => $type, 'direction' => $direction, 'amount' => $amount, 'currency' => $baseCurrency,
                'transaction_date' => $date, 'financial_account_id' => $accountId,
                'source_is_general_cash' => $generalCash,
                'destination_financial_account_id' => $type === 'account_transfer' ? $destinationAccount?->id : null,
                'beneficiary_type' => $beneficiaryType, 'beneficiary_user_id' => $beneficiaryUserId,
                'beneficiary_name_snapshot' => $beneficiaryName, 'vehicle_id' => $vehicleId, 'trip_id' => $trip?->id,
                'category' => trim((string) $data['category']), 'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null, 'status' => 'posted', 'idempotency_key' => $data['idempotency_key'],
                'created_by' => $data['created_by'] ?? null, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $accounts = $this->ensureLedgerAccounts($organizationId);
            $transactionUuid = (string) Str::uuid();
            $cashLedger = $generalCash ? $accounts['cash'] : $this->ledgerAccountForFinancialAccount($organizationId, $sourceAccount ?? $destinationAccount);
            if ($type === 'other_income') {
                $this->ledger($organizationId, $transactionUuid, $cashLedger, $amount, '0.0000', 'cashbox_transaction', $id, $date);
                $this->ledger($organizationId, $transactionUuid, $accounts['other_income'], '0.0000', $amount, 'cashbox_transaction', $id, $date);
            } elseif ($type === 'opening_balance') {
                $this->ledger($organizationId, $transactionUuid, $cashLedger, $amount, '0.0000', 'cashbox_transaction', $id, $date);
                $this->ledger($organizationId, $transactionUuid, $accounts['opening_equity'], '0.0000', $amount, 'cashbox_transaction', $id, $date);
            } elseif (in_array($type, ['custody_issue', 'custody_return'], true)) {
                $custodyLedger = $accounts['employee_custody'];
                if ($type === 'custody_issue') {
                    $this->ledger($organizationId, $transactionUuid, $custodyLedger, $amount, '0.0000', 'cashbox_transaction', $id, $date);
                    $this->ledger($organizationId, $transactionUuid, $cashLedger, '0.0000', $amount, 'cashbox_transaction', $id, $date);
                } else {
                    $this->ledger($organizationId, $transactionUuid, $cashLedger, $amount, '0.0000', 'cashbox_transaction', $id, $date);
                    $this->ledger($organizationId, $transactionUuid, $custodyLedger, '0.0000', $amount, 'cashbox_transaction', $id, $date);
                }
            } elseif ($type === 'account_transfer') {
                $destinationLedger = $this->ledgerAccountForFinancialAccount($organizationId, $destinationAccount);
                $this->ledger($organizationId, $transactionUuid, $destinationLedger, $amount, '0.0000', 'cashbox_transaction', $id, $date);
                $this->ledger($organizationId, $transactionUuid, $cashLedger, '0.0000', $amount, 'cashbox_transaction', $id, $date);
            } else {
                $debitLedger = match ($type) {
                    'owner_withdrawal' => $accounts['owner_drawings'],
                    'salary_payment' => $accounts['salary_expenses'],
                    default => $accounts['operating_expenses'],
                };
                $this->ledger($organizationId, $transactionUuid, $debitLedger, $amount, '0.0000', 'cashbox_transaction', $id, $date);
                $this->ledger($organizationId, $transactionUuid, $cashLedger, '0.0000', $amount, 'cashbox_transaction', $id, $date);
            }

            $this->completeIdempotency($organizationId, 'cashbox.post', $data['idempotency_key'], 'cashbox_transaction:'.$id);
            $this->publishDomainEvent($organizationId, 'finance.cashbox_transaction.posted', 'cashbox_transaction', $id, [
                'document_number' => $documentNumber, 'transaction_type' => $type, 'direction' => $direction,
                'amount' => $amount, 'currency' => $baseCurrency, 'financial_account_id' => $accountId,
                'destination_financial_account_id' => $type === 'account_transfer' ? $destinationAccount?->id : null,
                'trip_id' => $trip?->id,
            ]);
            return $this->replayCashboxTransaction($organizationId, 'cashbox_transaction:'.$id);
        }, attempts: 5);
    }

    private function replayCashboxTransaction(string $organizationId, string $reference): array
    {
        [$type, $id] = array_pad(explode(':', $reference, 2), 2, null);
        if ($type !== 'cashbox_transaction' || ! $id) throw ValidationException::withMessages(['idempotency_key' => ['مرجع حركة الخزنة غير صالح.']]);
        $row = DB::table('cashbox_transactions')->where('organization_id', $organizationId)->where('id', $id)->first();
        if (! $row) throw ValidationException::withMessages(['idempotency_key' => ['حركة الخزنة السابقة غير موجودة.']]);
        return [
            'id' => $row->id, 'document_number' => $row->document_number, 'transaction_type' => $row->transaction_type,
            'direction' => $row->direction, 'amount' => $row->amount, 'currency' => $row->currency,
            'transaction_date' => $row->transaction_date, 'financial_account_id' => $row->financial_account_id,
            'source_is_general_cash' => (bool) $row->source_is_general_cash,
            'destination_financial_account_id' => $row->destination_financial_account_id,
            'beneficiary_type' => $row->beneficiary_type, 'beneficiary_name' => $row->beneficiary_name_snapshot,
            'vehicle_id' => $row->vehicle_id, 'trip_id' => $row->trip_id, 'category' => $row->category, 'status' => $row->status,
        ];
    }

    public function settleTrip(string $organizationId, array $data): array
    {
        return DB::transaction(function () use ($organizationId, $data): array {
            if ($replay = $this->claimIdempotency($organizationId, 'trip.settle', $data['idempotency_key'] ?? null, $data)) {
                return $this->replayTripSettlement($organizationId, $replay);
            }

            $trip = DB::table('trips')->where('organization_id',$organizationId)->where('id',$data['trip_id'])->lockForUpdate()->first();
            if (!$trip) throw ValidationException::withMessages(['trip_id'=>['Trip was not found.']]);
            if (in_array($trip->status, ['completed','cancelled'], true)) {
                throw ValidationException::withMessages(['trip_id'=>['This trip is already closed.']]);
            }

            $vehicle = DB::table('vehicles')->where('organization_id', $organizationId)->where('id', $trip->vehicle_id)->where('active', true)->lockForUpdate()->first();
            if (! $vehicle) {
                throw ValidationException::withMessages(['trip_id' => ['العربية المسندة للرحلة غير موجودة أو غير نشطة.']]);
            }
            $this->captureTripOpeningStockSnapshot($organizationId, $trip, $vehicle, $data['settled_by'] ?? null);
            $openingSnapshot = DB::table('trip_stock_opening_snapshots')->where('organization_id', $organizationId)->where('trip_id', $trip->id)->first();
            $openingItems = $openingSnapshot
                ? DB::table('trip_stock_opening_snapshot_items')->where('organization_id', $organizationId)->where('trip_id', $trip->id)->get()->keyBy('product_id')
                : collect();

            $productIds = DB::table('stock_movements')->where('organization_id',$organizationId)->where('trip_id',$trip->id)->distinct()->pluck('product_id')
                ->merge($openingItems->keys())->unique()->values();
            $movementRows = collect();
            foreach ($productIds as $productId) {
                $loaded = (string)DB::table('stock_movements')->where('organization_id',$organizationId)->where('trip_id',$trip->id)->where('product_id',$productId)->where('movement_type','vehicle_load')->sum('quantity_base');
                $sold = (string)DB::table('stock_movements')->where('organization_id',$organizationId)->where('trip_id',$trip->id)->where('product_id',$productId)->where('movement_type','sale')->sum('quantity_base');
                $returned = (string)DB::table('stock_movements')->where('organization_id',$organizationId)->where('trip_id',$trip->id)->where('product_id',$productId)->where('movement_type','sales_return')->sum('quantity_base');
                $transferred = (string)DB::table('stock_movements')->where('organization_id',$organizationId)->where('trip_id',$trip->id)->where('product_id',$productId)->whereIn('movement_type',['transfer_out','transfer_in'])->where('source_document_type','<>','trip_load')->sum('quantity_base');
                $adjustment = (string) DB::table('stock_movements')->where('organization_id',$organizationId)->where('trip_id',$trip->id)->where('product_id',$productId)->where('movement_type','stock_adjustment')->sum('quantity_base');
                $openingItem = $openingItems->get($productId);
                $opening = $openingSnapshot ? (string) ($openingItem->opening_quantity_base ?? '0.000000') : '0.000000';
                $expected = bcadd(bcadd(bcadd($opening, $loaded, 6), $sold, 6), bcadd(bcadd($returned, $transferred, 6), $adjustment, 6), 6);
                $movementRows->push((object)[
                    'product_id'=>$productId, 'opening'=>$opening, 'opening_average_cost'=>(string)($openingItem->opening_average_cost ?? '0.0000'),
                    'loaded'=>$loaded, 'sold'=>$sold, 'returned'=>$returned, 'transferred'=>$transferred, 'adjustment'=>$adjustment,
                    'expected_closing'=>$expected,
                ]);
            }

            $closingItems = $data['closing_items'] ?? [];
            $closingProductIds = array_column($closingItems, 'product_id');
            if (count($closingProductIds) !== count(array_unique($closingProductIds))) {
                throw ValidationException::withMessages(['closing_items' => ['Each product can appear only once in a trip closing count.']]);
            }
            $actual = collect($closingItems)->keyBy('product_id');
            foreach ($actual->keys() as $productId) {
                if (! DB::table('products')->where('organization_id', $organizationId)->where('id', $productId)->exists()) {
                    throw ValidationException::withMessages(['closing_items' => ['Every closing product must belong to this organization.']]);
                }
            }
            $productIds = $movementRows->pluck('product_id')->merge($actual->keys())->unique()->values();
            $settlementId = (string) Str::ulid();

            // Do not use mutable invoice paid_amount here: it includes later collections.
            // New receipts have their own payment row, which also identifies the real
            // financial account and payment method. Only physical cash belongs in a
            // cash custody close; bank transfers/card receipts are reported separately.
            $salesCashAtIssue = (string) DB::table('payments as p')
                ->join('financial_accounts as fa', function ($join): void {
                    $join->on('fa.id', '=', 'p.financial_account_id')->on('fa.organization_id', '=', 'p.organization_id');
                })
                ->join('payment_methods as pm', function ($join) use ($organizationId): void {
                    $join->on('pm.id', '=', 'p.payment_method_id')->where(function ($query) use ($organizationId): void {
                        $query->whereNull('pm.organization_id')->orWhere('pm.organization_id', $organizationId);
                    });
                })
                ->where('p.organization_id', $organizationId)
                ->where('p.party_type', 'customer')->where('p.direction', 'inbound')->where('p.status', 'posted')
                ->where('p.source_document_type', 'sale_at_issue')->where('p.trip_id', $trip->id)
                ->whereRaw("LOWER(fa.type) = 'cash'")
                ->where(function ($query): void {
                    $query->whereRaw("LOWER(pm.code) IN ('cash', 'cash_on_delivery', 'cash_payment')")
                        ->orWhereRaw("LOWER(pm.code) LIKE '%cash%'");
                })
                ->sum('p.amount');
            $salesCashAccountId = $this->ensureLedgerAccounts($organizationId)['cash'];
            $legacySalesCash = (string) DB::table('ledger_entries')
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
                ->whereNotExists(function ($query) use ($organizationId): void {
                    $query->selectRaw('1')->from('payments as at_sale')
                        ->whereColumn('at_sale.source_document_id', 'ledger_entries.source_document_id')
                        ->where('at_sale.organization_id', $organizationId)
                        ->where('at_sale.source_document_type', 'sale_at_issue')
                        ->where('at_sale.status', 'posted');
                })
                ->sum('debit');
            $salesCash = bcadd((string) $salesCashAtIssue, $legacySalesCash, 4);
            $collections = (string) DB::table('payments as p')
                ->join('financial_accounts as fa', function ($join): void {
                    $join->on('fa.id', '=', 'p.financial_account_id')->on('fa.organization_id', '=', 'p.organization_id');
                })
                ->join('payment_methods as pm', function ($join) use ($organizationId): void {
                    $join->on('pm.id', '=', 'p.payment_method_id')->where(function ($query) use ($organizationId): void {
                        $query->whereNull('pm.organization_id')->orWhere('pm.organization_id', $organizationId);
                    });
                })
                ->where('p.organization_id', $organizationId)
                ->where('p.party_type', 'customer')
                ->where('p.direction', 'inbound')
                ->where('p.status', 'posted')
                ->where(function ($query): void {
                    $query->whereNull('p.source_document_type')->orWhere('p.source_document_type', '<>', 'sale_at_issue');
                })
                ->whereRaw("LOWER(fa.type) = 'cash'")
                ->where(function ($query): void {
                    $query->whereRaw("LOWER(pm.code) IN ('cash', 'cash_on_delivery', 'cash_payment')")
                        ->orWhereRaw("LOWER(pm.code) LIKE '%cash%'");
                })
                ->where(function ($query) use ($organizationId, $trip): void {
                    $query->where('p.trip_id', $trip->id)->orWhereExists(function ($sub) use ($organizationId, $trip): void {
                        $sub->selectRaw('1')->from('payment_allocations as pa')
                            ->join('sales_invoices as si', function ($join): void {
                                $join->on('si.id', '=', 'pa.document_id')->on('si.organization_id', '=', 'pa.organization_id');
                            })
                            ->whereColumn('pa.payment_id', 'p.id')->where('pa.organization_id', $organizationId)
                            ->where('pa.document_type', 'sales_invoice')->where('si.trip_id', $trip->id);
                    });
                })
                ->selectRaw('COALESCE(SUM(CASE WHEN p.trip_id = ? THEN p.amount ELSE (SELECT COALESCE(SUM(pa2.amount), 0) FROM payment_allocations as pa2 INNER JOIN sales_invoices as si2 ON si2.id = pa2.document_id AND si2.organization_id = pa2.organization_id WHERE pa2.organization_id = p.organization_id AND pa2.payment_id = p.id AND pa2.document_type = ? AND si2.trip_id = ?) END), 0) as total', [$trip->id, 'sales_invoice', $trip->id])
                ->value('total');
            $expenses = (string) DB::table('expenses')->where('organization_id',$organizationId)->where('trip_id',$trip->id)->where('status','posted')->sum('amount');
            $cashboxIncome = (string) DB::table('cashbox_transactions')->where('organization_id', $organizationId)
                ->where('trip_id', $trip->id)->where('status', 'posted')->where('transaction_type', 'other_income')->sum('amount');
            $cashboxOutflow = (string) DB::table('cashbox_transactions')->where('organization_id', $organizationId)
                ->where('trip_id', $trip->id)->where('status', 'posted')->whereIn('transaction_type', ['owner_withdrawal', 'salary_payment', 'other_expense'])->sum('amount');
            $openingCash = (string)($data['opening_cash'] ?? 0);
            $expectedCash = bcsub(bcadd(bcadd(bcadd($openingCash, $salesCash, 4), $collections, 4), $cashboxIncome, 4), bcadd($expenses, $cashboxOutflow, 4), 4);
            $actualCash = (string)($data['actual_cash'] ?? 0);
            $cashVariance = bcsub($actualCash,$expectedCash,4);

            DB::table('trip_settlements')->insert([
                'id'=>$settlementId,'organization_id'=>$organizationId,'trip_id'=>$trip->id,'status'=>'settled',
                'opening_stock_basis'=>$openingSnapshot ? 'snapshot' : 'legacy_zero',
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
                $balance=DB::table('stock_balances')->where('organization_id',$organizationId)->where('product_id',$productId)->where('location_id',$vehicle->location_id)->first();
                $cost=(string)($balance->average_cost ?? $row->opening_average_cost ?? 0);
                $stockVarianceValue=bcadd($stockVarianceValue,bcmul($variance,$cost,4),4);
                DB::table('trip_settlement_lines')->insert([
                    'id'=>(string)Str::ulid(),'organization_id'=>$organizationId,'trip_settlement_id'=>$settlementId,'product_id'=>$productId,
                    'opening_quantity_base'=>$row->opening ?? '0.000000','loaded_quantity_base'=>$row->loaded ?? '0.000000',
                    'sold_quantity_base'=>bcsub('0',(string)($row->sold ?? '0.000000'),6),'returned_quantity_base'=>$row->returned ?? '0.000000','transferred_quantity_base'=>$row->transferred ?? '0.000000','adjustment_quantity_base'=>$row->adjustment ?? '0.000000',
                    'expected_closing_quantity_base'=>$expected,'actual_closing_quantity_base'=>$actualQty,'variance_quantity_base'=>$variance,
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
            }

            DB::table('trip_settlements')->where('id',$settlementId)->update(['stock_variance_value'=>$stockVarianceValue,'updated_at'=>now()]);
            $supplierSettlements = $this->saveTripSupplierSettlements($organizationId, $trip->id, $settlementId);
            DB::table('trips')->where('id',$trip->id)->update(['status'=>'completed','ended_at'=>now(),'updated_at'=>now()]);

            $result = [
                'id'=>$settlementId,'trip_id'=>$trip->id,'status'=>'settled',
                'opening_stock_basis'=>$openingSnapshot ? 'snapshot' : 'legacy_zero',
                'opening_stock_note'=>$openingSnapshot
                    ? 'تم احتساب رصيد بداية الرحلة من لقطة محفوظة قبل أول حركة مخزون مرتبطة بها.'
                    : 'هذه رحلة قديمة لها حركات سابقة بلا لقطة افتتاحية؛ لم يتم تخمين مخزون البداية واستُخدم الصفر للحفاظ على دقة السجل التاريخي.',
                'expected_cash'=>$expectedCash,'actual_cash'=>$actualCash,'cash_variance'=>$cashVariance,
                'stock_variance_value'=>$stockVarianceValue,
                'supplier_settlements'=>$supplierSettlements,
            ];

            $this->completeIdempotency($organizationId, 'trip.settle', $data['idempotency_key'] ?? null, 'trip_settlement:'.$settlementId);
            $this->publishDomainEvent($organizationId, 'distribution.trip_settled', 'trip_settlement', $settlementId, [
                'trip_id' => $trip->id, 'expected_cash' => $expectedCash, 'actual_cash' => $actualCash,
                'cash_variance' => $cashVariance, 'stock_variance_value' => $stockVarianceValue,
                'supplier_settlement_count' => count($supplierSettlements),
            ]);

            return $result;
        }, attempts:5);
    }

    /**
     * Preview supplier-sourced sell-through for an open trip, or return the
     * immutable snapshot persisted when that trip was closed. Amounts here
     * are source-lot cost, separate from purchase-invoice accounts payable.
     */
    public function previewTripSupplierSettlement(string $organizationId, string $tripId): array
    {
        $trip = DB::table('trips')->where('organization_id', $organizationId)->where('id', $tripId)->first();
        if (! $trip) {
            throw ValidationException::withMessages(['trip_id' => ['Trip was not found.']]);
        }

        if ($trip->status === 'completed') {
            $saved = DB::table('trip_supplier_settlements')
                ->where('organization_id', $organizationId)->where('trip_id', $tripId)
                ->orderBy('supplier_name_snapshot')->get();
            if ($saved->isNotEmpty()) {
                return [
                    'trip_id' => $tripId,
                    'snapshot' => true,
                    'note' => 'القيمة محسوبة بتكلفة مصدر البضاعة المسجل، ومنفصلة عن مديونية فواتير الشراء لتجنب التكرار.',
                    'suppliers' => $saved->map(fn ($row) => [
                        'id' => $row->id,
                        'supplier_id' => $row->supplier_id,
                        'supplier_name' => $row->supplier_name_snapshot,
                        'sold_quantity_base' => (float) $row->sold_quantity_base,
                        'returned_quantity_base' => (float) $row->returned_quantity_base,
                        'net_quantity_base' => (float) $row->net_quantity_base,
                        'sold_source_cost' => (float) $row->sold_source_cost,
                        'returned_source_cost' => (float) $row->returned_source_cost,
                        'settlement_amount' => (float) $row->settlement_amount,
                        'deferred_source_cost' => (float) $row->deferred_source_cost,
                        'settlement_basis' => $row->settlement_basis,
                        'items' => json_decode((string) $row->items_json, true) ?: [],
                    ])->values()->all(),
                ];
            }
        }

        return [
            'trip_id' => $tripId,
            'snapshot' => false,
            'note' => 'القيمة محسوبة بتكلفة مصدر البضاعة المسجل، ومنفصلة عن مديونية فواتير الشراء لتجنب التكرار.',
            'suppliers' => $this->buildTripSupplierSettlementRows($organizationId, $tripId),
        ];
    }

    private function buildTripSupplierSettlementRows(string $organizationId, string $tripId): array
    {
        $sourceQueries = [
            ['kind' => 'sale', 'allocation_type' => 'sale', 'source_type' => 'sales_invoice', 'table' => 'sales_invoices', 'alias' => 'sale_doc'],
            ['kind' => 'return', 'allocation_type' => 'sales_return', 'source_type' => 'sales_return', 'table' => 'sales_returns', 'alias' => 'return_doc'],
        ];
        $lines = [];

        foreach ($sourceQueries as $source) {
            $alias = $source['alias'];
            $rows = DB::table('stock_source_allocations as allocation')
                ->join($source['table'].' as '.$alias, function ($join) use ($alias): void {
                    $join->on($alias.'.id', '=', 'allocation.source_document_id')
                        ->on($alias.'.organization_id', '=', 'allocation.organization_id');
                })
                ->leftJoin('purchase_invoices as purchase', function ($join): void {
                    $join->on('purchase.id', '=', 'allocation.source_purchase_invoice_id')
                        ->on('purchase.organization_id', '=', 'allocation.organization_id');
                })
                ->leftJoin('suppliers as supplier', function ($join): void {
                    $join->on('supplier.id', '=', 'purchase.supplier_id')
                        ->on('supplier.organization_id', '=', 'purchase.organization_id');
                })
                ->leftJoin('purchase_invoice_items as source_item', function ($join): void {
                    $join->on('source_item.id', '=', 'allocation.source_purchase_invoice_item_id')
                        ->on('source_item.organization_id', '=', 'allocation.organization_id');
                })
                ->join('stock_source_layers as source_layer', function ($join): void {
                    $join->on('source_layer.id', '=', 'allocation.source_layer_id')
                        ->on('source_layer.organization_id', '=', 'allocation.organization_id');
                })
                ->join('products as product', function ($join): void {
                    $join->on('product.id', '=', 'source_layer.product_id')
                        ->on('product.organization_id', '=', 'allocation.organization_id');
                })
                ->where('allocation.organization_id', $organizationId)
                ->where('allocation.source_document_type', $source['source_type'])
                ->where('allocation.allocation_type', $source['allocation_type'])
                ->where($alias.'.trip_id', $tripId)
                ->where($alias.'.status', 'posted')
                ->select([
                    'purchase.supplier_id', 'supplier.name as supplier_name', 'source_layer.product_id',
                    'product.name_ar as product_name', 'source_item.settlement_method_snapshot',
                    'source_item.settlement_unit_price_snapshot',
                ])
                ->selectRaw('COALESCE(SUM(allocation.quantity_base), 0) as quantity_base')
                ->selectRaw('COALESCE(SUM(allocation.quantity_base * allocation.unit_cost_base), 0) as source_cost')
                ->selectRaw("COALESCE(SUM(CASE WHEN COALESCE(source_item.settlement_method_snapshot, 'purchase_debt') = 'sold_goods' THEN allocation.quantity_base * COALESCE(source_item.settlement_unit_price_snapshot, allocation.unit_cost_base) ELSE 0 END), 0) as settlement_value")
                ->selectRaw("COALESCE(SUM(CASE WHEN COALESCE(source_item.settlement_method_snapshot, 'purchase_debt') = 'sold_goods' THEN allocation.quantity_base * allocation.unit_cost_base ELSE 0 END), 0) as deferred_source_cost")
                ->groupBy(
                    'purchase.supplier_id', 'supplier.name', 'source_layer.product_id', 'product.name_ar',
                    'source_item.settlement_method_snapshot', 'source_item.settlement_unit_price_snapshot'
                )
                ->get();

            foreach ($rows as $row) {
                $supplierKey = $row->supplier_id ?: 'unattributed';
                $settlementMethod = (string) ($row->settlement_method_snapshot ?? 'purchase_debt');
                $settlementPrice = $row->settlement_unit_price_snapshot === null ? null : (string) $row->settlement_unit_price_snapshot;
                $key = $supplierKey.'|'.$row->product_id.'|'.$settlementMethod.'|'.($settlementPrice ?? 'source_cost');
                if (! isset($lines[$key])) {
                    $lines[$key] = [
                        'supplier_id' => $row->supplier_id,
                        'supplier_name' => $row->supplier_name ?: 'مصدر بضاعة غير معروف',
                        'product_id' => $row->product_id,
                        'product_name' => $row->product_name,
                        'settlement_method' => $settlementMethod,
                        'settlement_unit_price' => $settlementPrice,
                        'sold_quantity_base' => '0.000000',
                        'returned_quantity_base' => '0.000000',
                        'sold_source_cost' => '0.0000',
                        'returned_source_cost' => '0.0000',
                        'sold_settlement_value' => '0.0000',
                        'returned_settlement_value' => '0.0000',
                        'sold_deferred_source_cost' => '0.0000',
                        'returned_deferred_source_cost' => '0.0000',
                    ];
                }
                $quantityField = $source['kind'] === 'sale' ? 'sold_quantity_base' : 'returned_quantity_base';
                $costField = $source['kind'] === 'sale' ? 'sold_source_cost' : 'returned_source_cost';
                $settlementField = $source['kind'] === 'sale' ? 'sold_settlement_value' : 'returned_settlement_value';
                $deferredCostField = $source['kind'] === 'sale' ? 'sold_deferred_source_cost' : 'returned_deferred_source_cost';
                $lines[$key][$quantityField] = bcadd($lines[$key][$quantityField], (string) $row->quantity_base, 6);
                $lines[$key][$costField] = bcadd($lines[$key][$costField], (string) $row->source_cost, 4);
                $lines[$key][$settlementField] = bcadd($lines[$key][$settlementField], (string) $row->settlement_value, 4);
                $lines[$key][$deferredCostField] = bcadd($lines[$key][$deferredCostField], (string) $row->deferred_source_cost, 4);
            }
        }

        $suppliers = [];
        foreach ($lines as $line) {
            $key = $line['supplier_id'] ?: 'unattributed';
            if (! isset($suppliers[$key])) {
                $suppliers[$key] = [
                    'supplier_id' => $line['supplier_id'], 'supplier_name' => $line['supplier_name'],
                    'sold_quantity_base' => '0.000000', 'returned_quantity_base' => '0.000000', 'net_quantity_base' => '0.000000',
                    'sold_source_cost' => '0.0000', 'returned_source_cost' => '0.0000', 'settlement_amount' => '0.0000', 'items' => [],
                    'deferred_source_cost' => '0.0000', 'has_sold_goods_policy' => false, 'has_purchase_debt_policy' => false,
                ];
            }
            $netQty = bcsub($line['sold_quantity_base'], $line['returned_quantity_base'], 6);
            $amount = bcsub($line['sold_source_cost'], $line['returned_source_cost'], 4);
            $settlementAmount = bcsub($line['sold_settlement_value'], $line['returned_settlement_value'], 4);
            $deferredSourceCost = bcsub($line['sold_deferred_source_cost'], $line['returned_deferred_source_cost'], 4);
            if (bccomp($settlementAmount, '0.0000', 4) < 0) $settlementAmount = '0.0000';
            if (bccomp($deferredSourceCost, '0.0000', 4) < 0) $deferredSourceCost = '0.0000';
            foreach (['sold_quantity_base', 'returned_quantity_base'] as $field) {
                $suppliers[$key][$field] = bcadd($suppliers[$key][$field], $line[$field], 6);
            }
            foreach (['sold_source_cost', 'returned_source_cost'] as $field) {
                $suppliers[$key][$field] = bcadd($suppliers[$key][$field], $line[$field], 4);
            }
            $suppliers[$key]['net_quantity_base'] = bcadd($suppliers[$key]['net_quantity_base'], $netQty, 6);
            $suppliers[$key]['settlement_amount'] = bcadd($suppliers[$key]['settlement_amount'], $settlementAmount, 4);
            $suppliers[$key]['deferred_source_cost'] = bcadd($suppliers[$key]['deferred_source_cost'], $deferredSourceCost, 4);
            $suppliers[$key]['has_sold_goods_policy'] = $suppliers[$key]['has_sold_goods_policy'] || $line['settlement_method'] === 'sold_goods';
            $suppliers[$key]['has_purchase_debt_policy'] = $suppliers[$key]['has_purchase_debt_policy'] || $line['settlement_method'] !== 'sold_goods';
            $suppliers[$key]['items'][] = [
                'product_id' => $line['product_id'], 'product_name' => $line['product_name'],
                'settlement_method' => $line['settlement_method'],
                'settlement_unit_price' => $line['settlement_unit_price'] === null ? null : (float) $line['settlement_unit_price'],
                'sold_quantity_base' => (float) $line['sold_quantity_base'],
                'returned_quantity_base' => (float) $line['returned_quantity_base'],
                'net_quantity_base' => (float) $netQty,
                'sold_source_cost' => (float) $line['sold_source_cost'],
                'returned_source_cost' => (float) $line['returned_source_cost'],
                'settlement_amount' => (float) $settlementAmount,
                'deferred_source_cost' => (float) $deferredSourceCost,
            ];
        }

        return collect($suppliers)->map(fn ($row) => [
            'supplier_id' => $row['supplier_id'], 'supplier_name' => $row['supplier_name'],
            'sold_quantity_base' => (float) $row['sold_quantity_base'],
            'returned_quantity_base' => (float) $row['returned_quantity_base'],
            'net_quantity_base' => (float) $row['net_quantity_base'],
            'sold_source_cost' => (float) $row['sold_source_cost'],
            'returned_source_cost' => (float) $row['returned_source_cost'],
            'settlement_amount' => (float) $row['settlement_amount'],
            'deferred_source_cost' => (float) $row['deferred_source_cost'],
            'settlement_basis' => $row['has_sold_goods_policy'] ? ($row['has_purchase_debt_policy'] ? 'mixed' : 'sold_goods') : 'purchase_debt_only',
            'items' => $row['items'],
        ])->sortBy('supplier_name')->values()->all();
    }

    private function saveTripSupplierSettlements(string $organizationId, string $tripId, string $tripSettlementId): array
    {
        $rows = $this->buildTripSupplierSettlementRows($organizationId, $tripId);
        foreach ($rows as &$row) {
            $supplierSettlementId = (string) Str::ulid();
            DB::table('trip_supplier_settlements')->insert([
                'id' => $supplierSettlementId, 'organization_id' => $organizationId,
                'trip_id' => $tripId, 'trip_settlement_id' => $tripSettlementId,
                'supplier_id' => $row['supplier_id'], 'supplier_name_snapshot' => $row['supplier_name'],
                'sold_quantity_base' => $row['sold_quantity_base'], 'returned_quantity_base' => $row['returned_quantity_base'],
                'net_quantity_base' => $row['net_quantity_base'], 'sold_source_cost' => $row['sold_source_cost'],
                'returned_source_cost' => $row['returned_source_cost'], 'settlement_amount' => $row['settlement_amount'],
                'settlement_basis' => $row['settlement_basis'],
                'deferred_source_cost' => $row['deferred_source_cost'],
                'items_json' => json_encode($row['items'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            // Reclassify the sell-through share from the deferred supplier liability
            // recognized at purchase time to the amount payable under this settlement.
            if ($row['settlement_basis'] !== 'purchase_debt_only'
                && (bccomp((string) $row['deferred_source_cost'], '0.0000', 4) > 0
                    || bccomp((string) $row['settlement_amount'], '0.0000', 4) > 0)) {
                $accounts = $this->ensureLedgerAccounts($organizationId);
                $tx = (string) Str::uuid();
                if (bccomp((string) $row['deferred_source_cost'], '0.0000', 4) > 0) {
                    $this->ledger($organizationId, $tx, $accounts['deferred_supplier_payables'], (string) $row['deferred_source_cost'], '0.0000', 'trip_supplier_settlement', $supplierSettlementId);
                }
                if (bccomp((string) $row['settlement_amount'], '0.0000', 4) > 0) {
                    $this->ledger($organizationId, $tx, $accounts['accounts_payable'], '0.0000', (string) $row['settlement_amount'], 'trip_supplier_settlement', $supplierSettlementId);
                }
                $variance = bcsub((string) $row['settlement_amount'], (string) $row['deferred_source_cost'], 4);
                if (bccomp($variance, '0.0000', 4) > 0) {
                    $this->ledger($organizationId, $tx, $accounts['supplier_settlement_variance_expense'], $variance, '0.0000', 'trip_supplier_settlement', $supplierSettlementId);
                } elseif (bccomp($variance, '0.0000', 4) < 0) {
                    $this->ledger($organizationId, $tx, $accounts['supplier_settlement_variance_gain'], '0.0000', bcsub('0.0000', $variance, 4), 'trip_supplier_settlement', $supplierSettlementId);
                }
                if (! empty($row['supplier_id']) && bccomp((string) $row['settlement_amount'], '0.0000', 4) > 0) {
                    $this->adjustSupplierBalanceSummary(
                        $organizationId,
                        (string) $row['supplier_id'],
                        ['outstanding' => (string) $row['settlement_amount']],
                        false,
                        'updated_at'
                    );
                }
            }
            $row['id'] = $supplierSettlementId;
        }
        unset($row);
        return $rows;
    }

    public function postSalesReturn(string $organizationId, array $data): array
    {
        return DB::transaction(function () use ($organizationId, $data): array {
            if ($replay = $this->claimIdempotency($organizationId,'sales_return.post',$data['idempotency_key'] ?? null,$data)) {
                return $this->replayReturn($organizationId,$replay,'sales_return');
            }

            $isFieldRep = ! empty($data['created_by']) && DB::table('user_roles as ur')
                ->join('roles as r', function ($join): void {
                    $join->on('r.id', '=', 'ur.role_id')->on('r.organization_id', '=', 'ur.organization_id');
                })
                ->where('ur.organization_id', $organizationId)
                ->where('ur.user_id', $data['created_by'])
                ->where('r.key', 'field_rep')
                ->exists();
            if ($isFieldRep) {
                foreach ($data['items'] as $index => $line) {
                    $value = $line['quantity'] ?? null;
                    if (! is_numeric($value) || abs((float) $value - round((float) $value)) > 0.000001) {
                        throw ValidationException::withMessages([
                            "items.{$index}.quantity" => ['كمية المرتجع الميداني لازم تكون رقمًا صحيحًا من غير كسور.'],
                        ]);
                    }
                }
            }

            $baseCurrency = strtoupper((string) DB::table('organizations')->where('id', $organizationId)->value('default_currency'));
            $requestedCurrency = strtoupper((string) ($data['currency'] ?? $baseCurrency));
            if ($requestedCurrency !== $baseCurrency) {
                throw ValidationException::withMessages(['currency' => ['Return currency must match the organization base currency until foreign-exchange accounting is enabled.']]);
            }

            $customer = DB::table('customers')->where('organization_id',$organizationId)->where('id',$data['customer_id'])->where('status','active')->firstOrFail();
            $location = DB::table('locations')->where('organization_id',$organizationId)->where('id',$data['location_id'])->where('status','active')->firstOrFail();

            if (empty($data['original_sales_invoice_id'])) {
                throw ValidationException::withMessages(['original_sales_invoice_id' => ['The original sales invoice is required for a return.']]);
            }

            $originalInvoice = DB::table('sales_invoices')
                ->where('organization_id', $organizationId)
                ->where('id', $data['original_sales_invoice_id'])
                ->where('customer_id', $customer->id)
                ->where('status', 'posted')
                ->lockForUpdate()
                ->first();

            if (!$originalInvoice) {
                throw ValidationException::withMessages(['original_sales_invoice_id' => ['The original invoice is not a posted invoice for this customer.']]);
            }
            if (bccomp((string) $originalInvoice->discount, '0', 4) > 0 || bccomp((string) $originalInvoice->tax, '0', 4) > 0) {
                throw ValidationException::withMessages(['original_sales_invoice_id' => ['Returns against invoices with header discounts or taxes are temporarily disabled until line-level return allocation is configured.']]);
            }

            $returnId=(string)Str::ulid();
            $documentNumber=$this->nextDocumentNumber($organizationId,'sales_return','SR');
            $subtotal='0.0000';
            $items=[];
            $pendingReturnedByLine=[];

            foreach($data['items'] as $line){
                $product=DB::table('products')->where('organization_id',$organizationId)->where('id',$line['product_id'])->where('active',true)->firstOrFail();
                $qty=(string)$line['quantity'];

                if (empty($line['original_sales_invoice_item_id'])) {
                    throw ValidationException::withMessages(['items' => ['Every returned item must reference a line on the original invoice.']]);
                }

                $original=DB::table('sales_invoice_items')
                    ->where('organization_id',$organizationId)
                    ->where('sales_invoice_id', $originalInvoice->id)
                    ->where('id',$line['original_sales_invoice_item_id'])
                    ->lockForUpdate()
                    ->first();
                if(!$original || $original->product_id !== $product->id){
                    throw ValidationException::withMessages(['items'=>['Original sales invoice item is invalid.']]);
                }
                // Trust the original invoice snapshot, never client-supplied unit economics.
                $conversion=(string)$original->conversion_factor_snapshot;
                $qtyBase=bcmul($qty,$conversion,6);
                $unitPrice=(string)$original->unit_price_entered;
                $lineTotal=bcmul($qty,$unitPrice,4);
                $returned=DB::table('sales_return_items')->where('organization_id',$organizationId)->where('original_sales_invoice_item_id',$original->id)->sum('quantity_base');
                $pendingReturned = $pendingReturnedByLine[$original->id] ?? '0.000000';
                $alreadyReturned = bcadd((string)$returned, $pendingReturned, 6);
                $remaining=bcsub((string)$original->quantity_base,(string)$alreadyReturned,6);
                if(bccomp($qtyBase,$remaining,6)>0) throw ValidationException::withMessages(['items'=>['Return quantity exceeds the sold quantity.']]);

                $saleAllocations = DB::table('stock_source_allocations')
                    ->where('organization_id', $organizationId)
                    ->where('source_document_type', 'sales_invoice')
                    ->where('source_document_id', $originalInvoice->id)
                    ->where('source_document_item_id', $original->id)
                    ->where('allocation_type', 'sale');
                if ($saleAllocations->exists()) {
                    $sourceAllocatedQuantity = (string) $saleAllocations->sum('quantity_base');
                    $sourceQuantityAvailable = bcsub($sourceAllocatedQuantity, (string) $alreadyReturned, 6);
                    if (bccomp($qtyBase, $sourceQuantityAvailable, 6) > 0) {
                        throw ValidationException::withMessages([
                            'items' => ['Source-lot allocations do not cover this return quantity. Reconcile the sales source trace before posting the return.'],
                        ]);
                    }
                }
                $pendingReturnedByLine[$original->id] = bcadd($pendingReturned, $qtyBase, 6);
                $cost=(string)$original->unit_cost_snapshot;
                $packagingId=$original->packaging_id;

                $items[]=compact('product','conversion','qty','qtyBase','unitPrice','lineTotal','cost','line','packagingId');
                $subtotal=bcadd($subtotal,$lineTotal,4);
            }

            $discount=(string)($data['discount'] ?? 0);
            $tax=(string)($data['tax'] ?? 0);
            $this->validateHeaderAdjustments('sales_return', $discount, $tax);
            if(bccomp($discount,$subtotal,4)>0) throw ValidationException::withMessages(['discount'=>['Discount cannot exceed the return subtotal.']]);
            $total=bcadd(bcsub($subtotal,$discount,4),$tax,4);

            $alreadyReturnedTotal = (string) DB::table('sales_returns')
                ->where('organization_id', $organizationId)
                ->where('original_sales_invoice_id', $originalInvoice->id)
                ->where('status', 'posted')
                ->sum('total');
            $remainingReceivable = bcsub(bcsub((string) $originalInvoice->total, (string) $originalInvoice->paid_amount, 4), $alreadyReturnedTotal, 4);
            if (bccomp($total, $remainingReceivable, 4) > 0) {
                throw ValidationException::withMessages(['original_sales_invoice_id' => ['Return exceeds the invoice receivable balance; customer credit/refund policy is not configured.']]);
            }

            DB::table('sales_returns')->insert([
                'id'=>$returnId,'organization_id'=>$organizationId,'customer_id'=>$customer->id,
                'source_location_id'=>$location->id,'original_sales_invoice_id'=>$data['original_sales_invoice_id'] ?? null,
                // Returns inherit the trip association from the authoritative source invoice.
                'trip_id'=>$originalInvoice->trip_id,'document_number'=>$documentNumber,'status'=>'posted',
                'return_date'=>$data['return_date'] ?? now()->toDateString(),'posted_at'=>now(),
                'subtotal'=>$subtotal,'discount'=>$data['discount'] ?? 0,'tax'=>$data['tax'] ?? 0,'total'=>$total,
                'currency'=>$baseCurrency,'created_by'=>$data['created_by'] ?? null,
                'device_id'=>$data['device_id'] ?? null,'idempotency_key'=>$data['idempotency_key'] ?? null,
                'created_at'=>now(),'updated_at'=>now(),
            ]);

            foreach($items as $item){
                $returnItemId = (string) Str::ulid();
                DB::table('sales_return_items')->insert([
                    'id'=>$returnItemId,'organization_id'=>$organizationId,'sales_return_id'=>$returnId,
                    'product_id'=>$item['product']->id,'original_sales_invoice_item_id'=>$item['line']['original_sales_invoice_item_id'] ?? null,
                    'packaging_id'=>$item['packagingId'],'entered_quantity'=>$item['qty'],
                    'conversion_factor_snapshot'=>$item['conversion'],'quantity_base'=>$item['qtyBase'],
                    'unit_price_base'=>bcdiv($item['unitPrice'],$item['conversion'],4),'unit_cost_snapshot'=>$item['cost'],
                    'line_total'=>$item['lineTotal'],'created_at'=>now(),'updated_at'=>now(),
                ]);

                $balance=DB::table('stock_balances')->where('organization_id',$organizationId)->where('product_id',$item['product']->id)->where('location_id',$location->id)->lockForUpdate()->first();
                $movementId = (string) Str::ulid();
                DB::table('stock_movements')->insert([
                    'id'=>$movementId,'organization_id'=>$organizationId,'transaction_uuid'=>(string)Str::uuid(),
                    'product_id'=>$item['product']->id,'location_id'=>$location->id,'movement_type'=>'sales_return',
                    'quantity_base'=>$item['qtyBase'],'unit_cost'=>$item['cost'],'source_document_type'=>'sales_return',
                    'source_document_id'=>$returnId,'occurred_at'=>now(),'posted_at'=>now(),'created_by'=>$data['created_by'] ?? null,
                    'device_id'=>$data['device_id'] ?? null,'trip_id'=>$originalInvoice->trip_id,'reference'=>$documentNumber,
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
                $this->restoreSalesReturnSourceLayers(
                    $organizationId,
                    $item['product']->id,
                    $location->id,
                    (string) ($balance->quantity_base ?? '0.000000'),
                    $item['qtyBase'],
                    $movementId,
                    $returnId,
                    $returnItemId,
                    (string) $item['line']['original_sales_invoice_item_id'],
                    $item['cost']
                );
                if($balance){
                    DB::table('stock_balances')->where('id',$balance->id)->update(['quantity_base'=>bcadd((string)$balance->quantity_base,$item['qtyBase'],6),'updated_at'=>now()]);
                }else{
                    DB::table('stock_balances')->insert([
                        'id'=>(string)Str::ulid(),'organization_id'=>$organizationId,'product_id'=>$item['product']->id,
                        'location_id'=>$location->id,'quantity_base'=>$item['qtyBase'],'reserved_quantity_base'=>0,'average_cost'=>$item['cost'],'updated_at'=>now(),
                    ]);
                }
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
            $this->publishDomainEvent($organizationId, 'sales.return.posted', 'sales_return', $returnId, [
                'document_number' => $documentNumber, 'customer_id' => $customer->id,
                'original_sales_invoice_id' => $originalInvoice->id, 'trip_id' => $originalInvoice->trip_id,
                'total' => $total, 'currency' => $baseCurrency, 'item_count' => count($items),
            ]);
            return $result;
        }, attempts:5);
    }

    public function postPurchaseReturn(string $organizationId, array $data): array
    {
        return DB::transaction(function () use ($organizationId, $data): array {
            if ($replay = $this->claimIdempotency($organizationId,'purchase_return.post',$data['idempotency_key'] ?? null,$data)) {
                return $this->replayReturn($organizationId,$replay,'purchase_return');
            }

            $baseCurrency = strtoupper((string) DB::table('organizations')->where('id', $organizationId)->value('default_currency'));
            $requestedCurrency = strtoupper((string) ($data['currency'] ?? $baseCurrency));
            if ($requestedCurrency !== $baseCurrency) {
                throw ValidationException::withMessages(['currency' => ['Return currency must match the organization base currency until foreign-exchange accounting is enabled.']]);
            }

            $supplier=DB::table('suppliers')->where('organization_id',$organizationId)->where('id',$data['supplier_id'])->where('active',true)->firstOrFail();
            $location=DB::table('locations')->where('organization_id',$organizationId)->where('id',$data['location_id'])->where('status','active')->firstOrFail();

            if (empty($data['original_purchase_invoice_id'])) {
                throw ValidationException::withMessages(['original_purchase_invoice_id' => ['The original purchase invoice is required for a return.']]);
            }

            $originalInvoice=DB::table('purchase_invoices')
                ->where('organization_id',$organizationId)
                ->where('id',$data['original_purchase_invoice_id'])
                ->where('supplier_id',$supplier->id)
                ->where('status','posted')
                ->lockForUpdate()
                ->first();

            if (!$originalInvoice) {
                throw ValidationException::withMessages(['original_purchase_invoice_id' => ['The original invoice is not a posted invoice for this supplier.']]);
            }
            if (bccomp((string) $originalInvoice->discount, '0', 4) > 0 || bccomp((string) $originalInvoice->tax, '0', 4) > 0) {
                throw ValidationException::withMessages(['original_purchase_invoice_id' => ['Returns against invoices with header discounts or taxes are temporarily disabled until line-level return allocation is configured.']]);
            }

            $returnId=(string)Str::ulid();
            $documentNumber=$this->nextDocumentNumber($organizationId,'purchase_return','PR');
            $subtotal='0.0000';
            $payableAmount='0.0000';
            $items=[];
            $pendingReturnedByLine=[];
            $requiredByProduct=[];

            foreach($data['items'] as $line){
                $product=DB::table('products')->where('organization_id',$organizationId)->where('id',$line['product_id'])->where('active',true)->firstOrFail();
                $qty=(string)$line['quantity'];

                if (empty($line['original_purchase_invoice_item_id'])) {
                    throw ValidationException::withMessages(['items' => ['Every returned item must reference a line on the original purchase invoice.']]);
                }

                $original=DB::table('purchase_invoice_items')
                    ->where('organization_id',$organizationId)
                    ->where('purchase_invoice_id',$originalInvoice->id)
                    ->where('id',$line['original_purchase_invoice_item_id'])
                    ->lockForUpdate()
                    ->first();
                if (!$original || $original->product_id !== $product->id) {
                    throw ValidationException::withMessages(['items' => ['Original purchase invoice item is invalid.']]);
                }
                // The supplier's received-unit conversion and cost are immutable invoice snapshots.
                $conversion=(string)$original->conversion_factor_snapshot;
                $qtyBase=bcmul($qty,$conversion,6);
                $unitCostEntered=(string)$original->unit_cost_entered;
                $unitCost=(string)$original->unit_cost_base;
                $lineTotal=bcmul($qty,$unitCostEntered,4);
                $linePayableAmount = ($original->settlement_method_snapshot ?? 'purchase_debt') === 'purchase_debt'
                    ? $lineTotal
                    : '0.0000';
                $returned=DB::table('purchase_return_items')->where('organization_id',$organizationId)->where('original_purchase_invoice_item_id',$original->id)->sum('quantity_base');
                $pendingReturned=$pendingReturnedByLine[$original->id] ?? '0.000000';
                $alreadyReturned=bcadd((string)$returned,$pendingReturned,6);
                $remaining=bcsub((string)$original->quantity_base,$alreadyReturned,6);
                if (bccomp($qtyBase,$remaining,6)>0) {
                    throw ValidationException::withMessages(['items' => ['Purchase return quantity exceeds the received quantity on the original invoice.']]);
                }
                $pendingReturnedByLine[$original->id]=bcadd($pendingReturned,$qtyBase,6);
                $balance=DB::table('stock_balances')->where('organization_id',$organizationId)->where('product_id',$product->id)->where('location_id',$location->id)->lockForUpdate()->first();
                $available=(string)($balance->quantity_base ?? 0);
                $requiredQuantity=bcadd((string)($requiredByProduct[$product->id] ?? '0.000000'),$qtyBase,6);
                if(bccomp($available,$requiredQuantity,6)<0) throw ValidationException::withMessages(['items'=>['Stock is insufficient for this purchase return.']]);
                $requiredByProduct[$product->id]=$requiredQuantity;

                $packagingId=$original->packaging_id;
                $items[]=compact('product','conversion','qty','qtyBase','unitCost','lineTotal','linePayableAmount','balance','line','packagingId');
                $subtotal=bcadd($subtotal,$lineTotal,4);
                $payableAmount=bcadd($payableAmount,$linePayableAmount,4);
            }

            $discount=(string)($data['discount'] ?? 0);
            $tax=(string)($data['tax'] ?? 0);
            $this->validateHeaderAdjustments('purchase_return', $discount, $tax);
            if(bccomp($discount,$subtotal,4)>0) throw ValidationException::withMessages(['discount'=>['Discount cannot exceed the return subtotal.']]);
            $total=bcadd(bcsub($subtotal,$discount,4),$tax,4);

            $alreadyReturnedTotal = (string) DB::table('purchase_returns')
                ->where('organization_id', $organizationId)
                ->where('original_purchase_invoice_id', $originalInvoice->id)
                ->where('status', 'posted')
                ->sum('payable_amount');
            $remainingPayable = bcsub(bcsub((string) ($originalInvoice->payable_amount ?? $originalInvoice->total), (string) $originalInvoice->paid_amount, 4), $alreadyReturnedTotal, 4);
            if (bccomp($payableAmount, $remainingPayable, 4) > 0) {
                throw ValidationException::withMessages(['original_purchase_invoice_id' => ['قيمة تسوية المرتجع تتجاوز المستحق المتبقي على فاتورة المورد الأصلية.']]);
            }

            DB::table('purchase_returns')->insert([
                'id'=>$returnId,'organization_id'=>$organizationId,'supplier_id'=>$supplier->id,'location_id'=>$location->id,
                'original_purchase_invoice_id'=>$data['original_purchase_invoice_id'] ?? null,'document_number'=>$documentNumber,
                'status'=>'posted','return_date'=>$data['return_date'] ?? now()->toDateString(),'posted_at'=>now(),
                'subtotal'=>$subtotal,'discount'=>$data['discount'] ?? 0,'tax'=>$data['tax'] ?? 0,'total'=>$total,'payable_amount'=>$payableAmount,
                'currency'=>$baseCurrency,'created_by'=>$data['created_by'] ?? null,'device_id'=>$data['device_id'] ?? null,
                'idempotency_key'=>$data['idempotency_key'] ?? null,'created_at'=>now(),'updated_at'=>now(),
            ]);

            foreach($items as $item){
                $returnItemId = (string) Str::ulid();
                DB::table('purchase_return_items')->insert([
                    'id'=>$returnItemId,'organization_id'=>$organizationId,'purchase_return_id'=>$returnId,
                    'product_id'=>$item['product']->id,'original_purchase_invoice_item_id'=>$item['line']['original_purchase_invoice_item_id'] ?? null,
                    'packaging_id'=>$item['packagingId'],'entered_quantity'=>$item['qty'],
                    'conversion_factor_snapshot'=>$item['conversion'],'quantity_base'=>$item['qtyBase'],
                    'unit_cost_base'=>$item['unitCost'],'line_total'=>$item['lineTotal'],'payable_amount'=>$item['linePayableAmount'],'created_at'=>now(),'updated_at'=>now(),
                ]);

                $currentBalance=DB::table('stock_balances')->where('organization_id',$organizationId)->where('product_id',$item['product']->id)->where('location_id',$location->id)->lockForUpdate()->first();
                if(!$currentBalance) throw ValidationException::withMessages(['items'=>['Stock balance disappeared during purchase return posting.']]);
                $movementId = (string) Str::ulid();
                DB::table('stock_movements')->insert([
                    'id'=>$movementId,'organization_id'=>$organizationId,'transaction_uuid'=>(string)Str::uuid(),
                    'product_id'=>$item['product']->id,'location_id'=>$location->id,'movement_type'=>'purchase_return',
                    'quantity_base'=>bcsub('0',$item['qtyBase'],6),'unit_cost'=>$item['unitCost'],
                    'source_document_type'=>'purchase_return','source_document_id'=>$returnId,'occurred_at'=>now(),
                    'posted_at'=>now(),'created_by'=>$data['created_by'] ?? null,'device_id'=>$data['device_id'] ?? null,
                    'reference'=>$documentNumber,'created_at'=>now(),'updated_at'=>now(),
                ]);
                $this->consumeSourceLayers(
                    $organizationId,
                    $item['product']->id,
                    $location->id,
                    (string) $currentBalance->quantity_base,
                    $item['qtyBase'],
                    $movementId,
                    'purchase_return',
                    $returnId,
                    $returnItemId,
                    'purchase_return',
                    (string) $item['line']['original_purchase_invoice_item_id'],
                    $item['unitCost'],
                    true
                );
                DB::table('stock_balances')->where('id',$currentBalance->id)->update([
                    'quantity_base'=>bcsub((string)$currentBalance->quantity_base,$item['qtyBase'],6),'updated_at'=>now()
                ]);
            }

            $accounts=$this->ensureLedgerAccounts($organizationId);
            $tx=(string)Str::uuid();
            $returnedSourceCost = $this->returnCost($items,true);
            $deferredSupplierPayableReduction = bcsub($returnedSourceCost, $payableAmount, 4);
            if (bccomp($payableAmount, '0.0000', 4) > 0) {
                $this->ledger($organizationId,$tx,$accounts['accounts_payable'],$payableAmount,0,'purchase_return',$returnId);
            }
            if (bccomp($deferredSupplierPayableReduction, '0.0000', 4) > 0) {
                $this->ledger($organizationId,$tx,$accounts['deferred_supplier_payables'],$deferredSupplierPayableReduction,0,'purchase_return',$returnId);
            }
            $this->ledger($organizationId,$tx,$accounts['inventory'],0,$returnedSourceCost,'purchase_return',$returnId);

            $this->adjustSupplierBalanceSummary($organizationId,$supplier->id,[
                'total_returns'=>$payableAmount,'outstanding'=>bcsub('0',$payableAmount,4),
            ],false);

            $result=['id'=>$returnId,'document_number'=>$documentNumber,'total'=>$total,'payable_amount'=>$payableAmount,'status'=>'posted'];
            $this->completeIdempotency($organizationId,'purchase_return.post',$data['idempotency_key'] ?? null,'purchase_return:'.$returnId);
            $this->publishDomainEvent($organizationId, 'purchase.return.posted', 'purchase_return', $returnId, [
                'document_number' => $documentNumber, 'supplier_id' => $supplier->id,
                'original_purchase_invoice_id' => $originalInvoice->id, 'total' => $total,
                'payable_amount' => $payableAmount, 'currency' => $baseCurrency, 'item_count' => count($items),
            ]);
            return $result;
        }, attempts:5);
    }

    private function validateHeaderAdjustments(string $documentType, string $discount, string $tax): void
    {
        if (bccomp($discount, '0', 4) < 0) {
            throw ValidationException::withMessages(['discount' => ['Discount cannot be negative.']]);
        }
        if (bccomp($tax, '0', 4) < 0) {
            throw ValidationException::withMessages(['tax' => ['Tax cannot be negative.']]);
        }
        if (bccomp($tax, '0', 4) > 0) {
            throw ValidationException::withMessages(['tax' => ['Tax posting is temporarily disabled until tax-account and return-allocation policy is configured.']]);
        }
        if (in_array($documentType, ['purchase', 'purchase_return'], true) && bccomp($discount, '0', 4) > 0) {
            throw ValidationException::withMessages(['discount' => ['Purchase discount posting is temporarily disabled until inventory valuation and return-allocation policy is configured.']]);
        }
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

            $baseCurrency = strtoupper((string) DB::table('organizations')->where('id', $organizationId)->value('default_currency'));
            $requestedCurrency = strtoupper((string) ($data['currency'] ?? $baseCurrency));
            if ($requestedCurrency !== $baseCurrency) {
                throw ValidationException::withMessages(['currency' => ['Payment currency must match the organization base currency until foreign-exchange accounting is enabled.']]);
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

            if (!empty($data['trip_id'])) {
                $trip = DB::table('trips')->where('organization_id', $organizationId)->where('id', $data['trip_id'])->first();
                if (!$trip || in_array($trip->status, ['completed', 'cancelled'], true)) {
                    throw ValidationException::withMessages(['trip_id' => ['The selected trip is missing or closed.']]);
                }
                if ($partyType !== 'customer' || $direction !== 'inbound') {
                    throw ValidationException::withMessages(['trip_id' => ['Only customer collections can be linked to a distribution trip.']]);
                }
            }

            $partyTable = $partyType === 'customer' ? 'customers' : 'suppliers';
            $party = DB::table($partyTable)
                ->where('organization_id',$organizationId)
                ->where('id',$partyId)
                ->first();

            if (!$party) {
                throw ValidationException::withMessages(['party_id'=>['Party was not found in this organization.']]);
            }

            $tripSupplierSettlementId = $data['trip_supplier_settlement_id'] ?? null;
            if ($tripSupplierSettlementId !== null) {
                if ($partyType !== 'supplier' || $direction !== 'outbound') {
                    throw ValidationException::withMessages(['trip_supplier_settlement_id' => ['A trip supplier settlement can only be linked to a payment made to that supplier.']]);
                }
                $supplierSettlement = DB::table('trip_supplier_settlements')
                    ->where('organization_id', $organizationId)
                    ->where('id', $tripSupplierSettlementId)
                    ->where('supplier_id', $partyId)
                    ->lockForUpdate()
                    ->first();
                if (! $supplierSettlement) {
                    throw ValidationException::withMessages(['trip_supplier_settlement_id' => ['The selected trip settlement does not belong to this supplier.']]);
                }
                if (! in_array((string) ($supplierSettlement->settlement_basis ?? 'legacy_source_cost'), ['sold_goods', 'mixed'], true)) {
                    throw ValidationException::withMessages(['trip_supplier_settlement_id' => ['هذه تسوية تاريخية مرجعية بتكلفة المصدر وليست التزامًا ماليًا جديدًا قابلًا للصرف. راجع مديونية فاتورة الشراء أو صنّف التسوية بموافقة محاسبية قبل السداد.']]);
                }
                $alreadyPaid = (string) DB::table('payments')
                    ->where('organization_id', $organizationId)
                    ->where('trip_supplier_settlement_id', $tripSupplierSettlementId)
                    ->where('status', 'posted')
                    ->sum('amount');
                $settlementRemaining = bcsub((string) $supplierSettlement->settlement_amount, $alreadyPaid, 4);
                if (bccomp($settlementRemaining, '0.0000', 4) < 0) $settlementRemaining = '0.0000';
                if (bccomp($settlementRemaining, '0.0000', 4) <= 0 || bccomp($amount, $settlementRemaining, 4) > 0) {
                    throw ValidationException::withMessages(['amount' => ['Payment exceeds the remaining amount on this trip supplier settlement.']]);
                }
            }

            $account = DB::table('financial_accounts')
                ->where('organization_id',$organizationId)
                ->where('id',$data['financial_account_id'])
                ->where('active',true)
                ->first();

            if (!$account) {
                throw ValidationException::withMessages(['financial_account_id'=>['Financial account was not found or is inactive.']]);
            }
            if (strtoupper((string) ($account->currency ?? $baseCurrency)) !== $baseCurrency) {
                throw ValidationException::withMessages(['financial_account_id' => ['Financial account currency must match the organization base currency until foreign-exchange accounting is enabled.']]);
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
            $allocatedByDocument = [];
            // Acquire invoice row locks in the same deterministic order for every
            // multi-invoice payment. This reduces avoidable MySQL deadlocks when
            // two operators allocate receipts/payments across the same invoices
            // in opposite UI order. The enclosing transaction still retries deadlocks.
            $allocationRequests = array_values($data['allocations'] ?? []);
            usort($allocationRequests, static function (array $left, array $right): int {
                $leftKey = (string) ($left['document_type'] ?? '').':'.(string) ($left['document_id'] ?? '');
                $rightKey = (string) ($right['document_type'] ?? '').':'.(string) ($right['document_id'] ?? '');
                return strcmp($leftKey, $rightKey);
            });

            foreach ($allocationRequests as $allocation) {
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

                if ($partyType === 'customer') {
                    $due = (string) $document->balance_due;
                } else {
                    $purchaseReturns = (string) DB::table('purchase_returns')
                        ->where('organization_id', $organizationId)
                        ->where('original_purchase_invoice_id', $document->id)
                        ->where('status', 'posted')
                        ->sum('payable_amount');
                    $due = bcsub(bcsub((string) ($document->payable_amount ?? $document->total), (string) $document->paid_amount, 4), $purchaseReturns, 4);
                }

                $allocationKey = $allocation['document_type'].':'.$document->id;
                $allocatedBefore = (string) ($allocatedByDocument[$allocationKey] ?? '0.0000');
                $remainingDue = bcsub($due, $allocatedBefore, 4);
                if (bccomp($allocationAmount,$remainingDue,4) > 0) {
                    throw ValidationException::withMessages(['allocations'=>['Allocation exceeds document outstanding balance.']]);
                }

                $validatedAllocations[] = [
                    'document_type'=>$allocation['document_type'],
                    'document_id'=>$document->id,
                    'amount'=>$allocationAmount,
                ];

                $remaining = bcsub($remaining,$allocationAmount,4);
                $allocatedByDocument[$allocationKey] = bcadd($allocatedBefore, $allocationAmount, 4);
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
                'currency'=>$baseCurrency,
                'payment_date'=>$data['payment_date'] ?? now()->toDateString(),
                'reference'=>$data['reference'] ?? null,
                'status'=>'posted',
                'trip_id'=>$data['trip_id'] ?? null,
                'trip_supplier_settlement_id'=>$tripSupplierSettlementId,
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
            $this->publishDomainEvent($organizationId, 'finance.payment.posted', 'payment', $paymentId, [
                'party_type' => $partyType, 'party_id' => $partyId, 'direction' => $direction,
                'amount' => $amount, 'currency' => $baseCurrency, 'trip_id' => $data['trip_id'] ?? null,
                'allocation_count' => count($validatedAllocations),
            ]);
            return $result;
        }, attempts:5);
    }

    /**
     * Capture the vehicle's stock once, immediately before the first trip-linked
     * stock movement. A pre-existing movement without a snapshot is legacy data:
     * its true opening balance cannot be inferred from today's mutable balance.
     */
    private function captureTripOpeningStockSnapshot(string $organizationId, object $trip, object $vehicle, ?string $capturedBy = null): ?object
    {
        $existing = DB::table('trip_stock_opening_snapshots')
            ->where('organization_id', $organizationId)->where('trip_id', $trip->id)->first();
        if ($existing) {
            return $existing;
        }

        if (DB::table('stock_movements')->where('organization_id', $organizationId)->where('trip_id', $trip->id)->exists()) {
            return null;
        }

        $locationId = (string) $vehicle->location_id;
        $balances = DB::table('stock_balances')
            ->where('organization_id', $organizationId)
            ->where('location_id', $locationId)
            ->where('quantity_base', '>', 0)
            ->orderBy('product_id')
            ->lockForUpdate()
            ->get(['product_id', 'quantity_base', 'average_cost']);

        $snapshotId = (string) Str::ulid();
        $capturedAt = now();
        $userId = $capturedBy && DB::table('users')->where('organization_id', $organizationId)->where('id', $capturedBy)->exists()
            ? $capturedBy
            : null;

        DB::table('trip_stock_opening_snapshots')->insert([
            'id' => $snapshotId,
            'organization_id' => $organizationId,
            'trip_id' => $trip->id,
            'vehicle_id' => $vehicle->id,
            'location_id' => $locationId,
            'captured_by' => $userId,
            'captured_at' => $capturedAt,
            'created_at' => $capturedAt,
            'updated_at' => $capturedAt,
        ]);

        foreach ($balances as $balance) {
            DB::table('trip_stock_opening_snapshot_items')->insert([
                'id' => (string) Str::ulid(),
                'organization_id' => $organizationId,
                'snapshot_id' => $snapshotId,
                'trip_id' => $trip->id,
                'product_id' => $balance->product_id,
                'location_id' => $locationId,
                'opening_quantity_base' => $balance->quantity_base,
                'opening_average_cost' => $balance->average_cost ?? '0.0000',
                'created_at' => $capturedAt,
                'updated_at' => $capturedAt,
            ]);
        }

        return DB::table('trip_stock_opening_snapshots')
            ->where('organization_id', $organizationId)->where('id', $snapshotId)->first();
    }

    /** Persist the event on the same connection/transaction as the source document. */
    private function publishDomainEvent(string $organizationId, string $eventType, string $aggregateType, string $aggregateId, array $data): void
    {
        app(MessageOutboxPublisher::class)->publish($organizationId, $eventType, $aggregateType, $aggregateId, $data);
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

        return [
            'id'=>$invoice->id,'document_number'=>$invoice->document_number,'total'=>$invoice->total,
            'payable_amount'=>$invoice->payable_amount ?? $invoice->total,'status'=>$invoice->status,
        ];
    }

    private function replayTripLoad(string $organizationId, string $reference): array
    {
        [$type,$id,$loadNumber]=array_pad(explode(':',$reference,3),3,null);
        if($type !== 'trip_load' || !$id || !$loadNumber) throw ValidationException::withMessages(['idempotency_key'=>['Stored trip load response is invalid.']]);
        $load=DB::table('trip_loads')->where('organization_id',$organizationId)->where('id',$id)->first();
        if(!$load) throw ValidationException::withMessages(['idempotency_key'=>['Stored trip load response could not be replayed.']]);
        return ['id'=>$load->id,'load_number'=>$loadNumber,'trip_id'=>$load->trip_id,'vehicle_id'=>$load->to_vehicle_id,'status'=>$load->status];
    }

    private function replayStockTransfer(string $organizationId, string $reference): array
    {
        [$type, $id, $documentNumber] = array_pad(explode(':', $reference, 3), 3, null);
        if ($type !== 'stock_transfer' || ! $id || ! $documentNumber) {
            throw ValidationException::withMessages(['idempotency_key' => ['بيانات إعادة التحويل المخزني غير مكتملة.']]);
        }
        $transfer = DB::table('stock_transfers as transfer')
            ->join('locations as source', fn ($join) => $join->on('source.id', '=', 'transfer.from_location_id')->on('source.organization_id', '=', 'transfer.organization_id'))
            ->join('locations as destination', fn ($join) => $join->on('destination.id', '=', 'transfer.to_location_id')->on('destination.organization_id', '=', 'transfer.organization_id'))
            ->where('transfer.organization_id', $organizationId)->where('transfer.id', $id)
            ->select('transfer.*', 'source.name as from_location_name', 'destination.name as to_location_name')->first();
        if (! $transfer) {
            throw ValidationException::withMessages(['idempotency_key' => ['تعذر استرجاع التحويل المخزني المحفوظ.']]);
        }
        $items = DB::table('stock_transfer_items as item')->join('products as product', function ($join) {
            $join->on('product.id', '=', 'item.product_id')->on('product.organization_id', '=', 'item.organization_id');
        })->where('item.organization_id', $organizationId)->where('item.stock_transfer_id', $id)
            ->select('item.product_id', 'product.sku', 'product.name_ar', 'item.quantity_base', 'item.unit_cost')->get()
            ->map(fn ($item) => ['product_id' => $item->product_id, 'sku' => $item->sku, 'name_ar' => $item->name_ar,
                'quantity_base' => (float) $item->quantity_base, 'unit_cost' => (float) $item->unit_cost])->values()->all();

        return ['id' => $transfer->id, 'document_number' => $documentNumber, 'status' => $transfer->status,
            'from_location_id' => $transfer->from_location_id, 'from_location_name' => $transfer->from_location_name,
            'to_location_id' => $transfer->to_location_id, 'to_location_name' => $transfer->to_location_name,
            'reason' => $transfer->reason, 'total_quantity_base' => array_sum(array_column($items, 'quantity_base')), 'items' => $items,
            'replayed' => true];
    }

    private function replayTripSettlement(string $organizationId, string $reference): array
    {
        [$type, $id] = array_pad(explode(':', $reference, 2), 2, null);
        if ($type !== 'trip_settlement' || !$id) {
            throw ValidationException::withMessages(['idempotency_key' => ['Stored trip settlement response is invalid.']]);
        }

        $settlement = DB::table('trip_settlements')
            ->where('organization_id', $organizationId)
            ->where('id', $id)
            ->first();

        if (!$settlement) {
            throw ValidationException::withMessages(['idempotency_key' => ['Stored trip settlement could not be replayed.']]);
        }

        return [
            'id' => $settlement->id,
            'trip_id' => $settlement->trip_id,
            'status' => $settlement->status,
            'opening_stock_basis' => $settlement->opening_stock_basis ?? 'legacy_zero',
            'opening_stock_note' => ($settlement->opening_stock_basis ?? 'legacy_zero') === 'snapshot'
                ? 'تم احتساب رصيد بداية الرحلة من لقطة محفوظة قبل أول حركة مخزون مرتبطة بها.'
                : 'هذه رحلة قديمة لها حركات سابقة بلا لقطة افتتاحية؛ لم يتم تخمين مخزون البداية واستُخدم الصفر للحفاظ على دقة السجل التاريخي.',
            'expected_cash' => $settlement->expected_cash,
            'actual_cash' => $settlement->actual_cash,
            'cash_variance' => $settlement->cash_variance,
            'stock_variance_value' => $settlement->stock_variance_value,
            'supplier_settlements' => $this->previewTripSupplierSettlement($organizationId, $settlement->trip_id)['suppliers'],
        ];
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

    private function replayExpense(string $organizationId, string $reference): array
    {
        [$type, $id] = array_pad(explode(':', $reference, 2), 2, null);
        if ($type !== 'expense' || ! $id) {
            throw ValidationException::withMessages(['idempotency_key' => ['Stored expense response is invalid.']]);
        }

        $expense = DB::table('expenses')
            ->where('organization_id', $organizationId)
            ->where('id', $id)
            ->first();

        if (! $expense) {
            throw ValidationException::withMessages(['idempotency_key' => ['Stored expense response could not be replayed.']]);
        }

        return [
            'id' => $expense->id,
            'category' => $expense->category,
            'amount' => $expense->amount,
            'financial_account_id' => $expense->financial_account_id,
            'trip_id' => $expense->trip_id,
            'vehicle_id' => $expense->vehicle_id,
            'expense_date' => $expense->expense_date,
            'status' => $expense->status,
        ];
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
            'deferred_supplier_payables'=>['2110','Deferred Supplier Payables','liability'],
            'supplier_settlement_variance_expense'=>['5300','Supplier Settlement Variance Expense','expense'],
            'supplier_settlement_variance_gain'=>['4905','Supplier Settlement Variance Gain','revenue'],
            'cash'=>['1000','Cash','asset'],
            'employee_custody'=>['1250','Employee Cash Custody','asset'],
            'other_income'=>['4900','Other Income','revenue'],
            'owner_drawings'=>['3100','Owner Drawings','equity'],
            'salary_expenses'=>['5200','Salaries and Wages','expense'],
            'opening_equity'=>['3000','Opening Balance Equity','equity'],
            'sales_revenue'=>['4000','Sales Revenue','revenue'],
            'cogs'=>['5000','Cost of Goods Sold','expense'],
            'operating_expenses'=>['5100','Operating Expenses','expense'],
            'sales_returns'=>['4100','Sales Returns','revenue'],
        ];
        $ids=[];
        foreach($map as $key=>[$code,$name,$type]){
            $row=DB::table('ledger_accounts')->where('organization_id',$organizationId)->where('code',$code)->first();
            if(!$row){
                $id=(string)Str::ulid();
                DB::table('ledger_accounts')->insert([
                    'id'=>$id,'organization_id'=>$organizationId,'code'=>$code,'name'=>$name,'type'=>$type,
                    'currency'=>strtoupper((string) DB::table('organizations')->where('id', $organizationId)->value('default_currency')),'active'=>true,'created_at'=>now(),'updated_at'=>now(),
                ]);
                $ids[$key]=$id;
            }else $ids[$key]=$row->id;
        }
        return $ids;
    }

    /**
     * Establish an auditable quantity layer for inventory that predates source
     * tracking. Such quantity is explicitly marked unattributed; it is never
     * silently assigned to a supplier invoice.
     */
    private function ensureSourceLayerCoverage(
        string $organizationId,
        string $productId,
        string $locationId,
        string $actualQuantity,
        string $unitCost
    ): void {
        $layerQuantity = (string) DB::table('stock_source_layers')
            ->where('organization_id', $organizationId)
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->sum('quantity_remaining_base');
        $difference = bcsub($actualQuantity, $layerQuantity, 6);

        if (bccomp($difference, '0', 6) > 0) {
            $this->createSourceLayer(
                $organizationId,
                $productId,
                $locationId,
                null,
                null,
                null,
                'legacy_unattributed',
                null,
                null,
                $difference,
                $unitCost
            );
            return;
        }

        if (bccomp($difference, '0', 6) < 0) {
            throw ValidationException::withMessages([
                'inventory' => ['Source-lot quantity is greater than the stock balance. Reconcile inventory before posting another transaction.'],
            ]);
        }
    }

    private function createSourceLayer(
        string $organizationId,
        string $productId,
        string $locationId,
        ?string $purchaseInvoiceId,
        ?string $purchaseInvoiceItemId,
        ?string $parentLayerId,
        string $originType,
        ?string $originDocumentType,
        ?string $originDocumentId,
        string $quantityBase,
        string $unitCostBase
    ): string {
        if (bccomp($quantityBase, '0', 6) <= 0) {
            throw ValidationException::withMessages(['inventory' => ['A source layer must contain a positive quantity.']]);
        }

        $id = (string) Str::ulid();
        DB::table('stock_source_layers')->insert([
            'id' => $id,
            'organization_id' => $organizationId,
            'product_id' => $productId,
            'location_id' => $locationId,
            'source_purchase_invoice_id' => $purchaseInvoiceId,
            'source_purchase_invoice_item_id' => $purchaseInvoiceItemId,
            'parent_layer_id' => $parentLayerId,
            'origin_type' => $originType,
            'origin_document_type' => $originDocumentType,
            'origin_document_id' => $originDocumentId,
            'quantity_received_base' => $quantityBase,
            'quantity_remaining_base' => $quantityBase,
            'unit_cost_base' => $unitCostBase,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * Consume source layers for a movement and record which purchase invoice
     * lines supplied the quantity. This is traceability only; GL COGS remains
     * on the approved weighted-average snapshot stored by the sale engine.
     */
    private function consumeSourceLayers(
        string $organizationId,
        string $productId,
        string $locationId,
        string $actualQuantity,
        string $quantityToConsume,
        string $movementId,
        string $sourceDocumentType,
        string $sourceDocumentId,
        ?string $sourceDocumentItemId,
        string $allocationType,
        ?string $preferredPurchaseItemId = null,
        string $legacyUnitCost = '0.0000',
        bool $strictSourcePreference = false
    ): void {
        $this->ensureSourceLayerCoverage($organizationId, $productId, $locationId, $actualQuantity, $legacyUnitCost);

        $layers = DB::table('stock_source_layers')
            ->where('organization_id', $organizationId)
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->whereRaw('quantity_remaining_base > 0')
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($preferredPurchaseItemId !== null) {
            if ($strictSourcePreference) {
                $layers = $layers->filter(static fn (object $layer): bool =>
                    $layer->source_purchase_invoice_item_id === $preferredPurchaseItemId
                    || $layer->source_purchase_invoice_item_id === null
                )->values();
            }
            $layers = $layers->sortBy(function (object $layer) use ($preferredPurchaseItemId): int {
                if ($layer->source_purchase_invoice_item_id === $preferredPurchaseItemId) return 0;
                if ($layer->source_purchase_invoice_item_id === null) return 1;
                return 2;
            })->values();
        }

        $remaining = $quantityToConsume;
        foreach ($layers as $layer) {
            if (bccomp($remaining, '0', 6) <= 0) break;
            $available = (string) $layer->quantity_remaining_base;
            $take = bccomp($available, $remaining, 6) <= 0 ? $available : $remaining;
            if (bccomp($take, '0', 6) <= 0) continue;

            DB::table('stock_source_layers')
                ->where('organization_id', $organizationId)
                ->where('id', $layer->id)
                ->update([
                    'quantity_remaining_base' => bcsub($available, $take, 6),
                    'updated_at' => now(),
                ]);

            DB::table('stock_source_allocations')->insert([
                'id' => (string) Str::ulid(),
                'organization_id' => $organizationId,
                'source_layer_id' => $layer->id,
                'destination_layer_id' => null,
                'stock_movement_id' => $movementId,
                'reverses_allocation_id' => null,
                'source_purchase_invoice_id' => $layer->source_purchase_invoice_id,
                'source_purchase_invoice_item_id' => $layer->source_purchase_invoice_item_id,
                'source_document_type' => $sourceDocumentType,
                'source_document_id' => $sourceDocumentId,
                'source_document_item_id' => $sourceDocumentItemId,
                'allocation_type' => $allocationType,
                'quantity_base' => $take,
                'unit_cost_base' => $layer->unit_cost_base,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $remaining = bcsub($remaining, $take, 6);
        }

        if (bccomp($remaining, '0', 6) > 0) {
            throw ValidationException::withMessages([
                'inventory' => ['Source-lot quantities do not cover the requested movement. Reconcile inventory before posting.'],
            ]);
        }
    }

    private function transferSourceLayers(
        string $organizationId,
        string $productId,
        string $sourceLocationId,
        string $destinationLocationId,
        string $sourceQuantity,
        string $destinationQuantity,
        string $quantityToTransfer,
        string $sourceMovementId,
        string $loadId,
        string $loadItemId,
        string $legacyUnitCost = '0.0000'
    ): void {
        $this->ensureSourceLayerCoverage($organizationId, $productId, $sourceLocationId, $sourceQuantity, $legacyUnitCost);
        $this->ensureSourceLayerCoverage($organizationId, $productId, $destinationLocationId, $destinationQuantity, $legacyUnitCost);

        $layers = DB::table('stock_source_layers')
            ->where('organization_id', $organizationId)
            ->where('product_id', $productId)
            ->where('location_id', $sourceLocationId)
            ->whereRaw('quantity_remaining_base > 0')
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $remaining = $quantityToTransfer;
        foreach ($layers as $layer) {
            if (bccomp($remaining, '0', 6) <= 0) break;
            $available = (string) $layer->quantity_remaining_base;
            $take = bccomp($available, $remaining, 6) <= 0 ? $available : $remaining;

            DB::table('stock_source_layers')->where('organization_id', $organizationId)->where('id', $layer->id)->update([
                'quantity_remaining_base' => bcsub($available, $take, 6),
                'updated_at' => now(),
            ]);

            $destinationLayerId = $this->createSourceLayer(
                $organizationId,
                $productId,
                $destinationLocationId,
                $layer->source_purchase_invoice_id,
                $layer->source_purchase_invoice_item_id,
                $layer->id,
                'vehicle_transfer',
                'trip_load',
                $loadId,
                $take,
                (string) $layer->unit_cost_base
            );

            DB::table('stock_source_allocations')->insert([
                'id' => (string) Str::ulid(),
                'organization_id' => $organizationId,
                'source_layer_id' => $layer->id,
                'destination_layer_id' => $destinationLayerId,
                'stock_movement_id' => $sourceMovementId,
                'reverses_allocation_id' => null,
                'source_purchase_invoice_id' => $layer->source_purchase_invoice_id,
                'source_purchase_invoice_item_id' => $layer->source_purchase_invoice_item_id,
                'source_document_type' => 'trip_load',
                'source_document_id' => $loadId,
                'source_document_item_id' => $loadItemId,
                'allocation_type' => 'transfer',
                'quantity_base' => $take,
                'unit_cost_base' => $layer->unit_cost_base,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $remaining = bcsub($remaining, $take, 6);
        }

        if (bccomp($remaining, '0', 6) > 0) {
            throw ValidationException::withMessages([
                'inventory' => ['Source-lot quantities do not cover the trip load. Reconcile inventory before loading the vehicle.'],
            ]);
        }
    }

    private function restoreSalesReturnSourceLayers(
        string $organizationId,
        string $productId,
        string $locationId,
        string $actualQuantity,
        string $quantityToRestore,
        string $returnMovementId,
        string $returnId,
        string $returnItemId,
        string $originalSalesItemId,
        string $fallbackUnitCost
    ): void {
        $this->ensureSourceLayerCoverage($organizationId, $productId, $locationId, $actualQuantity, $fallbackUnitCost);

        $saleAllocations = DB::table('stock_source_allocations')
            ->where('organization_id', $organizationId)
            ->where('source_document_type', 'sales_invoice')
            ->where('source_document_item_id', $originalSalesItemId)
            ->where('allocation_type', 'sale')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $remaining = $quantityToRestore;
        foreach ($saleAllocations as $saleAllocation) {
            if (bccomp($remaining, '0', 6) <= 0) break;
            $alreadyRestored = (string) DB::table('stock_source_allocations')
                ->where('organization_id', $organizationId)
                ->where('allocation_type', 'sales_return')
                ->where('reverses_allocation_id', $saleAllocation->id)
                ->sum('quantity_base');
            $unreturned = bcsub((string) $saleAllocation->quantity_base, $alreadyRestored, 6);
            if (bccomp($unreturned, '0', 6) <= 0) continue;

            $take = bccomp($unreturned, $remaining, 6) <= 0 ? $unreturned : $remaining;
            $returnedLayerId = $this->createSourceLayer(
                $organizationId,
                $productId,
                $locationId,
                $saleAllocation->source_purchase_invoice_id,
                $saleAllocation->source_purchase_invoice_item_id,
                $saleAllocation->source_layer_id,
                'sales_return',
                'sales_return',
                $returnId,
                $take,
                (string) $saleAllocation->unit_cost_base
            );

            DB::table('stock_source_allocations')->insert([
                'id' => (string) Str::ulid(),
                'organization_id' => $organizationId,
                'source_layer_id' => $returnedLayerId,
                'destination_layer_id' => null,
                'stock_movement_id' => $returnMovementId,
                'reverses_allocation_id' => $saleAllocation->id,
                'source_purchase_invoice_id' => $saleAllocation->source_purchase_invoice_id,
                'source_purchase_invoice_item_id' => $saleAllocation->source_purchase_invoice_item_id,
                'source_document_type' => 'sales_return',
                'source_document_id' => $returnId,
                'source_document_item_id' => $returnItemId,
                'allocation_type' => 'sales_return',
                'quantity_base' => $take,
                'unit_cost_base' => $saleAllocation->unit_cost_base,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $remaining = bcsub($remaining, $take, 6);
        }

        if (bccomp($remaining, '0', 6) > 0) {
            $layerId = $this->createSourceLayer(
                $organizationId, $productId, $locationId, null, null, null,
                'legacy_unattributed', 'sales_return', $returnId, $remaining, $fallbackUnitCost
            );
            DB::table('stock_source_allocations')->insert([
                'id' => (string) Str::ulid(),
                'organization_id' => $organizationId,
                'source_layer_id' => $layerId,
                'destination_layer_id' => null,
                'stock_movement_id' => $returnMovementId,
                'reverses_allocation_id' => null,
                'source_purchase_invoice_id' => null,
                'source_purchase_invoice_item_id' => null,
                'source_document_type' => 'sales_return',
                'source_document_id' => $returnId,
                'source_document_item_id' => $returnItemId,
                'allocation_type' => 'sales_return',
                'quantity_base' => $remaining,
                'unit_cost_base' => $fallbackUnitCost,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function ledger(string $organizationId,string $tx,string $accountId,string $debit,string $credit,string $sourceType,string $sourceId,?string $occurredAt=null): void
    {
        DB::table('ledger_entries')->insert([
            'id'=>(string)Str::ulid(),'organization_id'=>$organizationId,'transaction_uuid'=>$tx,'account_id'=>$accountId,
            'debit'=>$debit,'credit'=>$credit,'currency'=>strtoupper((string) DB::table('organizations')->where('id', $organizationId)->value('default_currency')),'source_document_type'=>$sourceType,
            'source_document_id'=>$sourceId,'occurred_at'=>$occurredAt ?: now(),'posted_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
        ]);
    }
}
