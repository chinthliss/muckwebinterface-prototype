<?php


namespace App\CardPayment;

use App\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;

//Technically this should be split into core management things and an authenticate.net provider.
//Don't have enough experience with payment providers to do this though.
class CardPaymentManager
{

    private $loginId = '';
    private $transactionKey = '';
    private $endPoint = '';

    const CARD_TYPE_MATCHES = [
        "VISA" => '/^4[0-9]{12}(?:[0-9]{3})?$/',
        "American Express" => '/^3[47][0-9]{13}$/',
        "JCB" => '/^(?:2131|1800|35\d{3})\d{11}$/',
        // "Discover" => '/^(?:6011\d{12})|(?:65\d{14})$/', // Not accepted by us
        "Mastercard" => '/^(?:5[1-5][0-9]{2}|222[1-9]|22[3-9][0-9]|2[3-6][0-9]{2}|27[01][0-9]|2720)[0-9]{12}$/'
        //Solo, Switch removed from this list due to being discontinued. Maestro removed as not actually accepted by Authorize.net
    ];

    /**
     * Class used to hold customer details
     * @var CardPaymentCustomerProfile|null
     */
    private $customerProfileModel = null;

    /**
     * @var array<int, CardPaymentCustomerProfile>
     */
    private $customerProfiles = [];

    /**
     * Authentication passed through on each request
     * @var AnetAPI\MerchantAuthenticationType|null
     */
    private $merchantAuthentication = null;

    public function __construct(string $loginId, string $transactionKey, string $endPoint,
                                string $cardPaymentCustomerProfileModel)
    {
        $this->loginId = $loginId;
        $this->transactionKey = $transactionKey;
        $this->endPoint = $endPoint;
        $this->customerProfileModel = $cardPaymentCustomerProfileModel;
    }

    private function refId()
    {
        return 'ref' . time();
    }

    private function merchantAuthentication()
    {
        if (!$this->merchantAuthentication) {
            $this->merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
            $this->merchantAuthentication->setName($this->loginId);
            $this->merchantAuthentication->setTransactionKey($this->transactionKey);
        }
        return $this->merchantAuthentication;
    }

    /**
     * Loads customer profile, any customer payment profiles and subscription ids.
     * Returns the profile or null if there was no profile to load
     * @param User $user
     * @return CardPaymentCustomerProfile|null
     */
    public function loadProfileFor(User $user)
    {
        $accountId = $user->getAid();
        //Return if already fetched
        if (array_key_exists($accountId, $this->customerProfiles)) return $this->customerProfiles[$accountId];

        /** @var CardPaymentCustomerProfile $profile */
        $profile = null;

        //Attempt to find ID in database
        $row = DB::table('billing_profiles')->where('aid', $accountId)->first();
        if ($row) {
            $profileId = $row->profileid;
            $request = new AnetAPI\GetCustomerProfileRequest();
            $request->setMerchantAuthentication($this->merchantAuthentication());
            $request->setCustomerProfileId($profileId);
            $controller = new AnetController\GetCustomerProfileController($request);
            $response = $controller->executeWithApiResponse($this->endPoint);
            if ($response && $response->getMessages()->getResultCode() == "Ok") {
                $profile = $this->customerProfileModel::fromApiResponse($response);
                if ($profile->getMerchantCustomerId() != $accountId) {
                    // $profile = null;
                    Log::warning("Retrieved Authorize.net customer profile for AID " . $accountId . " didn't have a matching merchantId.");
                }
                // Need to populate full card details from what we know, since ANet response masks expiry dates.
                $paymentProfiles = DB::table('billing_paymentprofiles')
                    ->where('profileid', $profile->getCustomerProfileId())->get();
                foreach ($paymentProfiles as $paymentProfile) {
                    $present = $profile->getCard($paymentProfile->paymentid);
                    if ($present) {
                        $present->expiryDate = $paymentProfile->expdate;
                        $profile->setCard($present);
                    }

                }
                // Subscriptions
                $subscriptions = $response->getSubscriptionIds();
                if ($subscriptions) {
                    //TODO Retrieve subscription
                    dd("Subscription found!");
                }
                // Historic thing - default is controlled by the muck (But we'll set it on ANet going forwards)
                $defaultCardId = DB::table('billing_profiles')
                    ->leftJoin('billing_paymentprofiles', 'billing_profiles.defaultcard', '=', 'billing_paymentprofiles.id')
                    ->where('billing_profiles.profileid', '=', $profile->getCustomerProfileId())
                    ->value('billing_paymentprofiles.paymentid');
                if ($defaultCardId) {
                    foreach ($profile->getCardIds() as $cardId) {
                        $card = $profile->getCard($cardId);
                        $card->isDefault = $card->id == $defaultCardId;
                    }
                }

            }
        }
        $this->customerProfiles[$accountId] = $profile;
        return $profile;
    }

