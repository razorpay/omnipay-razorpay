<?php

namespace Omnipay\Razorpay\Tests\Message;

use Omnipay\Tests\TestCase;
use Omnipay\Razorpay\Message\Signature;
use Omnipay\Razorpay\Message\CompletePurchaseRequest;
use Omnipay\Razorpay\Message\CompletePurchaseResponse;

class CompletePurchaseResponseTest extends TestCase
{
    const KEY_ID = 'rzp_test_1DP5mmOlF5G5ag';
    const KEY_SECRET = 'random_key_secret';
    const REFERENCE = 'ORDER-123';
    const ORDER_ID = 'order_TESTORDER123456';
    const PAYMENT_ID = 'pay_TESTPAYMENT123456';

    private $parameters;

    private $payment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parameters = array(
            'amount'        => '10.00',
            'currency'      => 'INR',
            'transactionId' => self::REFERENCE,
            'key_id'        => self::KEY_ID,
            'key_secret'    => self::KEY_SECRET,
        );

        $this->payment = array(
            'id'       => self::PAYMENT_ID,
            'status'   => 'captured',
            'amount'   => 1000,
            'currency' => 'INR',
            'order_id' => self::ORDER_ID,
        );
    }

    protected function tearDown(): void
    {
        $_POST = array();

        parent::tearDown();
    }

    public function testCompletedMatchingPaymentSucceeds()
    {
        $this->assertTrue($this->isSuccessful($this->signedCallback()));
    }

    public function testFailedResultIsRejected()
    {
        $this->assertFalse($this->isSuccessful($this->signedCallback(array('x_result' => 'failed'))));
    }

    public function testPendingResultIsRejected()
    {
        $this->assertFalse($this->isSuccessful($this->signedCallback(array('x_result' => 'pending'))));
    }

    public function testEmptyResultIsRejected()
    {
        $this->assertFalse($this->isSuccessful($this->signedCallback(array('x_result' => ''))));
    }

    public function testReferenceMismatchIsRejected()
    {
        $this->assertFalse($this->isSuccessful($this->signedCallback(array('x_reference' => 'ORDER-999'))));
    }

    public function testAmountMismatchIsRejected()
    {
        $this->assertFalse($this->isSuccessful($this->signedCallback(array('x_amount' => '1.00'))));
    }

    public function testCurrencyMismatchIsRejected()
    {
        $this->assertFalse($this->isSuccessful($this->signedCallback(array('x_currency' => 'USD'))));
    }

    public function testMerchantKeyMismatchIsRejected()
    {
        $callback = $this->signedCallback(array('x_account_id' => 'rzp_test_someoneElse'));

        $this->assertFalse($this->isSuccessful($callback));
    }

    public function testInvalidSignatureIsRejected()
    {
        $callback = $this->signedCallback();
        $callback['x_signature'] = str_repeat('a', 64);

        $this->assertFalse($this->isSuccessful($callback));
    }

    public function testMissingSignatureIsRejected()
    {
        $callback = $this->signedCallback();
        unset($callback['x_signature']);

        $this->assertFalse($this->isSuccessful($callback));
    }

    public function testPaymentNotCapturedIsRejected()
    {
        $payment = array_merge($this->payment, array('status' => 'authorized'));

        $this->assertFalse($this->isSuccessful($this->signedCallback(), $payment));
    }

    public function testPaidAmountMismatchIsRejected()
    {
        $payment = array_merge($this->payment, array('amount' => 100));

        $this->assertFalse($this->isSuccessful($this->signedCallback(), $payment));
    }

    public function testPaidCurrencyMismatchIsRejected()
    {
        $payment = array_merge($this->payment, array('currency' => 'USD'));

        $this->assertFalse($this->isSuccessful($this->signedCallback(), $payment));
    }

    public function testPaymentFromAnotherOrderIsRejected()
    {
        $payment = array_merge($this->payment, array('order_id' => 'order_SOMEONEELSE123'));

        $this->assertFalse($this->isSuccessful($this->signedCallback(), $payment));
    }

    public function testApiErrorIsRejected()
    {
        $this->assertFalse($this->isSuccessful(
            $this->signedCallback(),
            new \RuntimeException('Razorpay API unavailable')
        ));
    }

    public function testMissingGatewayReferenceIsRejected()
    {
        $this->assertFalse($this->isSuccessful($this->signedCallback(array('x_gateway_reference' => ''))));
    }

    /**
     * The signature scheme concatenates key + value with no separators, so the
     * same bytes can be re-split into different fields. Such a payload passes
     * the signature check, and must still be rejected because the fields it
     * dropped no longer match the order.
     */
    public function testResplitPayloadWithSameSignatureIsRejected()
    {
        $signer = new Signature(self::KEY_SECRET);

        $original = array(
            'x_account_id' => self::KEY_ID,
            'x_amount'     => '10.00',
            'x_currency'   => 'INR',
            'x_reference'  => self::REFERENCE,
            'x_result'     => CompletePurchaseResponse::RESULT_COMPLETED,
        );

        $signature = $signer->getSignature($original);

        $forged = array(
            'x_account_id' => self::KEY_ID . 'x_amount10.00x_currencyINR',
            'x_reference'  => self::REFERENCE,
            'x_result'     => CompletePurchaseResponse::RESULT_COMPLETED,
            'x_signature'  => $signature,
        );

        // Same concatenated message, split differently: the signature verifies.
        $this->assertSame($signature, $signer->getSignature($forged));

        $this->assertFalse($this->isSuccessful($forged));
    }

    /**
     * Builds a callback that hosted checkout would sign for this order.
     *
     * @return array
     */
    private function signedCallback(array $overrides = array())
    {
        $callback = array_merge(array(
            'x_account_id'        => self::KEY_ID,
            'x_amount'            => '10.00',
            'x_currency'          => 'INR',
            'x_gateway_reference' => self::ORDER_ID . '|' . self::PAYMENT_ID,
            'x_reference'         => self::REFERENCE,
            'x_result'            => CompletePurchaseResponse::RESULT_COMPLETED,
            'x_test'              => 'false',
        ), $overrides);

        $signer = new Signature(self::KEY_SECRET);
        $callback['x_signature'] = $signer->getSignature($callback);

        return $callback;
    }

    /**
     * @param array             $callback the posted $_POST payload
     * @param array|\Throwable  $payment  what the Razorpay API returns
     *
     * @return bool
     */
    private function isSuccessful(array $callback, $payment = null)
    {
        $_POST = $callback;

        $request = new CompletePurchaseRequest($this->getHttpClient(), $this->getHttpRequest());
        $request->initialize($this->parameters);

        $response = new TestableCompletePurchaseResponse($request, array());
        $response->api = new FakeApi($payment === null ? $this->payment : $payment);

        return $response->isSuccessful();
    }
}

class TestableCompletePurchaseResponse extends CompletePurchaseResponse
{
    public $api;

    protected function createApi($request)
    {
        return $this->api;
    }
}

class FakeApi
{
    public $payment;

    public function __construct($payment)
    {
        $this->payment = new FakePaymentResource($payment);
    }
}

class FakePaymentResource
{
    private $payment;

    public function __construct($payment)
    {
        $this->payment = $payment;
    }

    public function fetch($paymentId)
    {
        if ($this->payment instanceof \Throwable) {
            throw $this->payment;
        }

        return $this->payment;
    }
}
