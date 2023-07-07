<?php

namespace Letsco\Stripe;

use Stripe\Charge;
use Stripe\Stripe;
use Stripe\Account;
use Stripe\Customer;
use Stripe\ApiResource;
use Composer\CaBundle\CaBundle;
use Stripe\Product;
use Stripe\Plan;
use Stripe\Subscription;
use Stripe\Card;
use Stripe\Token;
use Stripe\Transfer;
use Stripe\File;
use Stripe\Source;
use Stripe\Checkout\Session;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;

use SilverStripe\Core\Environment;
use SilverStripe\Control\Director;

/**
 * Stripe helper
 *
 * Provide helpers between SilverStripe and Stripe sdk
 *
 * Since we use static methods, we need to call init() on each method that call
 * the sdk
 *
 * @link https://stripe.com/docs/api
 * @link https://github.com/stripe/stripe-php
 */
class StripeHelper
{
    const CURRENCY = 'eur';
    const FILE_FRONT = 'front';
    const FILE_BACK = 'back';

    protected static $apiKey;

    /**
     * Configure the api using the SECRET key
     *
     * @param string $apiKey Something like sk_test_BQokikJOvBiI2HlWgH4olfQ2
     * @return void
     */
    public static function init($apiKey = null)
    {
        if (Stripe::getApiKey()) {
            return;
        }
        if ($apiKey === null) {
            $apiKey = self::StripeSecretKey();
        }
        if (!$apiKey) {
            throw new Exception("No api key found");
        }
        Stripe::setApiKey($apiKey);
        Stripe::setApiVersion('2019-09-09');
        Stripe::setCABundlePath(CaBundle::getBundledCaBundlePath());
    }

    /**
     * @todo "include the script in the head section on every page on your site, not just the checkout page."
     * @link https://stripe.com/docs/web/setup#setup
     * @see MlbcController.init()
     * @return void
     */
    public static function requireJsSdk()
    {
        Requirements::javascript('https://js.stripe.com/v3/');
    }

    public static function getCurrency()
    {
        return self::CURRENCY;
    }

    /**
     * @link https://stripe.com/docs/testing#international-cards
     *
     * @return void
     */
    public static function getTestCardNumber()
    {
        return '4000002500000003';
    }
    public static function getTestBankIBAN()
    {
        return 'FR1420041010050500013M02606';
    }

    /**
     * @deprecated since [JD-2019.09.01] Stripe v2 SCA migration
     * @link https://stripe.com/docs/charges
     * @link https://stripe.com/docs/connect/destination-charges
     * @param string $customer
     * @param int $amount (without decimals)
     * @param string $description
     * @param array $meta Meta data to associate
     * @param array $extra Extra api params
     * @return Charge
     */
    public static function charge($customer, $amount, $description, $meta = [])
    {
        self::log("StripeHelper.charge", [$customer->id, $amount, $description, $meta]);
        self::init();
        $descriptor = self::StripeStatementDesc();
        if (!$descriptor) {
            $descriptor = 'STRIPE';
        }

        $params = [
            'customer'              => $customer->id,
            'amount'                => $amount,
            'currency'              => self::getCurrency(),
            'description'           => $description,
            'statement_descriptor'  => $descriptor,
            'transfer_group'        => $description,
            'metadata'              => $meta,
        ];
        return Charge::create($params);
    }

