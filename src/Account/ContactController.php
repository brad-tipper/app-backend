<?php

namespace BradTipper\AppBackend\Account;

use BradTipper\AppBackend\Api\ApiController;
use BradTipper\AppBackend\Api\ApiException;
use BradTipper\AppBackend\Api\Input;
use BradTipper\AppBackend\Model\ContactSubmission;
use BradTipper\AppBackend\OpenApi\Spec;
use BradTipper\AppBackend\Support\Turnstile;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;

class ContactController extends ApiController
{
    public static function describe(Spec $spec): void
    {
        $s = static fn () => Spec::string();
        $spec->schema('ContactRequest', Spec::object([
            'name' => $s(),
            'email' => Spec::string('email'),
            'subject' => $s(),
            'message' => $s(),
            'pageUrl' => $s(),
            'gdprConsent' => Spec::boolean(),
            'turnstileToken' => $s(),
        ], ['name', 'email', 'subject', 'message', 'pageUrl', 'gdprConsent']));
        $spec->operation('post', '/v1/contact', 'submitContact', [
            'tags' => ['Support'],
            'summary' => 'Send a contact message',
            'body' => Spec::ref('ContactRequest'),
            'responses' => [201 => Spec::ref('Ok')],
        ]);
    }

    protected function dispatch(HTTPRequest $request): HTTPResponse
    {
        $this->requireMethod($request, 'POST');
        $ip = $this->clientIp($request);
        $this->limit("contact:{$ip}", 10, 3600);

        $in = Input::fromRequest($request);
        $name = $in->string('name', true, 255);
        $email = $in->email('email');
        $subject = $in->string('subject', true, 255);
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

        ContactSubmission::create([
            'Name' => $name,
            'Email' => $email,
            'Subject' => $subject,
            'Message' => $message,
            'PageUrl' => $pageUrl,
            'GdprConsentGiven' => true,
            'GdprConsentAt' => gmdate('Y-m-d H:i:s'),
            'IpAddress' => $ip === 'unknown' ? '' : $ip,
            'UserAgent' => mb_substr((string) $request->getHeader('User-Agent'), 0, 512),
        ])->write();
        return $this->json(['ok' => true], 201);
    }
}
