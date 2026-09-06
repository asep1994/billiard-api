<?php

namespace App\Http\Requests;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBookingRequest extends FormRequest
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
        $booking = $this->route('booking');

        return [
            'billiard_table_id' => ['sometimes', 'integer', Rule::exists('billiard_tables', 'id')->where('venue_id', $booking->venue_id)],
            'customer_id' => ['sometimes', 'integer', Rule::exists('customers', 'id')->where('vendor_id', $booking->vendor_id)],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('vendor_id', $booking->vendor_id)],
            'start_time' => ['sometimes', 'date'],
            'end_time' => ['sometimes', 'date', 'after:start_time'],
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
            $booking = $this->route('booking');

            $tableId = $this->input('billiard_table_id', $booking->billiard_table_id);
            $start = $this->input('start_time', $booking->start_time);
            $end = $this->input('end_time', $booking->end_time);

            if (($this->input('status') ?? $booking->status->value) === BookingStatus::Cancelled->value) {
                return;
            }

            $overlaps = Booking::query()
                ->where('billiard_table_id', $tableId)
                ->where('id', '!=', $booking->id)
                ->where('status', '!=', BookingStatus::Cancelled)
                ->where('start_time', '<', $end)
                ->where('end_time', '>', $start)
                ->exists();

            if ($overlaps) {
                $validator->errors()->add('billiard_table_id', 'This table is already booked for the selected time range.');
            }
        });
    }
}
