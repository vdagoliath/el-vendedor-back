<?php

namespace App\Http\Requests\Api\Marketplace\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConfirmMarketplaceQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'recipient' => ['nullable', 'array'],
            'recipient.name' => ['nullable', 'string', 'max:255'],
            'recipient.phone' => ['nullable', 'string', 'max:64'],
            'recipient.email' => ['nullable', 'email', 'max:255'],
            'delivery_address' => ['nullable', 'array'],
            'delivery_address.country' => ['nullable', 'string', 'max:120'],
            'delivery_address.province' => ['nullable', 'string', 'max:120'],
            'delivery_address.municipality' => ['nullable', 'string', 'max:120'],
            'delivery_address.street' => ['nullable', 'string', 'max:255'],
            'payment' => ['nullable', 'array'],
            'payment.status' => ['nullable', 'string', Rule::in(['pending', 'authorized', 'paid', 'failed', 'refunded'])],
            'payment.provider' => ['nullable', 'string', 'max:64'],
            'payment.reference' => ['nullable', 'string', 'max:191'],
            'payment.amount' => ['nullable', 'numeric', 'min:0'],
            'delivery' => ['nullable', 'array'],
            'delivery.status' => ['nullable', 'string', Rule::in(['pending', 'requested', 'assigned', 'in_transit', 'delivered', 'failed'])],
            'delivery.provider' => ['nullable', 'string', 'max:64'],
            'delivery.method' => ['nullable', 'string', 'max:64'],
            'delivery.reference' => ['nullable', 'string', 'max:191'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function recipientSnapshot(): ?array
    {
        $recipient = $this->validated('recipient');

        return is_array($recipient) ? $recipient : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function deliveryAddressSnapshot(): ?array
    {
        $address = $this->validated('delivery_address');

        return is_array($address) ? $address : null;
    }

    public function paymentStatus(): string
    {
        $payment = $this->validated('payment');

        return is_array($payment) && is_string($payment['status'] ?? null)
            ? $payment['status']
            : 'pending';
    }

    public function deliveryStatus(): string
    {
        $delivery = $this->validated('delivery');

        return is_array($delivery) && is_string($delivery['status'] ?? null)
            ? $delivery['status']
            : 'pending';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function paymentSnapshot(): ?array
    {
        $payment = $this->validated('payment');

        return is_array($payment) ? $payment : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function deliverySnapshot(): ?array
    {
        $delivery = $this->validated('delivery');

        return is_array($delivery) ? $delivery : null;
    }
}
