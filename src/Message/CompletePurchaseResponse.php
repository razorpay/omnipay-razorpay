<?php

namespace Omnipay\Razorpay\Message;

class CompletePurchaseResponse extends PurchaseResponse
{
    public function isSuccessful()
    {
        if (!empty($_POST['x_result']))
        {
            $hmacKey = $this->data['key_secret'];
            $razorpaySignature = $_POST['x_signature'];

            $verifySignature = new Signature($hmacKey);
            $signature = $verifySignature->getSignature($_POST);

            return hash_equals($signature, $razorpaySignature);
        }

        return false;
    }
}
