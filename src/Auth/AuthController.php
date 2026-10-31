<?php

namespace BradTipper\AppBackend\Auth;

use BradTipper\AppBackend\Api\ApiController;
use BradTipper\AppBackend\Api\ApiException;
use BradTipper\AppBackend\Api\Input;
use BradTipper\AppBackend\OpenApi\Spec;
use BradTipper\AppBackend\Support\Mailer;
use BradTipper\AppBackend\Support\Secrets;
use BradTipper\AppBackend\Support\Totp;
use BradTipper\AppBackend\Support\Turnstile;
use SilverStripe\Control\Email\Email;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Environment;
use SilverStripe\Security\Member;
use SilverStripe\Security\MemberAuthenticator\MemberAuthenticator;
use SilverStripe\Security\Security;
use SilverStripe\Security\PasswordEncryptor;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Validation\ValidationResult;

/**
 * Email + password accounts with refresh-token sessions, password reset,
 * magic links, email confirmation and TOTP two-factor authentication.
 */
class AuthController extends ApiController
{
    private const RESET_TTL = 3600;
    private const MAGIC_TTL = 900;
    private const CONFIRM_TTL = 172800;
    private const MFA_TTL = 300;

    protected function routes(): array
    {
        return [
            'POST sign-up' => 'doSignUp',
            'POST sign-in' => 'doSignIn',
            'POST refresh' => 'doRefresh',
            'POST sign-out' => 'doSignOut',
            'GET me' => 'doMe',
            'PATCH me' => 'doUpdateMe',
            'POST password/forgot' => 'doForgotPassword',
            'POST password/reset' => 'doResetPassword',
            'POST password/change' => 'doChangePassword',
            'POST otp/request' => 'doRequestMagicLink',
            'POST otp/verify' => 'doVerifyMagicLink',
            'POST email/confirm' => 'doConfirmEmail',
            'POST email/resend' => 'doResendConfirmation',
            'POST mfa/verify' => 'doMfaVerify',
            'POST mfa/enroll' => 'doMfaEnroll',
            'POST mfa/enroll/confirm' => 'doMfaEnrollConfirm',
            'POST mfa/disable' => 'doMfaDisable',
        ];
    }

    // ---- sign up / in / out -------------------------------------------

    protected function doSignUp(HTTPRequest $request): array
    {
        $in = Input::fromRequest($request);
        $email = $in->email('email');
        $password = $in->password('password');
        $name = $in->string('name', false, 200);
        $first = $in->string('firstName', false, 100);
        $last = $in->string('lastName', false, 100);
        $country = $in->string('country', false, 2);
        $redirectUrl = $this->redirectUrl($in);
        $captcha = $in->string('captchaToken', false, 4096);
        $in->assertValid();

        $ip = $this->clientIp($request);
        $this->limit("sign-up:{$ip}", 20, 3600);
        $this->requireCaptcha($captcha, $ip);

        if (ApiMemberExtension::findByEmail($email)) {
            throw ApiException::conflict('An account with this email already exists.', 'email_in_use');
        }

        $member = Member::create();
        $member->Email = $email;
        $member->Password = $password;
        if ($name) {
            $member->setApiName($name);
        } else {
            $member->FirstName = (string) $first;
            $member->Surname = (string) $last;
        }
        $member->Country = $country ? strtoupper($country) : '';

        $result = $member->validate();
        if (!$result->isValid()) {
            throw ApiException::validation($this->resultErrors($result, 'password'));
        }
        $member->write();
        $member->extend('onAfterApiSignUp');

        if ($this->requiresConfirmation()) {
            $this->sendConfirmation($member, $redirectUrl);
            return ['session' => null, 'mfaRequired' => false, 'mfaToken' => null, 'confirmationRequired' => true];
        }
        $member->EmailConfirmedAt = gmdate('Y-m-d H:i:s');
        $member->write();
        return $this->authResult(SessionService::start($member, $request));
    }

