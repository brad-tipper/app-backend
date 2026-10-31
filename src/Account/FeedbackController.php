<?php

namespace BradTipper\AppBackend\Account;

use BradTipper\AppBackend\Api\ApiController;
use BradTipper\AppBackend\Api\ApiException;
use BradTipper\AppBackend\Api\Input;
use BradTipper\AppBackend\Model\FeedbackSubmission;
use BradTipper\AppBackend\OpenApi\Spec;
use BradTipper\AppBackend\Support\Turnstile;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;

/** Feedback from signed-in users or visitors (a bearer token is optional). */
class FeedbackController extends ApiController
{
    private const LABELS = ['bug' => 'Bug', 'feature' => 'Feature request', 'praise' => 'Praise'];

    public static function describe(Spec $spec): void
    {
        $s = static fn () => Spec::string();
        $spec->schema('FeedbackRequest', Spec::object([
            'name' => $s(),
            'email' => Spec::string('email'),
            'type' => Spec::enum(['bug', 'feature', 'praise']),
            'subject' => $s(),
            'message' => $s(),
            'pageUrl' => $s(),
            'gdprConsent' => Spec::boolean(),
            'turnstileToken' => $s(),
        ], ['name', 'email', 'type', 'message', 'pageUrl', 'gdprConsent']));
        $spec->schema('FeedbackResult', Spec::object(['ok' => Spec::boolean(), 'emailSent' => Spec::boolean()]));
        $spec->operation('post', '/v1/feedback', 'submitFeedback', [
            'tags' => ['Support'],
            'summary' => 'Send feedback',
            'body' => Spec::ref('FeedbackRequest'),
            'responses' => [201 => Spec::ref('FeedbackResult')],
        ]);
    }

    protected function dispatch(HTTPRequest $request): HTTPResponse
    {
        $this->requireMethod($request, 'POST');
        $ip = $this->clientIp($request);
        $this->limit("feedback:{$ip}", 20, 3600);

        $in = Input::fromRequest($request);
        $name = $in->string('name', true, 255);
        $email = $in->email('email');
        $type = $in->enum('type', array_keys(self::LABELS));
        $detail = $in->string('subject', false, 200);
        $message = $in->string('message', true, 10000);
        $pageUrl = $in->string('pageUrl', true, 2048);
        $consent = $in->bool('gdprConsent', true);
        $token = $in->string('turnstileToken', false, 4096);
        $in->assertValid();
        if ($consent !== true) {
            $in->fail('gdprConsent', 'Consent is required.');
            $in->assertValid();
        }
        if (!Turnstile::verify($token, $ip)) {
            throw ApiException::badRequest('Captcha verification failed.', 'captcha_failed');
        }

        $label = self::LABELS[$type];
        FeedbackSubmission::create([
            'MemberID' => $this->currentMember($request)?->ID ?? 0,
            'Name' => $name,
            'Email' => $email,
            'Subject' => $detail ? "[{$label}] {$detail}" : "[{$label}] from {$name}",
            'Message' => $message,
            'PageUrl' => $pageUrl,
            'GdprConsentGiven' => true,
            'GdprConsentAt' => gmdate('Y-m-d H:i:s'),
            'IpAddress' => $ip === 'unknown' ? '' : $ip,
            'UserAgent' => mb_substr((string) $request->getHeader('User-Agent'), 0, 512),
        ])->write();
        return $this->json(['ok' => true, 'emailSent' => false], 201);
    }
}
