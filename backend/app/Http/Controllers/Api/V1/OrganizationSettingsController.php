<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\Request;

final class OrganizationSettingsController extends Controller
{
    public function show(Request $request)
    {
        $organization = Organization::query()->findOrFail($request->user()->organization_id);
        return response()->json([
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'legal_name' => $organization->legal_name,
                'default_currency' => $organization->default_currency,
                'timezone' => $organization->timezone,
                'country_code' => $organization->country_code,
            ],
            'settings' => $organization->settings_json ?? [],
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'organization.name' => ['sometimes', 'required', 'string', 'max:180'],
            'organization.legal_name' => ['nullable', 'string', 'max:180'],
            'organization.default_currency' => ['sometimes', 'required', 'in:EGP,SAR,USD'],
            'organization.timezone' => ['sometimes', 'required', 'in:Africa/Cairo,Asia/Riyadh,UTC'],
            'settings' => ['sometimes', 'array'],
            'settings.invoice_prefix' => ['sometimes', 'string', 'max:12'],
            'settings.invoice_footer' => ['sometimes', 'string', 'max:500'],
            'settings.default_payment_method' => ['sometimes', 'in:cash,credit,partial'],
            'settings.allow_customer_credit' => ['sometimes', 'boolean'],
            'settings.allow_invoice_discount' => ['sometimes', 'boolean'],
            'settings.require_sale_price_confirmation' => ['sometimes', 'boolean'],
            'settings.prevent_negative_stock' => ['sometimes', 'boolean'],
            'settings.default_credit_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'settings.default_customer_credit_limit' => ['sometimes', 'numeric', 'min:0'],
            'settings.low_stock_threshold' => ['sometimes', 'numeric', 'min:0'],
            'settings.tax_enabled' => ['sometimes', 'boolean'],
            'settings.tax_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
        ]);

        $organization = Organization::query()->findOrFail($request->user()->organization_id);
        if (isset($data['organization'])) $organization->fill($data['organization']);
        if (isset($data['settings'])) {
            $organization->settings_json = array_replace($organization->settings_json ?? [], $data['settings']);
        }
        $organization->save();

        return $this->show($request);
    }
}
