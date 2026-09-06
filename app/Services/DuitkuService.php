<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class DuitkuService
{
    private const SANDBOX_URL = 'https://sandbox.duitku.com';

    private const PRODUCTION_URL = 'https://passport.duitku.com';

    private const INQUIRY_PATH = '/webapi/api/merchant/v2/inquiry';

    public function __construct(
        private readonly string $merchantCode,
        private readonly string $apiKey,
        private readonly bool $sandbox,
    ) {}

    /**
     * Build the signature Duitku expects for a Create Transaction (v2 inquiry) request.
     *
     * Formula per Duitku API v2: MD5(merchantCode + merchantOrderId + paymentAmount + apiKey).
     */
    public function generateSignature(string $merchantOrderId, int $paymentAmount): string
    {
        return md5($this->merchantCode.$merchantOrderId.$paymentAmount.$this->apiKey);
    }

    /**
     * Request a new transaction from Duitku's v2 inquiry endpoint.
     *
     * @param  array{
     *     paymentAmount: int,
     *     merchantOrderId: string,
     *     productDetails: string,
     *     email: string,
     *     customerVaName: string,
     *     callbackUrl: string,
     *     returnUrl: string,
     *     paymentMethod?: string|null,
     *     phoneNumber?: string|null,
     *     expiryPeriod?: int,
     * }  $payload
     * @return array<string, mixed> the decoded Duitku response (statusCode, statusMessage, reference, paymentUrl, ...)
     */
    public function createTransaction(array $payload): array
    {
        $paymentAmount = (int) $payload['paymentAmount'];

        $body = array_merge($payload, [
            'merchantCode' => $this->merchantCode,
            'signature' => $this->generateSignature($payload['merchantOrderId'], $paymentAmount),
        ]);

        $response = Http::baseUrl($this->baseUrl())->post(self::INQUIRY_PATH, $body);

        if ($response->failed()) {
            throw new RuntimeException("Duitku create transaction request failed: {$response->status()} {$response->body()}");
        }

        return $response->json();
    }

    /**
     * Verify the signature Duitku sends with a payment notification callback.
     *
     * Formula per Duitku API v2: MD5(merchantCode + amount + merchantOrderId + apiKey).
     */
    public function verifyCallbackSignature(string $merchantOrderId, int $amount, string $signature): bool
    {
        $expected = md5($this->merchantCode.$amount.$merchantOrderId.$this->apiKey);

        return hash_equals($expected, $signature);
    }

    private function baseUrl(): string
    {
        return $this->sandbox ? self::SANDBOX_URL : self::PRODUCTION_URL;
    }
}
