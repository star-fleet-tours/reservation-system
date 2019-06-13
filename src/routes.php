<?php

use Slim\App;
use Slim\Http\Request;
use Slim\Http\Response;

return function (App $app) {
    $currentMission = 'stp-2';
    $container = $app->getContainer();

    $app->get('/', function (Request $request, Response $response, array $args) use ($container, $currentMission) {
        if (isset($_GET['discountCode'])) {
            $discount = $container->get('redis')->hGetAll("$currentMission:discount:" . $_GET['discountCode']);
            if ($discount) {
                $args['discountCode'] = $_GET['discountCode'];
                $args['discount'] = $discount;
            }
        }
        $args['inventory'] = $container->get('redis')->hGetAll("$currentMission:inventory");
        return $container->get('renderer')->render($response, $currentMission . '.phtml', $args);
    });

    $app->post('/review', function (Request $request, Response $response, array $args) use ($container, $currentMission) {
        // TODO: Server-side validation... Someday.
        $reservation = $_POST;

        $reservation['upperPrice']         = 75;
        $reservation['standardPrice']      = 65;
        $reservation['privatePrice']       = 95;
        $reservation['tourPrice']          = 80;
        $reservation['shirtPrice']         = 20;
        $reservation['cookiePrice']        = 2;
        $reservation['tourDiscountValue']  = 10;

        $totalPrice = 0;
        $totalPrice += $reservation['upperPrice']    * $reservation['upperQty'];
        $totalPrice += $reservation['standardPrice'] * $reservation['standardQty'];
        $totalPrice += $reservation['privatePrice']  * $reservation['privateQty'];
        $totalPrice += $reservation['tourPrice']     * $reservation['tourQty'];
        $totalPrice += $reservation['shirtPrice']    * $reservation['shirtQtySm'];
        $totalPrice += $reservation['shirtPrice']    * $reservation['shirtQtyMd'];
        $totalPrice += $reservation['shirtPrice']    * $reservation['shirtQtyLg'];
        $totalPrice += $reservation['shirtPrice']    * $reservation['shirtQtyXl'];
        $totalPrice += $reservation['shirtPrice']    * $reservation['shirtQty2xl'];
        $totalPrice += $reservation['shirtPrice']    * $reservation['shirtQty3xl'];
        $totalPrice += $reservation['cookiePrice']   * $reservation['cookieQty'];

        $reservation['subTotal'] = $totalPrice;

        $reservation['launchQty']         = $reservation['upperQty'] + $reservation['standardQty'] + $reservation['privateQty'];
        $reservation['tourDiscountQty']   = (int) min($reservation['launchQty'], $reservation['tourQty']);
        $reservation['tourDiscountAmount'] = $reservation['tourDiscountValue'] * $reservation['tourDiscountQty'];

        $totalPrice -= $reservation['tourDiscountAmount'];

        if (isset($reservation['discountCode'])) {
            $discount = $container->get('redis')->hGetAll("$currentMission:discount:" . $reservation['discountCode']);
            if ($discount) {
                $reservation['discountCodeAmount'] = (float) $discount['amount'];
                $totalPrice -= $discount['amount'];
            }
        }

        $totalPrice = $totalPrice < 0 ? 0 : $totalPrice;

        $reservation['skipStripe'] = ($totalPrice == 0 && $reservation['donation'] == 0);

        $totalPrice += $reservation['donation'];

        $reservation['totalPaymentDue'] = $totalPrice;

        $this->session->reservation = $reservation;
        return $response->withRedirect('/review');
    });

    $app->get('/review', function (Request $request, Response $response, array $args) use ($container) {
        if (!$container->session->exists('reservation')) {
            return $response->withRedirect('/');
        }

        $args['reservation'] = $container->session->reservation;
        return $container->get('renderer')->render($response, 'review.phtml', $args);
    });

    $app->post('/complete', function (Request $request, Response $response, array $args) use ($container, $currentMission) {
        if (!$container->session->exists('reservation')) {
            return $response->withRedirect('/');
        }
        $reservation = $container->session->reservation;

        $i = 1;
        do {
            $reservationNumber = count($container->get('redis')->zRevRangeByScore("$currentMission:reservations", '+inf', '-inf')) + 1000 + $i++;
            $reservationID     = $container->get('hashids')->encode($reservationNumber);
            $idCreated         = $container->get('redis')->hSetNx("$currentMission:reservation-ids", $reservationID, $reservationNumber);
        } while(!$idCreated);

        if (!$reservation['skipStripe']) {
            \Stripe\Stripe::setApiKey(getenv('STRIPE_PRIVATE_KEY'));
            $customer = \Stripe\Customer::create([
                'source'          => $_POST['stripeToken'],
                'email'           => $reservation['reservationEmail'],
                'idempotency_key' => "create-customer-$reservationID",
            ]);

            $charge = \Stripe\Charge::create([
                'amount'               => $reservation['totalPaymentDue'] * 100,
                'currency'             => 'usd',
                'description'          => "Star Fleet Tours " . strtoupper($currentMission) . " Mission - $reservationID",
                'statement_descriptor' => "SFT " . strtoupper($currentMission) . " $reservationID",
                'customer'             => $customer->id,
                'idempotency_key'      => "create-charge-$reservationID",
            ]);

            $reservation['stripeCustomerID'] = $customer->id;
        }

        $container->get('redis')->hMSet("$currentMission:reservation:$reservationID", $reservation);
        $container->get('redis')->zAdd("$currentMission:reservations", microtime(true), "$currentMission:reservation:$reservationID");


        // Update inventory.
        $container->get('redis')->hIncrBy("$currentMission:inventory", 'upper', $reservation['upperQty'] * -1);
        $container->get('redis')->hIncrBy("$currentMission:inventory", 'standard', $reservation['standardQty'] * -1);
        $container->get('redis')->hIncrBy("$currentMission:inventory", 'private', $reservation['privateQty'] * -1);
        $container->get('redis')->hIncrBy("$currentMission:inventory", 'tour', $reservation['tourQty'] * -1);

        $container->session->delete('reservation');

        return $response->withRedirect('/reservation/'.$reservationID);
    });

    $app->get('/reservation/{id}', function (Request $request, Response $response, array $args) use ($container) {
        return $container->get('renderer')->render($response, 'reservation.phtml', $args);
    });
};
