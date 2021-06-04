<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

use App\Http\Controllers\AdminController;
use App\Http\Controllers\Auth\AccountController;
use App\Http\Controllers\Auth\AccountEmailController;
use App\Http\Controllers\AccountNotificationsController;
use App\Http\Controllers\Auth\AccountPasswordController;
use App\Http\Controllers\Auth\TermsOfServiceController;
use App\Http\Controllers\MultiplayerController;
use App\Http\Controllers\Payment\AccountCurrencyController;
use App\Http\Controllers\Payment\CardManagementController;
use App\Http\Controllers\Payment\PatreonController;
use App\Http\Controllers\Payment\PayPalController;
use App\Http\Controllers\HomeController;

//Only available when NOT logged in
Route::group(['middleware' => ['web', 'guest']], function() {
    Route::get('login', [AccountController::class, 'showLoginForm'])
        ->name('login');
    Route::post('account/login', [AccountController::class, 'loginAccount'])
        ->name('auth.account.login')->middleware('throttle:8,1');
    Route::post('account/create', [AccountController::class, 'createAccount'])
        ->name('auth.account.create');

    //Password forgot / reset
    Route::get('account/passwordforgotten', [AccountPasswordController::class, 'showForgotten'])
        ->name('auth.account.passwordforgotten');
    Route::post('account/passwordforgotten', [AccountPasswordController::class, 'showEmailSent'])
        ->middleware('throttle:3,1');
    Route::get('account/passwordreset/{id}/{hash}', [AccountPasswordController::class, 'showReset'])
        ->name('auth.account.passwordreset')->middleware('signed', 'throttle:8,1');
    Route::post('account/passwordreset/{id}/{hash}', [AccountPasswordController::class, 'resetPassword'])
        ->middleware('signed', 'throttle:8,1');
});

//Requires an account but DOESN'T require verification or Terms of Service acceptance
Route::group(['middleware' => ['web', 'auth:account']], function() {
    Route::post('logout', [AccountController::class, 'logout'])->name('logout');
    Route::get('account/verifyemail', [AccountEmailController::class, 'show'])
        ->name('verification.notice'); // Name is required for Laravel's verification middleware
    Route::get('account/verifyemail/{id}/{hash}', [AccountEmailController::class, 'verify'])
        ->name('auth.account.verifyemail')->middleware('signed', 'throttle:8,1');
    Route::get('account/resendverifyemail', [AccountEmailController::class, 'resend'])
        ->name('auth.account.resendverifyemail')->middleware('throttle:8,1');
});

//Requires account, verification and terms of service acceptance
Route::group(['middleware' => ['web', 'auth:account', 'verified', 'tos.agreed']], function() {

    //Account
    Route::get('account', [AccountController::class, 'show'])->name('auth.account');
    Route::post('account/setactivecharacter', [MultiplayerController::class, 'setActiveCharacter'])
        ->name('multiplayer.character.set');

    //Password change
    Route::get('account/changepassword', [AccountPasswordController::class, 'showChange'])
        ->name('auth.account.passwordchange');
    Route::post('account/changepassword', [AccountPasswordController::class, 'changePassword']);

    //Email change
    Route::get('account/changeemail', [AccountEmailController::class, 'showChangeEmail'])
        ->name('auth.account.emailchange');
    Route::post('account/useexistingemail', [AccountEmailController::class, 'useExistingEmail']);
    Route::post('account/changeemail', [AccountEmailController::class, 'changeEmail']);

    //Preference change
    Route::post('account/updatePreference', [AccountController::class, 'updatePreference']);

    //Card Management
    Route::get('account/cardmanagement', [CardManagementController::class, 'show'])
        ->name('payment.cardmanagement');
    Route::post('account/cardmanagement', [CardManagementController::class, 'addCard'])
        ->name('payment.cardmanagement.add');
    Route::delete('account/cardmanagement', [CardManagementController::class, 'deleteCard'])
        ->name('payment.cardmanagement.delete');
    Route::patch('account/cardmanagement', [CardManagementController::class, 'updateDefaultCard']);

    //Notifications
    Route::get('account/notifications', [AccountNotificationsController::class, 'show'])
        ->name('account.notifications');
    Route::get('account/notifications/api', [AccountNotificationsController::class, 'getNotifications'])
        ->name('account.notifications.api');  //TODO: Replace with api call
    Route::delete('account/notifications/api/{id}', [AccountNotificationsController::class, 'deleteNotification']); //TODO: Replace with api call
    Route::delete('account/notifications/api', [AccountNotificationsController::class, 'deleteAllNotifications']); //TODO: Replace with api call

    //Account Currency
    Route::get('accountcurrency', [AccountCurrencyController::class, 'show'])
        ->name('accountcurrency');
    Route::post('accountcurrency/fromUsd', [AccountCurrencyController::class, 'usdToAccountCurrency']);
    Route::post('accountcurrency/newCardTransaction', [AccountCurrencyController::class, 'newCardTransaction']);
    Route::post('accountcurrency/newPayPalTransaction', [AccountCurrencyController::class, 'newPayPalTransaction']);
    Route::post('accountcurrency/declineTransaction', [AccountCurrencyController::class, 'declineTransaction']);
    Route::get('accountcurrency/acceptTransaction', [AccountCurrencyController::class, 'acceptTransaction']);
    Route::get('accountcurrency/transaction/{id}', [AccountCurrencyController::class, 'viewTransaction'])
        ->name('accountcurrency.transaction');
    Route::get('accountcurrency/history/{accountId?}', [AccountCurrencyController::class, 'viewTransactions'])
        ->name('accountcurrency.transactions');
    Route::get('accountcurrency/paypal_order_return', [PayPalController::class, 'paypalOrderReturn'])
        ->name('accountcurrency.paypal.order.return');
    Route::get('accountcurrency/paypal_order_cancel', [PayPalController::class, 'paypalOrderCancel'])
        ->name('accountcurrency.paypal.order.cancel');

    Route::post('accountcurrency/newCardSubscription', [AccountCurrencyController::class, 'newCardSubscription']);
    Route::post('accountcurrency/newPayPalSubscription', [AccountCurrencyController::class, 'newPayPalSubscription']);
    Route::post('accountcurrency/declineSubscription', [AccountCurrencyController::class, 'declineSubscription']);
    Route::post('accountcurrency/cancelSubscription', [AccountCurrencyController::class, 'cancelSubscription']);
    Route::get('accountcurrency/acceptSubscription', [AccountCurrencyController::class, 'acceptSubscription']);
    Route::get('accountcurrency/subscription/{id}', [AccountCurrencyController::class, 'viewSubscription'])
        ->name('accountcurrency.subscription');
    Route::get('accountcurrency/paypal_subscription_return', [PayPalController::class, 'paypalSubscriptionReturn'])
        ->name('accountcurrency.paypal.subscription.return');
    Route::get('accountcurrency/paypal_subscription_cancel', [PayPalController::class, 'paypalSubscriptionCancel'])
        ->name('accountcurrency.paypal.subscription.cancel');

    //Multiplayer core - these don't require an active character
    Route::get('multiplayer', [MultiplayerController::class, 'showMultiplayerDashboard'])
        ->name('multiplayer.home');

    Route::get('multiplayer/selectCharacter', [MultiplayerController::class, 'showCharacterSelect'])
        ->name('multiplayer.character.select');
});

