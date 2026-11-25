<?php

namespace Omnipay\Razorpay\Tests\Message;

use Omnipay\Tests\TestCase;
use Omnipay\Razorpay\Message\Signature;

class SignatureTest extends TestCase
{
    private $signature;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signature = new Signature('random_key_secret');
    }

    public function testBooleanFalseMatchesTheStringHostedCheckoutSigns()
    {
        $this->assertSame(
            $this->signature->getSignature(array('x_test' => false, 'x_reference' => 'ORDER-1')),
            $this->signature->getSignature(array('x_test' => 'false', 'x_reference' => 'ORDER-1'))
        );
    }

    public function testBooleanTrueMatchesTheStringHostedCheckoutSigns()
    {
        $this->assertSame(
            $this->signature->getSignature(array('x_test' => true, 'x_reference' => 'ORDER-1')),
            $this->signature->getSignature(array('x_test' => 'true', 'x_reference' => 'ORDER-1'))
        );
    }

    public function testAnEmptyXTestIsSignedAsFalse()
    {
        $this->assertSame(
            $this->signature->getSignature(array('x_test' => '', 'x_reference' => 'ORDER-1')),
            $this->signature->getSignature(array('x_test' => 'false', 'x_reference' => 'ORDER-1'))
        );
    }

    /**
     * The bug this guards: signing the boolean produced "x_test", while hosted
     * computes "x_testfalse", so every real form failed verification.
     */
    public function testSigningTheBooleanIsNotSigningAnEmptyValue()
    {
        $this->assertNotSame(
            $this->signature->getSignature(array('x_test' => false, 'x_reference' => 'ORDER-1')),
            hash_hmac('sha256', 'x_referenceORDER-1x_test', 'random_key_secret')
        );
    }
}
