<?php

namespace Omnipay\Razorpay\Message;

class Signature
{
    /**
     * @var string
     */
    private $key;

	public function __construct($key)
	{
		$this->key = $key;
	}

	public function getSignature(array $data)
    {
        $validFields = array_filter(array_keys($data), function ($key)
        {
            return $key != 'x_signature' and substr($key,0,2) == 'x_';
        });

        $data = array_intersect_key($data, array_flip($validFields));

        //sort the array
        ksort($data);

        // Match hosted checkout: it normalises x_test to the strings "true" /
        // "false" before signing. Signing the boolean false instead yields an
        // empty string, so the HMAC differs (key + "x_test" vs key + "x_testfalse")
        // and hosted rejects the form with "Invalid Signature".
        if (isset($data['x_test']))
        {
            $data['x_test'] = ($data['x_test'] === true || $data['x_test'] === 'true')
                ? 'true'
                : 'false';
        }

        // prepare the message
        $message = '';

        foreach ($data as $key => $value)
        {
            $message .= $key.$value;
        }

        return hash_hmac('sha256', $message, $this->key);
    }
}
