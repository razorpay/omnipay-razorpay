<?php

namespace Omnipay\Razorpay\Message;

class CompletePurchaseResponse extends PurchaseResponse
{
    public function isSuccessful()
    {
        if (!empty($_POST['x_result']))
        {
            $data = parent::getRedirectData();

            $HMAC_key = $data['key_secret'];
            $razorpay_signature = $_POST['x_signature'];

            $verifySignature = new Signature($HMAC_key);
            $signature = $verifySignature->getSignature($_POST);

            return hash_equals($signature, $razorpay_signature);
        }

        return false;
    }
}