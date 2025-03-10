<?php

/*
 * Copyright 2005 - 2023 Centreon (https://www.centreon.com/)
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * https://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * For more information : contact@centreon.com
 *
 */

declare(strict_types=1);

namespace Core\Security\Authentication\Domain\Provider;

use Centreon;
use Centreon\Domain\Contact\Interfaces\ContactInterface;
use Centreon\Domain\Contact\Interfaces\ContactServiceInterface;
use Centreon\Domain\Log\LoggerTrait;
use CentreonUserLog;
use Core\Application\Configuration\User\Repository\WriteUserRepositoryInterface;
use Core\Contact\Domain\Model\ContactGroup;
use Core\Domain\Configuration\User\Model\NewUser;
use Core\Security\Authentication\Domain\Exception\AclConditionsException;
use Core\Security\Authentication\Domain\Exception\AuthenticationConditionsException;
use Core\Security\Authentication\Domain\Exception\SSOAuthenticationException;
use Core\Security\Authentication\Domain\Model\AuthenticationTokens;
use Core\Security\Authentication\Domain\Model\NewProviderToken;
use Core\Security\Authentication\Domain\Model\ProviderToken;
use Core\Security\ProviderConfiguration\Domain\Exception\ConfigurationException;
use Core\Security\ProviderConfiguration\Domain\Model\Configuration;
use Core\Security\ProviderConfiguration\Domain\OpenId\Model\CustomConfiguration;
use Core\Security\ProviderConfiguration\Domain\SecurityAccess\AttributePath\AttributePathFetcher;
use Core\Security\ProviderConfiguration\Domain\SecurityAccess\Conditions;
use Core\Security\ProviderConfiguration\Domain\SecurityAccess\GroupsMapping as GroupsMappingSecurityAccess;
use Core\Security\ProviderConfiguration\Domain\SecurityAccess\RolesMapping;
use DateInterval;
use Exception;
use Pimple\Container;
use Security\Domain\Authentication\Interfaces\OpenIdProviderInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

class OpenIdProvider implements OpenIdProviderInterface
{
    use LoggerTrait;
    public const NAME = 'openid';

    /** @var Configuration */
    private Configuration $configuration;

    /** @var NewProviderToken */
    private NewProviderToken $providerToken;

    /** @var NewProviderToken|null */
    private ?NewProviderToken $refreshToken = null;

    /** @var array<string,mixed> */
    private array $userInformations = [];

    /** @var string */
    private string $username;

    /** @var Centreon */
    private Centreon $legacySession;

    /** @var CentreonUserLog */
    private CentreonUserLog $centreonLog;

    /**
     * Array of information store in id_token JWT Payload.
     *
     * @var array<string,mixed>
     */
    private array $idTokenPayload = [];

    /**
     * Content of the connexion token response.
     *
     * @var array<string,mixed>
     */
    private array $connectionTokenResponseContent = [];

    /** @var string[] */
    private array $aclConditionsMatches = [];

    /**
     * @param HttpClientInterface $client
     * @param UrlGeneratorInterface $router
     * @param ContactServiceInterface $contactService
     * @param Container $dependencyInjector
     * @param WriteUserRepositoryInterface $userRepository
     * @param Conditions $conditions
     * @param RolesMapping $rolesMapping
     * @param GroupsMappingSecurityAccess $groupsMapping
     * @param AttributePathFetcher $attributePathFetcher
     */
    public function __construct(
        private HttpClientInterface $client,
        private UrlGeneratorInterface $router,
        private ContactServiceInterface $contactService,
        private Container $dependencyInjector,
        private WriteUserRepositoryInterface $userRepository,
        private readonly Conditions $conditions,
        private readonly RolesMapping $rolesMapping,
        private readonly GroupsMappingSecurityAccess $groupsMapping,
        private readonly AttributePathFetcher $attributePathFetcher
    ) {
        $pearDB = $this->dependencyInjector['configuration_db'];
        $this->centreonLog = new CentreonUserLog(-1, $pearDB);
    }