    protected function doSignIn(HTTPRequest $request): array
    {
        $in = Input::fromRequest($request);
        $email = $in->email('email');
        $password = $in->password('password');
        $captcha = $in->string('captchaToken', false, 4096);
        $in->assertValid();

        $ip = $this->clientIp($request);
        $this->limit("sign-in-ip:{$ip}", 60, 900);
        $this->limit("sign-in:{$ip}:{$email}", 10, 900);
        $this->requireCaptcha($captcha, $ip);

        $result = ValidationResult::create();
        $member = (new MemberAuthenticator())->authenticate(['Email' => $email, 'Password' => $password], $request, $result);
        if (!$member) {
            throw ApiException::unauthorized('Invalid email or password.', 'invalid_credentials');
        }
        return $this->completeSignIn($member, $request);
    }

    protected function doRefresh(HTTPRequest $request): array
    {
        $in = Input::fromRequest($request);
        $token = $in->string('refreshToken', true, 256);
        $in->assertValid();
        $session = SessionService::refresh($token, $request);
        if (!$session) {
            throw ApiException::unauthorized('The refresh token is invalid or expired.', 'invalid_refresh_token');
        }
        return $session;
    }

    protected function doSignOut(HTTPRequest $request): array
    {
        $this->requireMember($request);
        SessionService::revokeAccessToken((string) $this->bearerToken($request));
        return ['ok' => true];
    }

    // ---- profile ------------------------------------------------------

    protected function doMe(HTTPRequest $request): array
    {
        return $this->requireMember($request)->toApiUser();
    }

    protected function doUpdateMe(HTTPRequest $request): array
    {
        $member = $this->requireMember($request);
        $in = Input::fromRequest($request);
        $name = $in->string('name', false, 200);
        $first = $in->string('firstName', false, 100);
        $last = $in->string('lastName', false, 100);
        $units = $in->enum('units', ['metric', 'imperial'], false);
        $dateFormat = $in->string('dateFormat', false, 16);
        $timezone = $in->string('timezone', false, 64);
        $country = $in->string('country', false, 2);
        $preferences = $in->object('preferences');
        $in->assertValid();

        if ($timezone !== null && !in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            $in->fail('timezone', 'Unknown time zone.');
            $in->assertValid();
        }

        if ($name !== null) {
            $member->setApiName($name);
        } elseif ($first !== null || $last !== null) {
            $member->FirstName = $first ?? $member->FirstName;
            $member->Surname = $last ?? $member->Surname;
        }
        if ($units !== null) {
            $member->Units = $units;
        }
        if ($dateFormat !== null) {
            $member->DisplayDateFormat = $dateFormat;
        }
        if ($timezone !== null) {
            $member->Timezone = $timezone;
        }
        if ($country !== null) {
            $member->Country = strtoupper($country);
        }
        if ($preferences !== null) {
            // Shallow merge, like the previous client-side behaviour.
            $member->setPreferences(array_merge($member->preferences(), $preferences));
        }
        $member->extend('onApiProfileUpdate', $in);
        $member->write();
        return $member->toApiUser();
    }

    // ---- passwords ----------------------------------------------------

    protected function doForgotPassword(HTTPRequest $request): array
    {
        $in = Input::fromRequest($request);
        $email = $in->email('email');
        $redirectUrl = $this->redirectUrl($in);
        $captcha = $in->string('captchaToken', false, 4096);
        $in->assertValid();

        $ip = $this->clientIp($request);
        $this->limit("forgot:{$ip}", 10, 3600);
        $this->limit("forgot-email:{$email}", 5, 3600);
        $this->requireCaptcha($captcha, $ip);

        // Always report success so the endpoint cannot be used to find accounts.
        $member = ApiMemberExtension::findByEmail($email);
        if ($member) {
            $token = AuthChallenge::issue($member, AuthChallenge::RESET, self::RESET_TTL);
            Mailer::send($email, 'Reset your password', $this->linkMessage(
                'You asked to reset your password.',
                $this->buildLink($redirectUrl, '/reset-password', ['token' => $token]),
                'This link works for one hour. If you did not ask for this, you can ignore this email.',
            ));
        }
        return ['ok' => true];
    }

    protected function doResetPassword(HTTPRequest $request): array
    {
        $in = Input::fromRequest($request);
        $token = $in->string('token', true, 256);
        $password = $in->password('password');
        $in->assertValid();

        $this->limit('reset:' . $this->clientIp($request), 20, 3600);
        $challenge = AuthChallenge::find(AuthChallenge::RESET, $token);
        if (!$challenge) {
            throw ApiException::badRequest('This reset link is invalid or has expired.', 'invalid_token');
        }
        $member = $challenge->Member();
        $result = $member->changePassword($password);
        if (!$result->isValid()) {
            throw ApiException::validation($this->resultErrors($result, 'password'));
        }
        $challenge->markUsed();
        SessionService::revokeAll($member);
        return ['ok' => true];
    }