// Multiplayer content - Requires an active character, along with account, verification and terms of service acceptance
Route::group(['middleware' => ['web', 'auth:account', 'verified', 'tos.agreed', 'character']], function() {
    Route::get('multiplayer/avatar', [MultiplayerController::class, 'showAvatarEditor'])
        ->name('multiplayer.avatar');
});

//Website admin routes
Route::group(['middleware' => ['web', 'auth:account', 'verified', 'tos.agreed', 'role:admin']], function() {
    Route::get('admin', [AdminController::class, 'show'])
        ->name('admin.home');

    Route::get('admin/accounts', [AdminController::class, 'showAccountFinder'])
        ->name('admin.accounts');
    Route::get('admin/accounts/api', [AdminController::class, 'findAccounts'])
        ->name('admin.accounts.api');
    Route::get('admin/account/{accountId}', [AdminController::class, 'showAccount'])
        ->name('admin.account');

    Route::get('admin/logs', [AdminController::class, 'showLogViewer'])
        ->name('admin.logs');
    Route::get('admin/logs/{date}', [AdminController::class, 'getLogForDate']);

    Route::get('accountcurrency/subscriptions', [AccountCurrencyController::class, 'adminViewSubscriptions'])
        ->name('admin.subscriptions');
    Route::get('accountcurrency/subscriptions/api', [AccountCurrencyController::class, 'adminGetSubscriptions'])
        ->name('admin.subscriptions.api'); //TODO: Replace with api call

    Route::get('accountcurrency/transactions', [AccountCurrencyController::class, 'adminViewTransactions'])
        ->name('admin.transactions');
    Route::get('accountcurrency/transactions/api', [AccountCurrencyController::class, 'adminGetTransactions'])
        ->name('admin.transactions.api'); //TODO: Replace with api call

    Route::get('admin/patreons', [PatreonController::class, 'adminShow'])
        ->name('admin.patrons');
    Route::get('admin/patreons/api', [PatreonController::class, 'adminGetPatrons'])
        ->name('admin.patrons.api'); //TODO: Replace with api call

});

//----------------------------------------
//Always available

Route::get('/', [HomeController::class, 'show'])
    ->name('home');

//Character Profiles
Route::get('c/{characterName}', [MultiplayerController::class, 'showCharacter'])
    ->name('multiplayer.character.view');

//Terms of service - always viewable, does challenge if logged in.
Route::get('account/termsofservice', [TermsOfServiceController::class, 'view'])
    ->name('auth.account.termsofservice');
Route::post('account/termsofservice', [TermsOfServiceController::class, 'accept'])
    ->name('auth.account.termsofservice');

//Paypal Notifications - this route is exempt from CSRF token. Controlled in the middleware.
Route::post('accountcurrency/paypal_webhook', [PayPalController::class, 'paypalWebhook']);
