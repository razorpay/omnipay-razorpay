<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

demo_autoload();

require __DIR__ . '/DemoCompletePurchaseResponse.php';

$posted = $_POST;
$reference = (string) ($posted['order_reference'] ?? $posted['x_reference'] ?? '');
$order = $reference === '' ? null : demo_find_order($reference);
$scenario = isset($posted['scenario']) ? (string) $posted['scenario'] : null;

if ($order !== null && $scenario !== null) {
    $posted = demo_scenario_payload($scenario, $order);
}

$_POST = $posted;

$trace = array();
$success = false;
$payment = null;
$error = null;

if ($order === null) {
    $error = 'No demo order found for reference "' . $reference . '". Start a purchase first.';
} elseif (demo_key_id() === '' || demo_key_secret() === '') {
    $error = 'Set RAZORPAY_KEY_ID and RAZORPAY_KEY_SECRET and restart the demo.';
} else {
    try {
        $gateway = demo_gateway();

        $request = $gateway->completePurchase(array(
            'transactionId' => $order['reference'],
            'amount'        => $order['amount'],
            'currency'      => $order['currency'],
        ));

        $response = new DemoCompletePurchaseResponse($request, array());

        $trace = $response->trace();
        $success = $response->isSuccessful();
        $payment = $response->rawPayment();
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

$paymentSummary = null;

if (is_object($payment)) {
    $paymentSummary = array();
    foreach (array('id', 'status', 'amount', 'currency', 'order_id', 'method') as $field) {
        if (isset($payment[$field])) {
            $paymentSummary[$field] = (string) $payment[$field];
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>omnipay-razorpay demo — callback</title>
<style>
    :root { color-scheme: light; }
    * { box-sizing: border-box; }
    body { margin: 0; padding: 32px 20px 64px; font: 15px/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #1b1f24; background: #f6f7f9; }
    .wrap { max-width: 860px; margin: 0 auto; }
    h1 { font-size: 22px; margin: 0 0 4px; }
    h2 { font-size: 16px; margin: 0 0 12px; }
    p.lede { margin: 0 0 24px; color: #57606a; }
    .card { background: #fff; border: 1px solid #d8dee4; border-radius: 10px; padding: 20px; margin-bottom: 20px; }
    .banner { border-radius: 8px; padding: 16px 18px; font-size: 15px; margin-bottom: 20px; }
    .banner.pass { background: #dafbe1; border: 1px solid #4ac26b; }
    .banner.fail { background: #ffebe9; border: 1px solid #ff8182; }
    .banner.error { background: #fff8c5; border: 1px solid #d4a72c; }
    .banner strong { display: block; font-size: 17px; margin-bottom: 2px; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    th, td { text-align: left; padding: 7px 8px; border-bottom: 1px solid #eaeef2; vertical-align: top; }
    th { color: #57606a; font-weight: 600; }
    td.state { white-space: nowrap; font-weight: 600; }
    .ok { color: #1a7f37; }
    .no { color: #cf222e; }
    code, pre { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12.5px; }
    pre { background: #f6f8fa; border: 1px solid #d8dee4; border-radius: 8px; padding: 12px; overflow: auto; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; word-break: break-all; }
    .actions { margin-top: 18px; }
    a.btn { display: inline-block; font-weight: 600; padding: 9px 16px; border-radius: 6px; border: 1px solid #1f6feb; background: #1f6feb; color: #fff; text-decoration: none; }
</style>
</head>
<body>
<div class="wrap">
    <h1>Callback result</h1>
    <p class="lede">What <code>CompletePurchaseResponse::isSuccessful()</code> returns for the payload below.</p>

    <?php if ($error !== null): ?>
        <div class="banner error"><strong>Not evaluated</strong><?= h($error) ?></div>
    <?php elseif ($success): ?>
        <div class="banner pass">
            <strong>PASS — order completed</strong>
            The callback matched the order and Razorpay reports the payment as captured for the right amount.
        </div>
    <?php else: ?>
        <div class="banner fail">
            <strong>REJECTED — order not completed</strong>
            At least one check below failed. A merchant app must leave the order unpaid.
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>Checks, in the order isSuccessful() runs them</h2>
        <table>
            <tr><th>Check</th><th>Result</th><th>Why</th></tr>
            <?php foreach ($trace as $step): ?>
                <tr>
                    <td><?= h($step[0]) ?></td>
                    <td class="state <?= $step[1] ? 'ok' : 'no' ?>"><?= $step[1] ? 'pass' : 'fail' ?></td>
                    <td><?= h($step[2]) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <div class="card">
        <h2>Posted payload</h2>
        <table>
            <tr><th>Field</th><th>Value</th></tr>
            <?php foreach ($_POST as $field => $value): ?>
                <tr>
                    <td><code><?= h($field) ?></code></td>
                    <td class="mono"><?= h(is_scalar($value) ? $value : json_encode($value)) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <?php if ($order !== null): ?>
        <div class="card">
            <h2>Order being completed</h2>
            <table>
                <tr><th>Reference</th><td class="mono"><?= h($order['reference']) ?></td></tr>
                <tr><th>Amount</th><td class="mono"><?= h($order['amount']) ?> <?= h($order['currency']) ?></td></tr>
            </table>
        </div>
    <?php endif; ?>

    <?php if (is_array($paymentSummary)): ?>
        <div class="card">
            <h2>Payment as Razorpay reports it</h2>
            <table>
                <?php foreach ($paymentSummary as $field => $value): ?>
                    <tr><th><?= h($field) ?></th><td class="mono"><?= h($value) ?></td></tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>

    <div class="actions">
        <a class="btn" href="index.php">Back to checkout</a>
    </div>
</div>
</body>
</html>
