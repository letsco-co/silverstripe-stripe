<?php

namespace Letsco\Stripe;

use SilverStripe\Core\Injector\Injector;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\Session;
use SilverStripe\ORM\ValidationResult;
use SilverStripe\Security\SecurityToken;
use SilverStripe\Security\Member;
use SilverStripe\Security\MemberAuthenticator\MemberAuthenticator;

/**
 * StripeController
 *
 * @package stripe
 * @author Johann
 * @since 2023.07.07
 */
class StripeController extends Controller
{
    private static $allowed_actions = [
    	'auth',
        'customer',
        'connect',
        'payment',
        'webhook',
    ];

    /**
     * Api method for authorization. Only POST is authorized.
     * Try to connect member by the specified credentials.
     * Returns generated random token if member could connect, false otherwise.
     * @param clientId client id
     * @param clientSecret client secret
     * @return HTTPResponse
     */
    public function auth(HTTPRequest $request)
    {
    	if (!$request->isPOST()) return;

    	$data = json_decode($request->getBody(), true);
    	$email = $data['clientId'];
    	$password = $data['clientSecret'];

        $success = false;
        $result = null;

        if ($member = Member::get()->filter('Email', $email)->first()) {
        	$result = ValidationResult::create();
        	Injector::inst()->get(MemberAuthenticator::class)->checkPassword($member, $password, $result);
            $success = $result->isValid();
        }

        $result = ['success' => $success];
        if ($member) {
	        if ($success) {
	        	$member->registerSuccessfulLogin();
	            $result['token'] = SecurityToken::inst()->getValue();
	        }
	        else {
	        	$member->registerFailedLogin();
	        }
	    }

        return $this->send200($result);
    }

    /**
     * Api method for customers :
     *      - GET : returns customer informations
     *      - POST: creates a customer, returns customer id
     * @return HTTPResponse
     */
    public function customer(HTTPRequest $request)
    {
    	$check = $this->securityCheck($request);
    	if ($check !== true) return $check;

    	switch ($request->httpMethod())
    	{
    		case 'GET':
                $customer = $request->param('ID');
                if (empty($customer)) return;
                $customer = StripeHelper::getCustomer($customer);
                return $this->send200(['customer' => $customer]);

    		case 'POST':
                $customer = StripeHelper::createCustomer();
                return $this->send200(['customer' => $customer]);
                break;
    	}

        return $this->send404();
    }

    /**
     * Api method for connected accounts actions :
     *      - GET : returns connected account informations
     *      - POST: creates a connected account, returns account id
     * @return HTTPResponse
     */
    public function connect(HTTPRequest $request)
    {
        $check = $this->securityCheck($request);
        if ($check !== true) return $check;

        switch ($request->httpMethod())
        {
            case 'GET':
                $account = $request->param('ID');
                if (empty($account)) return;
                $account = StripeHelper::getAccount($account);
                return $this->send200(['account' => $account]);
                break;

            case 'POST':
                $account = StripeHelper::createAccount();
                return $this->send200(['account' => $account]);
                break;
        }

        return $this->send404();
    }

    /**
     * Api method for payment actions :
     *      - GET : returns charge informations
     *      - POST: creates a charge and associated waiting transfers, returns charge id and fees amount
     *      - PUT : pays waiting transfers associated with specified charge id, returns transfers informations
     * @return HTTPResponse
     */
    public function payment(HTTPRequest $request)
    {
        $check = $this->securityCheck($request);
        if ($check !== true) return $check;

        switch ($request->httpMethod())
        {
            case 'GET':
                $charge = $request->param('ID');
                if (!empty($charge)) {
                    return $this->send200(['charge' => $charge]);
                }
                break;

            case 'POST':
                $data = json_decode($request->getBody(), true);
                $amount = intval($data['total_amount']);

                // 1. Get customer
                $customer = $data['customer'];
                if (empty($customer)) return;
                $customer = StripeHelper::getCustomer($customer);

                // 2. Debit customer
                // https://stripe.com/docs/sources/customers#d%C3%A9biter-une-source-rattach%C3%A9e
                $charge = StripeHelper::charge($customer, $amount, $data['description'], $data['meta']);
                // $charge = StripeHelper::createPaymentIntent($customer->id, $data['description'], $amount, $data['meta']);
                // $result = $charge->confirm(['payment_method' => $customer->default_source]);

                $fees = StripeHelper::getDebitFees($amount);

                if ($charge->id)
                {
                    $transfer = new StripeTransfer();
                    $transfer->Type         = StripeTransfer::TYPE_IN;
                    $transfer->Status       = StripeTransfer::STATUS_WAITING;
                    $transfer->PaymentId    = $charge->id;
                    $transfer->AccountId    = $customer->id;
                    $transfer->Amount       = $amount;
                    $transfer->Description  = $data['description'];
                    $transfer->write();

                    foreach ($data['accounts'] as $account) {
                        $transfer = new StripeTransfer();
                        $transfer->Type         = StripeTransfer::TYPE_OUT;
                        $transfer->Status       = StripeTransfer::STATUS_WAITING;
                        $transfer->PaymentId    = $charge->id;
                        $transfer->AccountId    = $account['id'];
                        $transfer->Amount       = $account['amount'];
                        $transfer->Description  = $data['description'];
                        $transfer->write();
                    }

                    return $this->send200(['charge' => $charge->id, 'fees' => $fees]);
                }
                break;

            case 'PUT':
                $charge = $request->param('ID');
                if (!empty($charge)) {
                    $transfers = $this->doTransfers($charge);
                    if ($transfers !== false) {
                        return $this->send200($transfers);
                    }
                }
                break;
        }

        return $this->send404();
    }

