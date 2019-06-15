<?php

use Slim\App;
use Slim\Http\Request;
use Slim\Http\Response;

return function (App $app) {
    $currentMission = 'stp-2';
    $container = $app->getContainer();

    $inventoryCheck = new class($container, $currentMission) {
        private $container;
        private $currentMission;

        public function __construct($container, $currentMission)
        {
            $this->container = $container;
            $this->currentMission = $currentMission;
        }

        public function notEnoughTicketsForReservation($reservation)
        {
             $inventory = $this->container->get('redis')->hGetAll("{$this->currentMission}:inventory");
             return (
                 $reservation['upperQty'] > $inventory['upper'] ||
                 $reservation['standardQty'] > $inventory['standard'] ||
                 $reservation['privateQty'] > $inventory['private'] ||
                 $reservation['tourQty'] > $inventory['tour']
             );
        }
    };

    $app->get('/', function (Request $request, Response $response, array $args) use ($container, $currentMission) {
        if (time() < strtotime(getenv("TICKET_SALE_TIME"))) {
            $args['ticketTime'] = strtotime(getenv("TICKET_SALE_TIME"));
            return $container->get('renderer')->render($response, $currentMission . '-countdown.phtml', $args);
        }
        $args['totalInTopBar'] = true;
        if (isset($_GET['discountCode'])) {
            $discount = $container->get('redis')->hGetAll("$currentMission:discount:" . $_GET['discountCode']);
            if ($discount) {
                $args['discountCode'] = $_GET['discountCode'];
                $args['discount'] = $discount;
            }
        }
        $args['inventory'] = $container->get('redis')->hGetAll("$currentMission:inventory");
        $args['prices'] = $container->get('redis')->hGetAll("$currentMission:prices");
        return $container->get('renderer')->render($response, $currentMission . '.phtml', $args);
    });

    $app->get(getenv('EARLY_ACCESS_URL'), function (Request $request, Response $response, array $args) use ($container, $currentMission) {
        $args['totalInTopBar'] = true;
        if (isset($_GET['discountCode'])) {
            $discount = $container->get('redis')->hGetAll("$currentMission:discount:" . $_GET['discountCode']);
            if ($discount) {
                $args['discountCode'] = $_GET['discountCode'];
                $args['discount'] = $discount;
            }
        }
        $args['inventory'] = $container->get('redis')->hGetAll("$currentMission:inventory");
        $args['prices'] = $container->get('redis')->hGetAll("$currentMission:prices");
        return $container->get('renderer')->render($response, $currentMission . '.phtml', $args);
    });

    $app->post('/review', function (Request $request, Response $response, array $args) use ($container, $currentMission, $inventoryCheck) {
        $reservation = $_POST;
        if ($inventoryCheck->notEnoughTicketsForReservation($reservation)) {
            return $response->withRedirect('/?soldOut=true');
        }
        // TODO: Server-side validation... Someday.
        $prices = $container->get('redis')->hGetAll("$currentMission:prices");
        $reservation['upperPrice']         = $prices['upper'];
        $reservation['standardPrice']      = $prices['standard'];
        $reservation['privatePrice']       = $prices['private'];
        $reservation['tourPrice']          = $prices['tour'];
        $reservation['shirtPrice']         = $prices['shirt'];
        $reservation['cookiePrice']        = $prices['cookie'];
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

        $totalPrice -= $reservation['tourDiscountValue'] * $reservation['tourDiscountQty'];

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

    $app->get('/review', function (Request $request, Response $response, array $args) use ($container, $inventoryCheck) {
        if (!$container->session->exists('reservation')) {
            return $response->withRedirect('/');
        }
        if ($inventoryCheck->notEnoughTicketsForReservation($container->session->reservation)) {
            return $response->withRedirect('/?soldOut=true');
        }

        $args['reservation'] = $container->session->reservation;
        return $container->get('renderer')->render($response, 'review.phtml', $args);
    });

    $app->post('/complete', function (Request $request, Response $response, array $args) use ($container, $currentMission, $inventoryCheck) {
        if (!$container->session->exists('reservation')) {
            return $response->withRedirect('/');
        }
        $reservation = $container->session->reservation;
        if ($inventoryCheck->notEnoughTicketsForReservation($reservation)) {
            return $response->withRedirect('/?soldOut=true');
        }

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
            ],[
                'idempotency_key' => "create-customer-$reservationID",
            ]);

            $charge = \Stripe\Charge::create([
                'amount'               => $reservation['totalPaymentDue'] * 100,
                'currency'             => 'usd',
                'description'          => "Star Fleet Tours " . strtoupper($currentMission) . " Mission - $reservationID",
                'statement_descriptor' => "SFT " . strtoupper($currentMission) . " $reservationID",
                'customer'             => $customer->id,
            ],[
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

    $app->get('/admin/login', function (Request $request, Response $response, array $args) use ($container) {
        return $container->get('renderer')->render($response, 'admin/login.phtml', $args);
    });
    $app->post('/admin/login', function (Request $request, Response $response, array $args) use ($container) {
        if (!password_verify($_POST['password'], getenv('ADMIN_PASS'))) return $response->withRedirect('/admin/login');
        $container->get('session')->admin = true;
        return $response->withRedirect('/admin');
    });
    $app->get('/admin/logout', function (Request $request, Response $response, array $args) use ($container) {
        $container->session->delete('admin');
        return $response->withRedirect('/admin/login');
    });
    $app->get('/admin', function (Request $request, Response $response, array $args) use ($container) {
        if (!$container->get('session')->exists('admin')) return $response->withRedirect('/admin/login');
        return $container->get('renderer')->render($response, 'admin/index.phtml', $args);
    });
    $app->get('/admin/discounts', function (Request $request, Response $response, array $args) use ($container, $currentMission) {
        if (!$container->get('session')->exists('admin')) return $response->withRedirect('/admin/login');
        $args['discounts'] = [];
        foreach ($container->get('redis')->keys("$currentMission:discount:*") as $discountKey) {
            $args['discounts'][str_replace("$currentMission:discount:", "", $discountKey)] = $container->get('redis')->hGetAll($discountKey);
        }

        return $container->get('renderer')->render($response, 'admin/discounts.phtml', $args);
    });
    $app->post('/admin/discounts', function (Request $request, Response $response, array $args) use ($container, $currentMission) {
        if (!$container->get('session')->exists('admin')) return $response->withRedirect('/admin/login');
        $container->get('redis')->hset("$currentMission:discount:{$_POST['discount-code']}", 'amount', $_POST['discount-amount']);
        return $response->withRedirect('/admin/discounts');
    });
    $app->get('/admin/discounts/delete/{id}', function (Request $request, Response $response, array $args) use ($container, $currentMission) {
        if (!$container->get('session')->exists('admin')) return $response->withRedirect('/admin/login');
        $container->get('redis')->del("$currentMission:discount:{$args['id']}");
        return $response->withRedirect('/admin/discounts');
    });
    $app->get('/admin/checkin/{id}', function (Request $request, Response $response, array $args) use ($container, $currentMission) {
        if (!$container->get('session')->exists('admin')) return $response->withRedirect('/admin/login');
        $args['reservation'] = $container->get('redis')->hGetAll("$currentMission:reservation:{$args['id']}");
        return $container->get('renderer')->render($response, 'admin/checkin.phtml', $args);
    });
};
