<?php


namespace App\Payment;

//Holding class for a transactions details
use App\User;

class PaymentTransaction
{
    public $id = null;
    public $userId = null;
    public $cardPaymentId = null; // If card

    public $purchaseDescription = "";
    public $accountCurrencyRewarded = 0;
    public $totalPriceUsd = 0.0;
    public $recurringInterval = null;

    public function toClientArray()
    {
        return [
            "token" => $this->id,
            "purchase" => $this->purchaseDescription,
            "price" => "$" . round($this->totalPriceUsd, 2)
        ];

    }
}