    /**
     * Send "404 NOT FOUND" HTTPResponse.
     * @return HTTPResponse
     */
    protected function send404()
    {
        return HTTPResponse::create()
            ->setStatusCode(404)
            ->setBody(json_encode([]));

    }

    /**
     * Send "200 OK" HTTPResponse with data.
     * @param data An array of data to add to response
     * @return HTTPResponse
     */
    protected function send200($data)
    {
        return HTTPResponse::create()
            ->setStatusCode(200)
            ->setBody(json_encode(array_merge(['success' => true], $data)));

    }

    /**
     * Check security token from authorization bearer header.
     * @param token The bearer token
     * @return true if security check passed, HTTPResponse otherwise
     */
    protected function securityCheck(HTTPRequest $request)
    {
    	if ($token = $request->getHeader('Authorization')) {
    		$token = explode('Bearer ', $token)[1];
    	}
    	// echo $token;
        if (!SecurityToken::inst()->check($token)) {
	        return HTTPResponse::create()
	            ->setStatusCode(401)
            	->setBody(json_encode([]));
        }
        return true;
    }

    /**
     * Webhook method called each time an event occured from Stripe // public URL !
     * @param input The json input object
     */
    public function webhook(HTTPRequest $request)
    {
        $input = json_decode(file_get_contents('php://input'), true);
        // \Log::info($input);

        $method = str_replace('.', '_', $input['type']);
        if ($this->hasMethod($method)) {
            $result = $this->{$method}($input['data']['object']);
            return $this->send200($result);
        }

        return $this->send404();
    }

    /**
     * Webhook method called when payment is succeeded.
     * @param chargeId The json input charge
     */
    private function charge_succeeded($charge)
    {
        if (empty($charge['object']) || $charge['object'] != 'charge' || empty($charge['id'])) {
            \Log::error(["Unknow data", $charge['object'], $charge['id']]);
            return false;
        }

        return $this->doTransfers($charge['id']);
    }

    /**
     * Get "out" tranfers to be done for the original payment specified by its id.
     * @param chargeId The original payment id
     * @return array Transfers paid this time
     */
    private function doTransfers($chargeId)
    {
        $InTransfer = StripeTransfer::get()->filter([
            'Type'         => StripeTransfer::TYPE_IN,
            'PaymentId'    => $chargeId,
        ])->first();

        if (!$InTransfer) {
            \Log::error(["Unknow charge", $chargeId]);
            return false;
        }

        $OutTransfers = StripeTransfer::get()->filter([
            'Type'         => StripeTransfer::TYPE_OUT,
            'Status'       => StripeTransfer::STATUS_WAITING,
            'PaymentId'    => $chargeId,
        ]);

        $transfers = [];
        foreach ($OutTransfers as $OutTransfer) {
            $transfer = StripeHelper::transfer(
                $OutTransfer->Amount, 
                $OutTransfer->AccountId, 
                $OutTransfer->Description,
                $InTransfer->PaymentId,
            );
            
            $OutTransfer->Status = StripeTransfer::STATUS_PAID;
            $OutTransfer->TransferId = $transfer->id;
            $OutTransfer->write();
            
            $transfers[] = [
                'amount'   => $OutTransfer->Amount,
                'account'  => $OutTransfer->AccountId,
                'transfer' => $transfer->id,
            ];

            // @TODO May payment be sent from Stripe daily or programmatically here on paid transfer ?
        }

        $InTransfer->Status = StripeTransfer::STATUS_PAID;
        $InTransfer->write();

        // @TODO Call client webhook or api to inform transfers paid.
        
        return $transfers;
    }
}