    /**
     * @inheritDoc
     */
    public function getConfiguration(): Configuration
    {
        return $this->configuration;
    }

    /**
     * @inheritDoc
     */
    public function getProviderToken(): NewProviderToken
    {
        return $this->providerToken;
    }

    /**
     * @inheritDoc
     */
    public function getProviderRefreshToken(): ?NewProviderToken
    {
        return $this->refreshToken;
    }

    /**
     * @inheritDoc
     */
    public function setConfiguration(Configuration $configuration): void
    {
        $this->configuration = $configuration;
    }

    /**
     * @inheritDoc
     */
    public function canCreateUser(): bool
    {
        /** @var CustomConfiguration $customConfiguration */
        $customConfiguration = $this->configuration->getCustomConfiguration();

        return $customConfiguration->isAutoImportEnabled();
    }

    /**
     * {@inheritDoc}
     *
     * @throws SSOAuthenticationException
     */
    public function createUser(): void
    {
        /** @var CustomConfiguration $customConfiguration */
        $customConfiguration = $this->configuration->getCustomConfiguration();
        $this->info('Auto import starting...', [
            'user' => $this->username,
        ]);
        $this->validateAutoImportAttributesOrFail();

        $user = new NewUser(
            $this->username,
            $this->userInformations[$customConfiguration->getUserNameBindAttribute()],
            $this->userInformations[$customConfiguration->getEmailBindAttribute()],
        );
        if ($user->canReachFrontend()) {
            $user->setCanReachRealtimeApi(true);
        }
        $user->setContactTemplate($customConfiguration->getContactTemplate());
        $this->userRepository->create($user);
        $this->info('Auto import complete', [
            'user_alias' => $this->username,
            'user_fullname' => $this->userInformations[$customConfiguration->getUserNameBindAttribute()],
            'user_email' => $this->userInformations[$customConfiguration->getEmailBindAttribute()],
        ]);
    }

    /**
     * @inheritDoc
     */
    public function getLegacySession(): Centreon
    {
        return $this->legacySession;
    }

    /**
     * @inheritDoc
     */
    public function setLegacySession(Centreon $legacySession): void
    {
        $this->legacySession = $legacySession;
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return $this->configuration->getName();
    }

