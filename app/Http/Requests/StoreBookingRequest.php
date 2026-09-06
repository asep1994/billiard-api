<?php

namespace App\Http\Requests;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
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

            $overlaps = Booking::query()
                ->where('billiard_table_id', $this->input('billiard_table_id'))
                ->where('status', '!=', BookingStatus::Cancelled)
                ->where('start_time', '<', $this->input('end_time'))
                ->where('end_time', '>', $this->input('start_time'))
                ->exists();

            if ($overlaps) {
                $validator->errors()->add('billiard_table_id', 'This table is already booked for the selected time range.');
            }
        });
    }
}