    /**
     * @since [JD-2019.09.01] Stripe v2 SCA migration
     */
    public static function createSession($callbackURL, $objectID, $title, $amount, $customer)
    {
        self::log("StripeHelper.session", [$callbackURL, $objectID, $title, $amount, $customer]);
        self::init();
        // $session = Session::create([
        //     'payment_method_types' => [
        //         'card',
        //     ],
        //     'line_items' => [[
        //         'name' => $contribution->Project()->Title,
        //         'amount' => $contribution->AmountToPay(),
        //         'currency' => StripeHelper::getCurrency(),
        //         'quantity' => 1,
        //     ]],
        //     // 'payment_intent_data' => [
        //     //     'application_fee_amount' => 0,
        //     // ],
        //     'success_url' => $returnUrl,
        //     'cancel_url' => $cancelUrl,
        // ], [
        //     'stripe_account' => $contribution->Project()->Member()->StripeAccountId,
        // ]);

        $session = Session::create([
            'payment_method_types' => ['card', 'sepa_debit'],
            'customer' => $customer,
            // 'customer_email' => $customer,
            'line_items' => [[
                'name' => $title,
                'amount' => $amount,
                'currency' => self::getCurrency(),
                'quantity' => 1,
            ]],
            'success_url' => $callbackURL .'/success/'. $objectID,
            'cancel_url'  => $callbackURL .'/cancel/' . $objectID,
            // Metadata will be set in StripeWebhook.checkout_session_completed later.
        ]);
        return $session;
    }

    /**
     * @since [JD-2019.09.05] Stripe v2 SCA migration
     * @see https://stripe.com/docs/api/payment_intents/create
     */
    public static function createPaymentIntent($customer, $description, $amount, $meta = [])
    {
        self::log("StripeHelper.PaymentIntent", [$customer, $description, $amount, $meta]);
        self::init();

        $params = [
            'amount' => $amount,
            'currency' => self::getCurrency(),
            'payment_method_types' => ['card','sepa_debit'], // [JD-2020.05.14] Accept Stripe SEPA payments.
            'customer' => $customer,
            'description' => $description,
            'transfer_group' => $description,
            'metadata' => $meta,
        ];

        return PaymentIntent::create($params);
    }

    /**
     */
    public static function getPaymentIntent($session)
    {
        self::log("StripeHelper.PaymentIntent", [$session]);
        self::init();

        return PaymentIntent::retrieve($session);
    }

    /**
     */
    public static function getCharge($chargeId)
    {
        self::log("StripeHelper.getCharge", [$chargeId]);
        self::init();

        return Charge::retrieve($chargeId);
    }

    /**
     * @link https://stripe.com/docs/api/transfers/create
     * @link https://stripe.com/docs/connect/charges-transfers
     * Use the source_transaction parameter to tie a transfer to an existing, @see https://stripe.com/docs/connect/charges-transfers#transfer-availability
     * @param string $amount (without decimals)
     * @param string $account The account to transfer
     * @param string $transaction The original transaction
     * @param string $description The reference (typically the order ref)
     * @param array $meta Meta data to associate
     * @return Transfer
     */
    public static function transfer($amount, $account, $description, $transaction = null, $meta = [])
    {
        self::log("StripeHelper.transfer", array($amount, $account, $transaction, $description, $meta));
        self::init();

        $params = [
            'amount' => $amount,
            'currency' => self::getCurrency(),
            'destination' => $account,
            'description' => $description,
            'transfer_group' => $description,
            'metadata' => $meta,
        ];

        if ($transaction) {
            $params['source_transaction'] = $transaction;
        }

        return Transfer::create($params);
    }

    /**
     * @link https://stripe.com/docs/api/transfer_reversals/create
     * Use the ID of the transfer to be reversed.
     * @param string $transfer The original transfer id
     * @param string $amount (without decimals)
     * @param string $description The reference (typically the order ref)
     * @param array $meta Meta data to associate
     * @return Transfer reverse
     */
    public static function reverseTransfer($transfer, $amount, $description, $meta = [])
    {
        self::log("StripeHelper.reverseTransfer", array($transfer, $amount, $description, $meta));
        self::init();

        $params = [
            'amount' => $amount,
            'description' => $description,
            'metadata' => $meta,
        ];

        return Transfer::createReversal($transfer, $params);
    }

    /**
     * @link https://stripe.com/docs/api#create_customer
     * @param Member $member
     * @return Customer
     */
    public static function createCustomerFromMember(Member $member)
    {
        self::init();
        $customer = Customer::create([
            'email' => $member->Email,
            'metadata' => [
                'MemberID' => $member->ID,
            ]
        ]);
        return $customer;
    }