    /**
     * @inheritDoc
     */
    public function canRefreshToken(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     *
     * @param string|null $authorizationCode
     * @param string $clientIp
     *
     * @throws AclConditionsException
     * @throws AuthenticationConditionsException
     * @throws ConfigurationException
     * @throws SSOAuthenticationException
     */
    public function authenticateOrFail(?string $authorizationCode, string $clientIp): string
    {
        /** @var CustomConfiguration $customConfiguration */
        $customConfiguration = $this->configuration->getCustomConfiguration();

        $this->info('Start authenticating user...', [
            'provider' => $this->configuration->getName(),
        ]);
        if (empty($authorizationCode)) {
            $this->error(
                'No authorization code returned from external provider',
                [
                    'provider' => $this->configuration->getName(),
                ]
            );

            throw SSOAuthenticationException::noAuthorizationCode($this->configuration->getName());
        }

        if ($customConfiguration->getTokenEndpoint() === null) {
            throw ConfigurationException::missingTokenEndpoint();
        }
        if (
            $customConfiguration->getIntrospectionTokenEndpoint() === null
            && $customConfiguration->getUserInformationEndpoint() === null
        ) {
            throw ConfigurationException::missingInformationEndpoint();
        }

        $this->sendRequestForConnectionTokenOrFail($authorizationCode);
        $this->createAuthenticationTokens();
        if (array_key_exists('id_token', $this->connectionTokenResponseContent)) {
            $this->idTokenPayload = $this->extractTokenPayload($this->connectionTokenResponseContent['id_token']);
        }
        $this->verifyThatClientIsAllowedToConnectOrFail($clientIp);
        if ($this->providerToken->isExpired() && $this->refreshToken?->isExpired()) {
            throw SSOAuthenticationException::tokensExpired($this->configuration->getName());
        }
        if ($customConfiguration->getIntrospectionTokenEndpoint() !== null) {
            $this->getUserInformationFromIntrospectionEndpoint();
        }

        return $this->username = $this->getUsernameFromLoginClaim();
    }

    /**
     * @inheritDoc
     */
    public function getUser(): ?ContactInterface
    {
        $this->info('Searching user : ' . $this->username);

        return $this->contactService->findByName($this->username)
            ?? $this->contactService->findByEmail($this->username);
    }

    /**
     * @inheritDoc
     */
    public function refreshToken(AuthenticationTokens $authenticationTokens): AuthenticationTokens
    {
        /** @var CustomConfiguration $customConfiguration */
        $customConfiguration = $this->configuration->getCustomConfiguration();

        if ($authenticationTokens->getProviderRefreshToken() === null) {
            throw SSOAuthenticationException::noRefreshToken();
        }
        $this->info(
            'Refreshing token using refresh token',
            [
                'refresh_token' => mb_substr($authenticationTokens->getProviderRefreshToken()->getToken(), -10),
            ]
        );
        // Define parameters for the request
        $data = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $authenticationTokens->getProviderRefreshToken()->getToken(),
            'scope' => ! empty($customConfiguration->getConnectionScopes())
                ? implode(' ', $customConfiguration->getConnectionScopes())
                : null,
        ];

        $response = $this->sendRequestToTokenEndpoint($data);

        // Get the status code and throw an Exception if not a 200
        $statusCode = $response->getStatusCode();
        if ($statusCode !== Response::HTTP_OK) {
            $this->logErrorForInvalidStatusCode($statusCode, Response::HTTP_OK);
            $this->logExceptionInLoginLogFile(
                'Unable to get Refresh Token Information: %s, message: %s',
                SSOAuthenticationException::requestForRefreshTokenFail()
            );

            throw SSOAuthenticationException::requestForRefreshTokenFail();
        }
        $content = json_decode($response->getContent(false), true) ?: [];
        if (empty($content) || array_key_exists('error', $content)) {
            $this->logErrorInLoginLogFile('Refresh Token Info:', $content);
            $this->logErrorFromExternalProvider($content);

            throw SSOAuthenticationException::errorFromExternalProvider($this->configuration->getName());
        }
        $this->logAuthenticationDebug('Token Access Information:', $content);
        $creationDate = new \DateTimeImmutable();
        $providerTokenExpiration
            = (new \DateTimeImmutable())->add(new DateInterval('PT' . $content['expires_in'] . 'S'));

        /** @var ProviderToken $providerToken */
        $providerToken = $authenticationTokens->getProviderToken();
        $this->providerToken = new ProviderToken(
            $providerToken->getId(),
            $content['access_token'],
            $creationDate,
            $providerTokenExpiration
        );
        if (array_key_exists('refresh_token', $content)) {
            $expirationDelay = $content['expires_in'] + 3600;
            if (array_key_exists('refresh_expires_in', $content)) {
                $expirationDelay = $content['refresh_expires_in'];
            }
            $refreshTokenExpiration = (new \DateTimeImmutable())
                ->add(new DateInterval('PT' . $expirationDelay . 'S'));
            $this->refreshToken = new NewProviderToken(
                $content['refresh_token'],
                $creationDate,
                $refreshTokenExpiration
            );
        }

        return new AuthenticationTokens(
            $authenticationTokens->getUserId(),
            $authenticationTokens->getConfigurationProviderId(),
            $authenticationTokens->getSessionToken(),
            $this->providerToken,
            $this->refreshToken
        );
    }

    /**
     * @inheritDoc
     */
    public function getUserInformation(): array
    {
        return $this->userInformations;
    }

