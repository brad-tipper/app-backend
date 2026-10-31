<?php

namespace BradTipper\AppBackend\Account;

use BradTipper\AppBackend\Api\ApiController;
use BradTipper\AppBackend\Api\ApiException;
use BradTipper\AppBackend\Api\Uuid;
use BradTipper\AppBackend\Auth\ApiMemberExtension;
use BradTipper\AppBackend\Model\RevenueCatEvent;
use BradTipper\AppBackend\OpenApi\Spec;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;

/**
 * RevenueCat is the single source of truth for access: this webhook is the only
 * code that may change a user's subscription state.
 */
class RevenueCatWebhookController extends ApiController
{
    use Configurable;

    private static string $environment = 'PRODUCTION';

    /** @var string[] */
    private static array $entitlement_ids = ['pro'];

    /** @var string[] */
    private static array $product_ids = [];

    private const ACTIVE = ['INITIAL_PURCHASE', 'RENEWAL', 'UNCANCELLATION', 'PRODUCT_CHANGE', 'NON_RENEWING_PURCHASE'];
    private const EXPIRED = ['EXPIRATION'];

    public static function describe(Spec $spec): void
    {
        $spec->schema('RevenueCatWebhookRequest', ['type' => 'object', 'properties' => ['event' => Spec::map()], 'required' => ['event']]);
        $spec->schema('RevenueCatWebhookResult', Spec::object([
            'ok' => Spec::boolean(),
            'processed' => Spec::boolean(),
            'outcome' => Spec::string(),
            'subscriptionStatus' => Spec::string(),
        ], ['ok', 'processed']));
        $spec->operation('post', '/v1/webhooks/revenuecat', 'revenueCatWebhook', [
            'tags' => ['Webhooks'],
            'summary' => 'RevenueCat server-to-server event',
            'body' => Spec::ref('RevenueCatWebhookRequest'),
            'responses' => [200 => Spec::ref('RevenueCatWebhookResult')],
        ]);
    }

    protected function dispatch(HTTPRequest $request): array
    {
        $this->requireMethod($request, 'POST');

        $secret = trim((string) Environment::getEnv('REVENUECAT_WEBHOOK_SECRET'));
        $supplied = (string) $this->bearerToken($request);
        if ($secret === '' || !hash_equals($secret, $supplied)) {
            throw ApiException::unauthorized('Unauthorized.');
        }

        $payload = json_decode((string) $request->getBody(), true);
        $event = is_array($payload) ? ($payload['event'] ?? null) : null;
        foreach (['id', 'app_user_id', 'type', 'event_timestamp_ms', 'environment'] as $field) {
            if (!is_array($event) || empty($event[$field])) {
                throw ApiException::badRequest('Missing required RevenueCat event fields.', 'invalid_event');
            }
        }
        if (!Uuid::isValid((string) $event['app_user_id'])) {
            return ['ok' => true, 'processed' => false, 'outcome' => 'ignored_non_user_id'];
        }

        $eventTs = intdiv((int) $event['event_timestamp_ms'], 1000);
        $matches = $this->matchesPro($event);
        $sameEnvironment = $event['environment'] === self::config()->get('environment');
        $status = null;
        if ($sameEnvironment && in_array($event['type'], self::ACTIVE, true) && $matches) {
            $status = 'active';
        } elseif ($sameEnvironment && in_array($event['type'], self::EXPIRED, true)) {
            $status = 'unsubscribed';
        }

        $existing = RevenueCatEvent::get()->filter('EventId', (string) $event['id'])->exists();
        if ($existing) {
            return ['ok' => true, 'processed' => false, 'outcome' => 'ignored_duplicate'];
        }

        if ($status) {
            $member = ApiMemberExtension::findByUuid((string) $event['app_user_id']);
            if ($member) {
                $member->applySubscriptionEvent($status, $eventTs);
            }
        }

        RevenueCatEvent::create([
            'EventId' => (string) $event['id'],
            'AppUserUuid' => strtolower((string) $event['app_user_id']),
            'Type' => (string) $event['type'],
            'EventAt' => gmdate('Y-m-d H:i:s', $eventTs),
            'Environment' => (string) $event['environment'],
            'ProductId' => (string) ($event['product_id'] ?? ''),
            'EntitlementIds' => json_encode($event['entitlement_ids'] ?? []),
            'Outcome' => $status ? 'applied' : 'ignored_unmatched',
            'AppliedTier' => $status === 'active' ? 'pro' : ($status === 'unsubscribed' ? 'free' : ''),
        ])->write();

        return [
            'ok' => true,
            'processed' => $status !== null,
            'subscriptionStatus' => $status ?? 'unchanged',
        ];
    }

    /** @param array<string, mixed> $event */
    private function matchesPro(array $event): bool
    {
        $entitlements = (array) (self::config()->get('entitlement_ids') ?? []);
        $products = (array) self::config()->get('product_ids');
        $held = (array) ($event['entitlement_ids'] ?? []);
        return array_intersect($entitlements, $held) !== []
            || in_array($event['product_id'] ?? null, $products, true);
    }
}