    /**
     * Loads customer profile, any customer payment profiles and subscription ids.
     * If such doesn't exist, creates an entry for them.
     * @param User $user
     * @return CardPaymentCustomerProfile
     */
    public function loadOrCreateProfileFor(User $user)
    {
        $profile = $this->loadProfileFor($user);
        if (!$profile) {
            $anetProfile = new AnetAPI\CustomerProfileType();
            $anetProfile->setDescription("");
            $anetProfile->setMerchantCustomerId($user->getAid());
            $anetProfile->setEmail($user->getEmailForVerification());
            $request = new AnetAPI\CreateCustomerProfileRequest();
            $request->setMerchantAuthentication($this->merchantAuthentication());
            $request->setRefId($this->refId());
            $request->setProfile($anetProfile);
            $controller = new AnetController\CreateCustomerProfileController($request);
            $response = $controller->executeWithApiResponse($this->endPoint);
            if ($response && ($response->getMessages()->getResultCode() == "Ok")) {
                $profile = $this->customerProfileModel::fromApiResponse($response);
            } else {
                $errorMessages = $response->getMessages()->getMessage();
                throw new \Exception("Couldn't create a profile. Response : "
                    . $errorMessages[0]->getCode() . "  " . $errorMessages[0]->getText() . "\n");
            }
            DB::table('billing_profiles')->insert([
                'aid' => $user->getAid(),
                'profileid' => $profile->getCustomerProfileId(),
                'defaultcard' => 0,
                'spendinglimit' => 0
            ]);
        }
        return $profile;
    }