    /**
     * @inheritDoc
     */
    public function getIdTokenPayload(): array
    {
        return $this->idTokenPayload;
    }

    /**
     * @inheritDoc
     */
    public function getAclConditionsMatches(): array
    {
        return $this->aclConditionsMatches;
    }

    /**
     * @return ContactGroup[]
     */
    public function getUserContactGroups(): array
    {
        return $this->groupsMapping->getUserContactGroups();
    }

    /**
     * Extract Payload from JWT token.
     *
     * @param string $token
     *
     * @throws SSOAuthenticationException
     *
     * @return array<string,mixed>
     */
    private function extractTokenPayload(string $token): array
    {
        try {
            $tokenParts = explode('.', $token);

            return json_decode($this->urlSafeTokenDecode($tokenParts[1]), true);
        } catch (Throwable $ex) {
            $this->error(
                SSOAuthenticationException::unableToDecodeIdToken()->getMessage(),
                ['trace' => $ex->getTraceAsString()]
            );

            throw SSOAuthenticationException::unableToDecodeIdToken();
        }
    }

    /**
     * Get Connection Token from OpenId Provider.
     *
     * @param string $authorizationCode
     *
     * @throws SSOAuthenticationException
     */
    private function sendRequestForConnectionTokenOrFail(string $authorizationCode): void
    {
        $this->info('Send request to external provider for connection token...');

        // Define parameters for the request
        $redirectUri = $this->router->generate(
            'centreon_security_authentication_login_openid',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
        $data = [
            'grant_type' => 'authorization_code',
            'code' => $authorizationCode,
            'redirect_uri' => $redirectUri,
        ];

        $response = $this->sendRequestToTokenEndpoint($data);

        // Get the status code and throw an Exception if not a 200
        $statusCode = $response->getStatusCode();
        if ($statusCode !== Response::HTTP_OK) {
            $this->logErrorForInvalidStatusCode($statusCode, Response::HTTP_OK);
            $this->logExceptionInLoginLogFile(
                'Unable to get Token Access Information: %s, message: %s',
                SSOAuthenticationException::requestForConnectionTokenFail()
            );

            throw SSOAuthenticationException::requestForConnectionTokenFail();
        }
        $content = json_decode($response->getContent(false), true) ?: [];
        if (empty($content) || array_key_exists('error', $content)) {
            $this->logErrorInLoginLogFile('Connection Token Info: ', $content);
            $this->logErrorFromExternalProvider($content);

            throw SSOAuthenticationException::errorFromExternalProvider($this->configuration->getName());
        }
        $this->logAuthenticationDebug('Token Access Information:', $content);
        $this->connectionTokenResponseContent = $content;
    }

    /**
     * Create Authentication Tokens.
     */
    private function createAuthenticationTokens(): void
    {
        $creationDate = new \DateTimeImmutable();
        $expirationDelay = array_key_exists('expires_in', $this->connectionTokenResponseContent)
            ? $this->connectionTokenResponseContent['expires_in']
            : 3600;
        $providerTokenExpiration = (new \DateTimeImmutable())->add(
            new DateInterval('PT' . $expirationDelay . 'S')
        );
        $this->providerToken = new NewProviderToken(
            $this->connectionTokenResponseContent['access_token'],
            $creationDate,
            $providerTokenExpiration
        );
        if (array_key_exists('refresh_token', $this->connectionTokenResponseContent)) {
            $expirationDelay = $this->connectionTokenResponseContent['expires_in'] + 3600;
            if (array_key_exists('refresh_expires_in', $this->connectionTokenResponseContent)) {
                $expirationDelay = $this->connectionTokenResponseContent['refresh_expires_in'];
            }
            $refreshTokenExpiration = (new \DateTimeImmutable())
                ->add(new DateInterval('PT' . $expirationDelay . 'S'));
            $this->refreshToken = new NewProviderToken(
                $this->connectionTokenResponseContent['refresh_token'],
                $creationDate,
                $refreshTokenExpiration
            );
        }
    }

    /**
     * Send a request to get introspection token information.
     *
     * @throws SSOAuthenticationException
     */
    private function getUserInformationFromIntrospectionEndpoint(): void
    {
        $this->userInformations = $this->sendRequestForIntrospectionEndpoint();
    }

    /**
     * Send a request to get introspection token information.
     *
     * @throws SSOAuthenticationException
     *
     * @return array<string,mixed>
     */
    private function sendRequestForIntrospectionEndpoint(): array
    {
        $this->info('Sending request for introspection token information');

        /** @var CustomConfiguration $customConfiguration */
        $customConfiguration = $this->configuration->getCustomConfiguration();

        // Define parameters for the request
        $data = [
            'token' => $this->providerToken->getToken(),
            'client_id' => $customConfiguration->getClientId(),
            'client_secret' => $customConfiguration->getClientSecret(),
        ];
        $headers = [
            'Authorization' => 'Bearer ' . trim($this->providerToken->getToken()),
        ];
        try {
            $response = $this->client->request(
                'POST',
                $customConfiguration->getBaseUrl() . '/'
                . ltrim($customConfiguration->getIntrospectionTokenEndpoint() ?? '', '/'),
                [
                    'headers' => $headers,
                    'body' => $data,
                    'verify_peer' => $customConfiguration->verifyPeer(),
                ]
            );
        } catch (Exception $exception) {
            $this->logExceptionInLoginLogFile('Unable to get Introspection Information: %s, message: %s', $exception);
            $this->error(
                sprintf(
                    '[Error] Unable to get Introspection Token Information:, message: %s',
                    $exception->getMessage()
                )
            );

            throw SSOAuthenticationException::requestForIntrospectionTokenFail();
        }

        $statusCode = $response->getStatusCode();
        if ($statusCode !== Response::HTTP_OK) {
            $this->logErrorForInvalidStatusCode($statusCode, Response::HTTP_OK);
            $this->logExceptionInLoginLogFile(
                'Unable to get Introspection Information: %s, message: %s',
                SSOAuthenticationException::requestForIntrospectionTokenFail()
            );

            throw SSOAuthenticationException::requestForIntrospectionTokenFail();
        }
        $content = json_decode($response->getContent(false), true) ?: [];
        if (empty($content) || array_key_exists('error', $content)) {
            $this->logErrorInLoginLogFile('Introspection Token Info: ', $content);
            $this->logErrorFromExternalProvider($content);

            throw SSOAuthenticationException::errorFromExternalProvider($this->configuration->getName());
        }

        $this->logAuthenticationInfo('Token Introspection Information: ', $content);

        return $content;
    }

    /**
     * Send a request to get user information.
     *
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws SSOAuthenticationException
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    private function getUserInformationFromUserInfoEndpoint(): void
    {
        $this->userInformations = $this->sendRequestForUserInformationEndpoint();
    }

    /**
     * Send a request to get user information.
     *
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws SSOAuthenticationException
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     *
     * @return array<string,mixed>
     */
    private function sendRequestForUserInformationEndpoint(): array
    {
        $this->info('Sending Request for User Information...');

        $headers = [
            'Authorization' => 'Bearer ' . trim($this->providerToken->getToken()),
        ];
        /** @var CustomConfiguration $customConfiguration */
        $customConfiguration = $this->configuration->getCustomConfiguration();
        $url = str_starts_with($customConfiguration->getUserInformationEndpoint() ?? '', '/')
            ? $customConfiguration->getBaseUrl() . $customConfiguration->getUserInformationEndpoint()
            : $customConfiguration->getUserInformationEndpoint() ?? '';
        try {
            $response = $this->client->request(
                'GET',
                $url,
                [
                    'headers' => $headers,
                    'verify_peer' => $customConfiguration->verifyPeer(),
                ]
            );
        } catch (Exception) {
            throw SSOAuthenticationException::requestForUserInformationFail();
        }

        $statusCode = $response->getStatusCode();
        if ($statusCode !== Response::HTTP_OK) {
            $this->logErrorForInvalidStatusCode($statusCode, Response::HTTP_OK);
            $this->logExceptionInLoginLogFile(
                'Unable to get User Information: %s, message: %s',
                SSOAuthenticationException::requestForUserInformationFail()
            );

            throw SSOAuthenticationException::requestForUserInformationFail();
        }
        $content = json_decode($response->getContent(false), true) ?: [];
        if (empty($content) || array_key_exists('error', $content)) {
            $this->logErrorInLoginLogFile('User Information Info: ', $content);
            $this->logErrorFromExternalProvider($content);

            throw SSOAuthenticationException::errorFromExternalProvider($this->configuration->getName());
        }

        $this->logAuthenticationDebug('User Information: ', $content);

        return $content;
    }

    /**
     * Validate that Client IP is allowed to connect to external provider.
     *
     * @param string $clientIp
     *
     * @throws AclConditionsException
     * @throws AuthenticationConditionsException
     * @throws SSOAuthenticationException
     */
    private function verifyThatClientIsAllowedToConnectOrFail(string $clientIp): void
    {
        $this->info('Check Client IP against blacklist/whitelist addresses');
        /** @var CustomConfiguration $customConfiguration */
        $customConfiguration = $this->configuration->getCustomConfiguration();
        $authenticationConditions = $customConfiguration->getAuthenticationConditions();
        $rolesMapping = $customConfiguration->getACLConditions();
        $groupsMapping = $customConfiguration->getGroupsMapping();

        foreach ($authenticationConditions->getBlacklistClientAddresses() as $blackListedAddress) {
            if ($blackListedAddress !== '' && preg_match('/' . $blackListedAddress . '/', $clientIp)) {
                $this->error('IP Blacklisted', ['ip' => '...' . mb_substr($clientIp, -5)]);

                throw SSOAuthenticationException::blackListedClient();
            }
        }

        foreach ($authenticationConditions->getTrustedClientAddresses() as $trustedClientAddress) {
            if (
                $trustedClientAddress !== ''
                && preg_match('/' . $trustedClientAddress . '/', $clientIp)
            ) {
                $this->error('IP not  Whitelisted', ['ip' => '...' . mb_substr($clientIp, -5)]);

                throw SSOAuthenticationException::notWhiteListedClient();
            }
        }

        $accessToken = $this->providerToken->getToken();

        $this->conditions->validate(
            $this->configuration,
            $authenticationConditions->isEnabled()
                ? array_merge(
                $this->idTokenPayload,
                array_diff_key(
                    $this->attributePathFetcher->fetch(
                        $accessToken,
                        $this->configuration,
                        $authenticationConditions->getEndpoint() ?? throw new \LogicException()
                    ),
                    $this->idTokenPayload
                )
            )
                : $this->idTokenPayload
        );

        $this->rolesMapping->validate(
            $this->configuration,
            $rolesMapping->isEnabled()
                ? array_merge(
                $this->idTokenPayload,
                array_diff_key(
                    $this->attributePathFetcher->fetch(
                        $accessToken,
                        $this->configuration,
                        $rolesMapping->getEndpoint() ?? throw new \LogicException()
                    ),
                    $this->idTokenPayload
                )
            )
                : $this->idTokenPayload
        );
        $this->aclConditionsMatches = $this->rolesMapping->getConditionMatches();

        $this->groupsMapping->validate(
            $this->configuration,
            $groupsMapping->isEnabled()
                ? array_merge(
                $this->idTokenPayload,
                array_diff_key(
                    $this->attributePathFetcher->fetch(
                        $accessToken,
                        $this->configuration,
                        $groupsMapping->getEndpoint() ?? throw new \LogicException()
                    ),
                    $this->idTokenPayload
                )
            )
                : $this->idTokenPayload
        );
    }

    /**
     * Return username from login claim.
     *
     * @throws SSOAuthenticationException
     *
     * @return string
     */
    private function getUsernameFromLoginClaim(): string
    {
        /** @var CustomConfiguration $customConfiguration */
        $customConfiguration = $this->configuration->getCustomConfiguration();
        $loginClaim = ! empty($customConfiguration->getLoginClaim())
            ? $customConfiguration->getLoginClaim()
            : CustomConfiguration::DEFAULT_LOGIN_CLAIM;
        if (
            ! array_key_exists($loginClaim, $this->userInformations)
            && $customConfiguration->getUserInformationEndpoint() !== null
        ) {
            $this->getUserInformationFromUserInfoEndpoint();
        }
        if (! array_key_exists($loginClaim, $this->userInformations)) {
            $this->centreonLog->insertLog(
                CentreonUserLog::TYPE_LOGIN,
                '[Openid] [Error] Unable to get login from claim: ' . $loginClaim
            );
            $this->error('Login Claim not found', ['login_claim' => $loginClaim]);

            throw SSOAuthenticationException::loginClaimNotFound($this->configuration->getName(), $loginClaim);
        }

        return $this->userInformations[$loginClaim];
    }

    /**
     * Define authentication type based on configuration.
     *
     * @param array<string,mixed> $data
     *
     * @throws SSOAuthenticationException
     *
     * @return ResponseInterface
     */
    private function sendRequestToTokenEndpoint(array $data): ResponseInterface
    {
        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];
        /** @var CustomConfiguration $customConfiguration */
        $customConfiguration = $this->configuration->getCustomConfiguration();
        if ($customConfiguration->getAuthenticationType() === CustomConfiguration::AUTHENTICATION_BASIC) {
            $headers['Authorization'] = 'Basic ' . base64_encode(
                    $customConfiguration->getClientId() . ':' . $customConfiguration->getClientSecret()
                );
        } else {
            $data['client_id'] = $customConfiguration->getClientId();
            $data['client_secret'] = $customConfiguration->getClientSecret();
        }

        $url = str_starts_with($customConfiguration->getTokenEndpoint() ?? '', '/')
            ? $customConfiguration->getBaseUrl() . $customConfiguration->getTokenEndpoint()
            : $customConfiguration->getTokenEndpoint() ?? '';

        // Send the request to IDP
        try {
            return $this->client->request(
                'POST',
                $url,
                [
                    'headers' => $headers,
                    'body' => $data,
                    'verify_peer' => $customConfiguration->verifyPeer(),
                ]
            );
        } catch (Exception $exception) {
            $this->logExceptionInLoginLogFile('Unable to get Token Access Information: %s, message: %s', $exception);
            if (array_key_exists('refresh_token', $data)) {
                $this->error(
                    sprintf('[Error] Unable to get Token Refresh Information:, message: %s', $exception->getMessage())
                );

                throw SSOAuthenticationException::requestForRefreshTokenFail();
            }
            $this->error(
                sprintf('[Error] Unable to get Token Access Information:, message: %s', $exception->getMessage())
            );

            throw SSOAuthenticationException::requestForConnectionTokenFail();
        }
    }

