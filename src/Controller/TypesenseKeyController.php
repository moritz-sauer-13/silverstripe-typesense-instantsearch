<?php

namespace MoritzSauer\Instantsearch\Controller;

use MoritzSauer\Instantsearch\Services\TypesenseScopedKeyService;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Security\Security;
use Throwable;

class TypesenseKeyController extends Controller
{
    private static $allowed_actions = [
        'index' => true,
    ];

    public function index(HTTPRequest $request): HTTPResponse
    {
        /** @var TypesenseScopedKeyService $service */
        $service = Injector::inst()->get(TypesenseScopedKeyService::class);

        try {
            $payload = $service->getScopedKeyPayload(Security::getCurrentUser());

            $response = HTTPResponse::create(json_encode([
                'key' => $payload['key'],
                'expires_at' => $payload['expires_at'],
            ], JSON_THROW_ON_ERROR));
            $response->addHeader('Content-Type', 'application/json; charset=utf-8');
            $response->addHeader('Cache-Control', 'no-store, private, max-age=0');

            return $response;
        } catch (Throwable $exception) {
            $response = HTTPResponse::create(json_encode([
                'error' => 'Unable to generate scoped key',
            ], JSON_THROW_ON_ERROR), 500);
            $response->addHeader('Content-Type', 'application/json; charset=utf-8');
            $response->addHeader('Cache-Control', 'no-store, private, max-age=0');

            return $response;
        }
    }
}
