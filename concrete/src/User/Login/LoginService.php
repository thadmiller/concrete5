<?php

namespace Concrete\Core\User\Login;

use Concrete\Core\Config\Repository\Repository;
use Concrete\Core\Permission\IPService;
use Concrete\Core\User\Exception\FailedLoginThresholdExceededException;
use Concrete\Core\User\Exception\InvalidCredentialsException;
use Concrete\Core\User\Exception\NotActiveException;
use Concrete\Core\User\Exception\NotValidatedException;
use Concrete\Core\User\Exception\SessionExpiredException;
use Concrete\Core\User\Exception\UserDeactivatedException;
use Concrete\Core\User\User;

class LoginService
{

    /**
     * The config repository we use to format errors appropriately
     *
     * @var \Concrete\Core\Config\Repository\Repository
     */
    protected $config;

    /**
     * This service tracks login attempts and deals with excessive attempts
     *
     * @var \Concrete\Core\User\Login\LoginAttemptService
     */
    protected $loginAttemptService;

    /**
     * The class to use when testing login credentials.
     * The way we test is we run `$user = new {$this->userClass}($username, $password);` then check `$user->isError()`
     *
     * @var string
     * @deprecated Will no longer be needed once the User class is not in charge of testing login
     */
    protected $userClass = User::class;

    /**
     * This service tracks login attempts against IPs and deals with excessive attempts from specific IPs
     *
     * @var \Concrete\Core\Permission\IPService
     */
    protected $ipService;

    public function __construct(
        Repository $config,
        LoginAttemptService $loginAttemptService,
        IPService $IPService)
    {
        $this->config = $config;
        $this->loginAttemptService = $loginAttemptService;
        $this->ipService = $IPService;
    }

    /**
     * Attempt login given a username and a password
     *
     * @param string $username The username or email to attempt login with
     * @param string $password The plain text password to attempt login with
     *
     * @return User The logged in user
     *
     * @throws \Concrete\Core\User\Exception\NotActiveException If the user is inactive
     * @throws \Concrete\Core\User\Exception\InvalidCredentialsException If passed credentials don't seem valid
     * @throws \Concrete\Core\User\Exception\NotValidatedException If the user has not yet been validated
     * @throws \Concrete\Core\User\Exception\SessionExpiredException If the session immediately expires after login
     */
    public function login($username, $password)
    {
        /**
         * @todo Invert this so that the `new User` constructor login relies on this service
         */
        $className = $this->userClass;
        $user = new $className($username, $password);

        if ($user->isError()) {
            // Throw an appropriate acception matching the exceptional state
            $this->handleUserError($user->getError());
        }

        return $user;
    }

    /**
     * Force log in as a specific user ID
     * This method will setup session and set a cookie
     *
     * @param int $userId The user id to login as
     *
     * @return User The newly logged in user
     */
    public function loginByUserID($userId)
    {
        /**
         * @todo Move the responsibility of logging users in out of the User class and into this service
         */
        $className = $this->userClass;
        return $className::getByUserID($userId, true);
    }

    /**
     * Handle a failed login attempt
     *
     * @param string $username The user provided username
     * @param string $password The user provided password
     *
     * @throws \Concrete\Core\User\Exception\FailedLoginThresholdExceededException
     */
    public function failLogin($username, $password)
    {
        $ipFailed = false;
        $userFailed = false;

        // Track both failed logins from this IP and attempts to login to this user account
        $this->ipService->logFailedLogin();
        $this->loginAttemptService->trackAttempt($username, $password);

        // Deal with excessive logins from an IP
        if ($this->ipService->failedLoginsThresholdReached()) {
            $this->ipService->addToBlacklistForThresholdReached();
            $ipFailed = true;
        }

        // If the remaining attempts are less than 0
        if ($this->loginAttemptService->remainingAttempts($username, $password) <= 0) {
            $this->loginAttemptService->deactivate($username);
            $userFailed = true;
        }

        // Throw the IP ban error if we hit both ip limit and user limit at the same time
        if ($ipFailed) {
            throw new FailedLoginThresholdExceededException($this->ipService->getErrorMessage());
        }

        // If the user has been automatically deactivated and the IP has not been banned
        if ($userFailed) {
            throw new UserDeactivatedException($this->config->get('concrete.user.deactivation.message'));
        }
    }

    /**
     * Throw an exception based on the given error number
     *
     * @param int $errorNum The error number retrieved from the user object
     *
     * @throws \RuntimeException If an unknown error number is passed
     * @throws \Concrete\Core\User\Exception\NotActiveException If the user is inactive
     * @throws \Concrete\Core\User\Exception\InvalidCredentialsException If passed credentials don't seem valid
     * @throws \Concrete\Core\User\Exception\NotValidatedException If the user has not yet been validated
     * @throws \Concrete\Core\User\Exception\SessionExpiredException If the session immediately expires after login
     */
    protected function handleUserError($errorNum)
    {
        switch ($errorNum) {
            case USER_INACTIVE:
                throw new NotActiveException(t($this->config->get('concrete.user.deactivation.message')));

            case USER_SESSION_EXPIRED:
                throw new SessionExpiredException(t('Your session has expired. Please sign in again.'));

            case USER_NON_VALIDATED:
                throw new NotValidatedException(t(
                    'This account has not yet been validated. Please check the email associated with this ' .
                    'account and follow the link it contains.'));

            case USER_INVALID:
                if ($this->config->get('concrete.user.registration.email_registration')) {
                    $message = t('Invalid email address or password.');
                } else {
                    $message = t('Invalid username or password.');
                }

                throw new InvalidCredentialsException($message);
        }

        throw new \RuntimeException(t('An unknown login error occurred. Please try again.'));
    }

    /**
     * Change the user class used to validate the given credentials
     *
     * @param string $userClass
     *
     * @deprecated This will be removed once the User class is no longer in charge of negotiating login ~9.0
     */
    public function setUserClass($userClass)
    {
        $this->userClass = $userClass;
    }
}
