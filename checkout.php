<?php
require __DIR__ . '/config.php';

function finish(string $title, string $message): never {
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title><body style="font:16px Arial;max-width:800px;margin:40px auto">';
    echo '<h1>' . htmlspecialchars($title) . '</h1><p>' . htmlspecialchars($message) . '</p><p><a href="index.php">Store</a> | <a href="cart.php">Cart</a> | <a href="orders.php">Orders</a></p></body></html>';
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: cart.php'); exit; }

$customer = trim((string)($_POST['customer_name'] ?? ''));
$method = (string)($_POST['payment_method'] ?? 'CARD');
if ($customer === '') finish('Missing information', 'Enter a customer name.');
if (!in_array($method, ['CARD','EWALLET','BANK'], true)) $method = 'CARD';

$cart = $_SESSION['cart'] ?? [];
if (!$cart) finish('Empty cart', 'Add an item before checkout.');

$pdo = cart_db();
$ids = array_map('intval', array_keys($cart));
$marks = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare("SELECT id,name,price,stock FROM products WHERE id IN ($marks)");
$stmt->execute($ids);
$products = $stmt->fetchAll();
$total = 0.0; $validItems = [];
foreach ($products as $p) {
    $qty = min((int)$cart[$p['id']], (int)$p['stock']);
    if ($qty > 0) {
        $total += $qty * (float)$p['price'];
        $validItems[] = ['product'=>$p,'qty'=>$qty];
    }
}
if (!$validItems || $total <= 0) finish('Checkout error', 'No valid items were found.');

/* Persist order before calling the independent payment service. */
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("INSERT INTO orders (customer_name,total_amount,status) VALUES (?,?,'PENDING_PAYMENT')");
    $stmt->execute([$customer, number_format($total,2,'.','')]);
    $orderId = (int)$pdo->lastInsertId();
    $itemStmt = $pdo->prepare('INSERT INTO order_items (order_id,product_id,product_name,quantity,unit_price) VALUES (?,?,?,?,?)');
    foreach ($validItems as $i) {
        $itemStmt->execute([$orderId,$i['product']['id'],$i['product']['name'],$i['qty'],$i['product']['price']]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    finish('Order error', 'Could not save the order.');
}

/*
 * STUDENT TASK: Integrate the shopping cart with the
 * independent payment gateway.
 *
 * Requirements:
 * 1. Prepare the payment request payload.
 * 2. Send an HTTP POST request with JSON using PHP cURL.
 * 3. Handle the gateway's HTTP response.
 * 4. Validate the response and match the order ID.
 * 5. Handle SUCCESS, FAILED, and PENDING outcomes.
 * 6. Handle timeouts and invalid responses safely.
 * 7. Save the gateway transaction ID and update the
 *    order status in the shopping cart database.
 * 8. Prevent duplicate payment requests using an
 *    idempotency key.
 *
 * Read payment-gateway/README-API.md for the API contract.
 */

// TODO: Implement the system integration here.

/*
 * Payment Gateway Integration
 */

$apiUrl = 'http://localhost/payment-gateway/api/payments.php';

/*
 * Testing only:
 * Keep null in the final submission.
 * Temporarily use SUCCESS, FAILED, or PENDING
 * to test the gateway's documented simulator.
 */
$testOutcome = null;

$idempotencyKey = 'cart-order-' . $orderId;

$payload = [
    'order_id' => $orderId,
    'amount' => round($total, 2),
    'customer_name' => $customer,
    'payment_method' => $method,
    'idempotency_key' => $idempotencyKey
];

/* Include the simulator option only during testing. */
if (in_array($testOutcome, ['SUCCESS', 'FAILED', 'PENDING'], true)) {
    $payload['outcome'] = $testOutcome;
}

/* Convert the request payload to JSON. */
$jsonPayload = json_encode($payload);

if ($jsonPayload === false) {
    $stmt = $pdo->prepare(
        "UPDATE orders SET status = 'PAYMENT_PENDING' WHERE id = ?"
    );
    $stmt->execute([$orderId]);

    unset($_SESSION['cart']);

    finish(
        'Payment status not confirmed',
        'The payment request could not be prepared. Check Order History before trying again.'
    );
}

/* Initialize cURL. */
$ch = curl_init();

if ($ch === false) {
    $stmt = $pdo->prepare(
        "UPDATE orders SET status = 'PAYMENT_PENDING' WHERE id = ?"
    );
    $stmt->execute([$orderId]);

    unset($_SESSION['cart']);

    finish(
        'Payment status not confirmed',
        'The payment service could not be contacted. Check Order History before retrying.'
    );
}

/* Configure the HTTP POST request. */
curl_setopt_array($ch, [
    CURLOPT_URL => $apiUrl,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $jsonPayload,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json'
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 15
]);

/* Send the request and capture the response. */
$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

/* A communication error does not prove payment failure. */
if ($response === false || $curlError !== '') {
    $stmt = $pdo->prepare(
        "UPDATE orders SET status = 'PAYMENT_PENDING' WHERE id = ?"
    );
    $stmt->execute([$orderId]);

    unset($_SESSION['cart']);

    finish(
        'Payment status not confirmed',
        'We could not confirm the payment response. Check Order History before attempting payment again.'
    );
}

/* Decode and validate the JSON response. */
$data = json_decode($response, true);

$validResponse =
    $httpCode >= 200 &&
    $httpCode < 300 &&
    is_array($data) &&
    isset($data['success']) &&
    $data['success'] === true &&
    isset($data['order_id']) &&
    is_numeric($data['order_id']) &&
    (int) $data['order_id'] === $orderId &&
    isset($data['amount']) &&
    is_numeric($data['amount']) &&
    abs((float) $data['amount'] - $total) < 0.01 &&
    isset($data['status']) &&
    is_string($data['status']) &&
    in_array(
        strtoupper($data['status']),
        ['SUCCESS', 'FAILED', 'PENDING'],
        true
    );

if (!$validResponse) {
    $stmt = $pdo->prepare(
        "UPDATE orders SET status = 'PAYMENT_PENDING' WHERE id = ?"
    );
    $stmt->execute([$orderId]);

    unset($_SESSION['cart']);

    finish(
        'Payment status not confirmed',
        'The gateway response could not be verified. Check Order History before retrying.'
    );
}

/* Determine the order status from the gateway response. */
$gatewayStatus = strtoupper($data['status']);

$statusMap = [
    'SUCCESS' => 'PAID',
    'FAILED' => 'PAYMENT_FAILED',
    'PENDING' => 'PAYMENT_PENDING'
];

$orderStatus = $statusMap[$gatewayStatus];

/* The transaction ID may be absent in some responses. */
$transactionId = null;

if (isset($data['transaction_id']) && is_string($data['transaction_id'])) {
    $candidate = trim($data['transaction_id']);

    if ($candidate !== '' && strlen($candidate) <= 80) {
        $transactionId = $candidate;
    }
}

/* Save the payment result in the shopping cart database. */
try {
    $stmt = $pdo->prepare(
        'UPDATE orders
         SET status = ?,
             gateway_transaction_id =
                 COALESCE(?, gateway_transaction_id)
         WHERE id = ?'
    );

    $stmt->execute([
        $orderStatus,
        $transactionId,
        $orderId
    ]);

    /* Verify that the order exists. */
    $check = $pdo->prepare(
        'SELECT id FROM orders WHERE id = ?'
    );
    $check->execute([$orderId]);

    if (!$check->fetch()) {
        unset($_SESSION['cart']);

        finish(
            'Order update error',
            'The payment response was received, but the order could not be verified. Contact the system operator.'
        );
    }
} catch (Throwable $e) {
    unset($_SESSION['cart']);

    finish(
        'Order update error',
        'The payment response was received, but the order status could not be saved. Check Order History before retrying.'
    );
}

/* Do not leave the submitted items in the cart. */
unset($_SESSION['cart']);

/* Display the correct payment result. */
switch ($gatewayStatus) {
    case 'SUCCESS':
        finish(
            'Payment successful',
            'Payment confirmed. Order #' . $orderId .
            ' is marked PAID.' .
            ($transactionId !== null
                ? ' Transaction ID: ' . $transactionId
                : '')
        );

    case 'FAILED':
        finish(
            'Payment failed',
            'The gateway confirmed that payment failed. Order #' .
            $orderId . ' is marked PAYMENT_FAILED.'
        );

    case 'PENDING':
        finish(
            'Payment pending',
            'Order #' . $orderId .
            ' is awaiting confirmation. Check Order History before trying again.'
        );
}