    protected function doChangePassword(HTTPRequest $request): array
    {
        $member = $this->requireMember($request);
        $in = Input::fromRequest($request);
        $current = $in->password('currentPassword');
        $new = $in->password('newPassword');
        $in->assertValid();

        $this->limit("change-password:{$member->ID}", 10, 900);
        $result = ValidationResult::create();
        $verified = (new MemberAuthenticator())->authenticate(['Email' => $member->Email, 'Password' => $current], $request, $result);
        if (!$verified || $verified->ID !== $member->ID) {
            throw ApiException::forbidden('Current password is incorrect.', 'invalid_credentials');
        }
        $result = $member->changePassword($new);
        if (!$result->isValid()) {
            throw ApiException::validation($this->resultErrors($result, 'newPassword'));
        }
        SessionService::revokeAll($member, $this->bearerToken($request));
        return ['ok' => true];
    }

    // ---- magic link ---------------------------------------------------

    protected function doRequestMagicLink(HTTPRequest $request): array
    {
        $in = Input::fromRequest($request);
        $email = $in->email('email');
        $redirectUrl = $this->redirectUrl($in);
        $in->assertValid();

        $ip = $this->clientIp($request);
        $this->limit("magic:{$ip}", 10, 3600);
        $this->limit("magic-email:{$email}", 5, 3600);

        $member = ApiMemberExtension::findByEmail($email);
        if ($member) {
            $token = AuthChallenge::issue($member, AuthChallenge::MAGIC, self::MAGIC_TTL);
            Mailer::send($email, 'Your sign-in link', $this->linkMessage(
                'Use this link to sign in.',
                $this->buildLink($redirectUrl, '/auth/callback', ['token' => $token, 'type' => 'magiclink']),
                'This link works for 15 minutes and can be used once.',
            ));
        }
        return ['ok' => true];
    }

    protected function doVerifyMagicLink(HTTPRequest $request): array
    {
        $in = Input::fromRequest($request);
        $token = $in->string('token', true, 256);
        $in->assertValid();

        $this->limit('magic-verify:' . $this->clientIp($request), 30, 900);
        $challenge = AuthChallenge::consume(AuthChallenge::MAGIC, $token);
        if (!$challenge) {
            throw ApiException::badRequest('This sign-in link is invalid or has expired.', 'invalid_token');
        }
        return $this->completeSignIn($challenge->Member(), $request);
    }

    // ---- email confirmation ------------------------------------------

    protected function doConfirmEmail(HTTPRequest $request): array
    {
        $in = Input::fromRequest($request);
        $token = $in->string('token', true, 256);
        $in->assertValid();

        $challenge = AuthChallenge::consume(AuthChallenge::CONFIRM, $token);
        if (!$challenge) {
            throw ApiException::badRequest('This confirmation link is invalid or has expired.', 'invalid_token');
        }
        $member = $challenge->Member();
        $member->EmailConfirmedAt = gmdate('Y-m-d H:i:s');
        $member->write();
        return $this->completeSignIn($member, $request);
    }

    protected function doResendConfirmation(HTTPRequest $request): array
    {
        $in = Input::fromRequest($request);
        $email = $in->email('email');
        $redirectUrl = $this->redirectUrl($in);
        $in->assertValid();

        $this->limit('resend:' . $this->clientIp($request), 10, 3600);
        $member = ApiMemberExtension::findByEmail($email);
        if ($member && !$member->EmailConfirmedAt) {
            $this->sendConfirmation($member, $redirectUrl);
        }
        return ['ok' => true];
    }

    // ---- two-factor ---------------------------------------------------

