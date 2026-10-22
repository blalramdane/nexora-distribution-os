<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $q=trim((string)$request->query('q',''));
        $query=DB::table('customers')->where('organization_id',$request->user()->organization_id)->where('status','active');
        if($q!=='') $query->where(function($w)use($q){$w->where('name','like','%'.$q.'%')->orWhere('phone','like','%'.$q.'%')->orWhere('code','like','%'.$q.'%');});
        // Keep the customer directory searchable after bulk contact imports.
        $this->withCreditEnabledProjection($query);
        return response()->json($query->orderByDesc('created_at')->orderBy('name')->limit(2500)->get());
    }

    public function prices(Request $request, string $customerId)
    {
        $organizationId = (string) $request->user()->organization_id;
        abort_unless(DB::table('customers')->where('organization_id', $organizationId)->where('id', $customerId)->exists(), 404);

        // Customer-specific prices were added after the base preview schema.
        // Until that additive migration is approved/applied, return no overrides
        // so the POS can still use the product's default price instead of 500ing.
        if (! Schema::hasTable('customer_product_prices')) {
            return response()->json([]);
        }
        $colorColumn = Schema::hasColumn('products', 'color') ? ['p.color'] : [];

        return response()->json(DB::table('customer_product_prices as cp')
            ->join('products as p', function ($join): void {
                $join->on('p.id', '=', 'cp.product_id')->on('p.organization_id', '=', 'cp.organization_id');
            })
            ->leftJoin('product_packagings as pkg', function ($join): void {
                $join->on('pkg.id', '=', 'cp.packaging_id')->on('pkg.organization_id', '=', 'cp.organization_id');
            })
            ->where('cp.organization_id', $organizationId)->where('cp.customer_id', $customerId)->where('cp.active', true)
            ->orderBy('p.name_ar')->orderBy('cp.unit_key')
            ->get(['cp.id', 'cp.product_id', 'cp.packaging_id', 'cp.unit_key', 'cp.unit_price', 'p.sku', 'p.name_ar as product_name', ...$colorColumn, 'pkg.name_ar as packaging_name']));
    }

    public function savePrice(Request $request, string $customerId)
    {
        $organizationId = (string) $request->user()->organization_id;
        abort_unless(Schema::hasTable('customer_product_prices'), 503, 'تحديث قاعدة المعاينة مطلوب لتفعيل قوائم الأسعار الخاصة بالعميل.');
        $data = $request->validate([
            'product_id' => ['required', 'string', 'size:26'],
            'packaging_id' => ['nullable', 'string', 'size:26'],
            'unit_price' => ['required', 'numeric', 'min:0', 'max:1000000000'],
        ]);

        return DB::transaction(function () use ($organizationId, $customerId, $data) {
            $customer = DB::table('customers')->where('organization_id', $organizationId)->where('id', $customerId)->lockForUpdate()->first();
            abort_unless($customer, 404);
            $product = DB::table('products')->where('organization_id', $organizationId)->where('id', $data['product_id'])->where('active', true)->first();
            abort_unless($product, 404, 'الصنف غير موجود داخل المؤسسة.');

            $packagingId = $data['packaging_id'] ?? null;
            if ($packagingId !== null) {
                $packaging = DB::table('product_packagings')->where('organization_id', $organizationId)
                    ->where('product_id', $product->id)->where('id', $packagingId)->where('active', true)->first();
                abort_unless($packaging, 422, 'وحدة البيع لا تتبع الصنف المحدد.');
            }

            $unitKey = $packagingId ?? 'base';
            $existing = DB::table('customer_product_prices')->where('organization_id', $organizationId)
                ->where('customer_id', $customerId)->where('product_id', $product->id)->where('unit_key', $unitKey)->first();
            $now = now();
            if ($existing) {
                DB::table('customer_product_prices')->where('id', $existing->id)->update([
                    'packaging_id' => $packagingId, 'unit_price' => $data['unit_price'], 'active' => true, 'updated_at' => $now,
                ]);
                $id = $existing->id;
            } else {
                $id = (string) Str::ulid();
                DB::table('customer_product_prices')->insert([
                    'id' => $id, 'organization_id' => $organizationId, 'customer_id' => $customerId,
                    'product_id' => $product->id, 'packaging_id' => $packagingId, 'unit_key' => $unitKey,
                    'unit_price' => $data['unit_price'], 'active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            return response()->json(DB::table('customer_product_prices')->where('id', $id)->first());
        }, attempts: 3);
    }

    public function show360(Request $request, string $customerId)
    {
        $organizationId = $request->user()->organization_id;
        $filters = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:10', 'max:50'],
            'invoices_page' => ['sometimes', 'integer', 'min:1'],
            'payments_page' => ['sometimes', 'integer', 'min:1'],
            'returns_page' => ['sometimes', 'integer', 'min:1'],
            'visits_page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $perPage = (int) ($filters['per_page'] ?? 25);
        $customerQuery = DB::table('customers')
            ->where('organization_id', $organizationId)
            ->where('id', $customerId);
        $this->withCreditEnabledProjection($customerQuery);
        $customer = $customerQuery->first();

        abort_unless($customer, 404);

        $invoiceBase = DB::table('sales_invoices')
            ->where('organization_id', $organizationId)
            ->where('customer_id', $customerId)
            ->where('status', 'posted');

        $summary = [
            'invoice_count' => (clone $invoiceBase)->count(),
            'sales_total' => (float) (clone $invoiceBase)->sum('total'),
            'paid_on_invoices' => (float) (clone $invoiceBase)->sum('paid_amount'),
            'open_balance' => (float) (clone $invoiceBase)->sum('balance_due'),
            'returns_total' => (float) DB::table('sales_returns')->where('organization_id', $organizationId)->where('customer_id', $customerId)->where('status', 'posted')->sum('total'),
            'collections_total' => (float) DB::table('payments')->where('organization_id', $organizationId)->where('party_type', 'customer')->where('party_id', $customerId)->where('direction', 'inbound')->where('status', 'posted')->sum('amount'),
            'last_invoice_at' => (clone $invoiceBase)->max('invoice_date'),
            'last_collection_at' => DB::table('payments')->where('organization_id', $organizationId)->where('party_type', 'customer')->where('party_id', $customerId)->where('direction', 'inbound')->where('status', 'posted')->max('payment_date'),
        ];

        $invoices = (clone $invoiceBase)->orderByDesc('invoice_date')->orderByDesc('created_at')
            ->paginate($perPage, ['id', 'document_number', 'invoice_date', 'total', 'paid_amount', 'balance_due', 'currency', 'status', 'trip_id', 'created_at'], 'invoices_page', (int) ($filters['invoices_page'] ?? 1));

        $returns = DB::table('sales_returns as return_doc')
            ->leftJoin('sales_invoices as original', function ($join): void {
                $join->on('original.id', '=', 'return_doc.original_sales_invoice_id')
                    ->on('original.organization_id', '=', 'return_doc.organization_id');
            })
            ->where('return_doc.organization_id', $organizationId)
            ->where('return_doc.customer_id', $customerId)
            ->where('return_doc.status', 'posted')
            ->orderByDesc('return_doc.return_date')->orderByDesc('return_doc.created_at')
            ->paginate($perPage, [
                'return_doc.id', 'return_doc.document_number', 'return_doc.return_date', 'return_doc.total',
                'return_doc.currency', 'return_doc.original_sales_invoice_id', 'original.document_number as original_invoice_number',
            ], 'returns_page', (int) ($filters['returns_page'] ?? 1));

        $payments = DB::table('payments')
            ->where('organization_id', $organizationId)
            ->where('party_type', 'customer')
            ->where('party_id', $customerId)
            ->where('direction', 'inbound')
            ->where('status', 'posted')
            ->orderByDesc('payment_date')->orderByDesc('created_at')
            ->paginate($perPage, ['id', 'amount', 'currency', 'payment_date', 'reference', 'trip_id', 'created_at'], 'payments_page', (int) ($filters['payments_page'] ?? 1));

        $visits = DB::table('customer_visits as visit')
            ->join('trips as trip', function ($join): void {
                $join->on('trip.id', '=', 'visit.trip_id')->on('trip.organization_id', '=', 'visit.organization_id');
            })
            ->where('visit.organization_id', $organizationId)
            ->where('visit.customer_id', $customerId)
            ->orderByDesc('visit.created_at')
            ->paginate($perPage, ['visit.id', 'visit.status', 'visit.check_in_at', 'visit.check_out_at', 'visit.notes', 'visit.created_at', 'trip.trip_number'], 'visits_page', (int) ($filters['visits_page'] ?? 1));

        return response()->json([
            'customer' => $customer,
            'summary' => $summary,
            'invoices' => $invoices,
            'returns' => $returns,
            'payments' => $payments,
            'visits' => $visits,
        ]);
    }

    public function store(Request $request)
    {
        $org = $request->user()->organization_id;

        $data=$request->validate([
            'name'=>['required','string','max:255'],
            'phone'=>['nullable','string','max:32'],
            'code'=>[
                'nullable',
                'string',
                'max:64',
                Rule::unique('customers', 'code')->where(fn ($q) => $q->where('organization_id', $org)),
            ],
            'governorate_id'=>['nullable','string','size:26'],
            'center_id'=>['nullable','string','size:26'],
            'city_area_id'=>['nullable','string','size:26'],
            'address_text'=>['nullable','string'],
            'latitude'=>['nullable','numeric','between:-90,90'],
            'longitude'=>['nullable','numeric','between:-180,180'],
            'credit_limit'=>['nullable','numeric','min:0'],
            'credit_enabled'=>['nullable','boolean'],
            'payment_terms_days'=>['nullable','integer','min:0'],
            'notes'=>['nullable','string'],
        ]);

        $id=(string)Str::ulid();
        $code=$data['code']??'CUS-'.strtoupper(Str::random(8));

        $customerAttributes = [
            'id'=>$id,'organization_id'=>$org,'code'=>$code,
            'name'=>$data['name'],'normalized_name'=>mb_strtolower(trim($data['name']),'UTF-8'),
            'phone'=>$data['phone']??null,'alternate_phone'=>null,'governorate_id'=>$data['governorate_id']??null,
            'center_id'=>$data['center_id']??null,'city_area_id'=>$data['city_area_id']??null,
            'address_text'=>$data['address_text']??null,'credit_limit'=>$data['credit_limit']??0,
            'payment_terms_days'=>$data['payment_terms_days']??0,'status'=>'active','notes'=>$data['notes']??null,
            'created_at'=>now(),'updated_at'=>now(),
        ];
        if (Schema::hasColumn('customers', 'credit_enabled')) {
            $customerAttributes['credit_enabled'] = (bool) ($data['credit_enabled'] ?? ((float) ($data['credit_limit'] ?? 0) > 0));
        }
        DB::table('customers')->insert($customerAttributes);

        if(isset($data['latitude'],$data['longitude'])){
            DB::table('customer_addresses')->insert([
                'id'=>(string)Str::ulid(),'organization_id'=>$org,'customer_id'=>$id,
                'label'=>'primary','governorate_id'=>$data['governorate_id']??null,'center_id'=>$data['center_id']??null,
                'city_area_id'=>$data['city_area_id']??null,'address_text'=>$data['address_text']??null,
                'latitude'=>$data['latitude'],'longitude'=>$data['longitude'],'is_primary'=>true,'active'=>true,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
        }

        return response()->json(DB::table('customers')->where('id',$id)->first(),201);
    }

    public function update(Request $request, string $customerId)
    {
        $organizationId = (string) $request->user()->organization_id;
        $data = $request->validate([
            'credit_enabled' => ['sometimes', 'boolean'],
            'credit_limit' => ['sometimes', 'numeric', 'min:0', 'max:1000000000'],
            'payment_terms_days' => ['sometimes', 'integer', 'min:0', 'max:3650'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);
        abort_if(array_key_exists('credit_enabled', $data) && ! Schema::hasColumn('customers', 'credit_enabled'), 503, 'تحديث مخطط قاعدة المعاينة مطلوب لتغيير تفعيل الآجل بشكل مباشر.');
        abort_unless(DB::table('customers')->where('organization_id', $organizationId)->where('id', $customerId)->exists(), 404);
        DB::table('customers')->where('organization_id', $organizationId)->where('id', $customerId)->update([...$data, 'updated_at' => now()]);

        $customerQuery = DB::table('customers')->where('organization_id', $organizationId)->where('id', $customerId);
        $this->withCreditEnabledProjection($customerQuery);
        return response()->json($customerQuery->first());
    }

    /** Import a small batch of address-book contacts into the authenticated tenant. */
    public function importContacts(Request $request)
    {
        $data = $request->validate([
            'contacts' => ['required', 'array', 'min:1', 'max:250'],
            'contacts.*.code' => ['nullable', 'string', 'max:64'],
            'contacts.*.source_contact_id' => ['nullable', 'string', 'max:64'],
            'contacts.*.name' => ['required', 'string', 'max:255'],
            'contacts.*.normalized_name' => ['nullable', 'string', 'max:255'],
            'contacts.*.phone' => ['required', 'string', 'max:32'],
            'contacts.*.alternate_phone' => ['nullable', 'string', 'max:32'],
            'contacts.*.additional_phones_json' => ['nullable', 'string', 'max:2000'],
            'contacts.*.shared_phone_review' => ['nullable', 'string', 'max:100'],
        ]);

        $organizationId = $request->user()->organization_id;
        $digits = static function ($value): string {
            $phone = preg_replace('/\\D+/', '', (string) $value) ?? '';
            if (str_starts_with($phone, '0020')) $phone = substr($phone, 2);
            if (str_starts_with($phone, '20') && strlen($phone) === 12) $phone = '0' . substr($phone, 2);
            return $phone;
        };
        $normalizeName = static function ($value): string {
            $name = mb_strtolower(trim((string) $value), 'UTF-8');
            return preg_replace('/\\s+/u', ' ', $name) ?? $name;
        };

        $result = DB::transaction(function () use ($data, $organizationId, $digits, $normalizeName): array {
            $existing = DB::table('customers')
                ->where('organization_id', $organizationId)
                ->get(['code', 'name', 'normalized_name', 'phone', 'alternate_phone']);
            $codes = $exact = $phones = [];
            foreach ($existing as $customer) {
                $code = (string) $customer->code;
                if ($code !== '') $codes[$code] = true;
                $phone = $digits($customer->phone);
                $name = $normalizeName($customer->normalized_name ?: $customer->name);
                $exact[$name . '|' . $phone] = true;
                if ($phone !== '') $phones[$phone] = true;
                $alternate = $digits($customer->alternate_phone);
                if ($alternate !== '') $phones[$alternate] = true;
            }

            $stats = ['inserted' => 0, 'skipped_duplicates' => 0, 'shared_phone_flagged' => 0];
            $seenExact = $seenPhones = [];
            foreach ($data['contacts'] as $contact) {
                $name = trim($contact['name']);
                $phone = $digits($contact['phone']);
                if ($phone === '') continue;
                $normalizedName = $normalizeName($contact['normalized_name'] ?? $name);
                $signature = $normalizedName . '|' . $phone;
                $code = trim((string) ($contact['code'] ?? ''));
                if (($code !== '' && isset($codes[$code])) || isset($exact[$signature]) || isset($seenExact[$signature])) {
                    $stats['skipped_duplicates']++;
                    continue;
                }

                $alternate = $digits($contact['alternate_phone'] ?? '');
                $shared = str_contains((string) ($contact['shared_phone_review'] ?? ''), 'مراجعة')
                    || isset($phones[$phone]) || isset($seenPhones[$phone]);
                if ($shared) $stats['shared_phone_flagged']++;
                if ($code === '') $code = 'IPHONE-' . strtoupper(substr(hash('sha256', $name . '|' . $phone), 0, 12));
                if (isset($codes[$code])) $code .= '-' . strtoupper(substr(hash('sha256', $signature), 0, 4));

                $notes = 'جهة اتصال مستوردة من iPhone؛ لم يتم التحقق من كونها عميلاً فعلياً.';
                if ($shared) $notes .= ' رقم مشترك/يحتاج مراجعة قبل إرسال الفواتير.';
                $extra = trim((string) ($contact['additional_phones_json'] ?? ''));
                if ($extra !== '' && $extra !== '[]') $notes .= ' أرقام إضافية محفوظة من المصدر: ' . $extra;
                if ($alternate !== '') $notes .= ' رقم بديل: ' . $alternate . '.';

                DB::table('customers')->insert([
                    'id' => (string) Str::ulid(),
                    'organization_id' => $organizationId,
                    'code' => $code,
                    'name' => $name,
                    'normalized_name' => $normalizedName,
                    'phone' => $phone,
                    'alternate_phone' => $alternate !== '' ? $alternate : null,
                    'address_text' => null,
                    'credit_limit' => 0,
                    'payment_terms_days' => 0,
                    'status' => 'active',
                    'notes' => $notes,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $stats['inserted']++;
                $codes[$code] = true;
                $seenExact[$signature] = true;
                $seenPhones[$phone] = true;
            }

            $stats['active_customer_count'] = DB::table('customers')
                ->where('organization_id', $organizationId)->where('status', 'active')->count();
            return $stats;
        }, 1);

        return response()->json($result);
    }

    /**
     * Read the same initial credit eligibility as migration 2026_10_20_000002
     * when a local preview has not yet received the additive credit flag column.
     */
    private function withCreditEnabledProjection($query)
    {
        if (Schema::hasColumn('customers', 'credit_enabled')) {
            return $query;
        }

        return $query->select('customers.*')->selectRaw("CASE WHEN COALESCE(customers.credit_limit, 0) > 0 OR EXISTS (SELECT 1 FROM sales_invoices AS credit_invoice WHERE credit_invoice.organization_id = customers.organization_id AND credit_invoice.customer_id = customers.id AND credit_invoice.status = 'posted' AND credit_invoice.balance_due > 0) THEN 1 ELSE 0 END AS credit_enabled");
    }

}
