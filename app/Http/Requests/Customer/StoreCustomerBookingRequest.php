<?php

namespace App\Http\Requests\Customer;

use App\Enums\Status;
use App\Models\Booking;
use App\Models\Promotion;
use App\Models\Venue;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerBookingRequest extends FormRequest
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
            'venue_id' => ['required', 'integer', Rule::exists('venues', 'id')->where('status', Status::Active->value)],
            'billiard_table_id' => ['required', 'integer', Rule::exists('billiard_tables', 'id')->where('venue_id', $this->input('venue_id'))],
            'start_time' => ['required', 'date', 'after:now'],
            'end_time' => ['required', 'date', 'after:start_time'],
            'promo_code' => ['nullable', 'string'],
            'notes' => ['nullable', 'string', 'max:500'],
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

            if (Booking::overlapsExisting($this->integer('billiard_table_id'), $this->input('start_time'), $this->input('end_time'))) {
                $validator->errors()->add('billiard_table_id', 'This table is already booked for the selected time range.');
            }
        });

        $validator->after(function (Validator $validator): void {
            if (! $this->filled(['venue_id', 'promo_code'])) {
                return;
            }

            $venue = Venue::find($this->input('venue_id'));

            if (! $venue) {
                return;
            }

            $promotion = Promotion::where('vendor_id', $venue->vendor_id)
                ->where('code', $this->input('promo_code'))
                ->first();

            if ($promotion && ! $promotion->isValidNow()) {
                $validator->errors()->add('promo_code', 'This promo code is no longer valid.');
            }
        });
    }
}
