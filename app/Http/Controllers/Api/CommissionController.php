<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentGatewayStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Vendor;
use Illuminate\Http\Request;

class CommissionController extends Controller
{
    /**
     * Display commission and payout summaries, one row per vendor.
     */
    public function index(Request $request)
    {
        abort_unless(
            $request->user()->isSuperAdmin() || $request->user()->isVendorAdmin(),
            403,
            'You are not authorized to view commission data.',
        );

        $isSuperAdmin = $request->user()->isSuperAdmin();
        $vendorId = $request->user()->vendor_id;

        $vendors = $isSuperAdmin ? Vendor::query()->get() : Vendor::where('id', $vendorId)->get();

        $paymentSums = Payment::query()
            ->join('bookings', 'bookings.id', '=', 'payments.booking_id')
            ->where('payments.status', PaymentGatewayStatus::Paid->value)
            ->when(! $isSuperAdmin, fn ($query) => $query->where('bookings.vendor_id', $vendorId))
            ->selectRaw('bookings.vendor_id as vendor_id, SUM(payments.amount) as gross, SUM(payments.commission_amount) as commission, SUM(payments.vendor_payout_amount) as payout_owed')
            ->groupBy('bookings.vendor_id')
            ->get()
            ->keyBy('vendor_id');

        $payoutSums = Payout::query()
            ->when(! $isSuperAdmin, fn ($query) => $query->where('vendor_id', $vendorId))
            ->selectRaw('vendor_id, SUM(amount) as paid_out')
            ->groupBy('vendor_id')
            ->get()
            ->keyBy('vendor_id');

        $summaries = $vendors->map(function (Vendor $vendor) use ($paymentSums, $payoutSums) {
            $sums = $paymentSums->get($vendor->id);
            $gross = (float) ($sums->gross ?? 0);
            $commission = (float) ($sums->commission ?? 0);
            $payoutOwed = (float) ($sums->payout_owed ?? 0);
            $paidOut = (float) ($payoutSums->get($vendor->id)->paid_out ?? 0);
            $outstanding = round($payoutOwed - $paidOut, 2);

            return [
                'vendor_id' => $vendor->id,
                'vendor_name' => $vendor->name,
                'commission_rate' => (float) $vendor->commission_rate,
                'gross_revenue' => number_format($gross, 2, '.', ''),
                'commission_earned' => number_format($commission, 2, '.', ''),
                'payout_owed' => number_format($payoutOwed, 2, '.', ''),
                'paid_out' => number_format($paidOut, 2, '.', ''),
                'outstanding_balance' => number_format($outstanding, 2, '.', ''),
            ];
        });

        return response()->json(['data' => $summaries->values()]);
    }
}