    /**
     * Validate that auto import attributes are present in user informations from provider.
     *
     * @throws SSOAuthenticationException
     */
    private function validateAutoImportAttributesOrFail(): void
    {
        $missingAttributes = [];
        /** @var CustomConfiguration $customConfiguration */
        $customConfiguration = $this->configuration->getCustomConfiguration();
        if (! array_key_exists($customConfiguration->getEmailBindAttribute() ?? '', $this->userInformations)) {
            $missingAttributes[] = $customConfiguration->getEmailBindAttribute();
        }
        if (! array_key_exists($customConfiguration->getUserNameBindAttribute() ?? '', $this->userInformations)) {
            $missingAttributes[] = $customConfiguration->getUserNameBindAttribute();
        }

        if (! empty($missingAttributes)) {
            $exception = SSOAuthenticationException::autoImportBindAttributeNotFound($missingAttributes);
            $this->logExceptionInLoginLogFile(
                "Some bind attributes can't be found in user information: %s, message: %s",
                $exception
            );

            throw $exception;
        }
    }

    /**
     * Log error when response from external provider contains error or is empty.
     *
     * @param array<string,string> $content
     */
    private function logErrorFromExternalProvider(array $content): void
    {
        $this->error(
            'error from external provider :' . (array_key_exists('error', $content)
                ? $content['error']
                : 'No content in response')
        );
    }

