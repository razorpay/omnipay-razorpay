<?php

declare(strict_types=1);

use Omnipay\Razorpay\Message\CompletePurchaseResponse;

/**
 * Demo-only wrapper that exposes the checks inside CompletePurchaseResponse so
 * the UI can show which one rejected the callback. It adds no logic of its own.
 */
class DemoCompletePurchaseResponse extends CompletePurchaseResponse
{
    private $payment = null;

    private $paymentResolved = false;

    private $paymentError = null;

    /**
     * @return array<int, array{0: string, 1: bool, 2: string}>
     */
    public function trace(): array
    {
        $request = $this->getRequest();

        $steps = array();

        $steps[] = array(
            'Signature verifies',
            $this->hasValidSignature($request),
            'x_signature recomputed over the posted x_* fields with the merchant key secret.',
        );

        $result = $this->callbackValue('x_result');
        $steps[] = array(
            "x_result is 'completed'",
            $result === self::RESULT_COMPLETED,
            'Posted value: ' . ($result === '' ? '(empty)' : $result),
        );

        $steps[] = array(
            'Callback matches the order',
            $this->callbackMatchesOrder($request),
            'x_reference, x_amount, x_currency and x_account_id must equal the order being completed.',
        );

        $payment = $this->payment();
        $steps[] = array(
            'Payment fetched from the Razorpay API',
            $payment !== null,
            $payment === null ? (string) $this->paymentError : 'ok',
        );

        $steps[] = array(
            'Payment is captured and matches the order',
            $payment !== null && $this->paymentMatchesOrder($payment, $request),
            'status, amount, currency and order id are compared with the order.',
        );

        return $steps;
    }

    /**
     * @return mixed|null
     */
    public function payment()
    {
        if (!$this->paymentResolved) {
            $this->paymentResolved = true;
            $this->payment = $this->fetchPaymentFromApi();
        }

        return $this->payment;
    }

    /**
     * @return mixed|null
     */
    public function rawPayment()
    {
        return $this->payment;
    }

    public function callbackValue(string $key): string
    {
        return $this->getCallbackValue($key);
    }

    /**
     * Serve the cached payment so isSuccessful() does not hit the API twice.
     */
    protected function fetchPayment($request)
    {
        return $this->payment();
    }

    /**
     * @return mixed|null
     */
    protected function fetchPaymentFromApi()
    {
        list(, $paymentId) = $this->getGatewayReferenceParts();

        if ($paymentId === null) {
            $this->paymentError = 'x_gateway_reference carries no pay_ id.';

            return null;
        }

        try {
            return $this->createApi($this->getRequest())->payment->fetch($paymentId);
        } catch (\Throwable $e) {
            $this->paymentError = $e->getMessage();

            return null;
        }
    }
}