    /**
     * @param string $id
     * @return Customer
     */
    public static function getCustomer($id)
    {
        self::init();
        return Customer::retrieve($id);
    }

    /**
     * @link https://stripe.com/docs/api#account
     * @param Member $member
     * @return Account
     */
    // public static function createAccount()
    // {
    //     self::log("StripeHelper.createAccount", array());
    //     self::init();


    //     // $params = [
    //     //     'business_type' => 'individual',
    //     //     'individual' => [
    //     //         'first_name' => $this->owner->FirstName,
    //     //         'last_name' => $this->owner->Surname,
    //     //         'email' => $this->owner->Email,
    //     //         'address' => [
    //     //             'line1' => ($this->owner->StreetNumber ?: '') .' '. ($this->owner->StreetName ?: ''),
    //     //             'postal_code' => $this->owner->PostalCode ?: '',
    //     //             'city' => $this->owner->Locality ?: '',
    //     //             'country' => $this->owner->CountryCode ?: 'FR',
    //     //         ],
    //     //     ],
    //     //     'tos_shown_and_accepted' => true,
    //     //     'metadata' => ['MemberID' => $this->owner->ID],
    //     //     // business_profile may be set when account is creating, cf. StripeHelper.createAccountFromMember
    //     //     // payout settings may be set when account is creating, cf. StripeHelper.createAccountFromMember
    //     // ];
    //     // if ($this->owner->SocialReason) {
    //     //     $params['company'] = [
    //     //         'name' => $this->owner->SocialReason
    //     //     ];
    //     // }
    //     // if ($phone = $this->owner->getValidPhone()) {
    //     //     $params['individual']['phone'] = $phone;
    //     // }
    //     // if ($this->owner->BirthDate) {
    //     //     $date = new DateTime($this->owner->BirthDate);
    //     //     $params['individual']['dob'] = [
    //     //         'day' => $date->format('d'),
    //     //         'month' => $date->format('m'),
    //     //         'year' => $date->format('Y'),
    //     //     ];
    //     // }
    //     // if ($this->owner->StripeIdentityFrontId || $this->owner->StripeIdentityBackId) {
    //     //     $params['individual']['verification'] = [
    //     //         'document' => [],
    //     //         'additional_document' => [],
    //     //     ];
    //     //     if ($this->owner->StripeIdentityFrontId) {
    //     //         $params['individual']['verification']['document']['front'] = $this->owner->StripeIdentityFrontId;
    //     //     }
    //     //     if ($this->owner->StripeIdentityBackId) {
    //     //         $params['individual']['verification']['document']['back'] = $this->owner->StripeIdentityBackId;
    //     //     }
    //     // }
    //     // if ($this->owner->StripeHomeJustifyId) {
    //     //     $params['individual']['verification']['additional_document']['front'] = $this->owner->StripeHomeJustifyId;
    //     // }
    //     // $params = json_encode($params);


    //     $tokenData = [
    //         'business_type' => 'individual',
    //         'company' => ['name' => 'LETSCO'],
    //         'individual' => [
    //             'first_name' => 'Jane',
    //             'last_name' => 'Doe',
    //             'email' => 'api@pada1.app',
    //             'phone' => '0033675951092',
    //             'address' => [
    //                 'line1' => 'line1',
    //                 'postal_code' => '26400',
    //                 'city' => 'Crest',
    //                 'country' => 'FR',
    //             ],
    //             'dob' => [
    //                 'day' => '25',
    //                 'month' => '04',
    //                 'year' => '1983',
    //             ],
    //         ],
    //         'tos_shown_and_accepted' => true,
    //     ];

    //     $token = Token::create($tokenData);
    //     $account_token = $token->id;

    //     $accountData = [
    //         'country' => 'FR',
    //         'type' => 'custom',
    //         'capabilities' => [
    //             'card_payments' => ['requested' => true],
    //             'transfers' => ['requested' => true],
    //         ],
    //         'account_token' => $account_token,
    //     ];