    /**
     * Log error when response from external provider has an invalid status code.
     *
     * @param int $codeReceived
     * @param int $codeExpected
     */
    private function logErrorForInvalidStatusCode(int $codeReceived, int $codeExpected): void
    {
        $this->error(
            sprintf(
                'invalid status code return by external provider, [%d] returned, [%d] expected',
                $codeReceived,
                $codeExpected
            )
        );
    }

    /**
     * Log error in login.log file.
     *
     * @param string $message
     * @param array<string,string> $content
     */
    private function logErrorInLoginLogFile(string $message, array $content): void
    {
        if (array_key_exists('error', $content)) {
            $this->centreonLog->insertLog(
                CentreonUserLog::TYPE_LOGIN,
                "[Openid] [Error] {$message}" . json_encode($content)
            );
        }
    }

    /**
     * Log Authentication debug.
     *
     * @param string $message
     * @param array<string,string> $content
     */
    private function logAuthenticationDebug(string $message, array $content): void
    {
        if (isset($content['jti'])) {
            $content['jti'] = mb_substr($content['jti'], -10);
        }
        if (isset($content['access_token'])) {
            $content['access_token'] = mb_substr($content['access_token'], -10);
        }
        if (isset($content['refresh_token'])) {
            $content['refresh_token'] = mb_substr($content['refresh_token'], -10);
        }
        if (isset($content['id_token'])) {
            $content['id_token'] = mb_substr($content['id_token'], -10);
        }
        if (isset($content['provider_token'])) {
            $content['provider_token'] = mb_substr($content['provider_token'], -10);
        }
        $this->centreonLog->insertLog(
            CentreonUserLog::TYPE_LOGIN,
            "[Openid] [Debug] {$message} " . json_encode($content)
        );
        $this->debug('Authentication information : ', $content);
    }