    protected function doMfaVerify(HTTPRequest $request): array
    {
        $in = Input::fromRequest($request);
        $token = $in->string('mfaToken', true, 256);
        $code = $in->string('code', false, 32);
        $recovery = $in->string('recoveryCode', false, 32);
        $in->assertValid();
        if (!$code && !$recovery) {
            $in->fail('code', 'A code or recovery code is required.');
            $in->assertValid();
        }

        $this->limit('mfa-verify:' . $this->clientIp($request), 30, 900);
        $challenge = AuthChallenge::find(AuthChallenge::MFA, $token);
        if (!$challenge || $challenge->Attempts >= 5) {
            throw ApiException::unauthorized('Sign in again to continue.', 'mfa_expired');
        }
        $member = $challenge->Member();

        $ok = $code !== null
            ? $this->verifyTotp($member, $code)
            : $this->consumeRecoveryCode($member, (string) $recovery);
        if (!$ok) {
            $challenge->Attempts++;
            $challenge->write();
            throw ApiException::unauthorized('That code is not correct.', 'invalid_mfa_code');
        }
        $challenge->markUsed();
        return $this->authResult(SessionService::start($member, $request));
    }

    protected function doMfaEnroll(HTTPRequest $request): array
    {
        $member = $this->requireMember($request);
        if ($member->TotpEnabled) {
            throw ApiException::conflict('Two-factor authentication is already enabled.', 'mfa_already_enabled');
        }
        $secret = Totp::generateSecret();
        $member->TotpPendingSecret = Secrets::encrypt($secret);
        $member->write();
        $issuer = (string) (Environment::getEnv('APP_NAME') ?: 'App');
        return ['secret' => $secret, 'otpauthUri' => Totp::uri($secret, $member->Email, $issuer)];
    }

    protected function doMfaEnrollConfirm(HTTPRequest $request): array
    {
        $member = $this->requireMember($request);
        $in = Input::fromRequest($request);
        $code = $in->string('code', true, 16);
        $in->assertValid();

        $pending = $member->TotpPendingSecret ? Secrets::decrypt($member->TotpPendingSecret) : null;
        if (!$pending) {
            throw ApiException::badRequest('Start enrolment first.', 'mfa_not_started');
        }
        $this->limit("mfa-enroll:{$member->ID}", 10, 900);
        if (!Totp::verify($pending, $code)) {
            throw ApiException::badRequest('That code is not correct.', 'invalid_mfa_code');
        }

        $plain = [];
        $hashed = [];
        for ($i = 0; $i < 10; $i++) {
            $recovery = strtoupper(bin2hex(random_bytes(5)));
            $plain[] = substr($recovery, 0, 5) . '-' . substr($recovery, 5);
            $hashed[] = hash('sha256', $recovery);
        }
        $member->TotpSecret = $member->TotpPendingSecret;
        $member->TotpPendingSecret = '';
        $member->TotpEnabled = true;
        $member->RecoveryCodes = json_encode($hashed);
        $member->write();
        return ['recoveryCodes' => $plain];
    }

    protected function doMfaDisable(HTTPRequest $request): array
    {
        $member = $this->requireMember($request);
        $in = Input::fromRequest($request);
        $password = $in->password('password');
        $code = $in->string('code', true, 32);
        $in->assertValid();

        $this->limit("mfa-disable:{$member->ID}", 10, 900);
        $result = ValidationResult::create();
        $verified = (new MemberAuthenticator())->authenticate(['Email' => $member->Email, 'Password' => $password], $request, $result);
        if (!$verified || $verified->ID !== $member->ID) {
            throw ApiException::forbidden('Password is incorrect.', 'invalid_credentials');
        }
        if (!$this->verifyTotp($member, $code) && !$this->consumeRecoveryCode($member, $code)) {
            throw ApiException::forbidden('That code is not correct.', 'invalid_mfa_code');
        }
        $member->TotpEnabled = false;
        $member->TotpSecret = '';
        $member->TotpPendingSecret = '';
        $member->RecoveryCodes = '';
        $member->write();
        return ['ok' => true];
    }

    // ---- helpers ------------------------------------------------------

    private function completeSignIn(Member $member, HTTPRequest $request): array
    {
        if ($this->requiresConfirmation() && !$member->EmailConfirmedAt) {
            throw ApiException::forbidden('Confirm your email address to sign in.', 'email_not_confirmed');
        }
        if ($member->TotpEnabled) {
            $token = AuthChallenge::issue($member, AuthChallenge::MFA, self::MFA_TTL);
            return ['session' => null, 'mfaRequired' => true, 'mfaToken' => $token, 'confirmationRequired' => false];
        }
        return $this->authResult(SessionService::start($member, $request));
    }

