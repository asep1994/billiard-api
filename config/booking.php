<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform Service Fee
    |--------------------------------------------------------------------------
    |
    | A flat fee added to every customer self-service booking on top of the
    | table price, covering payment processing. Not applied to bookings
    | entered by vendor staff, which are typically settled in cash on-site.
    |
    */

    'service_fee' => (float) env('BOOKING_SERVICE_FEE', 3000),

];