    //     $account = Account::create($accountData);
    //     self::log("StripeHelper.createAccount >> Create account id: ". $account->id, $accountData);
    //     \Log('StripeHelper.createAccount() << '. json_encode($account), SS_Log::INFO);
    //     return $account;
    // }

    /**
     * @link https://stripe.com/docs/api#account
     * @param Member $member
     * @return Account
     */
    public static function createAccountFromMember(Member $member)
    {
        self::log("StripeHelper.createAccountFromMember", array('member' => $member));
        self::init();

        if (empty($_POST['token'])) return null;

        $account_token = $_POST['token'];
        /*if (Director::isLive()) {
            $account_token = $_POST['token'];
        } else {
            // [JD-2018.11.14] Forbiden to create token in live mode...
            $params = [
                'account' => [
                    'business_type' => 'individual',
                    'individual' => [
                        'first_name' => $member->FirstName,
                        'last_name' => $member->Surname,
                        'email' => $member->Email,
                        'address' => [
                            // 'line1' => $member->StreetName . ' ' . $member->StreetNumber,
                            // 'postal_code' => $member->Postalcode,
                            // 'city' => $member->Locality,
                            'country' => $member->CountryCode ?: 'FR',
                        ],
                    ],
                    'tos_shown_and_accepted' => true
                ]
            ];

            if ($member->BirthDate) {
                $date = new DateTime($member->BirthDate);
                $params['account']['individual']['dob'] = [
                    'day' => $date->format('d'),
                    'month' => $date->format('m'),
                    'year' => $date->format('Y'),
                ];
            }

            SS_Log::Log('StripeHelper.createAccountFromMember() >> create token >> '. json_encode($params), SS_Log::INFO);
            $token = Token::create($params);
            $account_token = $token->id;
        }*/
        self::log("StripeHelper.createAccountFromMember", array('account_token' => $account_token));

        // [JD-2019.02.03] Define account type (standard-default or custom)
        $accountType = 'standard';
        if (self::StripeCustomAccount()) {
            $accountType = 'custom';
        }

        $accountData = [
            'type' => 'custom', // [JD-2018.11.14] Must use custom accounts // 'standard',
            'email' => $member->Email,
            'country' => $member->CountryCode ?: 'FR',  // [JD-2019.12.10] Must set country (if different from default 'FR')
            'account_token' => $account_token, // Everything is defined in token.
            'metadata' => [
                'MemberID' => $member->ID,
            ],
            // [JD-2020.01.31] @see https://stripe.com/docs/connect/managing-capabilities#creating
            'requested_capabilities' => ['card_payments', 'transfers'],
            // [JD-2020.04.21] @see https://stripe.com/docs/connect/setting-mcc#mcc-manual
            'business_profile' => [
                'url' => $member->Website ?: Director::absoluteURL('/'),
                'mcc' => '5499', // Food merchant
            ],
            // [JD-2020.04.21] @see https://stripe.com/docs/api/accounts/update#update_account-settings-payouts
            'settings' => [
                'payouts' => [
                    'schedule' => [
                        // 'delay_days' =>  3,
                        'interval' => 'monthly', // @see https://stripe.com/docs/api/accounts/create#create_account-settings-payouts-schedule-interval
                        'monthly_anchor' => 1, // @see https://stripe.com/docs/api/accounts/create#create_account-settings-payouts-schedule-monthly_anchor
                    ],
                ],
            ],
        ];

        // [JD-2019.07.24] Keep subsite origin.
        if (class_exists('Subsite') && $subsiteID = Subsite::currentSubsiteID()) {
            $accountData['metadata']['SubsiteID'] = $SubsiteID;
        }

        // if ($member->SocialReason) {
        //     $accountData['business_name'] = $member->SocialReason;
        // }

        // Params cannot be empty
        // foreach (StripeMember::getStripeAccountFields() as $stripeField => $ssField) {
        //     if ($member->$ssField) {
        //         $accountData[$stripeField] = $member->$ssField;
        //     }
        // }
        SS_Log::Log('StripeHelper.createAccountFromMember() >> '. json_encode($accountData), SS_Log::INFO);
        $account = Account::create($accountData);
        self::log("StripeHelper.createAccountFromMember >> Create account id: ". $account->id, $accountData);
        SS_Log::Log('StripeHelper.createAccountFromMember() << '. json_encode($account), SS_Log::INFO);
        return $account;
    }

