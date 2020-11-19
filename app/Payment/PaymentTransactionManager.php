<?php


namespace App\Payment;

use App\Muck\MuckConnection;
use App\User;
use Exception;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentTransactionManager
{
    /**
     * @var MuckConnection
     */
    protected $muck;

    public function __construct(MuckConnection $muck)
    {
        $this->muck = $muck;
    }

    /**
     * @return Builder
     */
    private function storageTable() : Builder
    {
        return DB::table('billing_transactions');
    }

    private function insertTransactionIntoStorage(PaymentTransaction $transaction)
    {
        $row = [
            'id' => $transaction->id,
            'account_id' => $transaction->accountId,
            'vendor' => $transaction->vendor,
            'vendor_profile_id' => $transaction->vendorProfileId,
            'vendor_transaction_id' => $transaction->vendorTransactionId,
            'amount_usd' => $transaction->accountCurrencyPriceUsd,
            'amount_usd_items' => $transaction->itemPriceUsd,
            'accountcurrency_quoted' => $transaction->accountCurrencyQuoted,
            'purchase_description' => $transaction->purchaseDescription,
            'subscription_id' => $transaction->subscriptionId,
            'created_at' => Carbon::now()
        ];
        if ($transaction->items) $row['items_json'] = json_encode(array_map(function ($item) {
            return $item->toArray();
        }, $transaction->items));
        $this->storageTable()->insert($row);
    }

    public function createTransaction(User $user, string $vendor, string $vendorProfileId,
                                      int $usdForAccountCurrency, array $items): PaymentTransaction
    {
        $purchases = [];

        $transaction = new PaymentTransaction();
        $transaction->accountId = $user->getAid();
        $transaction->id = Str::uuid();
        $transaction->vendor = $vendor;
        $transaction->vendorProfileId = $vendorProfileId;

        $transaction->accountCurrencyQuoted = $this->muck->usdToAccountCurrency($usdForAccountCurrency);
        if ($transaction->accountCurrencyQuoted) {
            $transaction->accountCurrencyPriceUsd = $usdForAccountCurrency;
            array_push($purchases, $transaction->accountCurrencyQuoted . ' Mako');
        }

        if ($items) {
            $itemCatalogue = resolve('App\Payment\PaymentTransactionItemCatalogue')->itemsCatalogue();
            $itemsRecord = [];
            foreach ($items as $itemCode) {
                if (!array_key_exists($itemCode, $itemCatalogue)) {
                    Log::error("Attempt made to purchase non-existent billing item with itemCode " . $itemCode);
                } else {
                    $item = new PaymentTransactionItem(
                        $itemCode,
                        $itemCatalogue[$itemCode]['name'],
                        1,
                        $itemCatalogue[$itemCode]['amountUsd'],
                        $this->muck->usdToAccountCurrency($itemCatalogue[$itemCode]['amountUsd'])
                    );
                    $transaction->itemPriceUsd += $item->priceUsd;
                    array_push($itemsRecord, $item);
                    array_push($purchases, $item->name);
                }
            }
            $transaction->items = $itemsRecord;
        }

        $transaction->purchaseDescription = implode('<br/>', $purchases);

        $this->insertTransactionIntoStorage($transaction);

        return $transaction;
    }

    private function buildTransactionFromRow($row): ?PaymentTransaction
    {
        if (!$row) return null;
        $transaction = new PaymentTransaction();
        $transaction->id = $row->id;
        $transaction->accountId = $row->account_id;
        $transaction->vendor = $row->vendor;
        $transaction->vendorProfileId = $row->vendor_profile_id;
        $transaction->vendorTransactionId = $row->vendor_transaction_id;
        $transaction->subscriptionId = $row->subscription_id;
        $transaction->accountCurrencyPriceUsd = $row->amount_usd;
        $transaction->accountCurrencyQuoted = $row->accountcurrency_quoted;
        $transaction->accountCurrencyRewarded = $row->accountcurrency_rewarded;
        $transaction->accountCurrencyRewardedForItems = $row->accountcurrency_rewarded_items;
        $transaction->purchaseDescription = $row->purchase_description;
        $transaction->itemPriceUsd = $row->amount_usd_items;
        //$transaction->items = json_decode($row->items_json);
        if ($row->items_json) {
            foreach (json_decode($row->items_json) as $itemArray) {
                array_push($transaction->items, PaymentTransactionItem::fromArray($itemArray));
            }
        }
        $transaction->createdAt = $row->created_at;
        $transaction->completedAt = $row->completed_at;
        $transaction->result = $row->result;
        return $transaction;
    }

    public function getTransactionsFor(int $userId): array
    {
        $rows = $this->storageTable()
            ->where('account_id', '=', $userId)
            ->orderBy('created_at')
            ->get();
        $result = [];
        foreach ($rows as $row) {
            $transaction = $this->buildTransactionFromRow($row);
            $result[$transaction->id] = [
                'id' => $transaction->id,
                'type' => $transaction->type(),
                'usd' => $transaction->totalPriceUsd(),
                'subscriptionId' => $transaction->subscriptionId,
                'accountCurrency' => $transaction->totalAccountCurrencyRewarded(),
                'items' => count($transaction->items),
                'timeStamp' => $transaction->completedAt ?? $transaction->createdAt,
                'result' => $transaction->result,
                'url' => route('accountcurrency.transaction', ["id" => $transaction->id])
            ];
        }
        return $result;
    }

    public function getTransaction(string $transactionId): ?PaymentTransaction
    {
        $row = $this->storageTable()->where('id', '=', $transactionId)->first();
        return $this->buildTransactionFromRow($row);
    }

    public function getTransactionFromExternalId($externalId): ?PaymentTransaction
    {
        $row = $this->storageTable()->where('vendor_transaction_id', '=', $externalId)->first();
        return $this->buildTransactionFromRow($row);
    }


    /**
     * @param PaymentTransaction $transaction
     * @param string $closureReason
     * @throws Exception
     */
    public function closeTransaction(PaymentTransaction $transaction, string $closureReason)
    {
        // Closure reason must match one of the accepted entries by the DB
        if (!in_array($closureReason, ['fulfilled', 'user_declined', 'vendor_refused', 'expired']))
            throw new Exception('Closure reason is unrecognised');
        $transaction->result = $closureReason;
        $transaction->completedAt = Carbon::now();
        $this->storageTable()->where('id', '=', $transaction->id)->update([
            'result' => $transaction->result,
            'completed_at' => $transaction->completedAt,
            'accountcurrency_rewarded' => $transaction->accountCurrencyRewarded,
            'accountcurrency_rewarded_items' => $transaction->accountCurrencyRewardedForItems
        ]);
    }

    public function setPaid(PaymentTransaction $transaction)
    {
        $transaction->paidAt = Carbon::now();
        $this->storageTable()->where('id', '=', $transaction->id)->update([
            'paid_at' => $transaction->paidAt
        ]);
    }

    public function updateVendorTransactionId(PaymentTransaction $transaction, string $vendorTransactionId)
    {
        $transaction->vendorTransactionId = $vendorTransactionId;
        $this->storageTable()->where('id', '=', $transaction->id)->update([
            'vendor_transaction_id' => $vendorTransactionId
        ]);
    }

    public function updateVendorProfileId(PaymentTransaction $transaction, string $vendorProfileId)
    {
        $transaction->vendorProfileId = $vendorProfileId;
        $this->storageTable()->where('id', '=', $transaction->id)->update([
            'vendor_profile_id' => $vendorProfileId
        ]);
    }

    /**
     * Closes off items that the user never accepted
     */
    public function closePending()
    {
        $cutOff = Carbon::now()->subMinutes(30);
        $rows = $this->storageTable()
            ->whereNull('result')
            ->whereNull('paid_at')
            ->whereNull('completed_at')
            ->where('created_at', '<', $cutOff)
            ->get();
        foreach ($rows as $row) {
            $transaction = $this->buildTransactionFromRow($row);
            if ($transaction->open()) {
                Log::info("Closing Payment Transaction " . $transaction->id
                    . " created at " . $transaction->createdAt . " because user never accepted it.");
                $this->closeTransaction($transaction, 'user_declined');
            }
        }
    }

    /**
     * @param PaymentTransaction $transaction
     */
    public function fulfillTransaction(PaymentTransaction $transaction)
    {
        //Actual fulfilment is done by the MUCK still, due to ingame triggers
        $muck = resolve('App\Muck\MuckConnection');

        if ($transaction->accountCurrencyQuoted) {
            $transaction->accountCurrencyRewarded = $muck->adjustAccountCurrency(
                $transaction->accountId,
                $transaction->accountCurrencyPriceUsd,
                $transaction->accountCurrencyQuoted,
                ''
            );
        }

        if ($transaction->items) {
            $transaction->accountCurrencyRewardedForItems = 0;
            foreach ($transaction->items as $item) {
                $transaction->accountCurrencyRewardedForItems += $muck->rewardItem(
                    $transaction->accountId,
                    $item->priceUsd,
                    $item->accountCurrencyValue,
                    $item->code
                );
            }
        }
    }
}