    /**
     * Registers with provider and returns Card.
     * @return Card
     */
    public function createCardFor(CardPaymentCustomerProfile $profile, $cardNumber,
                                  $expiryDate, $securityCode): Card
    {
        $anetCard = new AnetAPI\CreditCardType();
        $anetCard->setCardNumber($cardNumber);
        $anetCard->setExpirationDate($expiryDate);
        $anetCard->setCardCode($securityCode);

        $anetPaymentCard = new AnetAPI\PaymentType();
        $anetPaymentCard->setCreditCard($anetCard);

        //Previous code set a dummy address - not sure if this will remain valid?
        $anetAddress = new AnetAPI\CustomerAddressType();
        $anetAddress->setAddress("123 Not Available");
        $anetAddress->setZip("00000");

        $anetPaymentProfile = new AnetAPI\CustomerPaymentProfileType();
        $anetPaymentProfile->setCustomerType('individual');
        $anetPaymentProfile->setBillTo($anetAddress);
        $anetPaymentProfile->setPayment($anetPaymentCard);
        $anetPaymentProfile->setDefaultPaymentProfile(true);

        // Make the request
        $request = new AnetAPI\CreateCustomerPaymentProfileRequest();
        $request->setMerchantAuthentication($this->merchantAuthentication());
        // Add an existing profile id to the request
        $request->setCustomerProfileId($profile->getCustomerProfileId());
        $request->setPaymentProfile($anetPaymentProfile);
        $request->setValidationMode("liveMode");
        // Create the controller and get the response
        $controller = new AnetController\CreateCustomerPaymentProfileController($request);
        $response = $controller->executeWithApiResponse($this->endPoint);
        if (!$response || ($response->getMessages()->getResultCode() != "Ok")) {
            $errorMessages = $response->getMessages()->getMessage();
            if (count($errorMessages) == 1 && $errorMessages[0]->getCode() === 'E00027') {
                // E00027 - The transaction was unsuccessful.
                throw new \InvalidArgumentException("The transaction was unsuccessful.");
            } else
                throw new \Exception("Couldn't create a payment profile. Response : "
                    . $errorMessages[0]->getCode() . "  " . $errorMessages[0]->getText() . "\n");
        }
        $card = new Card();
        $card->id = $response->getCustomerPaymentProfileId();
        //Silly that this has to be extracted from a huge comma separated string..
        $responseParts = explode(',', $response->getValidationDirectResponse());
        $card->cardNumber = substr($responseParts[50], -4);
        $card->expiryDate = $expiryDate;
        $card->cardType = $responseParts[51];
        //This is just for historic purposes and to allow the muck easy access
        DB::table('billing_paymentprofiles')->insert([
            'profileid' => $profile->getCustomerProfileId(),
            'paymentid' => $response->getCustomerPaymentProfileId(),
            'firstname' => '',
            'lastname' => '',
            'cardtype' => $card->cardType,
            'maskedcardnum' => $card->cardNumber,
            'expdate' => $card->expiryDate
        ]);
        $newPaymentProfileId = DB::table('billing_paymentprofiles')->where([
            'profileid' => $profile->getCustomerProfileId(),
            'paymentid' => $response->getCustomerPaymentProfileId()
        ])->value('id');
        DB::table('billing_profiles')->where([
            'profileid' => $profile->getCustomerProfileId()
        ])->update([
            'defaultcard' => $newPaymentProfileId
        ]);
        return $card;
    }

    public function deleteCardFor(CardPaymentCustomerProfile $profile, Card $card)
    {
        $request = new AnetAPI\DeleteCustomerPaymentProfileRequest();
        $request->setMerchantAuthentication($this->merchantAuthentication());
        $request->setCustomerProfileId($profile->getCustomerProfileId());
        $request->setCustomerPaymentProfileId($card->id);
        $controller = new AnetController\DeleteCustomerPaymentProfileController($request);
        $response = $controller->executeWithApiResponse($this->endPoint);
        if (!$response || $response->getMessages()->getResultCode() != "Ok") {
            $errorMessages = $response->getMessages()->getMessage();
            throw new \Exception("Couldn't create a payment profile. Response : "
                . $errorMessages[0]->getCode() . "  " . $errorMessages[0]->getText() . "\n");
        }
        //This is just for historic purposes and to allow the muck easy access
        DB::table('billing_paymentprofiles')->where([
            'profileid' => $profile->getCustomerProfileId(),
            'paymentid' => $card->id
        ])->delete();
    }

    public function setDefaultCardFor(CardPaymentCustomerProfile $profile, Card $card)
    {
        $creditCard = new AnetAPI\CreditCardType();
        $creditCard->setCardNumber($card->cardNumber);
        $creditCard->setExpirationDate($card->expiryDate);

        $paymentCreditCard = new AnetAPI\PaymentType();
        $paymentCreditCard->setCreditCard($creditCard);
        $paymentProfile = new AnetAPI\CustomerPaymentProfileExType();
        // $paymentprofile->setBillTo($billto);
        $paymentProfile->setPayment($paymentCreditCard);
        $paymentProfile->setCustomerPaymentProfileId($card->id);
        $paymentProfile->setDefaultPaymentProfile(true);
        $request = new AnetAPI\UpdateCustomerPaymentProfileRequest();
        $request->setMerchantAuthentication($this->merchantAuthentication());
        $request->setCustomerProfileId($profile->getCustomerProfileId());
        $request->setPaymentProfile($paymentProfile);
        $controller = new AnetController\DeleteCustomerPaymentProfileController($request);
        $response = $controller->executeWithApiResponse($this->endPoint);
        if (!$response || $response->getMessages()->getResultCode() != "Ok") {
            $errorMessages = $response->getMessages()->getMessage();
            throw new \Exception("Couldn't update default payment profile. Response : "
                . $errorMessages[0]->getCode() . "  " . $errorMessages[0]->getText() . "\n");
        }
        //This is just for historic purposes and to allow the muck easy access
        $newPaymentProfileId = DB::table('billing_paymentprofiles')->where([
            'paymentid' => $card->id
        ])->value('id');
        DB::table('billing_profiles')->where([
            'profileid' => $profile->getCustomerProfileId()
        ])->update([
            'defaultcard' => $newPaymentProfileId
        ]);
    }

