<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

demo_autoload();

$keyId = demo_key_id();
$keySecret = demo_key_secret();
$error = null;
$payload = null;
$order = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'purchase') {
    $input = array(
        'amount'      => trim((string) ($_POST['amount'] ?? '10.00')),
        'currency'    => strtoupper(trim((string) ($_POST['currency'] ?? 'INR'))),
        'reference'   => trim((string) ($_POST['reference'] ?? '')),
        'description' => trim((string) ($_POST['description'] ?? 'Demo order')),
        'first_name'  => 'Test',
        'last_name'   => 'Shopper',
        'email'       => 'test.shopper@example.com',
        'address'     => '1 Demo Street',
        'city'        => 'Bengaluru',
        'state'       => 'KA',
        'postcode'    => '560001',
        'country'     => 'IN',
    );

    if ($keyId === '' || $keySecret === '') {
        $error = 'Set RAZORPAY_KEY_ID and RAZORPAY_KEY_SECRET before starting a purchase.';
    } elseif ($input['reference'] === '') {
        $error = 'An order reference is required.';
    } elseif (!is_numeric($input['amount']) || (float) $input['amount'] <= 0) {
        $error = 'Amount must be a positive number.';
    } else {
        $payload = demo_purchase_data($input);

        $order = array(
            'reference' => $input['reference'],
            'amount'    => $input['amount'],
            'currency'  => $input['currency'],
        );

        demo_save_order($order);
    }
}

$scenarios = array(
    'completed'     => 'Hosted checkout says completed, but no such payment exists at Razorpay — expect a rejection from the API check.',
    'failed'        => 'A genuinely signed failure. Previously returned true; now rejected on x_result.',
    'pending'       => 'A genuinely signed pending result. Previously returned true; now rejected.',
    'replay'        => 'A signed success for a different, cheaper order replayed against this one — expect a rejection.',
    'resplit'       => 'Attacker: same signature, fields re-split (x_amount and x_currency dropped) — expect a rejection.',
    'bad-signature' => 'Attacker: tampered signature — expect a rejection.',
);

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>omnipay-razorpay demo</title>
<style>
    :root { color-scheme: light; }
    * { box-sizing: border-box; }
    body { margin: 0; padding: 32px 20px 64px; font: 15px/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #1b1f24; background: #f6f7f9; }
    .wrap { max-width: 860px; margin: 0 auto; }
    h1 { font-size: 22px; margin: 0 0 4px; }
    h2 { font-size: 16px; margin: 0 0 12px; }
    p.lede { margin: 0 0 24px; color: #57606a; }
    .card { background: #fff; border: 1px solid #d8dee4; border-radius: 10px; padding: 20px; margin-bottom: 20px; }
    .row { display: flex; gap: 12px; flex-wrap: wrap; }
    .row > label { flex: 1 1 180px; }
    label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 4px; }
    input, select { width: 100%; padding: 8px 10px; font: inherit; border: 1px solid #c8d1da; border-radius: 6px; background: #fff; }
    button { font: inherit; font-weight: 600; padding: 9px 16px; border-radius: 6px; border: 1px solid #1f6feb; background: #1f6feb; color: #fff; cursor: pointer; }
    button.secondary { background: #fff; color: #1f6feb; }
    button:disabled { opacity: .5; cursor: not-allowed; }
    .actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 16px; }
    .note { font-size: 13px; color: #57606a; margin: 10px 0 0; }
    .banner { border-radius: 8px; padding: 12px 14px; font-size: 14px; margin-bottom: 20px; }
    .banner.warn { background: #fff8c5; border: 1px solid #d4a72c; }
    .banner.error { background: #ffebe9; border: 1px solid #ff8182; }
    .banner.ok { background: #dafbe1; border: 1px solid #4ac26b; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #eaeef2; vertical-align: top; }
    th { color: #57606a; font-weight: 600; }
    code, pre { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12.5px; }
    pre { background: #f6f8fa; border: 1px solid #d8dee4; border-radius: 8px; padding: 12px; overflow: auto; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; word-break: break-all; }
</style>
</head>
<body>
<div class="wrap">
    <h1>omnipay-razorpay demo</h1>
    <p class="lede">Drives the library's real <code>PurchaseRequest</code> and <code>CompletePurchaseResponse</code> against Razorpay test mode, so you can watch the callback checks accept and reject.</p>

    <?php if ($keyId === '' || $keySecret === ''): ?>
        <div class="banner warn">
            <strong>No API keys.</strong> Set <code>RAZORPAY_KEY_ID</code> and <code>RAZORPAY_KEY_SECRET</code>
            (test mode) and restart. You can still open the simulator below.
        </div>
    <?php endif; ?>

    <?php if ($error !== null): ?>
        <div class="banner error"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="card">
        <h2>1. Start a purchase</h2>
        <form method="post">
            <input type="hidden" name="action" value="purchase">
            <div class="row">
                <label>Amount <input name="amount" value="10.00" inputmode="decimal"></label>
                <label>Currency <input name="currency" value="INR" maxlength="3"></label>
                <label>Order reference <input name="reference" value="ORDER-<?= h((string) random_int(1000, 9999)) ?>"></label>
            </div>
            <div class="row" style="margin-top:12px">
                <label>Description <input name="description" value="Demo order"></label>
            </div>
            <div class="actions">
                <button type="submit">Build checkout form</button>
            </div>
        </form>
        <p class="note">The order reference is what the callback must echo back, and what the payment is matched against.</p>
    </div>

    <?php if (is_array($payload)): ?>
        <div class="card">
            <h2>2. The signed form the driver produces</h2>
            <table>
                <tr><th>Field</th><th>Value</th></tr>
                <?php foreach ($payload as $field => $value): ?>
                    <tr><td><code><?= h($field) ?></code></td><td class="mono"><?= h($value) ?></td></tr>
                <?php endforeach; ?>
            </table>

            <div class="banner warn" style="margin:16px 0 0">
                <strong>Live hosted checkout cannot accept this form.</strong>
                Hosted validates the callback URL in <code>Shopify::isValidCallbackUrl()</code>: it must be
                <code>https</code>, a <code>*.myshopify.com</code> host, and exactly the path
                <code>/services/ping/notify_integration/razorpay_cards_upi_netbanking_wallets_/6183780442</code>.
                A merchant's own return URL can never satisfy that, so the endpoint only serves Shopify
                storefronts — the driver cannot complete a payment for anyone else. Use the simulator below,
                which runs the same <code>isSuccessful()</code> over the same payload shape.
            </div>
        </div>

        <div class="card">
            <h2>3. Simulate the callback</h2>
            <form method="post" action="callback.php">
                <label>Scenario
                    <select name="scenario">
                        <?php foreach ($scenarios as $name => $description): ?>
                            <option value="<?= h($name) ?>"><?= h($name) ?> — <?= h($description) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <input type="hidden" name="order_reference" value="<?= h($order['reference']) ?>">
                <div class="actions">
                    <button type="submit" class="secondary">Verify this callback</button>
                </div>
            </form>
            <p class="note">Every scenario is signed with the merchant key secret, because an attacker cannot sign. The last two show why a valid signature is no longer enough on its own.</p>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
