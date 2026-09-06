<?php

namespace App\Http\Requests;

use App\Enums\PaymentGatewayStatus;
use App\Models\Payment;
use App\Models\Payout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StorePayoutRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'vendor_id' => ['required', 'integer', 'exists:vendors,id'],
            'amount' => ['required', 'numeric', 'min:0.01', function ($attribute, $value, $fail): void {
                $vendorId = $this->integer('vendor_id');

                if (! $vendorId) {
                    return;
                }

                $outstanding = $this->outstandingBalance($vendorId);

                if ((float) $value > $outstanding) {
                    $fail('Jumlah payout melebihi saldo tertunda vendor (Rp'.number_format($outstanding, 0, ',', '.').').');
                }
            }],
            'note' => ['nullable', 'string', 'max:500'],
            'paid_at' => ['nullable', 'date'],
        ];
    }

    private function outstandingBalance(int $vendorId): float
    {
        $payoutOwed = (float) Payment::whereHas('booking', fn ($query) => $query->where('vendor_id', $vendorId))
            ->where('status', PaymentGatewayStatus::Paid)
            ->sum('vendor_payout_amount');

        $paidOut = (float) Payout::where('vendor_id', $vendorId)->sum('amount');

        return round($payoutOwed - $paidOut, 2);
    }
}
