<?php

namespace Omnipay\Razorpay\Message;

use Razorpay\Api\Api;

/**
 * Response for the callback that Razorpay hosted checkout posts back to the
 * merchant's return URL (x_url_complete / x_url_callback).
 *
 * The callback payload is shopper-controlled: it reaches the merchant through
 * the shopper's browser, and x_signature is computed with a scheme that
 * concatenates key + value without separators, so bytes can be re-split
 * between fields without invalidating it.
 *
 * isSuccessful() therefore never decides on the posted payload alone. It uses
 * the payload only to find the payment, then re-reads that payment from the
 * Razorpay API with the merchant's key and requires it to be captured and to
 * match the order being completed.
 */
class CompletePurchaseResponse extends PurchaseResponse
{
    /**
     * Value Razorpay hosted checkout posts in x_result for a successful payment.
     */
    const RESULT_COMPLETED = 'completed';

    /**
     * The only payment status that may complete an order.
     */
    const PAYMENT_STATUS_CAPTURED = 'captured';

    /**
     * @return bool
     */
    public function isSuccessful()
    {
        $request = $this->getRequest();

        if (!($request instanceof CompletePurchaseRequest)) {
            return false;
        }

        if (!$this->hasValidSignature($request)) {
            return false;
        }

        if ($this->getCallbackValue('x_result') !== self::RESULT_COMPLETED) {
            return false;
        }

        if (!$this->callbackMatchesOrder($request)) {
            return false;
        }

        $payment = $this->fetchPayment($request);

        if ($payment === null) {
            return false;
        }

        return $this->paymentMatchesOrder($payment, $request);
    }

    /**
     * Kept as an additional filter only; the payment lookup below is the check
     * that decides. The scheme itself is ambiguous (see the class docblock),
     * so a valid signature proves the payload was signed with the merchant's
     * key secret, not that it says what the shopper claims it says.
     *
     * @return bool
     */
    protected function hasValidSignature($request)
    {
        $keySecret = (string) $request->getKeySecret();
        $signature = $this->getCallbackValue('x_signature');

        if ($keySecret === '' || $signature === '') {
            return false;
        }

        $signer = new Signature($keySecret);
        $expectedSignature = $signer->getSignature($_POST);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Binds the posted callback to the order this request is completing, using
     * the expected values from the request parameters and never from $_POST.
     *
     * @return bool
     */
    protected function callbackMatchesOrder($request)
    {
        $expectedReference = (string) $request->getTransactionId();
        $expectedCurrency = strtoupper((string) $request->getCurrency());
        $expectedAmount = $this->getExpectedAmountInPaise($request);

        if ($expectedReference === '' || $expectedCurrency === '' || $expectedAmount === null) {
            return false;
        }

        if ($this->getCallbackValue('x_reference') !== $expectedReference) {
            return false;
        }

        if (strtoupper($this->getCallbackValue('x_currency')) !== $expectedCurrency) {
            return false;
        }

        $expectedKeyId = (string) $request->getKeyID();
        if ($expectedKeyId !== '' && $this->getCallbackValue('x_account_id') !== $expectedKeyId) {
            return false;
        }

        return $this->amountMatchesInPaise($this->getCallbackValue('x_amount'), $expectedAmount);
    }

    /**
     * Razorpay is the source of truth for whether money moved: the payment must
     * be captured, for the exact amount and currency of this order, and it must
     * belong to the order id handed back in x_gateway_reference.
     *
     * @return bool
     */
    protected function paymentMatchesOrder($payment, $request)
    {
        $status = isset($payment['status']) ? (string) $payment['status'] : '';
        if ($status !== self::PAYMENT_STATUS_CAPTURED) {
            return false;
        }

        $expectedAmount = $this->getExpectedAmountInPaise($request);
        $paidAmount = isset($payment['amount']) ? (int) $payment['amount'] : 0;
        if ($expectedAmount === null || $paidAmount !== $expectedAmount) {
            return false;
        }

        $paidCurrency = isset($payment['currency']) ? strtoupper((string) $payment['currency']) : '';
        if ($paidCurrency !== strtoupper((string) $request->getCurrency())) {
            return false;
        }

        list($orderId) = $this->getGatewayReferenceParts();
        if ($orderId !== null) {
            $paidOrderId = isset($payment['order_id']) ? (string) $payment['order_id'] : '';
            if ($paidOrderId !== $orderId) {
                return false;
            }
        }

        return true;
    }

    /**
     * Reads x_gateway_reference, which hosted checkout builds as
     * "<razorpay_order_id>|<payment_id>" (or just the order id when no payment
     * was made).
     *
     * @return array [order id|null, payment id|null]
     */
    protected function getGatewayReferenceParts()
    {
        $reference = $this->getCallbackValue('x_gateway_reference');

        $orderId = null;
        $paymentId = null;

        if ($reference === '') {
            return array($orderId, $paymentId);
        }

        foreach (explode('|', $reference) as $part) {
            if (preg_match('/\Aorder_[A-Za-z0-9]+\z/', $part) === 1) {
                $orderId = $part;
                continue;
            }

            if (preg_match('/\Apay_[A-Za-z0-9]+\z/', $part) === 1) {
                $paymentId = $part;
            }
        }

        return array($orderId, $paymentId);
    }

    /**
     * @return mixed|null the payment entity, or null when it cannot be fetched
     */
    protected function fetchPayment($request)
    {
        list(, $paymentId) = $this->getGatewayReferenceParts();

        if ($paymentId === null) {
            return null;
        }

        try {
            return $this->createApi($request)->payment->fetch($paymentId);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return Api
     */
    protected function createApi($request)
    {
        return new Api($request->getKeyID(), $request->getKeySecret());
    }

    /**
     * @return int|null the order amount in paise
     */
    protected function getExpectedAmountInPaise($request)
    {
        $amount = $request->getAmount();

        if ($amount === null || $amount === '' || !is_numeric($amount)) {
            return null;
        }

        $paise = (int) round(((float) $amount) * 100);

        return $paise > 0 ? $paise : null;
    }

    /**
     * Hosted checkout posts x_amount in major units (the same format the
     * merchant sent), so accept either that or paise, as long as both resolve
     * to the exact expected amount.
     *
     * @return bool
     */
    protected function amountMatchesInPaise($postedAmount, $expectedPaise)
    {
        if ($postedAmount === '' || !is_numeric($postedAmount)) {
            return false;
        }

        $value = (float) $postedAmount;

        return abs($value - $expectedPaise) < 0.0001
            || abs(($value * 100) - $expectedPaise) < 0.0001;
    }

    /**
     * @return string
     */
    protected function getCallbackValue($key)
    {
        if (!isset($_POST[$key]) || !is_scalar($_POST[$key])) {
            return '';
        }

        return trim((string) $_POST[$key]);
    }
}
