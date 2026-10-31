<?php

namespace BradTipper\AppBackend\Support;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Factory;
use Symfony\Component\Mailer\Transport;

/**
 * Builds the mail transport from the project's `smtp.yml` (host, port, username)
 * plus the SMTP_PASSWORD secret. A MAILER_DSN environment variable, if set,
 * still wins (handy for a throwaway local mail catcher).
 */
class SmtpTransportFactory implements Factory
{
    use Configurable;

    private static string $scheme = 'smtp';

    private static string $host = '';

    private static int $port = 587;

    private static string $username = '';

    public function create(string $service, array $params = []): object
    {
        return Transport::fromDsn(self::dsn() ?? $params['dsn'], $params['dispatcher']);
    }

    public static function dsn(): ?string
    {
        $override = trim((string) Environment::getEnv('MAILER_DSN'));
        if ($override !== '') {
            return $override;
        }
        $config = self::config();
        $host = (string) $config->get('host');
        if ($host === '') {
            return null;
        }
        $username = (string) $config->get('username');
        $password = (string) Environment::getEnv('SMTP_PASSWORD');
        $auth = $username !== '' ? rawurlencode($username) . ($password !== '' ? ':' . rawurlencode($password) : '') . '@' : '';
        return sprintf('%s://%s%s:%d', $config->get('scheme'), $auth, $host, (int) $config->get('port'));
    }
}
