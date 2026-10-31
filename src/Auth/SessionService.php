<?php

namespace BradTipper\AppBackend\Auth;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Security\Member;

/** Issues, rotates and revokes opaque access/refresh token pairs. */
final class SessionService
{
    use Configurable;

    private static int $access_ttl = 3600;

    private static int $refresh_ttl = 5184000;

    private static bool $require_email_confirmation = false;

    /** @var string[] */
    private static array $allowed_redirect_origins = [];

    /** @return array{accessToken: string, refreshToken: string, expiresAt: string, user: array<string, mixed>} */
    public static function start(Member $member, ?HTTPRequest $request = null): array
    {
        // Prune sessions whose refresh token has expired.
        if (random_int(1, 50) === 1) {
            ApiToken::get()->filter('RefreshExpires:LessThan', time())->removeAll();
        }

        $access = self::randomToken();
        $refresh = self::randomToken();
        $accessExpires = time() + (int) self::config()->get('access_ttl');
        ApiToken::create([
            'AccessHash' => hash('sha256', $access),
            'RefreshHash' => hash('sha256', $refresh),
            'AccessExpires' => $accessExpires,
            'RefreshExpires' => time() + (int) self::config()->get('refresh_ttl'),
            'UserAgent' => $request ? mb_substr((string) $request->getHeader('User-Agent'), 0, 255) : '',
            'MemberID' => $member->ID,
        ])->write();

        return [
            'accessToken' => $access,
            'refreshToken' => $refresh,
            'expiresAt' => gmdate(DATE_ATOM, $accessExpires),
            'user' => $member->toApiUser(),
        ];
    }

    public static function memberForAccessToken(string $token): ?Member
    {
        /** @var ApiToken|null $row */
        $row = ApiToken::get()->filter('AccessHash', hash('sha256', $token))->first();
        if (!$row || $row->AccessExpires < time()) {
            return null;
        }
        $member = $row->Member();
        return $member->exists() ? $member : null;
    }

    /** Rotates: the presented refresh token is spent and a new pair is returned. */
    public static function refresh(string $refreshToken, ?HTTPRequest $request = null): ?array
    {
        /** @var ApiToken|null $row */
        $row = ApiToken::get()->filter('RefreshHash', hash('sha256', $refreshToken))->first();
        if (!$row || $row->RefreshExpires < time()) {
            return null;
        }
        $member = $row->Member();
        if (!$member->exists()) {
            return null;
        }
        $row->delete();
        return self::start($member, $request);
    }

    public static function revokeAccessToken(string $token): void
    {
        ApiToken::get()->filter('AccessHash', hash('sha256', $token))->removeAll();
    }

    /** Signs a user out everywhere, optionally keeping the session making the request. */
    public static function revokeAll(Member $member, ?string $keepAccessToken = null): void
    {
        $tokens = ApiToken::get()->filter('MemberID', $member->ID);
        if ($keepAccessToken) {
            $tokens = $tokens->exclude('AccessHash', hash('sha256', $keepAccessToken));
        }
        $tokens->removeAll();
    }

    /** True when `$url` is on one of the configured client origins. */
    public static function isAllowedRedirect(string $url): bool
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            // App deep links such as myapp://reset-password carry only a scheme and host.
            return false;
        }
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $allowed = (array) self::config()->get('allowed_redirect_origins');
        $fromEnv = trim((string) \SilverStripe\Core\Environment::getEnv('APP_REDIRECT_ORIGINS'));
        if ($fromEnv !== '') {
            $allowed = array_merge($allowed, array_map('trim', explode(',', $fromEnv)));
        }
        return in_array($origin, $allowed, true);
    }

    private static function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
