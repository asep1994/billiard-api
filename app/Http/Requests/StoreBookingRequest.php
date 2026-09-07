<?php

namespace App\Http\Requests;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Promotion;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class StoreBookingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize start/end time to app-timezone wall-clock strings before
     * validation - see StoreCustomerBookingRequest for why this matters:
     * Eloquent's `datetime` cast preserves whatever offset a value was
     * parsed with instead of converting to `config('app.timezone')` on
     * save, so a UTC-tagged input would otherwise land in the database at
     * the wrong wall-clock hour.
     */
    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['start_time', 'end_time'] as $field) {
            if ($this->filled($field)) {
                $normalized[$field] = Carbon::parse($this->input($field))
                    ->setTimezone(config('app.timezone'))
                    ->format('Y-m-d H:i:s');
            }
        }

        $this->merge($normalized);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $vendorId = $this->user()->isSuperAdmin() ? $this->integer('vendor_id') : $this->user()->vendor_id;

        return [
            'vendor_id' => [Rule::requiredIf($this->user()->isSuperAdmin()), 'integer', 'exists:vendors,id'],
            'venue_id' => ['required', 'integer', Rule::exists('venues', 'id')->where('vendor_id', $vendorId)],
            'billiard_table_id' => ['required', 'integer', Rule::exists('billiard_tables', 'id')->where('venue_id', $this->input('venue_id'))],
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('vendor_id', $vendorId)],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('vendor_id', $vendorId)],
            'start_time' => ['required', 'date'],
            'end_time' => ['required', 'date', 'after:start_time'],
            'status' => ['sometimes', Rule::enum(BookingStatus::class)],
            'payment_status' => ['sometimes', Rule::enum(PaymentStatus::class)],
            'promo_code' => ['nullable', 'string', Rule::exists('promotions', 'code')->where('vendor_id', $vendorId)],
            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled(['billiard_table_id', 'start_time', 'end_time'])) {
                return;
            }

            $overlaps = Booking::overlapsExisting(
                $this->integer('billiard_table_id'),
                $this->input('start_time'),
                $this->input('end_time'),
            );

            if ($overlaps) {
                $validator->errors()->add('billiard_table_id', 'This table is already booked for the selected time range.');
            }
        });

        $validator->after(function (Validator $validator): void {
            if (! $this->filled('promo_code')) {
                return;
            }

            $vendorId = $this->user()->isSuperAdmin() ? $this->integer('vendor_id') : $this->user()->vendor_id;

            $promotion = Promotion::where('vendor_id', $vendorId)
                ->where('code', $this->input('promo_code'))
                ->first();

            if ($promotion && ! $promotion->isValidNow()) {
                $validator->errors()->add('promo_code', 'This promo code is no longer valid.');
            }
        });
    }
}
