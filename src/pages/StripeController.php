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
 * 
 */
class StripeController extends Controller
{
    private static $allowed_actions = [
    	'auth',
        'customer',
        'connect',
        'payment',
    ];

    /**
     * 
     * 
     * @param 
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

        return HTTPResponse::create()
            ->setStatusCode(200)
            ->setBody(json_encode($result));
    }

    /**
     * 
     * 
     * @param 
     */
    public function customer(HTTPRequest $request)
    {
    	$check = $this->securityCheck($request);
    	if ($check !== true) return $check;

    	switch ($request->httpMethod())
    	{
    		case 'GET':
    			echo $request->httpMethod();
    			// return $this->getCustomer();
    			break;
    		case 'POST':
    			echo $request->httpMethod();
    			break;
    		default:
    			echo "WRONG";
    			// throw new Exception("Error Processing Request", 1);
    	}
        // $amount = $request->requestVar('amount');
        // $methods = $this->SiteConfig()->getFloaAvailableMethods($amount);
        // return json_encode($methods);
    }

    /**
     * 
     * 
     * @param 
     */
    public function connect(HTTPRequest $request)
    {
    	$check = $this->securityCheck($request);
    	if ($check !== true) return $check;

    	switch ($request->httpMethod())
    	{
    		case 'GET':
    			$accountID = $request->param('ID');
    			echo $accountID;
    			StripeHelper::getAccount($accountID);
    			break;
    		case 'POST':
    			echo $request->httpMethod();

    			break;
    		default:
    			echo "WRONG";
    			// throw new Exception("Error Processing Request", 1);
    	}
        // $amount = $request->requestVar('amount');
        // $methods = $this->SiteConfig()->getFloaAvailableMethods($amount);
        // return json_encode($methods);
    }

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
}
