<?php


namespace App\Payment;

//Holding class for a transactions details

class PaymentTransaction
{
    /**
     * @var string|null
     */
    public $id = null;

    /**
     * @var int|null
     */
    public $accountId = null;

    /**
     * Payment type, such as Card or Paypal
     * @var string|null
     */
    public $type = null;

    /**
     * Actual vendor used.
     * @var string|null
     */
    public $vendor = null;

    /**
     * @var string|null
     */
    public $vendorProfileId = null;

    /**
     * @var string|null
     */
    public $vendorTransactionId = null;

    /**
     * @var string
     */
    public $purchaseDescription = "";

    /**
     * @var float The USD value associated with only an account currency purchase
     */
    public $accountCurrencyPriceUsd = 0.0;

    /**
     * @var float The USD value associated with item purchases
     */
    public $itemPriceUsd = 0.0;

    /**
     * @var int
     */
    public $accountCurrencyQuoted = 0;

    /**
     * @var int Account currency rewarded from direct purchase - can vary due to bonuses
     */
    public $accountCurrencyRewarded = 0;

    /**
     * @var int Any account currency that was rewarded for item purchases.
     */
    public $accountCurrencyRewardedForItems = 0;

    /**
     * Recurring individual in days
     * @var int|null
     */
    public $recurringInterval = null;

    /**
     * @var PaymentTransactionItem[]
     */
    public $items = [];

    public $createdAt = null;
    public $completedAt = null;

    public $updated = null;

    public $status = 'unknown';
    public $open = true;

    public function totalPriceUsd(): float
    {
        return $this->accountCurrencyPriceUsd + $this->itemPriceUsd;
    }

    public function totalAccountCurrencyRewarded(): int
    {
        return $this->accountCurrencyRewarded + $this->accountCurrencyRewardedForItems;
    }


    /**
     * Produces the array used to offer a user the chance to accept/decline the transaction
     * @return array
     */
    public function toTransactionArray(): array
    {
        $clientArray = [
            "token" => $this->id,
            "purchase" => $this->purchaseDescription,
            "price" => "$" . round($this->totalPriceUsd(), 2)
        ];

        if ($this->recurringInterval) $clientArray['note'] = "$" . round($this->accountCurrencyPriceUsd, 2)
            . ' will be recharged every ' . $this->recurringInterval . ' days.';

        return $clientArray;
    }

    public function toArray(): array
    {
        $array = [
            "id" => $this->id,
            "type" => $this->type,
            "purchase_description" => $this->purchaseDescription,
            "account_currency_quoted" => $this->accountCurrencyQuoted,
            "account_currency_rewarded" => $this->accountCurrencyRewarded,
            "account_currency_rewarded_items" => $this->accountCurrencyRewardedForItems,
            "total_account_currency_rewarded" => $this->totalAccountCurrencyRewarded(),
            "total_usd" => $this->totalPriceUsd(),
            "open" => $this->open,
            "created_at" => $this->createdAt,
            "completed_at" => $this->completedAt,
            "status" => $this->status
        ];
        if ($this->recurringInterval) $array["recurring_interval"] = $this->recurringInterval;
        return $array;
    }
}
