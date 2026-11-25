<?php

declare(strict_types=1);

use Omnipay\Common\CreditCard;
use Omnipay\Razorpay\CheckoutGateway;
use Omnipay\Razorpay\Message\Signature;

/**
 * Shared helpers for the demo checkout pages. Not part of the library.
 */

function demo_autoload(): void
{
    $autoload = __DIR__ . '/../vendor/autoload.php';

    if (!is_file($autoload)) {
        http_response_code(500);
        echo '<!doctype html><meta charset="utf-8"><h1>Composer dependencies missing</h1>';
        echo '<p>Run <code>composer install</code> in the repository root, then reload.</p>';
        exit;
    }

    require $autoload;
}

function demo_key_id(): string
{
    return (string) (getenv('RAZORPAY_KEY_ID') ?: '');
}

function demo_key_secret(): string
{
    return (string) (getenv('RAZORPAY_KEY_SECRET') ?: '');
}

function demo_base_url(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8080';

    return 'http://' . $host;
}

/**
 * @return CheckoutGateway
 */
function demo_gateway()
{
    $factory = new \GuzzleHttp\Psr7\HttpFactory();
    $httpClient = new \Omnipay\Common\Http\PsrClient(new \GuzzleHttp\Client(), $factory, $factory);
    $httpRequest = \Symfony\Component\HttpFoundation\Request::createFromGlobals();

    $gateway = new CheckoutGateway($httpClient, $httpRequest);
    $gateway->setKeyID(demo_key_id());
    $gateway->setKeySecret(demo_key_secret());

    return $gateway;
}

function demo_store_path(): string
{
    return sys_get_temp_dir() . '/omnipay-demo-orders.json';
}

/**
 * @return array<string, array<string, string>>
 */
function demo_load_orders(): array
{
    $path = demo_store_path();

    if (!is_file($path)) {
        return array();
    }

    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : array();
}

function demo_save_order(array $order): void
{
    $orders = demo_load_orders();
    $orders[$order['reference']] = $order;

    file_put_contents(demo_store_path(), json_encode($orders, JSON_PRETTY_PRINT));
}

/**
 * @return array<string, string>|null
 */
function demo_find_order(string $reference)
{
    $orders = demo_load_orders();

    return $orders[$reference] ?? null;
}

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Builds the form data the browser would post to hosted checkout, using the
 * library's own PurchaseRequest.
 *
 * @return array<string, mixed>
 */
function demo_purchase_data(array $input): array
{
    $gateway = demo_gateway();

    $card = new CreditCard(array(
        'firstName'        => $input['first_name'],
        'lastName'         => $input['last_name'],
        'email'            => $input['email'],
        'billingAddress1'  => $input['address'],
        'billingCity'      => $input['city'],
        'billingState'     => $input['state'],
        'billingPostcode'  => $input['postcode'],
        'billingCountry'   => $input['country'],
    ));

    $request = $gateway->purchase(array(
        'amount'        => $input['amount'],
        'currency'      => $input['currency'],
        'transactionId' => $input['reference'],
        'description'   => $input['description'],
        'card'          => $card,
        'returnUrl'     => demo_base_url() . '/callback.php',
        'cancelUrl'     => demo_base_url() . '/callback.php',
    ));

    return $request->getData();
}

function demo_sign(array $payload): string
{
    $signer = new Signature(demo_key_secret());

    return $signer->getSignature($payload);
}

/**
 * Builds a callback payload for the selected scenario, signed the way hosted
 * checkout signs it. Scenarios marked "attacker" model what a shopper can do;
 * the rest model callbacks hosted checkout can genuinely send.
 *
 * @return array<string, string>
 */
function demo_scenario_payload(string $scenario, array $order): array
{
    $gatewayReference = 'order_DEMO0000000000|pay_DEMO0000000000';

    $pristine = array(
        'x_account_id'        => demo_key_id(),
        'x_amount'            => $order['amount'],
        'x_currency'          => $order['currency'],
        'x_gateway_reference' => $gatewayReference,
        'x_reference'         => $order['reference'],
        'x_result'            => 'completed',
        'x_test'              => 'false',
    );

    $signature = demo_sign($pristine);

    switch ($scenario) {
        case 'failed':
        case 'pending':
            $pristine['x_result'] = $scenario;

            return $pristine + array('x_signature' => demo_sign($pristine));

        case 'replay':
            // A success callback issued for a different, cheaper order.
            $pristine['x_reference'] = 'SOMEONE-ELSES-ORDER';
            $pristine['x_amount'] = '1.00';

            return $pristine + array('x_signature' => demo_sign($pristine));

        case 'resplit':
            // Same bytes, different fields: x_amount and x_currency are folded
            // into x_account_id and dropped. The signature still verifies.
            return array(
                'x_account_id'        => demo_key_id() . 'x_amount' . $pristine['x_amount'] . 'x_currency' . $pristine['x_currency'],
                'x_gateway_reference' => $gatewayReference,
                'x_reference'         => $pristine['x_reference'],
                'x_result'            => 'completed',
                'x_signature'         => $signature,
            );

        case 'bad-signature':
            $pristine['x_signature'] = str_repeat('0', 64);

            return $pristine;

        case 'completed':
        default:
            return $pristine + array('x_signature' => $signature);
    }
}
