<?php

namespace App\Http\Requests\Api\V1;

use App\Support\Validation\BusinessScopedExists;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBusinessRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return (bool) $user?->business_id && ($user->is_business_owner || $user->hasRole('admin'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'owner_name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:255'],
            'whatsapp_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'instagram_handle' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tiktok_handle' => ['sometimes', 'nullable', 'string', 'max:255'],
            'nit' => ['sometimes', 'nullable', 'string', 'max:30'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ticket_paper_width' => ['sometimes', 'string', 'in:58,80'],
            'invoice_prefix' => ['sometimes', 'string', 'max:10'],
            'ticket_header_tagline' => ['sometimes', 'nullable', 'string', 'max:500'],
            'ticket_thanks_message' => ['sometimes', 'nullable', 'string', 'max:500'],
            'ticket_footer_text' => ['sometimes', 'nullable', 'string'],
            'delivery_enabled' => ['sometimes', 'boolean'],
            'delivery_fee' => ['sometimes', 'numeric', 'min:0'],
            'service_charge_enabled' => ['sometimes', 'boolean'],
            'service_charge_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'ipoconsumo_enabled' => ['sometimes', 'boolean'],
            'ipoconsumo_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'payment_methods' => ['sometimes', 'array'],
            'low_stock_alert_threshold' => ['sometimes', 'integer', 'min:0'],
            'low_stock_email_enabled' => ['sometimes', 'boolean'],
            'low_stock_email' => ['sometimes', 'nullable', 'email'],
            'service_orders_show_catalog' => ['sometimes', 'boolean'],
            'service_orders_default_service_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'layaway_allowed_category_ids' => ['sometimes', 'nullable', 'array'],
            'layaway_allowed_category_ids.*' => [
                'integer',
                BusinessScopedExists::for('product_categories', $this->user()?->business_id),
            ],
            'email_header_color' => ['sometimes', 'nullable', 'string', 'max:20'],
            'email_footer_text' => ['sometimes', 'nullable', 'string', 'max:200'],
            'email_whatsapp_cta' => ['sometimes', 'boolean'],
        ];
    }
}
