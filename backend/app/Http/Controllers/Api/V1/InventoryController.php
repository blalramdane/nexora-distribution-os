<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Outbox\MessageOutboxPublisher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class InventoryController extends Controller
{
    public function adjust(Request $request, \App\Services\Transactions\TransactionPostingService $posting)
    {
        $data=$request->validate([
            'product_id'=>['required','string','size:26'],
            'location_id'=>['required','string','size:26'],
            'quantity_delta'=>['required','numeric','not_in:0'],
            'reason'=>['required','string','max:255'],
            'idempotency_key'=>['required','string','max:255'],
            'trip_id'=>['nullable','string','size:26'],
        ]);
        $data['created_by']=$request->user()->id;
        return response()->json($posting->postStockAdjustment($request->user()->organization_id,$data),201);
    }

    public function index(Request $request)
    {
        $filters = $this->validatedFilters($request, true);
        return response()->json($this->balanceQuery((string) $request->user()->organization_id, $filters)
            ->orderBy('p.name_ar')->orderBy('l.name')->paginate((int) ($filters['per_page'] ?? 50)));
    }

    public function summary(Request $request)
    {
        $filters = $this->validatedFilters($request, false);
        $org = (string) $request->user()->organization_id;
        $rows = $this->balanceQuery($org, $filters, false, false);
        $summary = (clone $rows)->selectRaw('COUNT(*) as balance_rows')
            ->selectRaw('COUNT(DISTINCT sb.product_id) as products_with_balance')
            ->selectRaw('COALESCE(SUM(sb.quantity_base),0) as quantity_units')
            ->selectRaw('COALESCE(SUM(sb.quantity_base * sb.average_cost),0) as stock_value')
            ->first();
        $threshold = (float) ($filters['low_stock_threshold'] ?? 5);
        $lowBalanceRows = $this->balanceQuery($org, $filters, false, false)
            ->whereRaw('(COALESCE(sb.quantity_base, 0) - COALESCE(sb.reserved_quantity_base, 0)) > 0')
            ->whereRaw('(COALESCE(sb.quantity_base, 0) - COALESCE(sb.reserved_quantity_base, 0)) <= CAST(? AS DECIMAL(20, 6))', [$threshold])
            ->count();
        $zeroBalanceRows = $this->balanceQuery($org, $filters, false, false)
            ->whereRaw('(COALESCE(sb.quantity_base, 0) - COALESCE(sb.reserved_quantity_base, 0)) <= 0')
            ->count();

        $productsQuery = DB::table('products as p')->where('p.organization_id', $org)->where('p.active', true);
        if (! empty($filters['category_id'])) $productsQuery->where('p.category_id', $filters['category_id']);
        if (! empty($filters['search'])) {
            $like = '%'.trim((string) $filters['search']).'%';
            $productsQuery->where(function ($matches) use ($like, $org): void {
                $matches->where('p.sku', 'like', $like)->orWhere('p.name_ar', 'like', $like)
                    ->orWhere('p.name_en', 'like', $like)->orWhere('p.brand', 'like', $like)
                    ->orWhereIn('p.id', DB::table('product_aliases')->where('organization_id', $org)->where('active', true)->where('normalized_alias', 'like', $like)->select('product_id'));
            });
        }
        if (! empty($filters['location_id'])) {
            $locationId = $filters['location_id'];
            $productsQuery->select('p.id')->selectRaw('EXISTS (SELECT 1 FROM stock_balances sb WHERE sb.organization_id = p.organization_id AND sb.product_id = p.id AND sb.location_id = ? AND sb.quantity_base > 0) as has_stock', [$locationId]);
        } else {
            $productsQuery->select('p.id')->selectRaw('EXISTS (SELECT 1 FROM stock_balances sb WHERE sb.organization_id = p.organization_id AND sb.product_id = p.id AND sb.quantity_base > 0) as has_stock');
        }
        $productRows = $productsQuery->get();

        return response()->json([
            'balance_rows' => (int) ($summary->balance_rows ?? 0),
            'products_with_balance' => (int) ($summary->products_with_balance ?? 0),
            'active_products' => $productRows->count(),
            'products_without_positive_stock' => $productRows->filter(fn ($product) => (int) $product->has_stock === 0)->count(),
            'quantity_units' => (float) ($summary->quantity_units ?? 0),
            'stock_value' => (float) ($summary->stock_value ?? 0),
            'low_balance_rows' => (int) $lowBalanceRows,
            'zero_balance_rows' => (int) $zeroBalanceRows,
            'threshold' => $threshold,
        ]);
    }

    public function movements(Request $request)
    {
        $org = (string) $request->user()->organization_id;
        $filters = $request->validate([
            'location_id' => ['nullable', 'string', 'size:26', Rule::exists('locations', 'id')->where(fn ($query) => $query->where('organization_id', $org))],
            'product_id' => ['nullable', 'string', 'size:26', Rule::exists('products', 'id')->where(fn ($query) => $query->where('organization_id', $org))],
            'movement_type' => ['nullable', 'string', 'max:64'],
            'search' => ['nullable', 'string', 'max:120'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = DB::table('stock_movements as movement')
            ->join('products as product', fn ($join) => $join->on('product.id', '=', 'movement.product_id')->on('product.organization_id', '=', 'movement.organization_id'))
            ->join('locations as location', fn ($join) => $join->on('location.id', '=', 'movement.location_id')->on('location.organization_id', '=', 'movement.organization_id'))
            ->leftJoin('users as creator', fn ($join) => $join->on('creator.id', '=', 'movement.created_by')->on('creator.organization_id', '=', 'movement.organization_id'))
            ->where('movement.organization_id', $org)->whereNotNull('movement.posted_at')
            ->select(['movement.id', 'movement.product_id', 'movement.location_id', 'movement.movement_type',
                'movement.quantity_base', 'movement.unit_cost', 'movement.source_document_type', 'movement.source_document_id',
                'movement.occurred_at', 'movement.posted_at', 'movement.reference', 'product.sku', 'product.name_ar',
                'location.name as location_name', 'location.code as location_code', 'creator.name as created_by_name']);
        if (! empty($filters['location_id'])) $query->where('movement.location_id', $filters['location_id']);
        if (! empty($filters['product_id'])) $query->where('movement.product_id', $filters['product_id']);
        if (! empty($filters['movement_type'])) $query->where('movement.movement_type', $filters['movement_type']);
        if (! empty($filters['from_date'])) $query->where('movement.occurred_at', '>=', $filters['from_date'].' 00:00:00');
        if (! empty($filters['to_date'])) $query->where('movement.occurred_at', '<=', $filters['to_date'].' 23:59:59');
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(fn ($matches) => $matches->where('product.sku', 'like', $like)->orWhere('product.name_ar', 'like', $like)
                ->orWhere('location.name', 'like', $like)->orWhere('movement.reference', 'like', $like)
                ->orWhere('movement.source_document_id', 'like', $like));
        }

        return response()->json($query->orderByDesc('movement.occurred_at')->orderByDesc('movement.id')->paginate((int) ($filters['per_page'] ?? 50)));
    }

    /** Review queue for field stock counts submitted by assigned vehicle reps. */
    public function stocktakes(Request $request)
    {
        $org = (string) $request->user()->organization_id;
        $filters = $request->validate([
            'status' => ['sometimes', 'in:submitted,approved,rejected,all'],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $status = $filters['status'] ?? 'submitted';

        $query = DB::table('field_stock_counts as count')
            ->join('trips as trip', fn ($join) => $join->on('trip.id', '=', 'count.trip_id')->on('trip.organization_id', '=', 'count.organization_id'))
            ->join('vehicles as vehicle', fn ($join) => $join->on('vehicle.id', '=', 'trip.vehicle_id')->on('vehicle.organization_id', '=', 'trip.organization_id'))
            ->join('users as submitter', fn ($join) => $join->on('submitter.id', '=', 'count.submitted_by')->on('submitter.organization_id', '=', 'count.organization_id'))
            ->leftJoin('users as reviewer', fn ($join) => $join->on('reviewer.id', '=', 'count.reviewed_by')->on('reviewer.organization_id', '=', 'count.organization_id'))
            ->where('count.organization_id', $org)
            ->when($status !== 'all', fn ($builder) => $builder->where('count.status', $status))
            ->select([
                'count.id', 'count.trip_id', 'count.status', 'count.item_count', 'count.variance_items_count',
                'count.items_json', 'count.notes', 'count.review_notes', 'count.submitted_at', 'count.reviewed_at',
                'count.submitted_by', 'count.reviewed_by', 'trip.trip_number', 'trip.display_name as trip_display_name',
                'trip.trip_date', 'vehicle.name as vehicle_name', 'vehicle.code as vehicle_code',
                'submitter.name as submitted_by_name', 'reviewer.name as reviewed_by_name',
            ]);

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(fn ($builder) => $builder->where('trip.trip_number', 'like', $like)
                ->orWhere('trip.display_name', 'like', $like)
                ->orWhere('vehicle.name', 'like', $like)
                ->orWhere('vehicle.code', 'like', $like)
                ->orWhere('submitter.name', 'like', $like)
                ->orWhere('count.notes', 'like', $like));
        }

        $page = $query->orderByRaw("CASE WHEN count.status = 'submitted' THEN 0 ELSE 1 END")
            ->orderByDesc('count.submitted_at')->paginate((int) ($filters['per_page'] ?? 30));
        $page->getCollection()->transform(function ($row): object {
            $row->items = json_decode((string) $row->items_json, true) ?: [];
            unset($row->items_json);
            return $row;
        });

        return response()->json($page);
    }

    /** Approve/reject a vehicle stock count without silently overwriting later stock movements. */
    public function reviewStocktake(
        Request $request,
        string $stocktakeId,
        \App\Services\Transactions\TransactionPostingService $posting,
        MessageOutboxPublisher $outbox,
    ) {
        $data = $request->validate([
            'action' => ['required', 'in:approve,reject'],
            'review_notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $reviewNotes = trim((string) ($data['review_notes'] ?? '')) ?: null;
        if ($data['action'] === 'reject' && $reviewNotes === null) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'review_notes' => ['اكتب سبب الرفض ليظهر في سجل مراجعة الجرد.'],
            ]);
        }

        $org = (string) $request->user()->organization_id;
        $reviewerId = (string) $request->user()->id;

        return DB::transaction(function () use ($org, $reviewerId, $stocktakeId, $data, $reviewNotes, $posting, $request, $outbox) {
            $count = DB::table('field_stock_counts')->where('organization_id', $org)
                ->where('id', $stocktakeId)->lockForUpdate()->first();
            abort_unless($count, 404);

            $targetStatus = $data['action'] === 'approve' ? 'approved' : 'rejected';
            if ($count->status === $targetStatus) {
                return response()->json([
                    'id' => $count->id, 'status' => $count->status, 'replayed' => true,
                    'message' => $count->status === 'approved' ? 'الجرد معتمد بالفعل، ولم تُكرر حركات المخزون.' : 'الجرد مرفوض بالفعل.',
                ]);
            }
            if ($count->status !== 'submitted') {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'status' => ['تم اتخاذ قرار نهائي بشأن الجرد؛ لا يمكن تغييره مرة أخرى.'],
                ]);
            }

            $now = now();
            if ($data['action'] === 'reject') {
                DB::table('field_stock_counts')->where('organization_id', $org)->where('id', $count->id)->update([
                    'status' => 'rejected', 'reviewed_by' => $reviewerId, 'reviewed_at' => $now,
                    'review_notes' => $reviewNotes, 'updated_at' => $now,
                ]);
                $this->writeStocktakeReviewAudit($request, $org, $reviewerId, $count, 'rejected', 0, $reviewNotes);
                $outbox->publish($org, 'inventory.stocktake.rejected', 'field_stock_count', $count->id, [
                    'trip_id' => $count->trip_id,
                    'reviewed_by' => $reviewerId,
                    'variance_items_count' => (int) $count->variance_items_count,
                ]);

                return response()->json([
                    'id' => $count->id, 'status' => 'rejected', 'posted_adjustments' => 0,
                    'message' => 'تم رفض الجرد مع تسجيل السبب؛ لم تتغير أرصدة المخزون.',
                ]);
            }

            $trip = DB::table('trips as trip')->join('vehicles as vehicle', function ($join): void {
                $join->on('vehicle.id', '=', 'trip.vehicle_id')->on('vehicle.organization_id', '=', 'trip.organization_id');
            })->where('trip.organization_id', $org)->where('trip.id', $count->trip_id)
                ->select('trip.id', 'trip.trip_number', 'vehicle.location_id', 'vehicle.name as vehicle_name')->first();
            abort_unless($trip, 404);

            $items = json_decode((string) $count->items_json, true);
            if (! is_array($items) || count($items) !== (int) $count->item_count) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'items' => ['بيانات لقطة الجرد غير سليمة؛ لم يتم ترحيل أي فرق.'],
                ]);
            }

            $posted = [];
            foreach ($items as $index => $item) {
                $productId = (string) ($item['product_id'] ?? '');
                if ($productId === '' || ! is_numeric($item['expected_quantity_base'] ?? null)
                    || ! is_numeric($item['counted_quantity_base'] ?? null)) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'items' => ['يوجد صنف أو كمية غير سليمة في لقطة الجرد؛ لم يتم ترحيل أي فرق.'],
                    ]);
                }
                $expected = bcadd((string) $item['expected_quantity_base'], '0', 6);
                $counted = bcadd((string) $item['counted_quantity_base'], '0', 6);
                $variance = bcsub($counted, $expected, 6);
                if (bccomp($variance, (string) ($item['variance_quantity_base'] ?? '0'), 6) !== 0) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'items' => ['بيانات فرق الجرد غير متطابقة؛ لم يتم ترحيل أي حركة.'],
                    ]);
                }

                $product = DB::table('products')->where('organization_id', $org)->where('id', $productId)->where('active', true)->first();
                abort_unless($product, 404);
                $balance = DB::table('stock_balances')->where('organization_id', $org)
                    ->where('product_id', $productId)->where('location_id', $trip->location_id)->lockForUpdate()->first();
                $currentQuantity = bcadd((string) ($balance->quantity_base ?? '0'), '0', 6);
                if (bccomp($currentQuantity, $expected, 6) !== 0) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        "items.{$index}.expected_quantity_base" => [
                            "رصيد {$product->name_ar} تغيّر بعد الجرد من {$expected} إلى {$currentQuantity}. ارفض اللقطة القديمة واطلب جردًا جديدًا قبل التسوية.",
                        ],
                    ]);
                }
                if (bccomp($variance, '0.000000', 6) === 0) {
                    continue;
                }

                $adjustment = $posting->postStockAdjustment($org, [
                    'product_id' => $productId,
                    'location_id' => $trip->location_id,
                    'trip_id' => $count->trip_id,
                    'quantity_delta' => $variance,
                    'reason' => mb_substr('اعتماد جرد '.$count->id.' للرحلة '.$trip->trip_number.' — '.($item['product_name'] ?? $product->name_ar), 0, 255),
                    'idempotency_key' => 'field-stocktake-approval:'.$count->id.':'.$productId,
                    'source_document_type' => 'field_stock_count',
                    'source_document_id' => $count->id,
                    'created_by' => $reviewerId,
                ]);
                $posted[] = [
                    'product_id' => $productId, 'product_name' => (string) ($item['product_name'] ?? $product->name_ar),
                    'variance_quantity_base' => (float) $variance, 'document_number' => $adjustment['document_number'] ?? null,
                ];
            }

            DB::table('field_stock_counts')->where('organization_id', $org)->where('id', $count->id)->update([
                'status' => 'approved', 'reviewed_by' => $reviewerId, 'reviewed_at' => $now,
                'review_notes' => $reviewNotes, 'updated_at' => $now,
            ]);
            $this->writeStocktakeReviewAudit($request, $org, $reviewerId, $count, 'approved', count($posted), $reviewNotes);
            $outbox->publish($org, 'inventory.stocktake.approved', 'field_stock_count', $count->id, [
                'trip_id' => $count->trip_id,
                'reviewed_by' => $reviewerId,
                'posted_adjustments' => count($posted),
            ]);

            return response()->json([
                'id' => $count->id, 'status' => 'approved', 'posted_adjustments' => count($posted),
                'adjustments' => $posted, 'message' => count($posted)
                    ? 'تم اعتماد الجرد وترحيل '.count($posted).' تسوية مخزنية مرتبطة بمستند الجرد.'
                    : 'تم اعتماد الجرد؛ لا توجد فروقات تحتاج إلى تعديل رصيد.',
            ]);
        }, attempts: 5);
    }

    private function writeStocktakeReviewAudit(Request $request, string $org, string $reviewerId, object $count, string $status, int $postedAdjustments, ?string $reviewNotes): void
    {
        $now = now();
        DB::table('audit_logs')->insert([
            'id' => (string) \Illuminate\Support\Str::ulid(), 'organization_id' => $org,
            'actor_user_id' => $reviewerId, 'device_id' => null, 'action' => 'inventory.stocktake.'.$status,
            'entity_type' => 'field_stock_count', 'entity_id' => $count->id, 'correlation_id' => null,
            'before_summary' => json_encode(['status' => $count->status, 'variance_items_count' => (int) $count->variance_items_count], JSON_THROW_ON_ERROR),
            'after_summary' => json_encode(['status' => $status, 'posted_adjustments' => $postedAdjustments], JSON_THROW_ON_ERROR),
            'metadata_json' => json_encode(['trip_id' => $count->trip_id, 'review_notes' => $reviewNotes], JSON_THROW_ON_ERROR),
            'ip_address' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000), 'created_at' => $now,
        ]);
    }

    public function transfer(Request $request, \App\Services\Transactions\TransactionPostingService $posting)
    {
        $org = (string) $request->user()->organization_id;
        $data = $request->validate([
            'from_location_id' => ['required', 'string', 'size:26', Rule::exists('locations', 'id')->where(fn ($query) => $query->where('organization_id', $org)->where('status', 'active'))],
            'to_location_id' => ['required', 'string', 'size:26', 'different:from_location_id', Rule::exists('locations', 'id')->where(fn ($query) => $query->where('organization_id', $org)->where('status', 'active'))],
            'reason' => ['required', 'string', 'max:255'],
            'idempotency_key' => ['required', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'string', 'size:26', 'distinct', Rule::exists('products', 'id')->where(fn ($query) => $query->where('organization_id', $org)->where('active', true))],
            'items.*.quantity_base' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
        ]);
        $data['created_by'] = $request->user()->id;
        return response()->json($posting->postStockTransfer($org, $data), 201);
    }

    /** @return array<string, mixed> */
    private function validatedFilters(Request $request, bool $includePagination): array
    {
        $org = (string) $request->user()->organization_id;
        $rules = [
            // Filter identifiers are shape-validated but not existence-validated;
            // an identifier from another tenant simply produces an empty result.
            'location_id' => ['nullable', 'string', 'size:26'],
            'product_id' => ['nullable', 'string', 'size:26'],
            'category_id' => ['nullable', 'string', 'size:26', Rule::exists('categories', 'id')->where(fn ($query) => $query->where('organization_id', $org))],
            'search' => ['nullable', 'string', 'max:120'],
            'stock_status' => ['sometimes', 'in:all,available,low,out'],
            'low_stock_threshold' => ['sometimes', 'numeric', 'min:0', 'max:1000000000'],
        ];
        if ($includePagination) {
            $rules['per_page'] = ['sometimes', 'integer', 'min:1', 'max:200'];
            $rules['page'] = ['sometimes', 'integer', 'min:1'];
        }
        return $request->validate($rules);
    }

    private function balanceQuery(string $org, array $filters, bool $applyStockStatus = true, bool $includeProjection = true)
    {
        $query = DB::table('stock_balances as sb')
            ->join('products as p', fn ($join) => $join->on('p.id', '=', 'sb.product_id')->on('p.organization_id', '=', 'sb.organization_id'))
            ->join('locations as l', fn ($join) => $join->on('l.id', '=', 'sb.location_id')->on('l.organization_id', '=', 'sb.organization_id'))
            ->leftJoin('categories as c', fn ($join) => $join->on('c.id', '=', 'p.category_id')->on('c.organization_id', '=', 'p.organization_id'))
            ->where('sb.organization_id', $org)->where('p.active', true)->where('l.status', 'active');
        if (! empty($filters['location_id'])) $query->where('sb.location_id', $filters['location_id']);
        if (! empty($filters['product_id'])) $query->where('sb.product_id', $filters['product_id']);
        if (! empty($filters['category_id'])) $query->where('p.category_id', $filters['category_id']);
        if ($applyStockStatus) {
            $threshold = (float) ($filters['low_stock_threshold'] ?? 5);
            match ($filters['stock_status'] ?? 'all') {
                'available' => $query->whereRaw('(sb.quantity_base - sb.reserved_quantity_base) > CAST(? AS DECIMAL(20, 6))', [$threshold]),
                'low' => $query->whereRaw('(sb.quantity_base - sb.reserved_quantity_base) > 0')->whereRaw('(sb.quantity_base - sb.reserved_quantity_base) <= CAST(? AS DECIMAL(20, 6))', [$threshold]),
                'out' => $query->whereRaw('(sb.quantity_base - sb.reserved_quantity_base) <= 0'),
                default => null,
            };
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(fn ($matches) => $matches->where('p.sku', 'like', $like)->orWhere('p.name_ar', 'like', $like)
                ->orWhere('p.name_en', 'like', $like)->orWhere('p.brand', 'like', $like)
                ->orWhere('l.code', 'like', $like)->orWhere('l.name', 'like', $like)
                ->orWhereIn('p.id', DB::table('product_aliases')->where('organization_id', $org)->where('active', true)->where('normalized_alias', 'like', $like)->select('product_id')));
        }
        if ($includeProjection) {
            $query->select('sb.*', 'p.sku', 'p.name_ar', 'p.name_en', 'p.category_id', 'c.name_ar as category_name', 'l.code as location_code', 'l.name as location_name');
        }
        return $query;
    }
}