    /**
     * @link https://stripe.com/docs/api#account
     * @param Member $member
     * @return Account
     */
    public static function updateAccount($account)
    {
        $account_token = $_POST['token'];
        self::log("StripeHelper.updateAccountFromMember", array('account' => $account, 'account_token' => $account_token));
        self::init();

        $accountData = [
            'account_token' => $account_token, // Everything is defined in token.
        ];

        SS_Log::Log('StripeHelper.updateAccountFromMember() >> '. json_encode($accountData), SS_Log::INFO);
        $account = Account::update($account, $accountData);
        self::log("StripeHelper.updateAccountFromMember >> Update account id: ". $account, $accountData);
        SS_Log::Log('StripeHelper.updateAccountFromMember() << '. json_encode($account), SS_Log::INFO);
        return $account;
    }

    /**
     * @link https://stripe.com/docs/api/accounts/delete
     * @param account Stripe account id
     * @return Account
     */
    public static function deleteAccount($accountID)
    {
        self::log("StripeHelper.deleteAccount", array('account' => $accountID));
        self::init();
        
        $account = Account::retrieve($accountID);
        $account->delete();
        return $account;
    }

    /**
     * @link https://stripe.com/docs/api/external_account_bank_accounts/create
     */
    public static function createBankAccount($account, $member)
    {
        $params = [
            'external_account' => [
                'object' => 'bank_account',
                'account_number' => $member->IBAN,
                'country' => $member->CountryCode ?: 'FR',
                'currency' => self::getCurrency(),
            ]
        ];

        return Account::createExternalAccount($account, $params);
    }

    /**
     * @param string $id
     * @return Account
     */
    public static function getAccount($id)
    {
        self::init();
        return Account::retrieve($id);
    }

    /**
     * @link https://stripe.com/docs/api#account
     * @param Member $member
     * @return Account
     */
    public static function lookupAccount(Member $member)
    {
        self::init();
        $params = ['limit' => 100];
        $account = null;
        do {
            $results = Account::all($params);
            foreach ($results['data'] as $account) {
                if ($account->email == $member->Email) {
                    return $account;
                }
            }
            if ($account) {
                $params['starting_after'] = $account->id;
            }
        } while (count($results['data']));
        return false;
    }

    /**
     * @param string $name
     * @param string $type
     * @return Product
     */
    public static function createProduct($name, $type = 'service')
    {
        self::init();
        $params = [
            'name' => $name,
            'type' => $type,
        ];
        return Product::create($params);
    }

    /**
     * @param string $id
     * @return Product
     */
    public static function getProduct($id)
    {
        self::init();
        return Product::retrieve($id);
    }

    /**
     * @param string $product
     * @param string $title
     * @param string $amount
     * @param string $interval
     * @return Plan
     */
    public static function createPlan($product, $title, $amount, $interval, $metadata = [])
    {
        self::init();
        $params = [
            'product'  => $product,
            'nickname' => $title,
            'amount'   => $amount,
            'interval' => $interval,
            'currency' => self::getCurrency(),
            'metadata' => $metadata,
            // 'trial_period_days' => $trial_period_days, // Not used
        ];
        return Plan::create($params);
    }

    /**
     * @param string $id
     * @return Plan
     */
    public static function getPlan($id)
    {
        self::init();
        return Plan::retrieve($id);
    }