    /** @param array<string, mixed> $session @return array<string, mixed> */
    private function authResult(array $session): array
    {
        return ['session' => $session, 'mfaRequired' => false, 'mfaToken' => null, 'confirmationRequired' => false];
    }

    private function verifyTotp(Member $member, string $code): bool
    {
        $secret = $member->totpSecretPlain();
        return $secret !== null && Totp::verify($secret, $code);
    }

    private function consumeRecoveryCode(Member $member, string $code): bool
    {
        $hashes = json_decode((string) $member->RecoveryCodes ?: '[]', true) ?: [];
        $hash = hash('sha256', strtoupper(str_replace(['-', ' '], '', $code)));
        $index = array_search($hash, $hashes, true);
        if ($index === false) {
            return false;
        }
        unset($hashes[$index]);
        $member->RecoveryCodes = json_encode(array_values($hashes));
        $member->write();
        return true;
    }

    private function requiresConfirmation(): bool
    {
        return (bool) SessionService::config()->get('require_email_confirmation');
    }

    private function sendConfirmation(Member $member, ?string $redirectUrl): void
    {
        $token = AuthChallenge::issue($member, AuthChallenge::CONFIRM, self::CONFIRM_TTL);
        Mailer::send($member->Email, 'Confirm your email address', $this->linkMessage(
            'Confirm your email address to finish creating your account.',
            $this->buildLink($redirectUrl, '/auth/callback', ['token' => $token, 'type' => 'signup']),
            'If you did not create an account, you can ignore this email.',
        ));
    }

    private function requireCaptcha(?string $token, string $ip): void
    {
        if (!Turnstile::verify($token, $ip)) {
            throw ApiException::badRequest('Captcha verification failed.', 'captcha_failed');
        }
    }

    private function redirectUrl(Input $in): ?string
    {
        $url = $in->string('redirectUrl', false, 2048);
        if ($url !== null && !SessionService::isAllowedRedirect($url)) {
            $in->fail('redirectUrl', 'This redirect URL is not allowed.');
        }
        return $url;
    }

    /** @param array<string, string> $query */
    private function buildLink(?string $redirectUrl, string $fallbackPath, array $query): string
    {
        $base = $redirectUrl ?? rtrim((string) Environment::getEnv('APP_WEB_URL'), '/') . $fallbackPath;
        return $base . (str_contains($base, '?') ? '&' : '?') . http_build_query($query);
    }

    private function linkMessage(string $intro, string $link, string $outro): string
    {
        return "{$intro}\n\n{$link}\n\n{$outro}\n";
    }

    /** @return array<string, string[]> */
    private function resultErrors(ValidationResult $result, string $defaultField): array
    {
        $errors = [];
        foreach ($result->getMessages() as $message) {
            $field = $message['fieldName'] ?? null;
            $key = $field === 'Password' || !$field ? $defaultField : lcfirst((string) $field);
            $errors[$key][] = strip_tags((string) $message['message']);
        }
        return $errors ?: [$defaultField => ['Invalid value.']];
    }

    // ---- OpenAPI ------------------------------------------------------

