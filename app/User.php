<?php

namespace App;

use App\Helpers\MuckInterop;
use App\Muck\MuckCharacter;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

/**
 * Class User
 * This represents a logged in user, so contains present account and present player if set
 */
class User implements Authenticatable, MustVerifyEmail
{
    use Notifiable;

    protected $aid = null;
    protected $email = null; // Primary email
    protected $emails = null;
    protected $password = null;
    protected $passwordType = null;
    protected $rememberToken = null;

    /**
     * @var MuckCharacter|null
     */
    protected $character = null;

    // These are public since they don't save directly
    public $createdAt = null;
    public $updatedAt = null;
    public $emailVerified = false;

    public static function fromDatabaseResponse(\stdClass $query)
    {
        if (
            !property_exists($query, 'aid') ||
            !property_exists($query, 'email') ||
            !property_exists($query, 'password')
        ) {
            throw new \InvalidArgumentException('Database response must at least contain aid, password and email');
        }
        $user = new self();
        $user->aid = $query->aid;
        $user->email = $query->email;
        $user->password = $query->password;
        if (property_exists($query, 'password_type')) $user->passwordType = $query->password_type;
        if (property_exists($query, 'verified_at') && $query->verified_at) $user->emailVerified = true;
        if (property_exists($query, 'created_at') && $query->created_at) $user->createdAt = $query->created_at;
        if (property_exists($query, 'updated_at') && $query->updated_at) $user->updatedAt = $query->updated_at;
        return $user;
    }

    public function SetCharacter(MuckCharacter $character)
    {
        $this->character = $character;
    }



    /**
     * Get expected user provider
     * @return DatabaseForMuckUserProvider
     */
    public function getProvider()
    {
        return auth()->guard('account')->getProvider();
    }

    /**
     * Get the name of the unique identifier for the user.
     *
     * @return string
     */
    public function getAuthIdentifierName()
    {
        throw new \Exception("getAuthIdentifierName() not supported because it's not a single column.");
    }

    /**
     * Get the unique identifier for the user.
     * In our case it's aid:characterDbref
     *
     * @return mixed
     */
    public function getAuthIdentifier()
    {
        return $this->aid . ':' . $this->getCharacterDbref();
    }

    public function getAid()
    {
        return $this->aid;
    }

    /**
     * Get the password for the user.
     *
     * @return string
     */
    public function getAuthPassword()
    {
        return $this->password;
    }

    public function getPasswordType()
    {
        return $this->passwordType;
    }

    /**
     * Get the column name for the "remember me" token.
     *
     * @return string
     */
    public function getRememberTokenName()
    {
        return 'remember_token';
    }

    /**
     * Get the token value for the "remember me" session.
     *
     * @return string
     */
    public function getRememberToken()
    {
        return $this->rememberToken;
    }

    /**
     * Set the token value for the "remember me" session.
     *
     * @param string $value
     * @return void
     */
    public function setRememberToken($value)
    {
        $this->rememberToken = $value;
    }

    public function getCharacterDbref()
    {
        if (!$this->character) return null;
        return $this->character->getDbref();
    }

    public function getCharacterName()
    {
        if (!$this->character) return null;
        return $this->character->getName();
    }

    /**
     * Determine if the user has verified their email address.
     *
     * @return bool
     */
    public function hasVerifiedEmail()
    {
        return $this->emailVerified;
    }

    /**
     * Mark the given user's email as verified.
     * This will also make sure the given email is the user's primary email
     *
     * @return bool
     */
    public function markEmailAsVerified()
    {
        $this->getProvider()->markEmailAsVerified($this, $this->email);
        $this->emailVerified = true;
        return true;
    }

    /**
     * Send the email verification notification.
     *
     * @return void
     */
    public function sendEmailVerificationNotification()
    {
        $this->notify(new Notifications\VerifyEmail);
        //TODO - look at introducing work queue to handle notifications?
    }

    /**
     * Get the email address that should be used for verification.
     *
     * @return string
     */
    public function getEmailForVerification()
    {
        return $this->email;
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    public function getEmails()
    {
        if (!is_null($this->emails)) {
            return $this->emails;
        } else {
            $rawEmails = $this->getProvider()->getEmails($this);
            return $this->emails = $rawEmails->mapWithKeys(function ($item, $key) {
                $email = $item->email;
                unset($item->email);
                return [$email => $item];
            });
        }
    }

    //Used by notifiable
    public function getKey()
    {
        return $this->aid;
    }

    public function setPassword(string $password)
    {
        $password = MuckInterop::createSHA1SALTPassword($password);
        $this->password = $password;
        $this->password_type = 'SHA1SALT';
        $this->getProvider()->updatePassword($this, $password, 'SHA1SALT');
        //$this->updateLastUpdated(); //Done automatically with update
    }

    public function setEmail(string $email)
    {
        $this->emailVerified = $this->getProvider()->updateEmail($this, $email);
        $this->email = $email;
    }

    /**
     * @return MuckCharacter|null
     */
    public function getCharacter()
    {
        return $this->character;
    }

}