    /**
     * @link https://stripe.com/docs/api#subscriptions
     * @link https://stripe.com/docs/connect/subscriptions
     * @see https://stripe.com/docs/billing/subscriptions/billing-cycle for invoice date !
     * @param string $customer
     * @param string $plan
     * @return Subscription
     */
    public static function createSubscription($customer, $plan, $metadata = [])
    {
        self::init();
        $params = [
            'customer' => $customer,
            'items' => [
                [
                    'plan' => $plan,
                    'quantity' => 1
                ]
            ],
            // 'description' => 'toto', Can't use description here (doesn't exist) but can change paiement description after, in Stripe webhook
            'metadata' => $metadata,
            // ?? 'transfer_group'
        ];

        // [JD-2019.05.31] Add global fees on each Stripe operation (charge, subscription).
        // ERROR: You can only apply an 'application_fee' when the request is made on behalf of another account (using an OAuth key or the Stripe-Account header)
        // if ($commission = SiteConfig::current_site_config()->CommissionAmount) {
        //     $params['application_fee_percent'] = $commission;
        // }

        $options = [];
        // if ($account) {
        //     $token = Token::create(
        //         ['customer' => $customer],
        //         ['stripe_account' => $account]
        //     );
        //     $options['stripe_account'] = $account;
        //     $params['source'] = $token->id;
        // }

        return Subscription::create($params, $options);
    }

    /**
     * @see https://stripe.com/docs/api/subscriptions/cancel
     * @since [JD-2020.05.20] Revoke Stripe Subscription.
     */
    public static function cancelSubscription($subscriptionId)
    {
        $subscription = Subscription::retrieve($subscriptionId);
        $subscription->delete();
        return $subscription;
    }

    /**
     * @link https://stripe.com/docs/api#cards
     * @param Customer $customer
     * @return Card[]
     */
    public static function getCards(Customer $customer)
    {
        $cache = SS_Cache::factory(get_called_class());
        $cache_key = __FUNCTION__. $customer->id;
        $cache_result = $cache->load($cache_key);

        if ($cache_result) {
            $cards = unserialize($cache_result);
            self::log("StripeHelper.getCards from cache", array('customer' => $customer, 'cards' => $cards));
            return $cards;
        }

        self::init();
        $cards = [];
        $card = null;
        $params = ['limit' => 10, 'object' => 'card'];
        do {
            $results = $customer->sources->all($params);
            foreach ($results['data'] as $card) {
                $cards[] = $card;
            }
            if ($card) {
                $params['starting_after'] = $card->id;
            }
        } while (count($results['data']));
        
        $cache->save(serialize($cards), $cache_key, array('cards'), 60 * 5);
        self::log("StripeHelper.getCards", array('customer' => $customer, 'cards' => $cards));
        return $cards;
    }

    /**
     * @link https://stripe.com/docs/api#cards
     * @param Customer $customer
     * @return Card
     */
    public static function getDefaultCard(Customer $customer)
    {
        $cache = SS_Cache::factory(get_called_class());
        $cache_key = __FUNCTION__. $customer->id;
        $cache_result = $cache->load($cache_key);

        if ($cache_result) {
            $card = unserialize($cache_result);
            self::log("StripeHelper.getDefaultCard from cache", array('customer' => $customer, 'card' => $card));
            return $card;
        }

        self::init();
        $card = Customer::retrieveSource(
            $customer->id,
            $customer->default_source
        );
        $cache->save(serialize($card), $cache_key, array('card'), 60 * 5);
        self::log("StripeHelper.getDefaultCard", array('customer' => $customer, 'card' => $card));
        return $card;
    }

    /**
     * @link https://stripe.com/docs/api/cards/delete?lang=php
     * @param Customer $customer
     * @param String $card
     * @return Card
     */
    public static function deleteCard(Customer $customer, $card)
    {
        self::init();
        $card = Customer::deleteSource(
            $customer->id,
            $card
        );
        self::log("StripeHelper.deleteCard", array('customer' => $customer, 'card' => $card));
        return $card;
    }