    /**
     * @param string|int $number
     * @return bool
     */
    public function checkLuhnChecksumIsValid($number)
    {
        $total = 0;
        foreach (str_split(strrev(strval($number))) as $index => $character) {
            $total += ($index % 2 == 0 ? $character : array_sum(str_split(strval($character * 2))));
        }
        return ($total % 10 == 0);
    }

    //Returns blank array if everything is okay, otherwise returns errors in the form { <element>:"error" }
    public function findIssuesWithAddCardParameters($cardNumber, $expiryDate, $securityCode)
    {
        $errors = [];

        //Card Number checks
        $cardNumber = str_replace([' ', '-'], '', $cardNumber);
        if ($cardNumber == '')
            $errors['cardNumber'] = 'Card number is required.';
        else {
            if (!is_numeric($cardNumber)) $errors['cardNumber'] = 'Card number can only contain numbers.';
            else {
                $cardType = "";
                foreach (self::CARD_TYPE_MATCHES as $testingFor => $cardTypeTest) {
                    if (preg_match($cardTypeTest, $cardNumber)) $cardType = $testingFor;
                }
                if (!$cardType) $errors['cardNumber'] = 'Unrecognized card number.';
                else {
                    if (!$this->checkLuhnChecksumIsValid($cardNumber)) $errors['cardNumber'] = 'Invalid card number.';
                }
            }
        }

        //Expiry Date checks
        if (!preg_match('/^\d\d\/\d\d\d\d$/', $expiryDate)) {
            $errors['expiryDate'] = 'Expiry Date must be in the form MM/YYYY.';
        } else {
            [$month, $year] = explode('/', $expiryDate);

            $endDate = Carbon::createFromDate($year, $month + 1, 1);
            if ($endDate < Carbon::now()) {
                $errors['expiryDate'] = 'Card has expired.';
            }
        }

        //Security Code checks
        if ($securityCode == '')
            $errors['securityCode'] = 'Security code is required.';
        else {
            if (!is_numeric($securityCode))
                $errors['securityCode'] = 'Security code can only contain numbers.';
            else if (strlen($securityCode) < 3 or strlen($securityCode) > 4)
                $errors['securityCode'] = 'Security code must be 3 or 4 numbers long.';
        }

        return $errors;
    }

    public function test()
    {
        $merchantAuthentication = $this->merchantAuthentication();
        $refId = 'ref' . time();

        $creditCard = new AnetAPI\CreditCardType();
        $creditCard->setCardNumber("4111111111111111");
        $creditCard->setExpirationDate("2038-12");
        $paymentOne = new AnetAPI\PaymentType();
        $paymentOne->setCreditCard($creditCard);

        $transactionRequestType = new AnetAPI\TransactionRequestType();
        $transactionRequestType->setTransactionType("authCaptureTransaction");
        $transactionRequestType->setAmount(151.51);
        $transactionRequestType->setPayment($paymentOne);
        $request = new AnetAPI\CreateTransactionRequest();
        $request->setMerchantAuthentication($merchantAuthentication);
        $request->setRefId($refId);
        $request->setTransactionRequest($transactionRequestType);
        $controller = new AnetController\CreateTransactionController($request);
        $response = $controller->executeWithApiResponse($this->endPoint);
        return $response;
    }
}