    /**
     * Log Authentication information.
     *
     * @param string $message
     * @param array<mixed>|null $content
     */
    private function logAuthenticationInfo(string $message, ?array $content = null): void
    {
        $this->centreonLog->insertLog(
            CentreonUserLog::TYPE_LOGIN,
            "[openid] [INFO] {$message}" . ($content !== null ? ' : ' . json_encode($content) : '')
        );

        $this->info("{$message} : ", $content ?: []);
    }

    /**
     * Log Exception in login.log file.
     *
     * @param string $message
     * @param Exception $exception
     */
    private function logExceptionInLoginLogFile(string $message, Exception $exception): void
    {
        $this->centreonLog->insertLog(
            CentreonUserLog::TYPE_LOGIN,
            sprintf(
                "[Openid] [Error] {$message}",
                $exception::class,
                $exception->getMessage()
            )
        );
    }

    /**
     * Decode using the RFC-4648 "URL and Filename safe" Base 64 Alphabet.
     *
     * @see https://www.ietf.org/rfc/rfc4648.txt
     *
     * @param string $token
     *
     * @throws \ValueError
     *
     * @return string
     */
    private function urlSafeTokenDecode(string $token): string
    {
        $decoded = base64_decode(str_replace(['-', '_'], ['+', '/'], $token), true);
        if (false === $decoded) {
            throw new \ValueError('The token cannot be base64 decoded');
        }

        return $decoded;
    }
}
