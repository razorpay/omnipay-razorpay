<?php

namespace Omnipay\Razorpay\Message;

class CompletePurchaseRequest extends PurchaseRequest
{
    public function getData()
    {
        $data = parent::getData();

        return $data;
    }

    // To send the data for our
    public function sendData($data)
    {
        return $this->createResponse($data);
    }

    /**
     * Sending data to Response class
     */
    protected function createResponse($data)
    {
        return $this->response = new CompletePurchaseResponse($this, $data);
    }
}
