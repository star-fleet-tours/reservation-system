<?php

use Slim\App;
use Slim\Http\Request;
use Slim\Http\Response;
use PHPMailer\PHPMailer\PHPMailer;

return function (App $app) {
    $currentMission = getenv('CURRENT_MISSION');
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

    $sendConfirmationEmail = function($reservationID, $reservation) {
        $mail = new PHPMailer();
        $mail->setFrom('fleetcommand@star-fleet.tours', 'Star-Fleet.tours');
        $mail->addAddress($reservation['reservationEmail'], $reservation['reservationName']);
        $mail->addReplyTo('fleetcommand@star-fleet.tours', 'Star-Fleet.tours');

        $mail->isHTML(true);
        $mail->CharSet  = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->Subject  = 'Star-Fleet.tours Confirmation: ' . $reservationID;
        $mail->Body     = <<<email
<p>Thanks for your reservation to join Star-Fleet Tours for the NASA SpaceX Crew-3 mission. We look forward to seeing you there! We will scan the QR code on the confirmation page below (printed or on your phone) to check you in on launch day, so you don't need to do anything with it now. If you are unable to pull up the reservation page on your phone and don't have it printed, don't worry. We can also check you in with your confirmation code: <strong>$reservationID</strong>.</p>

<p><strong>Your reservation confirmation:</strong><br><a href="https://book.star-fleet.tours/reservation/$reservationID">https://book.star-fleet.tours/reservation/$reservationID</a></p>

<p>Please carefully review the information on our <a href="https://www.star-fleet.tours/current/" target="_blank">tickets</a> page for the key details on where and when to meet, what to expect and other important announcements. In particular, if you've gone out with us before, note the **NEW** meeting location near Orlando Princess, between Rusty's and Gator's, instead of at Sunrise Marina next to Grills. We'll post status updates and any changes to the launch date and our own plans there, as well as on our <a href="https://twitter.com/StarFleetTours" target="_blank">Twitter feed</a>, <a href="https://www.facebook.com/starfleettours/" target="_blank">Facebook page</a>, and <a href="https://join.slack.com/t/spacexmeetups/shared_invite/enQtMzE0MjY1MTY0Mzc1LTFlMGE4MjY1ZTI4ZjZlNWQ4ZWQzZjEwMGFhNDU3NGRhZjBmNThhNTMwNzc0OWZhZGZhNzQ0YjJjNTY1Y2Q2ZWY" target="_blank">Slack group</a>. As the launch approaches, please check those frequently to stay up to date.</p>

<p>If you have questions, concerns, or would otherwise like to get in touch with us, you can reach us on the aforementioned Slack or our <a href="https://gitter.im/star-fleet-tours/public" target="_blank">Gitter</a>, and we can also be reached by email at <a href="mailto:fleetcommand@star-fleet.tours" target="_blank">FleetCommand@Star-Fleet.Tours</a> (or simply respond to this email). For urgent issues or if you can't get a hold of us, you can call or text C.A.M. Gerlach at 571-488-5878, and Steven Giraldo at 941-879-4240 .</p>

<p>Thanks, and we can't wait for you to join us for the launch!</p>
email;
        $mail->send();
    };

    $app->get('/', function (Request $request, Response $response, array $args) use ($container, $currentMission) {
        if (time() < strtotime(getenv("TICKET_SALE_TIME"))) {
            $args['ticketTime'] = strtotime(getenv("TICKET_SALE_TIME"));
            return $container->get('renderer')->render($response, $currentMission . '-countdown.phtml', $args);
        }
        if (time() < strtotime(getenv("ALT_PUBLIC_SALE_TIME")) && !isset($_GET['update'])) {
            $args['ticketTime'] = strtotime(getenv("ALT_PUBLIC_SALE_TIME"));
            return $container->get('renderer')->render($response, $currentMission . '-sold-out.phtml', $args);
        }
        $args['totalInTopBar'] = true;
        if (isset($_GET['discountCode'])) {
            $discount = $container->get('redis')->hGetAll("$currentMission:discount:" . $_GET['discountCode']);
            if ($discount) {
                $args['discountCode'] = $_GET['discountCode'];
                $args['discount'] = $discount;
            }
        }
        if (isset($_GET['update'])) {
            $args['id'] = $_GET['update'];
            $args['reservation'] = $container->get('redis')->hGetAll("$currentMission:reservation:{$args['id']}");
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
        $prices = $container->get('redis')->hGetAll("$currentMission:prices");
        $reservation['upperPrice']         = $prices['upper'];
        $reservation['standardPrice']      = $prices['standard'];
        $reservation['privatePrice']       = $prices['private'];
        $reservation['tourPrice']          = $prices['tour'];
        $reservation['shirtPrice']         = $prices['shirt'];
        $reservation['hatPrice']           = $prices['hat'];
        $reservation['cookiePrice']        = $prices['cookie'];
        $reservation['stickerPrice']       = $prices['sticker'];
        $reservation['tourDiscountValue']  = 10;

        if (isset($_POST['updateReservation'])) {

            //$reservation['retry1Price'] = $prices['retry1'];

            $totalPrice = 0;
            //$totalPrice += $reservation['retry1Price']   * $reservation['retry1Qty'];
            $totalPrice += $reservation['shirtPrice']    * $reservation['shirtQtySm'];
            $totalPrice += $reservation['shirtPrice']    * $reservation['shirtQtyMd'];
            $totalPrice += $reservation['shirtPrice']    * $reservation['shirtQtyLg'];
            $totalPrice += $reservation['shirtPrice']    * $reservation['shirtQtyXl'];
            $totalPrice += $reservation['shirtPrice']    * $reservation['shirtQty2xl'];
            $totalPrice += $reservation['shirtPrice']    * $reservation['shirtQty3xl'];
            $totalPrice += $reservation['hatPrice']      * $reservation['hatQty'];
            $totalPrice += $reservation['cookiePrice']   * $reservation['cookieQty'];
            $totalPrice += $reservation['stickerPrice']  * $reservation['stickerQty'];

            $reservation['upperQty'] = $reservation['standardQty'] = $reservation['privateQty'] = $reservation['tourQty'] = $reservation['tourDiscountQty'] = 0;
            $reservation['subTotal'] = $totalPrice;
            if ($totalPrice == 0) {
                return $response->withRedirect($_SERVER['HTTP_REFERER']);
            }

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
            $container->get('session')->reservation = $reservation;
            return $response->withRedirect('/review');
        }


        if ($inventoryCheck->notEnoughTicketsForReservation($reservation)) {
            return $response->withRedirect('/?soldOut=true');
        }
        // TODO: Server-side validation... Someday.
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
        $totalPrice += $reservation['hatPrice']      * $reservation['hatQty'];
        $totalPrice += $reservation['cookiePrice']   * $reservation['cookieQty'];
        $totalPrice += $reservation['stickerPrice']  * $reservation['stickerQty'];

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

        $container->get('session')->reservation = $reservation;
        return $response->withRedirect('/review');
    });

    $app->get('/review', function (Request $request, Response $response, array $args) use ($container, $inventoryCheck) {
        error_reporting(E_ALL & ~E_NOTICE);

        if (!$container->get('session')->exists('reservation')) {
            return $response->withRedirect('/');
        }
        if ($inventoryCheck->notEnoughTicketsForReservation($container->get('session')->reservation)) {
            return $response->withRedirect('/?soldOut=true');
        }

        $args['reservation'] = $container->get('session')->reservation;
        return $container->get('renderer')->render($response, 'review.phtml', $args);
    });

    $app->post('/complete', function (Request $request, Response $response, array $args) use ($container, $currentMission, $inventoryCheck, $sendConfirmationEmail) {
        \Stripe\Stripe::setApiKey(getenv('STRIPE_PRIVATE_KEY'));

        if (!$container->get('session')->exists('reservation')) {
            return $response->withRedirect('/');
        }
        $reservation = $container->get('session')->reservation;

        if (isset($reservation['updateReservation'])) {
            $reservationID = $reservation['updateReservation'];
            $originalReservation = $container->get('redis')->hGetAll("$currentMission:reservation:{$reservation['updateReservation']}");
            //$originalReservation['retry1Qty']       += $reservation['retry1Qty'];
            $originalReservation['shirtQtySm']      += $reservation['shirtQtySm'];
            $originalReservation['shirtQtyMd']      += $reservation['shirtQtyMd'];
            $originalReservation['shirtQtyLg']      += $reservation['shirtQtyLg'];
            $originalReservation['shirtQtyXl']      += $reservation['shirtQtyXl'];
            $originalReservation['shirtQty2xl']     += $reservation['shirtQty2xl'];
            $originalReservation['shirtQty3xl']     += $reservation['shirtQty3xl'];
            $originalReservation['stickerQty']      += $reservation['stickerQty'];
            $originalReservation['hatQty']          += $reservation['hatQty'];
            $originalReservation['cookieQty']       += $reservation['cookieQty'];
            $originalReservation['donation']        += $reservation['donation'];
            $originalReservation['subTotal']        += $reservation['subTotal'];
            $originalReservation['totalPaymentDue'] += $reservation['totalPaymentDue'];

            if (!$reservation['skipStripe']) {
                try {
                    $chargeParams = [
                        'amount'               => $reservation['totalPaymentDue'] * 100,
                        'currency'             => 'usd',
                        'description'          => "Star Fleet Tours " . strtoupper($currentMission) . " - $reservationID",
                        'statement_descriptor' => strtoupper($currentMission) . " Swag",
                    ];
                    if (isset($originalReservation['stripeCustomerID'])) {
                        $card = \Stripe\Customer::createSource(
                            $originalReservation['stripeCustomerID'],
                            [
                                'source' => $_POST['stripeToken'],
                            ]
                        );
                        $chargeParams['customer'] = $originalReservation['stripeCustomerID'];
                        $chargeParams['source'] = $card->id;
                    } else {
                        $customer = \Stripe\Customer::create([
                            'source'          => $_POST['stripeToken'],
                            'email'           => $originalReservation['reservationEmail'],
                        ]);
                        $chargeParams['customer'] = $customer->id;
                    }
                    $charge = \Stripe\Charge::create($chargeParams);

                } catch(\Exception $e) {
                    return $response->withRedirect('/?paymentError=true');
                }
            }

            //$container->get('redis')->hSet("$currentMission:reservation:$reservationID", 'retry1Price', $reservation['retry1Price']);
            //$container->get('redis')->hSet("$currentMission:reservation:$reservationID", 'retry1Qty', $originalReservation['retry1Qty']);
            $container->get('redis')->hSet("$currentMission:reservation:$reservationID", 'shirtQtySm', $originalReservation['shirtQtySm']);
            $container->get('redis')->hSet("$currentMission:reservation:$reservationID", 'shirtQtyMd', $originalReservation['shirtQtyMd']);
            $container->get('redis')->hSet("$currentMission:reservation:$reservationID", 'shirtQtyLg', $originalReservation['shirtQtyLg']);
            $container->get('redis')->hSet("$currentMission:reservation:$reservationID", 'shirtQtyXl', $originalReservation['shirtQtyXl']);
            $container->get('redis')->hSet("$currentMission:reservation:$reservationID", 'shirtQty2xl', $originalReservation['shirtQty2xl']);
            $container->get('redis')->hSet("$currentMission:reservation:$reservationID", 'shirtQty3xl', $originalReservation['shirtQty3xl']);
            $container->get('redis')->hSet("$currentMission:reservation:$reservationID", 'cookieQty', $originalReservation['cookieQty']);
            $container->get('redis')->hSet("$currentMission:reservation:$reservationID", 'stickerQty', $originalReservation['stickerQty']);
            $container->get('redis')->hSet("$currentMission:reservation:$reservationID", 'donation', $originalReservation['donation']);
            $container->get('redis')->hSet("$currentMission:reservation:$reservationID", 'subTotal', $originalReservation['subTotal']);
            $container->get('redis')->hSet("$currentMission:reservation:$reservationID", 'totalPaymentDue', $originalReservation['totalPaymentDue']);
            $container->get('session')->delete('reservation');
            return $response->withRedirect('/reservation/'.$reservationID);
        }

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
            try {
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
                    'statement_descriptor' => strtoupper($currentMission) . " $reservationID",
                    'customer'             => $customer->id,
                ],[
                    'idempotency_key'      => "create-charge-$reservationID",
                ]);
            } catch(\Exception $e) {
                return $response->withRedirect('/?paymentError=true');
            }

            $reservation['stripeCustomerID'] = $customer->id;
        }

        $container->get('redis')->hMSet("$currentMission:reservation:$reservationID", $reservation);
        $container->get('redis')->zAdd("$currentMission:reservations", microtime(true), "$currentMission:reservation:$reservationID");


        // Update inventory.
        $container->get('redis')->hIncrBy("$currentMission:inventory", 'upper', $reservation['upperQty'] * -1);
        $container->get('redis')->hIncrBy("$currentMission:inventory", 'standard', $reservation['standardQty'] * -1);
        $container->get('redis')->hIncrBy("$currentMission:inventory", 'private', $reservation['privateQty'] * -1);
        $container->get('redis')->hIncrBy("$currentMission:inventory", 'tour', $reservation['tourQty'] * -1);

        $sendConfirmationEmail($reservationID, $reservation);

        $container->get('session')->delete('reservation');

        return $response->withRedirect('/reservation/'.$reservationID);
    });

    $app->get('/reservation/{id}', function (Request $request, Response $response, array $args) use ($container, $currentMission) {
        error_reporting(E_ALL & ~E_NOTICE);
        $args['reservation'] = $container->get('redis')->hGetAll("$currentMission:reservation:{$args['id']}");
        if (!$args['reservation']) {
            return $container->get('renderer')->render($response, 'reservation-not-found.phtml', $args);
        }
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
        $container->get('session')->delete('admin');
        return $response->withRedirect('/admin/login');
    });
    $app->get('/admin', function (Request $request, Response $response, array $args) use ($container, $currentMission) {
        if (!$container->get('session')->exists('admin')) return $response->withRedirect('/admin/login');
        error_reporting(E_ALL & ~E_NOTICE);
        $args['inventory'] = $container->get('redis')->hgetall("$currentMission:inventory");
        $args['reservations'] = [];
        $reservationKeys = $container->get('redis')->zRangeByScore("$currentMission:reservations", '-inf', '+inf');
        foreach ($reservationKeys as $reservationKey) {
            $args['reservations'][str_replace("$currentMission:reservation:", "", $reservationKey)] = $container->get('redis')->hGetAll($reservationKey);
        }
        return $container->get('renderer')->render($response, 'admin/index.phtml', $args);
    });
    $app->get('/admin/tours-csv', function (Request $request, Response $response, array $args) use ($container, $currentMission) {
        if (!$container->get('session')->exists('admin')) return $response->withRedirect('/admin/login');
        $reservationKeys = $container->get('redis')->zRangeByScore("$currentMission:reservations", '-inf', '+inf');
        header('Content-Type:text/plain');
        echo "conf code,party size,8:30am,12:30pm\n";
        foreach ($reservationKeys as $reservationKey) {
            if (isset($reservation['cancelled'])) continue;
            $reservation = $container->get('redis')->hGetAll($reservationKey);
            $confCode = str_replace("$currentMission:reservation:", "", $reservationKey);
            if ($reservation['tourQty'] == 0) continue;
            echo "$confCode,{$reservation['tourQty']},{$reservation['tourPref1']},{$reservation['tourPref2']}\n";
        }
        die();
    });
    $app->get('/admin/tours', function (Request $request, Response $response, array $args) use ($container, $currentMission) {
        if (!$container->get('session')->exists('admin')) return $response->withRedirect('/admin/login');
        $args['reservations'] = [];
        $reservationKeys = $container->get('redis')->zRangeByScore("$currentMission:reservations", '-inf', '+inf');
        $tours = [
            [
                'first' => 0,
                'second' => 0,
                'none' => 0,
                'only' => 0,
            ],
            [
                'first' => 0,
                'second' => 0,
                'none' => 0,
                'only' => 0,
            ],
            [
                'first' => 0,
                'second' => 0,
                'none' => 0,
                'only' => 0,
            ],
        ];
        foreach ($reservationKeys as $reservationKey) {
            $reservation = $container->get('redis')->hGetAll($reservationKey);
            if (isset($reservation['cancelled'])) continue;
            if ($reservation['tourQty'] == 0) continue;
            $tours[0][$reservation['tourPref1']] += $reservation['tourQty'];
            $tours[1][$reservation['tourPref2']] += $reservation['tourQty'];
            if ($reservation['tourPref2'] == 'none') $tours[0]['only'] += $reservation['tourQty'];
            if ($reservation['tourPref1'] == 'none') $tours[1]['only'] += $reservation['tourQty'];
        }
        $args['tours'] = $tours;
        return $container->get('renderer')->render($response, 'admin/tours.phtml', $args);
    });
    $app->get('/admin/assign-boats-alt', function (Request $request, Response $response, array $args) use ($container, $currentMission) {
        if (!$container->get('session')->exists('admin')) return $response->withRedirect('/admin/login');
        error_reporting(E_ALL & ~E_NOTICE);
        $reservationKeys = $container->get('redis')->zRangeByScore("$currentMission:reservations", '-inf', '+inf');
        header('Content-Type:text/plain');
        echo "DELETE ALL BOAT ASSIGNMENTS:\n";
        foreach ($reservationKeys as $reservationKey) {
            $container->get('redis')->hDel($reservationKey, 'assignedBoat');
            echo "- HDEL {$reservationKey} assignedBoat\n";
        }
        echo "FILLING OO2-UPPER: One pass, first 18 that fit.\n";
        $oo2UpperPassengers = 0;
        foreach ($reservationKeys as $reservationKey) {
            $oo2UpperSpotsRemaining = 18 - $oo2UpperPassengers;
            if ($oo2UpperSpotsRemaining == 0) {
                $container->get('redis')->hSet($reservationKey, 'assignedBoat', 'OO2-LOWER');
                continue;
            }
            $reservation = $container->get('redis')->hGetAll($reservationKey);
            if (isset($reservation['cancelled'])) continue;
            if (isset($reservation['assignedBoat']) || $reservation['standardQty'] == 0 || $reservation['standardQty'] > $oo2UpperSpotsRemaining || $reservation['boatPref1'] != 'oo2-upper') {
                $container->get('redis')->hSet($reservationKey, 'assignedBoat', 'OO2-LOWER');
                continue;
            }
            $oo2UpperPassengers += $reservation['standardQty'];
            echo "- {$reservation['reservationName']}, party of {$reservation['standardQty']}, {$reservation['boatPref1']} preferred ($oo2UpperPassengers)\n";
            $container->get('redis')->hSet($reservationKey, 'assignedBoat', 'OO2-UPPER');
        }
    });
    $app->get('/admin/assign-boats', function (Request $request, Response $response, array $args) use ($container, $currentMission) {
        if (!$container->get('session')->exists('admin')) return $response->withRedirect('/admin/login');
        error_reporting(E_ALL & ~E_NOTICE);
        $reservationKeys = $container->get('redis')->zRangeByScore("$currentMission:reservations", '-inf', '+inf');
        header('Content-Type:text/plain');
        echo "PASS ZERO: DELETE ALL BOAT ASSIGNMENTS:\n";
        foreach ($reservationKeys as $reservationKey) {
            $container->get('redis')->hDel($reservationKey, 'assignedBoat');
            echo "- HDEL {$reservationKey} assignedBoat\n";
        }
        $tnt1Passengers = 0;
        echo "FILLING TNT1: First pass, finding all parties who selected TNT1:\n";
        foreach ($reservationKeys as $reservationKey) {
            $reservation = $container->get('redis')->hGetAll($reservationKey);
            if (isset($reservation['cancelled'])) continue;
            if ($reservation['standardQty'] == 0 || $reservation['boatPref1'] != 'tnt1') {
                continue;
            }
            $tnt1Passengers += $reservation['standardQty'];
            echo "- {$reservation['reservationName']}, party of {$reservation['standardQty']}, TNT1 preferred ($tnt1Passengers)\n";
            $container->get('redis')->hSet($reservationKey, 'assignedBoat', 'TNT1');
            // Technically > 18 can be assigned TNT1 if that many chose it. Shhh.
        }
        // Forgive me for writing this.
        echo "FILLING TNT1: Second pass, starting from last reservation, assign all parties with no preference:\n";
        foreach (array_reverse($reservationKeys) as $reservationKey) {
            $tnt1SpotsRemaining = 18 - $tnt1Passengers;
            if ($tnt1SpotsRemaining == 0) continue;
            $reservation = $container->get('redis')->hGetAll($reservationKey);
            if (isset($reservation['cancelled'])) continue;
            if ($reservation['standardQty'] == 0 || $reservation['standardQty'] > $tnt1SpotsRemaining || $reservation['boatPref1'] != 'none') {
                continue;
            }
            $tnt1Passengers += $reservation['standardQty'];
            echo "- {$reservation['reservationName']}, party of {$reservation['standardQty']}, no boat preference ($tnt1Passengers)\n";
            $container->get('redis')->hSet($reservationKey, 'assignedBoat', 'TNT1');
        }
        echo "FILLING TNT1: Third pass, starting from last reservation, reassign any 2nd pref TNT1 reservations:\n";
        foreach (array_reverse($reservationKeys) as $reservationKey) {
            $tnt1SpotsRemaining = 18 - $tnt1Passengers;
            if ($tnt1SpotsRemaining == 0) continue;
            $reservation = $container->get('redis')->hGetAll($reservationKey);
            if (isset($reservation['cancelled'])) continue;
            if ($reservation['standardQty'] == 0 || $reservation['standardQty'] > $tnt1SpotsRemaining || $reservation['boatPref2'] != 'tnt1') {
                continue;
            }
            $tnt1Passengers += $reservation['standardQty'];
            echo "- {$reservation['reservationName']}, party of {$reservation['standardQty']} assigned to TNT1, their 2nd preference ($tnt1Passengers)\n";
            $container->get('redis')->hSet($reservationKey, 'assignedBoat', 'TNT1');
        }
        echo "FILLING TNT1: Fourth pass, starting from last reservation, reassign any OO2/Upper reservations until TNT1 is full:\n";
        foreach (array_reverse($reservationKeys) as $reservationKey) {
            $tnt1SpotsRemaining = 18 - $tnt1Passengers;
            if ($tnt1SpotsRemaining == 0) continue;
            $reservation = $container->get('redis')->hGetAll($reservationKey);
            if (isset($reservation['cancelled'])) continue;
            if ($reservation['standardQty'] == 0 || $reservation['standardQty'] > $tnt1SpotsRemaining) {
                continue;
            }
            $tnt1Passengers += $reservation['standardQty'];
            echo "- {$reservation['reservationName']}, party of {$reservation['standardQty']} assigned to TNT1, {$reservation['boatPref1']} preferred ($tnt1Passengers)\n";
            $container->get('redis')->hSet($reservationKey, 'assignedBoat', 'TNT1');
        }
        echo "FILLING OO2-UPPER: One pass, first 20 that fit.\n";
        $oo2UpperPassengers = 0;
        foreach ($reservationKeys as $reservationKey) {
            $oo2UpperSpotsRemaining = 20 - $oo2UpperPassengers;
            if ($oo2UpperSpotsRemaining == 0) continue;
            $reservation = $container->get('redis')->hGetAll($reservationKey);
            if (isset($reservation['cancelled'])) continue;
            if (isset($reservation['assignedBoat']) || $reservation['standardQty'] == 0 || $reservation['standardQty'] > $oo2UpperSpotsRemaining || $reservation['boatPref1'] != 'oo2-upper') {
                continue;
            }
            $oo2UpperPassengers += $reservation['standardQty'];
            echo "- {$reservation['reservationName']}, party of {$reservation['standardQty']}, {$reservation['boatPref1']} preferred ($oo2UpperPassengers)\n";
            $container->get('redis')->hSet($reservationKey, 'assignedBoat', 'OO2-UPPER');
        }

        echo "FILLING OO2-LOWER: Final pass, assign all remaining standard passengers to OO2:\n";
        foreach ($reservationKeys as $reservationKey) {
            $reservation = $container->get('redis')->hGetAll($reservationKey);
            if (isset($reservation['cancelled'])) continue;
            if ($reservation['standardQty'] == 0 || isset($reservation['assignedBoat'])) {
                continue;
            }
            echo "- {$reservation['reservationName']}, party of {$reservation['standardQty']}, assigned to OO2\n";
            $container->get('redis')->hSet($reservationKey, 'assignedBoat', 'OO2');
        }
        die();

    });
    $app->get('/admin/{mission}/emails', function (Request $request, Response $response, array $args) use ($container) {
        if (!$container->get('session')->exists('admin')) return $response->withRedirect('/admin/login');
        $reservationKeys = $container->get('redis')->zRangeByScore("{$args['mission']}:reservations", '-inf', '+inf');
        header('Content-Type:text/plain');
        foreach ($reservationKeys as $reservationKey) {
            $reservation = $container->get('redis')->hGetAll($reservationKey);
            $reservation['reservationName'] = trim($reservation['reservationName']);
            $reservation['reservationEmail'] = trim($reservation['reservationEmail']);
            if (isset($reservation['cancelled'])) continue;
            echo "{$reservation['reservationName']},{$reservation['reservationEmail']}\n";
        }
        die();
    });
    $app->get('/admin/inventory', function (Request $request, Response $response, array $args) use ($container, $currentMission) {
        if (!$container->get('session')->exists('admin')) return $response->withRedirect('/admin/login');
        $args['inventory'] = $container->get('redis')->hgetall("$currentMission:inventory");
        return $container->get('renderer')->render($response, 'admin/inventory.phtml', $args);
    });
    $app->post('/admin/inventory', function (Request $request, Response $response, array $args) use ($container, $currentMission) {
        if (!$container->get('session')->exists('admin')) return $response->withRedirect('/admin/login');
        $container->get('redis')->hset("$currentMission:inventory", 'upper', $_POST['upperQty']);
        $container->get('redis')->hset("$currentMission:inventory", 'standard', $_POST['standardQty']);
        $container->get('redis')->hset("$currentMission:inventory", 'private', $_POST['privateQty']);
        $container->get('redis')->hset("$currentMission:inventory", 'tour', $_POST['tourQty']);
        return $response->withRedirect('/admin/inventory');
    });
    $app->post('/admin/reservation/{id}', function (Request $request, Response $response, array $args) use ($container, $currentMission) {
        if (!$container->get('session')->exists('admin')) return $response->withRedirect('/admin/login');
        $fieldWhitelist = [
            'reservationName',
            'reservationEmail',
            'tourPref1',
            'tourPref2',
            'tourPref3',
            'boatPref1',
            'boatPref2',
            'boatPref3',
            'tourCheckInTime',
            'tourCheckInNotes',
            'launchCheckInTime',
            'launchCheckInNotes',
            'sftNotes',
            'cancelled',
        ];
        foreach ($_POST as $key=>$val) {
            if (in_array($key, $fieldWhitelist)) {
                $container->get('redis')->hSet("$currentMission:reservation:{$args['id']}", $key, $_POST[$key]);
            }
        }
        if (isset($_GET['checkCancelled']) && !isset($_POST['cancelled'])) {
            $container->get('redis')->hDel("$currentMission:reservation:{$args['id']}", 'cancelled');
        }
        return $response->withRedirect('/admin/checkin/'.$args['id']);
    });
    $app->post('/admin/reservation-raw/{id}', function (Request $request, Response $response, array $args) use ($container, $currentMission) {
        if (!$container->get('session')->exists('admin')) return $response->withRedirect('/admin/login');
        foreach ($_POST as $key=>$val) {
            $container->get('redis')->hSet("$currentMission:reservation:{$args['id']}", $key, $_POST[$key]);
        }
        return $response->withRedirect('/admin/checkin/'.$args['id']);
    });
    $app->get('/admin/discounts', function (Request $request, Response $response, array $args) use ($container, $currentMission) {
        if (!$container->get('session')->exists('admin')) return $response->withRedirect('/admin/login');
        $args['discounts'] = [];
        foreach ($container->get('redis')->keys("$currentMission:discount:*") as $discountKey) {
            $discountCode = str_replace("$currentMission:discount:", "", $discountKey);
            if (!$discountCode) continue;
            $args['discounts'][$discountCode] = $container->get('redis')->hGetAll($discountKey);
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
    $app->get('/admin/resend-confirmation/{id}', function (Request $request, Response $response, array $args) use ($container, $currentMission, $sendConfirmationEmail) {
        if (!$container->get('session')->exists('admin')) return $response->withRedirect('/admin/login');
        $sendConfirmationEmail($args['id'], $container->get('redis')->hGetAll("$currentMission:reservation:{$args['id']}"));
        return $response->withRedirect('/admin?resent='.$args['id']);
    });
    $app->post('/admin/reservation', function (Request $request, Response $response, array $args) use ($container, $currentMission) {
        if (!$container->get('session')->exists('admin')) return $response->withRedirect('/admin/login');
        return $response->withRedirect('/admin/checkin/' . $_POST['confirmation-code']);
    });
    $app->get('/admin/checkin/{id}', function (Request $request, Response $response, array $args) use ($container, $currentMission) {
        if (!$container->get('session')->exists('admin')) return $response->withRedirect('/admin/login');
        $args['reservation'] = $container->get('redis')->hGetAll("$currentMission:reservation:{$args['id']}");
        $args['reservationTime'] = date("Y-m-d H:i:s", $container->get('redis')->zscore("$currentMission:reservations", "$currentMission:reservation:{$args['id']}"));
        return $container->get('renderer')->render($response, 'admin/checkin.phtml', $args);
    });
};
