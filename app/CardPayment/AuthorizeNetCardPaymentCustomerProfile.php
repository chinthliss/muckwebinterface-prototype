<?php


namespace App\CardPayment;

use net\authorize\api\contract\v1\CreateCustomerProfileResponse;
use net\authorize\api\contract\v1\GetCustomerProfileResponse;
use net\authorize\api\contract\v1\SubscriptionPaymentType;

/**
 * Class CardPaymentProfile
 * Represents an Authorize.Net customer profile.
 * @package App
 */
class AuthorizeNetCardPaymentCustomerProfile implements CardPaymentCustomerProfile
{
    protected $id;
    protected $merchantCustomerId = null;

    /**
     * @var array<int, Card[]> Stored as {paymentProfileId:Card}
     */
    protected $cards = [];

    protected $defaultCardId = null;

    /**
     * These are initially retrieved as just the id, so will be in the form id:null until a further call is made
     * @var array<int, SubscriptionPaymentType|null> Stored as {id:subscription}
     */
    protected $subscriptionProfiles = [];

    public function __construct($id)
    {
        $this->id = $id;
    }


    /**
     * @param $response
     * @return AuthorizeNetCardPaymentCustomerProfile
     */
    public static function fromApiResponse($response): AuthorizeNetCardPaymentCustomerProfile
    {
        $customerProfile = null;

        if ($response instanceof CreateCustomerProfileResponse) {
            $customerProfile = new self($response->getCustomerProfileId());
        } elseif ($response instanceof GetCustomerProfileResponse) {
            $customerProfile = new self($response->getProfile()->getCustomerProfileId());
        } else
            throw new \Exception("fromApiResponse called with unsupported response type.");

        if ($response instanceof GetCustomerProfileResponse) {
            $receivedProfile = $response->getProfile();
            $customerProfile->merchantCustomerId = $receivedProfile->getMerchantCustomerId();

            if ($subscriptionIds = $response->getSubscriptionIds()) {
                foreach ($subscriptionIds as $subscriptionId) {
                    $customerProfile->subscriptionProfiles[$subscriptionId] = null;
                }
            }

            if ($paymentProfiles = $receivedProfile->getPaymentProfiles()) {
                foreach ($paymentProfiles as $paymentProfile) {
                    $receivedCard = $paymentProfile->getPayment()->getCreditCard();
                    $card = new Card();
                    $card->id = $paymentProfile->getCustomerPaymentProfileId();
                    $card->cardType = $receivedCard->getCardType();
                    $card->cardNumber = $receivedCard->getCardNumber();
                    $card->expiryDate = $receivedCard->getExpirationDate();
                    $card->isDefault = $paymentProfile->getDefaultPaymentProfile();
                    $customerProfile->setCard($card);
                    if ($paymentProfile->getDefaultPaymentProfile()) $customerProfile->defaultCardId = $card->id;
                }
            }
        }
        return $customerProfile;

    }

    public function getCustomerProfileId()
    {
        return $this->id;
    }

    public function getMerchantCustomerId()
    {
        return $this->merchantCustomerId;
    }

    #region Card functionality
    public function getCardIds()
    {
        return array_keys($this->cards);
    }

    public function setCard(Card $card)
    {
        $this->cards[$card->id] = $card;
    }

    public function getCard(string $cardId)
    {
        if (array_key_exists($cardId, $this->cards))
            return $this->cards[$cardId];
        else return null;
    }

    #endregion
}
