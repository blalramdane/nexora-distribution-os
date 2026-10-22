<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Services\Transactions\TransactionPostingService;

class CatalogController extends Controller
{
    public function posCatalog(Request $request)
    {
        $this->abortFieldRepCostCatalog($request);
        $organizationId = $request->user()->organization_id;
        // `color` is optional in the UI. Older local preview schemas can
        // safely serve the catalog without it until the additive migration runs.
        $colorColumn = Schema::hasColumn('products', 'color') ? ['product.color'] : [];
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'category_id' => ['nullable', 'string', 'size:26'],
            'location_id' => ['nullable', 'string', 'size:26', Rule::exists('locations', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)->where('status', 'active'))],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:12', 'max:100'],
        ]);

        $categories = DB::table('categories')
            ->where('organization_id', $organizationId)
            ->where('active', true)
            ->orderBy('name_ar')
            ->get(['id', 'name_ar', 'code']);

        $stock = DB::table('stock_balances')
            ->where('organization_id', $organizationId)
            ->when(isset($filters['location_id']), fn ($query) => $query->where('location_id', $filters['location_id']))
            ->select('product_id')
            ->selectRaw('COALESCE(SUM(quantity_base), 0) as quantity_base')
            ->groupBy('product_id');

        $query = DB::table('products as product')
            ->leftJoin('categories as category', function ($join): void {
                $join->on('category.id', '=', 'product.category_id')
                    ->on('category.organization_id', '=', 'product.organization_id');
            })
            ->leftJoin('units as base_unit', 'base_unit.id', '=', 'product.base_unit_id')
            ->leftJoinSub($stock, 'stock', function ($join): void {
                $join->on('stock.product_id', '=', 'product.id');
            })
            ->where('product.organization_id', $organizationId)
            ->where('product.active', true)
            ->when(isset($filters['category_id']), fn ($query) => $query->where('product.category_id', $filters['category_id']))
            ->select([
                'product.id', 'product.sku', 'product.name_ar', 'product.name_en', 'product.brand', ...$colorColumn, 'product.base_unit_id', 'base_unit.name_ar as base_unit_name',
                'product.category_id', 'category.name_ar as category_name',
                'product.default_piece_price', 'product.default_cost',
            ])
            ->selectRaw('COALESCE(stock.quantity_base, 0) as quantity_base');

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($matches) use ($like, $search, $organizationId): void {
                $matches->where('product.sku', 'like', $like)
                    ->orWhere('product.name_ar', 'like', $like)
                    ->orWhere('product.name_en', 'like', $like)
                    ->orWhere('product.brand', 'like', $like)
                    ->orWhereIn('product.id', DB::table('product_aliases')
                        ->select('product_id')->where('organization_id', $organizationId)
                        ->where('active', true)->where('normalized_alias', 'like', $like))
                    ->orWhereIn('product.id', DB::table('product_barcodes')
                        ->select('product_id')->where('organization_id', $organizationId)
                        ->where('active', true)->where('barcode', 'like', $like))
                    ->orWhereIn('product.id', DB::table('supplier_products')
                        ->select('product_id')->where('organization_id', $organizationId)->where('active', true)
                        ->where(fn ($supplierMatches) => $supplierMatches->where('supplier_sku', 'like', $like)->orWhere('supplier_name', 'like', $like)));
            });
        }

        $products = $query->orderBy('product.name_ar')
            ->orderBy('product.sku')
            ->paginate((int) ($filters['per_page'] ?? 60));

        $productIds = $products->getCollection()->pluck('id')->all();
        $packagings = DB::table('product_packagings as packaging')
            ->join('units as unit', function ($join) use ($organizationId): void {
                $join->on('unit.id', '=', 'packaging.unit_id')
                    ->where(function ($query) use ($organizationId): void {
                        $query->whereNull('unit.organization_id')->orWhere('unit.organization_id', $organizationId);
                    });
            })
            ->where('packaging.organization_id', $organizationId)
            ->where('packaging.active', true)
            ->whereIn('packaging.product_id', $productIds)
            ->where(function ($query): void {
                $query->whereNull('packaging.effective_from')->orWhereDate('packaging.effective_from', '<=', now()->toDateString());
            })
            ->where(function ($query): void {
                $query->whereNull('packaging.effective_to')->orWhereDate('packaging.effective_to', '>=', now()->toDateString());
            })
            ->select([
                'packaging.id', 'packaging.organization_id', 'packaging.product_id', 'packaging.unit_id',
                'packaging.name_ar', 'packaging.conversion_to_base', 'packaging.sale_price', 'packaging.purchase_price',
                'packaging.is_default_sale_unit', 'packaging.is_default_purchase_unit', 'unit.name_ar as unit_name',
            ])
            ->orderByDesc('packaging.is_default_sale_unit')->orderByDesc('packaging.is_default_purchase_unit')->orderBy('packaging.conversion_to_base')
            ->get()
            ->groupBy('product_id');

        $products->getCollection()->transform(function (object $product) use ($packagings): object {
            // PDO/MySQL returns DECIMAL aggregates as strings while SQLite can
            // return integers. Keep the public POS contract numeric across both
            // drivers without rounding fractional stock quantities.
            $product->quantity_base = (float) ($product->quantity_base ?? 0);
            $product->packagings = $packagings->get($product->id, collect())->values();
            return $product;
        });

        return response()->json(['categories' => $categories, 'products' => $products]);
    }

    public function products(Request $request)
    {
        $this->abortFieldRepCostCatalog($request);
        $org=(string)$request->user()->organization_id;
        $filters=$request->validate([
            'q'=>['nullable','string','max:120'],
            'location_id'=>['nullable','string','size:26',Rule::exists('locations','id')->where(fn($query)=>$query->where('organization_id',$org)->where('status','active'))],
            'stock_status'=>['nullable','in:all,available,low,out'],
            'low_stock_threshold'=>['nullable','numeric','min:0','max:1000000000'],
            'category_id'=>['nullable','string','size:26',Rule::exists('categories','id')->where(fn($query)=>$query->where('organization_id',$org))],
            'catalog_status'=>['nullable','in:active,inactive,all'],
        ]);
        $stock=DB::table('stock_balances')->where('organization_id',$org)
            ->when(!empty($filters['location_id']),fn($query)=>$query->where('location_id',$filters['location_id']))
            ->select('product_id')->selectRaw('COALESCE(SUM(quantity_base),0) as quantity_base')
            ->selectRaw('COALESCE(SUM(quantity_base * average_cost),0) as stock_value')->groupBy('product_id');
        $colorColumn = Schema::hasColumn('products', 'color') ? ['p.color'] : [];
        $query=DB::table('products as p')
            ->leftJoinSub($stock,'stock',fn($join)=>$join->on('stock.product_id','=','p.id'))
            ->leftJoin('categories as c',fn($join)=>$join->on('c.id','=','p.category_id')->on('c.organization_id','=','p.organization_id'))
            ->leftJoin('units as u',fn($join)=>$join->on('u.id','=','p.base_unit_id')->where(fn($q)=>$q->whereNull('u.organization_id')->orWhere('u.organization_id',$org)))
            ->where('p.organization_id',$org)
            ->when(($filters['catalog_status'] ?? 'active') === 'active', fn ($query) => $query->where('p.active', true))
            ->when(($filters['catalog_status'] ?? 'active') === 'inactive', fn ($query) => $query->where('p.active', false))
            ->when(!empty($filters['category_id']),fn($query)=>$query->where('p.category_id',$filters['category_id']))
            ->select(['p.id','p.sku','p.name_ar','p.name_en','p.brand',...$colorColumn,'p.base_unit_id','u.name_ar as base_unit_name','p.category_id','c.name_ar as category_name','p.default_cost','p.default_piece_price','p.active'])
            ->selectRaw('COALESCE(stock.quantity_base,0) as quantity_base')
            ->selectRaw('COALESCE(stock.stock_value,0) as stock_value');
        $q=trim((string)($filters['q']??''));
        if($q!=='')$query->where(fn($w)=>$w->where('p.sku','like','%'.$q.'%')->orWhere('p.name_ar','like','%'.$q.'%')->orWhere('p.name_en','like','%'.$q.'%')->orWhere('p.brand','like','%'.$q.'%')->orWhereIn('p.id',DB::table('product_aliases')->where('organization_id',$org)->where('active',true)->select('product_id')->where('normalized_alias','like','%'.$q.'%'))->orWhereIn('p.id',DB::table('supplier_products')->where('organization_id',$org)->where('active',true)->select('product_id')->where(fn($supplierMatches)=>$supplierMatches->where('supplier_sku','like','%'.$q.'%')->orWhere('supplier_name','like','%'.$q.'%'))));
        $threshold=(float)($filters['low_stock_threshold']??5);
        match($filters['stock_status']??'all'){
            'available'=>$query->where('stock.quantity_base','>',$threshold),
            'low'=>$query->where('stock.quantity_base','>',0)->where('stock.quantity_base','<=',$threshold),
            'out'=>$query->where(fn($w)=>$w->whereNull('stock.quantity_base')->orWhere('stock.quantity_base','<=',0)),
            default=>null,
        };
        // A flat array is preserved for existing callers; stock fields enrich the
        // catalog and stay scoped to the current organization and optional location.
        return response()->json($query->orderByDesc('p.created_at')->orderBy('p.name_ar')->limit($q!==''?100:200)->get()->map(function($row){$row->quantity_base=(float)$row->quantity_base;$row->stock_value=(float)$row->stock_value;return $row;}));
    }

    public function showProduct(Request $request, string $productId)
    {
        $this->abortFieldRepCostCatalog($request);
        $org = (string) $request->user()->organization_id;
        $colorColumn = Schema::hasColumn('products', 'color') ? ['p.color'] : [];
        $product = DB::table('products as p')
            ->leftJoin('categories as c', fn ($join) => $join->on('c.id', '=', 'p.category_id')->on('c.organization_id', '=', 'p.organization_id'))
            ->leftJoin('units as u', function ($join) use ($org): void {
                $join->on('u.id', '=', 'p.base_unit_id')
                    ->where(fn ($query) => $query->whereNull('u.organization_id')->orWhere('u.organization_id', $org));
            })
            ->where('p.organization_id', $org)->where('p.id', $productId)
            ->select(['p.id','p.organization_id','p.sku','p.name_ar','p.brand', ...$colorColumn, 'p.base_unit_id','u.name_ar as base_unit_name','p.category_id','c.name_ar as category_name','p.default_cost','p.default_piece_price','p.active'])
            ->first();
        abort_unless($product, 404);

        $packaging = DB::table('product_packagings as pp')
            ->join('units as unit', 'unit.id', '=', 'pp.unit_id')
            ->where('pp.organization_id', $org)->where('pp.product_id', $productId)
            ->where('pp.active', true)->where('pp.is_default_purchase_unit', true)
            ->where(fn ($query) => $query->whereNull('unit.organization_id')->orWhere('unit.organization_id', $org))
            ->orderByDesc('pp.created_at')
            ->select(['pp.id','pp.unit_id as carton_unit_id','unit.name_ar as carton_unit_name','pp.conversion_to_base as pieces_per_carton','pp.purchase_price as carton_purchase_price','pp.sale_price','pp.is_default_sale_unit'])
            ->first();

        return response()->json([... (array) $product, 'purchase_packaging' => $packaging]);
    }

    public function updateProduct(Request $request, string $productId)
    {
        $org = (string) $request->user()->organization_id;
        $product = DB::table('products')->where('organization_id', $org)->where('id', $productId)->first();
        abort_unless($product, 404);

        $unitRule = Rule::exists('units', 'id')->where(fn ($query) => $query->where('active', true)->where(fn ($scope) => $scope->whereNull('organization_id')->orWhere('organization_id', $org)));
        $data = $request->validate([
            'sku' => ['sometimes','required','string','max:128','regex:/\\S/',Rule::unique('products','sku')->where(fn ($query) => $query->where('organization_id',$org))->ignore($productId,'id')],
            'name_ar' => ['sometimes','required','string','max:255','regex:/\\S/'],
            'brand' => ['sometimes','nullable','string','max:255'],
            'category_id' => ['sometimes','nullable','string','size:26',Rule::exists('categories','id')->where(fn ($query) => $query->where('organization_id',$org)->where('active',true))],
            'base_unit_id' => ['sometimes','required','string','size:26',$unitRule],
            'default_cost' => ['sometimes','numeric','min:0','max:1000000000'],
            'default_piece_price' => ['sometimes','numeric','min:0','max:1000000000'],
            'carton_unit_id' => ['sometimes','required','string','size:26',$unitRule],
            'pieces_per_carton' => ['sometimes','required','integer','min:1','max:1000000'],
            'carton_purchase_price' => ['sometimes','nullable','numeric','min:0','max:1000000000'],
        ]);

        if (isset($data['base_unit_id']) && $data['base_unit_id'] !== $product->base_unit_id && array_sum($this->productHistoryCounts($org, $productId)) > 0) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'base_unit_id' => ['لا يمكن تغيير وحدة المخزون الأساسية لصنف له فواتير أو حركات سابقة؛ غيّر عبوة الكرتونة فقط حتى لا تتغير دلالة الأرصدة التاريخية.'],
            ]);
        }

        $existingPack = DB::table('product_packagings')->where('organization_id', $org)
            ->where('product_id', $productId)->where('active', true)->where('is_default_purchase_unit', true)->first();
        $hasPackChanges = array_key_exists('carton_unit_id', $data) || array_key_exists('pieces_per_carton', $data);
        if ($hasPackChanges && (! array_key_exists('carton_unit_id', $data) || ! array_key_exists('pieces_per_carton', $data))) {
            throw \Illuminate\Validation\ValidationException::withMessages(['pieces_per_carton' => ['عند تعديل عبوة الكرتونة، حدد وحدة الكرتونة وعدد القطع معًا.']]);
        }
        $nextBaseUnitId = (string) ($data['base_unit_id'] ?? $product->base_unit_id);
        $nextCartonUnitId = (string) ($data['carton_unit_id'] ?? $existingPack?->unit_id ?? '');
        $nextConversion = (int) ($data['pieces_per_carton'] ?? $existingPack?->conversion_to_base ?? 1);
        if ($existingPack && $nextCartonUnitId !== '' && $nextCartonUnitId === $nextBaseUnitId) {
            throw \Illuminate\Validation\ValidationException::withMessages(['carton_unit_id' => ['وحدة الكرتونة يجب أن تختلف عن الوحدة الأساسية (القطعة).']]);
        }
        if ($hasPackChanges && $nextCartonUnitId === $nextBaseUnitId) {
            throw \Illuminate\Validation\ValidationException::withMessages(['carton_unit_id' => ['وحدة الكرتونة يجب أن تختلف عن الوحدة الأساسية (القطعة).']]);
        }

        $oldBaseCost = (string) ($product->default_cost ?? '0');
        $nextCartonCost = array_key_exists('carton_purchase_price', $data)
            ? ($data['carton_purchase_price'] === null ? null : (string) $data['carton_purchase_price'])
            : ($existingPack?->purchase_price === null ? null : (string) ($existingPack?->purchase_price ?? ''));
        $nextBaseCost = $nextCartonCost !== null && $nextCartonCost !== ''
            ? bcdiv($nextCartonCost, (string) max(1, $nextConversion), 4)
            : (string) ($data['default_cost'] ?? $oldBaseCost);
        $nextPiecePrice = (string) ($data['default_piece_price'] ?? $product->default_piece_price ?? '0');

        DB::transaction(function () use ($request, $org, $productId, $product, $data, $existingPack, $hasPackChanges, $nextBaseUnitId, $nextCartonUnitId, $nextConversion, $nextCartonCost, $nextBaseCost, $nextPiecePrice): void {
            $updates = ['updated_at' => now()];
            foreach (['sku', 'name_ar', 'brand', 'category_id', 'base_unit_id'] as $field) {
                if (array_key_exists($field, $data)) {
                    $updates[$field] = in_array($field, ['sku','name_ar'], true) ? trim((string) $data[$field]) : $data[$field];
                }
            }
            $updates['default_cost'] = $nextBaseCost;
            $updates['default_piece_price'] = $nextPiecePrice;
            if (Schema::hasColumn('products', 'color') && array_key_exists('color', $data)) {
                $updates['color'] = trim((string) ($data['color'] ?? '')) ?: null;
            }
            DB::table('products')->where('organization_id', $org)->where('id', $productId)->update($updates);

            if (! $hasPackChanges && ! array_key_exists('carton_purchase_price', $data)) {
                if (array_key_exists('default_piece_price', $data)) {
                    $this->syncActivePackagingSalePrices($org, $productId, $nextPiecePrice);
                }
                return;
            }

            $newUnitId = $hasPackChanges ? $nextCartonUnitId : (string) ($existingPack?->unit_id ?? '');
            if ($newUnitId === '') {
                throw \Illuminate\Validation\ValidationException::withMessages(['carton_unit_id' => ['الصنف لا يحتوي على وحدة كرتونة محفوظة. حدد وحدة الكرتونة وعدد القطع داخلها.']]);
            }
            $conversion = $hasPackChanges ? $nextConversion : (int) ($existingPack?->conversion_to_base ?? $nextConversion);
            $conversionChanged = $existingPack && ((string) $existingPack->unit_id !== $newUnitId || bccomp((string) $existingPack->conversion_to_base, (string) $conversion, 6) !== 0);

            if ($existingPack && ! $conversionChanged) {
                $packUpdates = ['purchase_price' => $nextCartonCost, 'updated_at' => now()];
                if ($existingPack->sale_price !== null) {
                    $packUpdates['sale_price'] = bcmul($nextPiecePrice, (string) $conversion, 4);
                }
                DB::table('product_packagings')->where('organization_id', $org)->where('id', $existingPack->id)->update($packUpdates);
                if (array_key_exists('default_piece_price', $data)) {
                    $this->syncActivePackagingSalePrices($org, $productId, $nextPiecePrice);
                }
                return;
            }

            $oldPackId = $existingPack?->id;
            $wasDefaultSale = (bool) ($existingPack?->is_default_sale_unit ?? false);
            if ($existingPack) {
                // Version rather than mutate a packaging row referenced by historical invoice lines.
                DB::table('product_packagings')->where('organization_id', $org)->where('id', $existingPack->id)->update([
                    'active' => false, 'is_default_purchase_unit' => false,
                    'is_default_sale_unit' => false, 'effective_to' => now()->toDateString(), 'updated_at' => now(),
                ]);
            }

            $previouslyUsedPack = DB::table('product_packagings')->where('organization_id', $org)
                ->where('product_id', $productId)->where('unit_id', $newUnitId)
                ->where('conversion_to_base', $conversion)->where('active', false)
                ->orderByDesc('created_at')->first();
            if ($previouslyUsedPack) {
                // Reactivate an identical historical package definition when the operator switches back to it.
                $packagingId = (string) $previouslyUsedPack->id;
                DB::table('product_packagings')->where('organization_id', $org)->where('id', $packagingId)->update([
                    'active' => true, 'is_default_purchase_unit' => true,
                    'is_default_sale_unit' => $wasDefaultSale,
                    'purchase_price' => $nextCartonCost,
                    'sale_price' => bcmul($nextPiecePrice, (string) $conversion, 4),
                    'effective_from' => now()->toDateString(), 'effective_to' => null, 'updated_at' => now(),
                ]);
            } else {
                $packagingId = $this->createPurchasePackaging(
                    $org, $productId, $newUnitId, $conversion, $nextCartonCost,
                    bcmul($nextPiecePrice, (string) $conversion, 4), true
                );
                DB::table('product_packagings')->where('organization_id', $org)->where('id', $packagingId)->update([
                    'is_default_purchase_unit' => true, 'is_default_sale_unit' => $wasDefaultSale,
                    'purchase_price' => $nextCartonCost, 'active' => true,
                    'effective_from' => now()->toDateString(), 'effective_to' => null, 'updated_at' => now(),
                ]);
            }
            if ($oldPackId !== null) {
                DB::table('supplier_products')->where('organization_id', $org)->where('product_id', $productId)
                    ->where('preferred_packaging_id', $oldPackId)->update(['preferred_packaging_id' => $packagingId, 'updated_at' => now()]);
            }
            if (array_key_exists('default_piece_price', $data)) {
                $this->syncActivePackagingSalePrices($org, $productId, $nextPiecePrice);
            }
        });

        $snapshot = $this->productSnapshot($org, $productId);
        return response()->json([
            'product' => $snapshot['product'],
            'purchase_packaging' => $snapshot['purchase_packaging'],
            'message' => 'تم حفظ تعديلات الصنف.',
        ]);
    }

    public function destroyProduct(Request $request, string $productId)
    {
        $org = (string) $request->user()->organization_id;
        $product = DB::table('products')->where('organization_id', $org)->where('id', $productId)->first();
        abort_unless($product, 404);

        $historyCounts = $this->productHistoryCounts($org, $productId);
        $historyCount = array_sum($historyCounts);
        if ($historyCount > 0) {
            DB::table('products')->where('organization_id', $org)->where('id', $productId)->update(['active' => false, 'updated_at' => now()]);
            return response()->json([
                'action' => 'deactivated', 'id' => $productId,
                'message' => 'الصنف مرتبط بحركات أو فواتير؛ تم إيقافه من الكتالوج مع الحفاظ على جميع سجلاته التاريخية.',
                'history_counts' => array_filter($historyCounts),
            ]);
        }

        DB::transaction(function () use ($org, $productId): void {
            // No operational history exists, so only catalog setup can be removed.
            if (Schema::hasTable('customer_product_prices')) {
                DB::table('customer_product_prices')->where('organization_id', $org)->where('product_id', $productId)->delete();
            }
            foreach (['supplier_products', 'product_barcodes', 'product_aliases', 'product_packagings'] as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->where('organization_id', $org)->where('product_id', $productId)->delete();
                }
            }
            DB::table('products')->where('organization_id', $org)->where('id', $productId)->delete();
        });

        return response()->json(['action' => 'deleted', 'id' => $productId, 'message' => 'تم حذف الصنف نهائيًا لأنه لا يملك حركات أو فواتير مرتبطة.']);
    }

    public function restoreProduct(Request $request, string $productId)
    {
        $org = (string) $request->user()->organization_id;
        $updated = DB::table('products')->where('organization_id', $org)->where('id', $productId)->where('active', false)->update(['active' => true, 'updated_at' => now()]);
        abort_unless($updated === 1, 404, 'الصنف غير موجود أو نشط بالفعل.');
        return response()->json(['id' => $productId, 'action' => 'restored', 'message' => 'تمت إعادة تفعيل الصنف.']);
    }

    private function productSnapshot(string $org, string $productId): array
    {
        $colorColumn = Schema::hasColumn('products', 'color') ? ['p.color'] : [];
        $product = DB::table('products as p')->where('p.organization_id', $org)->where('p.id', $productId)
            ->select(['p.id','p.sku','p.name_ar','p.brand', ...$colorColumn, 'p.base_unit_id','p.category_id','p.default_cost','p.default_piece_price','p.active'])->first();
        $pack = DB::table('product_packagings')->where('organization_id', $org)->where('product_id', $productId)->where('active', true)->where('is_default_purchase_unit', true)->orderByDesc('created_at')->first();
        return ['product' => (array) $product, 'purchase_packaging' => $pack ? (array) $pack : null];
    }

    /** Keep explicit active package selling prices consistent with the product's per-piece selling price. */
    private function syncActivePackagingSalePrices(string $org, string $productId, string $piecePrice): void
    {
        $packagings = DB::table('product_packagings')
            ->where('organization_id', $org)->where('product_id', $productId)
            ->where('active', true)->whereNotNull('sale_price')
            ->get(['id', 'conversion_to_base']);

        foreach ($packagings as $packaging) {
            DB::table('product_packagings')->where('organization_id', $org)->where('id', $packaging->id)->update([
                'sale_price' => bcmul($piecePrice, (string) $packaging->conversion_to_base, 4),
                'updated_at' => now(),
            ]);
        }
    }

    /** Counts references that make permanent deletion unsafe. Catalog-only relations are intentionally excluded. */
    private function productHistoryCounts(string $org, string $productId): array
    {
        $tables = [
            'purchase_invoice_items', 'purchase_return_items', 'sales_invoice_items', 'sales_return_items',
            'stock_movements', 'stock_balances', 'trip_load_items', 'trip_settlement_lines',
            'stock_source_layers', 'stock_transfer_items', 'trip_stock_opening_snapshot_items',
        ];
        if (Schema::hasTable('customer_product_prices')) {
            $tables[] = 'customer_product_prices';
        }
        $counts = [];
        foreach ($tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'product_id')) {
                continue;
            }
            $counts[$table] = DB::table($table)->where('product_id', $productId)
                ->when(Schema::hasColumn($table, 'organization_id'), fn ($query) => $query->where('organization_id', $org))
                ->count();
        }
        return $counts;
    }

    public function references(Request $request)
    {
        $this->abortFieldRepCostCatalog($request);
        $org = $request->user()->organization_id;

        return response()->json([
            'units' => DB::table('units')
                ->where(function ($q) use ($org) {
                    $q->whereNull('organization_id')->orWhere('organization_id', $org);
                })
                ->where('active', true)
                ->orderBy('name_ar')
                ->get(['id', 'code', 'name_ar', 'name_en', 'precision']),
            'categories' => DB::table('categories')
                ->where('organization_id', $org)
                ->where('active', true)
                ->orderBy('name_ar')
                ->get(['id', 'code', 'name_ar', 'name_en', 'parent_id']),
        ]);
    }

    /** Field reps must use the trip-scoped catalog, which never exposes purchase or default price data. */
    private function abortFieldRepCostCatalog(Request $request): void
    {
        $user = $request->user();
        $isFieldRep = DB::table('user_roles as ur')
            ->join('roles as r', function ($join): void {
                $join->on('r.id', '=', 'ur.role_id')->on('r.organization_id', '=', 'ur.organization_id');
            })
            ->where('ur.organization_id', $user->organization_id)
            ->where('ur.user_id', $user->id)
            ->where('r.key', 'field_rep')
            ->exists();

        abort_if($isFieldRep, 403, 'المندوب يستخدم كتالوج العربية المسندة فقط.');
    }

    public function storeCategory(Request $request)
    {
        $org = (string) $request->user()->organization_id;
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:180', 'regex:/\\S/'],
            'code' => ['nullable', 'string', 'max:64', Rule::unique('categories', 'code')->where(fn ($query) => $query->where('organization_id', $org))],
            'parent_id' => ['nullable', 'string', 'size:26', Rule::exists('categories', 'id')->where(fn ($query) => $query->where('organization_id', $org)->where('active', true))],
        ]);

        $code = trim((string) ($data['code'] ?? ''));
        if ($code === '') {
            do {
                $code = 'CAT-'.Str::upper(Str::random(8));
            } while (DB::table('categories')->where('organization_id', $org)->where('code', $code)->exists());
        }

        $id = (string) Str::ulid();
        DB::table('categories')->insert([
            'id' => $id, 'organization_id' => $org, 'parent_id' => $data['parent_id'] ?? null,
            'code' => $code, 'name_ar' => trim($data['name_ar']), 'name_en' => null,
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(DB::table('categories')->where('organization_id', $org)->where('id', $id)->first(), 201);
    }

    public function storeProduct(Request $request, TransactionPostingService $posting)
    {
        $org = (string) $request->user()->organization_id;
        $unitRule = Rule::exists('units', 'id')->where(fn ($query) => $query->where('active', true)->where(function ($scope) use ($org): void {
            $scope->whereNull('organization_id')->orWhere('organization_id', $org);
        }));
        $data = $request->validate([
            'sku' => ['required', 'string', 'max:128', 'regex:/\\S/', Rule::unique('products', 'sku')->where(fn ($query) => $query->where('organization_id', $org))],
            'name_ar' => ['required', 'string', 'max:255', 'regex:/\\S/'],
            'category_id' => ['nullable', 'string', 'size:26', Rule::exists('categories', 'id')->where(fn ($query) => $query->where('organization_id', $org)->where('active', true))],
            'base_unit_id' => ['required', 'string', 'size:26', $unitRule],
            'carton_unit_id' => ['nullable', 'string', 'size:26', $unitRule],
            'pieces_per_carton' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'carton_purchase_price' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'brand' => ['nullable', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:100'],
            'default_cost' => ['nullable', 'numeric', 'min:0'],
            'default_piece_price' => ['nullable', 'numeric', 'min:0'],
            'supplier_id' => ['nullable', 'string', 'size:26', Rule::exists('suppliers', 'id')->where(fn ($query) => $query->where('organization_id', $org)->where('active', true))],
            'supplier_sku' => ['nullable', 'string', 'max:128'],
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'initial_cartons' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'initial_pieces' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'initial_location_id' => ['nullable', 'string', 'size:26', Rule::exists('locations', 'id')->where(fn ($query) => $query->where('organization_id', $org)->where('status', 'active')->where('type', 'warehouse'))],
        ]);

        $conversion = (int) ($data['pieces_per_carton'] ?? 1);
        $cartonUnitId = trim((string) ($data['carton_unit_id'] ?? ''));
        if ($cartonUnitId === '' && $conversion > 1) {
            throw \Illuminate\Validation\ValidationException::withMessages(['carton_unit_id' => ['اختار وحدة الكرتونة عشان نقدر نحول الكراتين إلى قطع بشكل صحيح.']]);
        }
        if ($cartonUnitId !== '' && $cartonUnitId === (string) $data['base_unit_id']) {
            throw \Illuminate\Validation\ValidationException::withMessages(['carton_unit_id' => ['وحدة الكرتونة لازم تختلف عن الوحدة الأساسية «قطعة».']]);
        }

        $cartons = (int) ($data['initial_cartons'] ?? 0);
        $loosePieces = (int) ($data['initial_pieces'] ?? 0);
        $openingQuantity = ($cartons * $conversion) + $loosePieces;
        if ($openingQuantity > 0 && empty($data['initial_location_id'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['initial_location_id' => ['حدد المخزن الذي يوجد به الرصيد الافتتاحي.']]);
        }
        if ($openingQuantity > 0 && ! $request->user()->roles()
            ->where('roles.organization_id', $org)
            ->whereHas('permissions', static fn ($query) => $query->where('key', 'inventory.adjust'))
            ->exists()) {
            abort(403, 'إضافة الرصيد الافتتاحي تحتاج صلاحية تسوية المخزون. احفظ الصنف أولًا أو اطلب الصلاحية من المدير.');
        }

        $id = (string) Str::ulid();
        $pieceCost = isset($data['carton_purchase_price'])
            ? bcdiv((string) $data['carton_purchase_price'], (string) $conversion, 4)
            : (string) ($data['default_cost'] ?? 0);
        $piecePrice = (string) ($data['default_piece_price'] ?? 0);
        $supplierId = trim((string) ($data['supplier_id'] ?? '')) ?: null;

        $product = DB::transaction(function () use ($request, $posting, $org, $data, $id, $conversion, $cartonUnitId, $pieceCost, $piecePrice, $supplierId, $cartons, $loosePieces, $openingQuantity): object {
            $now = now();
            $productColumns = [
                'id' => $id, 'organization_id' => $org, 'category_id' => $data['category_id'] ?? null,
                'base_unit_id' => $data['base_unit_id'], 'sku' => trim($data['sku']), 'name_ar' => trim($data['name_ar']),
                'brand' => trim((string) ($data['brand'] ?? '')) ?: null,
                'default_cost' => $pieceCost, 'default_piece_price' => $piecePrice,
                'active' => true, 'created_at' => $now, 'updated_at' => $now,
            ];
            // Product color is an optional field in older preview databases.
            if (Schema::hasColumn('products', 'color')) {
                $productColumns['color'] = trim((string) ($data['color'] ?? '')) ?: null;
            }
            DB::table('products')->insert($productColumns);

            $packagingId = null;
            if ($cartonUnitId !== '') {
                $packagingId = $this->createPurchasePackaging(
                    $org, $id, $cartonUnitId, $conversion,
                    isset($data['carton_purchase_price']) ? (string) $data['carton_purchase_price'] : null,
                    bccomp($piecePrice, '0', 4) > 0 ? bcmul($piecePrice, (string) $conversion, 4) : null,
                    true
                );
            }

            if ($supplierId !== null) {
                DB::table('supplier_products')->insert([
                    'id' => (string) Str::ulid(), 'organization_id' => $org, 'supplier_id' => $supplierId,
                    'product_id' => $id, 'supplier_sku' => trim((string) ($data['supplier_sku'] ?? '')) ?: null,
                    'supplier_name' => trim((string) ($data['supplier_name'] ?? '')) ?: trim($data['name_ar']),
                    'preferred_packaging_id' => $packagingId, 'last_cost' => $pieceCost,
                    'last_purchase_at' => null, 'active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            if ($openingQuantity > 0) {
                $posting->postStockAdjustment($org, [
                    'product_id' => $id, 'location_id' => $data['initial_location_id'],
                    'quantity_delta' => (string) $openingQuantity,
                    'reason' => "رصيد افتتاحي: {$cartons} كرتونة × {$conversion} قطعة + {$loosePieces} قطعة مفردة",
                    'idempotency_key' => 'product-opening-stock-'.$id,
                    'created_by' => $request->user()->id,
                ]);
            }

            return DB::table('products')->where('organization_id', $org)->where('id', $id)->first();
        });

        return response()->json($product, 201);
    }

    public function storeSupplierProduct(Request $request, string $productId)
    {
        $org = (string) $request->user()->organization_id;
        $unitRule = Rule::exists('units', 'id')->where(fn ($query) => $query->where('active', true)->where(function ($scope) use ($org): void {
            $scope->whereNull('organization_id')->orWhere('organization_id', $org);
        }));
        $data = $request->validate([
            'supplier_id' => ['required', 'string', 'size:26', Rule::exists('suppliers', 'id')->where(fn ($query) => $query->where('organization_id', $org)->where('active', true))],
            'supplier_sku' => ['nullable', 'string', 'max:128'],
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'carton_unit_id' => ['nullable', 'string', 'size:26', $unitRule],
            'pieces_per_carton' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'carton_purchase_price' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'packaging_id' => ['nullable', 'string', 'size:26', Rule::exists('product_packagings', 'id')->where(fn ($query) => $query->where('organization_id', $org)->where('product_id', $productId)->where('active', true))],
        ]);

        $product = DB::table('products')->where('organization_id', $org)->where('id', $productId)->where('active', true)->first();
        abort_unless($product, 404);
        $supplierId = $data['supplier_id'];

        $result = DB::transaction(function () use ($org, $product, $supplierId, $data): object {
            $packagingId = trim((string) ($data['packaging_id'] ?? '')) ?: null;
            $conversion = 1;
            if ($packagingId === null && isset($data['pieces_per_carton'])) {
                $conversion = (int) $data['pieces_per_carton'];
                $cartonUnitId = trim((string) ($data['carton_unit_id'] ?? ''));
                if ($cartonUnitId === '' || $cartonUnitId === (string) $product->base_unit_id) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['carton_unit_id' => ['اختار وحدة كرتونة مختلفة عن القطعة.']]);
                }
                $hasDefaultPurchasePack = DB::table('product_packagings')->where('organization_id', $org)->where('product_id', $product->id)->where('active', true)->where('is_default_purchase_unit', true)->exists();
                $piecePrice = (string) ($product->default_piece_price ?? 0);
                $packagingId = $this->createPurchasePackaging(
                    $org, (string) $product->id, $cartonUnitId, $conversion,
                    isset($data['carton_purchase_price']) ? (string) $data['carton_purchase_price'] : null,
                    bccomp($piecePrice, '0', 4) > 0 ? bcmul($piecePrice, (string) $conversion, 4) : null,
                    ! $hasDefaultPurchasePack
                );
            } elseif ($packagingId !== null) {
                $packaging = DB::table('product_packagings')->where('organization_id', $org)->where('product_id', $product->id)->where('id', $packagingId)->first();
                $conversion = (int) $packaging->conversion_to_base;
            } else {
                $packaging = DB::table('product_packagings')->where('organization_id', $org)->where('product_id', $product->id)->where('active', true)->where('is_default_purchase_unit', true)->first();
                $packagingId = $packaging?->id;
                $conversion = (int) ($packaging?->conversion_to_base ?? 1);
            }

            if ($packagingId === null && isset($data['carton_purchase_price'])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'pieces_per_carton' => ['الصنف لا يملك عبوة شراء محفوظة. حدّد عدد القطع ووحدة الكرتونة لهذا المورد قبل إدخال سعر الكرتونة.'],
                ]);
            }

            $cartonCost = isset($data['carton_purchase_price']) ? (string) $data['carton_purchase_price'] : null;
            $pieceCost = $cartonCost !== null ? bcdiv($cartonCost, (string) max(1, $conversion), 4) : (string) ($product->default_cost ?? 0);
            $link = DB::table('supplier_products')->where('organization_id', $org)->where('supplier_id', $supplierId)->where('product_id', $product->id)->first();
            $values = [
                'supplier_sku' => trim((string) ($data['supplier_sku'] ?? '')) ?: null,
                'supplier_name' => trim((string) ($data['supplier_name'] ?? '')) ?: $product->name_ar,
                'preferred_packaging_id' => $packagingId,
                'last_cost' => $pieceCost, 'active' => true, 'updated_at' => now(),
            ];
            if ($link) {
                DB::table('supplier_products')->where('organization_id', $org)->where('id', $link->id)->update($values);
                $linkId = $link->id;
            } else {
                $linkId = (string) Str::ulid();
                DB::table('supplier_products')->insert([
                    'id' => $linkId, 'organization_id' => $org, 'supplier_id' => $supplierId,
                    'product_id' => $product->id, 'last_purchase_at' => null,
                    'created_at' => now(), ...$values,
                ]);
            }

            return DB::table('supplier_products as sp')
                ->join('suppliers as s', function ($join): void { $join->on('s.id', '=', 'sp.supplier_id')->on('s.organization_id', '=', 'sp.organization_id'); })
                ->where('sp.organization_id', $org)->where('sp.id', $linkId)
                ->select('sp.*', 's.name as supplier_display_name', 's.code as supplier_code')
                ->first();
        });

        return response()->json($result, 201);
    }

    private function createPurchasePackaging(string $org, string $productId, string $unitId, int $conversion, ?string $purchasePrice, ?string $salePrice, bool $defaultPurchase): string
    {
        $name = 'كرتونة ('.$conversion.' قطعة)';
        $existing = DB::table('product_packagings')
            ->where('organization_id', $org)->where('product_id', $productId)
            ->where('unit_id', $unitId)->where('name_ar', $name)->where('active', true)->first();
        if ($existing) {
            $updates = ['updated_at' => now()];
            if ($salePrice !== null) $updates['sale_price'] = $salePrice;
            DB::table('product_packagings')->where('organization_id', $org)->where('id', $existing->id)->update($updates);
            return (string) $existing->id;
        }

        $id = (string) Str::ulid();
        DB::table('product_packagings')->insert([
            'id' => $id, 'organization_id' => $org, 'product_id' => $productId, 'unit_id' => $unitId,
            'name_ar' => $name, 'conversion_to_base' => $conversion, 'barcode_primary' => null,
            'sale_price' => $salePrice, 'purchase_price' => $purchasePrice,
            'is_default_sale_unit' => false, 'is_default_purchase_unit' => $defaultPurchase,
            'active' => true, 'effective_from' => null, 'effective_to' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }
}
