<?php

namespace Pterodactyl\Http\Controllers\Api\Client;

use Exception;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Nyholm\Psr7\Factory\Psr17Factory;
use Illuminate\Contracts\Cache\Repository;
use Psr\Http\Message\ServerRequestInterface;
use Pterodactyl\Exceptions\DisplayException;
use Webauthn\PublicKeyCredentialCreationOptions;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Pterodactyl\Repositories\SecurityKeys\WebauthnServerRepository;
use Pterodactyl\Http\Requests\Api\Client\Account\RegisterWebauthnTokenRequest;
use Pterodactyl\Repositories\SecurityKeys\PublicKeyCredentialSourceRepository;
use Pterodactyl\Services\Users\SecurityKeys\CreatePublicKeyCredentialsService;

class SecurityKeyController extends ClientApiController
{
    protected CreatePublicKeyCredentialsService $createPublicKeyCredentials;

    protected Repository $cache;

    protected WebauthnServerRepository $webauthnServerRepository;

    public function __construct(
        Repository $cache,
        WebauthnServerRepository $webauthnServerRepository,
        CreatePublicKeyCredentialsService $createPublicKeyCredentials
    ) {
        parent::__construct();

        $this->cache = $cache;
        $this->webauthnServerRepository = $webauthnServerRepository;
        $this->createPublicKeyCredentials = $createPublicKeyCredentials;
    }

    /**
     * Returns all of the hardware security keys (WebAuthn) that exists for a user.
     */
    public function index(Request $request): array
    {
        return [];
    }

    /**
     * Returns the data necessary for creating a new hardware security key for the
     * user.
     */
    public function create(Request $request): JsonResponse
    {
        $tokenId = Str::random(64);
        $credentials = $this->createPublicKeyCredentials->handle($request->user(), $request->get('display_name'));

        $this->cache->put(
            "register-security-key:$tokenId",
            serialize($credentials),
            CarbonImmutable::now()->addMinutes(10)
        );

        return new JsonResponse([
            'data' => [
                'token_id' => $tokenId,
                'credentials' => $credentials->jsonSerialize(),
            ],
        ]);
    }

    /**
     * Stores a new key for a user account.
     *
     * @throws \Exception
     * @throws \Pterodactyl\Exceptions\DisplayException
     */
    public function store(RegisterWebauthnTokenRequest $request): JsonResponse
    {
        $stored = $this->cache->pull("register-security-key:{$request->input('token_id')}");

        if (!$stored) {
            throw new DisplayException('Could not register security key: no data present in session, please try your request again.');
        }

        $credentials = unserialize($stored);
        if (!$credentials instanceof PublicKeyCredentialCreationOptions) {
            throw new Exception(sprintf('Unexpected security key data pulled from cache: expected "%s" but got "%s".', PublicKeyCredentialCreationOptions::class, get_class($credentials)));
        }

        $server = $this->webauthnServerRepository->getServer($request->user());

        $source = $server->loadAndCheckAttestationResponse(
            json_encode($request->input('registration')),
            $credentials,
            $this->getServerRequest($request),
        );

        // Unfortunately this repository interface doesn't define a response — it is explicitly
        // void — so we need to just query the database immediately after this to pull the information
        // we just stored to return to the caller.
        PublicKeyCredentialSourceRepository::factory($request->user())->saveCredentialSource($source);

        $created = $request->user()->securityKeys()
            ->where('public_key_id', base64_encode($source->getPublicKeyCredentialId()))
            ->first();

        $created->update(['name' => $request->input('name')]);

        return new JsonResponse([
            'data' => $created->toArray(),
        ]);
    }

    /**
     * Removes a WebAuthn key from a user's account.
     */
    public function delete(Request $request, int $webauthnKeyId): JsonResponse
    {
        return new JsonResponse([]);
    }

    protected function getServerRequest(Request $request): ServerRequestInterface
    {
        $factory = new Psr17Factory();

        $httpFactory = new PsrHttpFactory($factory, $factory, $factory, $factory);

        return $httpFactory->createRequest($request);
    }
}