    /**
     * @link https://stripe.com/docs/api/payment_methods/create?lang=php
     * @param Customer $customer
     * @param String $token
     * @return Card
     */
    public static function createCard(Customer $customer, $token, $default = true)
    {
        self::init();

        // $customer->sources->create(['source' => $token]);
        $card = Customer::createSource(
            $customer->id,
            ['source' => $token]
        );

        if ($default) {
            $customer->default_source = $card->id;
            $customer->save();
        }

        self::log("StripeHelper.createCard", array('customer' => $customer, 'token' => $token, 'card' => $card));
        return $card;
    }

    /**
     * @link https://stripe.com/docs/api/payment_methods/create?lang=php
     * @param Customer $customer
     * @param String $token
     * @return Card
     */
    public static function createDebitSource($customer, $params, $default = true)
    {
        self::init();

        $source = Source::create([
            'type'       => 'sepa_debit',
            'sepa_debit' => $params,
            'currency'   => self::getCurrency(),
            'owner'      => [
                'name'      => 'Jenny Rosen',
                'address'   => [
                    'city'        => 'Frankfurt',
                    'line1'       => 'Genslerstraße 24',
                    'postal_code' => '15230',
                    'country'     => 'DE',
                ],
            ],
        ]);

        $card = Customer::createSource(
            $customer->id,
            ['source' => $source->id]
        );

        if ($default) {
            $customer->default_source = $source->id;
            $customer->save();
        }

        // self::log("StripeHelper.createCard", array('customer' => $customer, 'token' => $token, 'card' => $card));
        return $source;
    }

    /**
     * Attach a file to an account.
     * The file may be identity front or back.
     * The uploaded file needs to be a color image (smaller than 8,000px by 8,000px), in JPG or PNG format, and less than 5MB in size.
     * @link https://stripe.com/docs/api/accounts/update#update_account-individual-verification-document-back
     * @link https://stripe.com/docs/api/files/create
     * @link https://stripe.com/docs/connect/identity-verification-api
     * @param file
     * @param account
     * @param type 'front' or 'back'
     * @return 
     */
    public static function attachFile(\File $attached, $account, $type)
    {
        self::init();

        // Create file
        $params = [
            'purpose' => 'identity_document',
            'file' => fopen($attached->getFullPath(), 'r')
        ];
        $file = File::create($params, ['stripe_account' => $account]);
        SS_Log::Log('StripeHelper.attachFile('. $attached->getFullPath() .') >> '. $file->id, SS_Log::INFO);

        $account_token = $_POST['token'];
        self::log("StripeHelper.attachFile", array('account_token' => $account_token));

        // don't forget to update account after that.
        return $file;
    }

    public static function log($origin, $values)
    {
        $msg = date('Y-m-d H:i:s') .' '. $origin .' - '. json_encode($values) .PHP_EOL;
        $destFile = Director::baseFolder() . '/stripe.log';
        if (!is_file($destFile)) {
            file_put_contents($destFile, '');
        }
        file_put_contents($destFile, $msg, FILE_APPEND);
    }

    protected static function StripeIsCBAvailable()
    {
        return Environment::getEnv('STRIPE_CB');
    }

    protected static function StripeIsDebitAvailable()
    {
        return Environment::getEnv('STRIPE_SEPA');
    }

    protected static function StripePublicKey()
    {
        return Environment::getEnv('STRIPE_PUBLIC_KEY');
    }

    protected static function StripeSecretKey()
    {
        return Environment::getEnv('STRIPE_SECRET_KEY');
    }

    protected static function StripeStatementDesc()
    {
        return Environment::getEnv('STRIPE_STATEMENT');
    }

    // protected static function StripeCBMinAmount()
    // {
    //     return Environment::getEnv('StripeCBMinAmount');
    // }

    // protected static function StripeDefaultProduct()
    // {
    //     return Environment::getEnv('StripeDefaultProduct');
    // }

    public static function getCardFees($amount)
    {
        return $amount * 0.015 + 0.25;
    }

    public static function getDebitFees($amount)
    {
        return 35;
    }
}