    public static function describe(Spec $spec): void
    {
        $tag = ['Auth'];
        $s = static fn (?string $f = null) => Spec::string($f);

        $spec->schema('SignUpRequest', Spec::object([
            'email' => $s('email'),
            'password' => $s(),
            'name' => $s(),
            'firstName' => $s(),
            'lastName' => $s(),
            'country' => $s(),
            'redirectUrl' => $s('uri'),
            'captchaToken' => $s(),
        ], ['email', 'password']));
        $spec->schema('SignInRequest', Spec::object([
            'email' => $s('email'),
            'password' => $s(),
            'captchaToken' => $s(),
        ], ['email', 'password']));
        $spec->schema('RefreshRequest', Spec::object(['refreshToken' => $s()]));
        $spec->schema('ForgotPasswordRequest', Spec::object([
            'email' => $s('email'),
            'redirectUrl' => $s('uri'),
            'captchaToken' => $s(),
        ], ['email']));
        $spec->schema('ResetPasswordRequest', Spec::object(['token' => $s(), 'password' => $s()]));
        $spec->schema('ChangePasswordRequest', Spec::object(['currentPassword' => $s(), 'newPassword' => $s()]));
        $spec->schema('MagicLinkRequest', Spec::object(['email' => $s('email'), 'redirectUrl' => $s('uri')], ['email']));
        $spec->schema('TokenRequest', Spec::object(['token' => $s()]));
        $spec->schema('ResendConfirmationRequest', Spec::object(['email' => $s('email'), 'redirectUrl' => $s('uri')], ['email']));
        $spec->schema('MfaVerifyRequest', Spec::object([
            'mfaToken' => $s(),
            'code' => $s(),
            'recoveryCode' => $s(),
        ], ['mfaToken']));
        $spec->schema('MfaEnrollment', Spec::object(['secret' => $s(), 'otpauthUri' => $s()]));
        $spec->schema('MfaConfirmRequest', Spec::object(['code' => $s()]));
        $spec->schema('MfaRecoveryCodes', Spec::object(['recoveryCodes' => Spec::arrayOf($s())]));
        $spec->schema('MfaDisableRequest', Spec::object(['password' => $s(), 'code' => $s()]));
        $spec->schema('UpdateProfileRequest', Spec::object([
            'name' => $s(),
            'firstName' => $s(),
            'lastName' => $s(),
            'country' => $s(),
            'units' => Spec::enum(['metric', 'imperial']),
            'dateFormat' => $s(),
            'timezone' => $s(),
            'preferences' => Spec::map(),
        ], []));

        $ok = Spec::ref('Ok');
        $result = Spec::ref('AuthResult');
        $op = static fn (string $m, string $p, string $id, string $summary, ?string $body, array $response, bool $auth = false)
            => $spec->operation($m, "/v1/auth/{$p}", $id, [
                'tags' => $tag,
                'summary' => $summary,
                'auth' => $auth,
                ...($body ? ['body' => Spec::ref($body)] : []),
                'responses' => [200 => $response],
            ]);

        $op('post', 'sign-up', 'signUp', 'Create an account', 'SignUpRequest', $result);
        $op('post', 'sign-in', 'signIn', 'Sign in with email and password', 'SignInRequest', $result);
        $op('post', 'refresh', 'refreshSession', 'Rotate the refresh token', 'RefreshRequest', Spec::ref('Session'));
        $op('post', 'sign-out', 'signOut', 'Revoke the current session', null, $ok, true);
        $op('get', 'me', 'getMe', 'The signed-in user', null, Spec::ref('User'), true);
        $op('patch', 'me', 'updateMe', 'Update the signed-in user', 'UpdateProfileRequest', Spec::ref('User'), true);
        $op('post', 'password/forgot', 'forgotPassword', 'Email a password reset link', 'ForgotPasswordRequest', $ok);
        $op('post', 'password/reset', 'resetPassword', 'Set a new password using an emailed token', 'ResetPasswordRequest', $ok);
        $op('post', 'password/change', 'changePassword', 'Change password', 'ChangePasswordRequest', $ok, true);
        $op('post', 'otp/request', 'requestMagicLink', 'Email a sign-in link', 'MagicLinkRequest', $ok);
        $op('post', 'otp/verify', 'verifyMagicLink', 'Sign in with an emailed token', 'TokenRequest', $result);
        $op('post', 'email/confirm', 'confirmEmail', 'Confirm an email address', 'TokenRequest', $result);
        $op('post', 'email/resend', 'resendConfirmation', 'Resend the confirmation email', 'ResendConfirmationRequest', $ok);
        $op('post', 'mfa/verify', 'verifyMfa', 'Finish a two-factor sign-in', 'MfaVerifyRequest', $result);
        $op('post', 'mfa/enroll', 'enrollMfa', 'Begin two-factor enrolment', null, Spec::ref('MfaEnrollment'), true);
        $op('post', 'mfa/enroll/confirm', 'confirmMfaEnrollment', 'Confirm enrolment with a code', 'MfaConfirmRequest', Spec::ref('MfaRecoveryCodes'), true);
        $op('post', 'mfa/disable', 'disableMfa', 'Turn off two-factor authentication', 'MfaDisableRequest', $ok, true);
    }
}
