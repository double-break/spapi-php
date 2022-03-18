<?php

namespace DoubleBreak\Spapi;

use GuzzleHttp\Client;

class Credentials
{

    use HttpClientFactoryTrait;

    private $config;
    private $tokenStorage;
    private $signer;

    public function __construct(TokenStorageInterface $tokenStorage, Signer $signer, array $config = [])
    {
        $this->config = $config;
        $this->tokenStorage = $tokenStorage;
        $this->signer = $signer;
    }

    /**
     * Returns credentials
     * use $useMigrationToken = true for /authorization/v1/authorizationCode request
     * @param false $useMigrationToken
     * @return array
     * @throws \Exception
     */
    public function getCredentials($useMigrationToken = false)
    {
        $lwaAccessToken = $useMigrationToken === true ? $this->getMigrationToken() :
            ($useMigrationToken === 'grantless' ? $this->getGrantlessAuthToken() : $this->getLWAToken());
        $stsCredentials = $this->getStsTokens();

        return [
            'access_token' => $lwaAccessToken,
            'sts_credentials' => $stsCredentials
        ];
    }

    /**
     * Prepares credentials for clients which require Restricted Data Tokens
     * see: https://github.com/amzn/selling-partner-api-docs/blob/main/guides/en-US/use-case-guides/tokens-api-use-case-guide/tokens-API-use-case-guide-2021-03-01.md
     *
     *
     * @param  array https://github.com/amzn/selling-partner-api-docs/blob/main/references/tokens-api/tokens_2021-03-01.md#createrestricteddatatokenrequest$restrictedOperations Array with items representing CreateRestrictedDataTokenRequest
     * see: https://github.com/amzn/selling-partner-api-docs/blob/main/references/tokens-api/tokens_2021-03-01.md#createrestricteddatatokenrequest
     * @return array                       aceess token and STS credentials
     */
    public function getRdtCredentials(array $restrictedOperations)
    {
      $rdAccessToken = $this->getRestrictedDataAccessToken($restrictedOperations);
      $stsCredentials = $this->getStsTokens();

      return [
          'access_token' => $rdAccessToken,
          'sts_credentials' => $stsCredentials
      ];
    }

    private function getRestrictedDataAccessToken($restrictedOperations = [])
    {
      $restrictedOperationsHash = md5(\json_encode($restrictedOperations));
      $tokenKey = 'restricted_data_token_' . $restrictedOperationsHash;
      $knownToken = $this->loadTokenFromStorage($tokenKey);
      if (!is_null($knownToken)) {
        return $knownToken;
      }

      $cred = $this->getCredentials();
      $tokensClient = new \DoubleBreak\Spapi\Api\Tokens($cred, $this->config);

      $result = $tokensClient->createRestrictedDataToken($restrictedOperations);
      $rdt = $result['restrictedDataToken'];
      $expiresOn = time() + $result['expiresIn'];

      $this->tokenStorage->storeToken($tokenKey, [
        'token' => $rdt,
        'expiresOn' => $expiresOn
      ]);

      return $rdt;
    }

    private function getLWAToken()
    {

        $knownToken = $this->loadTokenFromStorage('lwa_access_token');
        if (!is_null($knownToken)) {
            return $knownToken;
        }

        $client = $this->createHttpClient([
            'base_uri' => 'https://api.amazon.com'
        ]);

        try {
            $requestOptions = [
                'form_params' => [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $this->config['refresh_token'],
                    'client_id' => $this->config['client_id'],
                    'client_secret' => $this->config['client_secret']
                ]
            ];
            $response = $client->post('/auth/o2/token', $requestOptions);
        } catch (\Exception $e) {
            //log something
            throw $e;
        }
        $json = json_decode($response->getBody(), true);
        $this->tokenStorage->storeToken('lwa_access_token', [
            'token' => $json['access_token'],
            'expiresOn' => time() + ($this->config['access_token_longevity'] ?? 3600)
        ]);

        return $json['access_token'];

    }

    /**
     * Request a Login with Amazon access token
     * @see https://github.com/amzn/selling-partner-api-docs/blob/main/guides/developer-guide/SellingPartnerApiDeveloperGuide.md#step-1-request-a-login-with-amazon-access-token
     * @return mixed
     * @throws \Exception
     */
    public function getMigrationToken()
    {
        $knownToken = $this->loadTokenFromStorage('migration_token');
        if (!is_null($knownToken)) {
            return $knownToken;
        }

        $client = $this->createHttpClient([
            'base_uri' => 'https://api.amazon.com'
        ]);

        try {
            $requestOptions = [
                'form_params' => [
                    'grant_type' => 'client_credentials',
                    'scope' => 'sellingpartnerapi::migration',
                    'client_id' => $this->config['client_id'],
                    'client_secret' => $this->config['client_secret']
                ]
            ];
            $response = $client->post('/auth/o2/token', $requestOptions);

            $json = json_decode($response->getBody(), true);

        } catch (\Exception $e) {
            //log something
            throw $e;
        }

        if (!array_key_exists('access_token', $json)) {
            throw new IncorrectResponseException('Failed to load migration token.');
        }

        $this->tokenStorage->storeToken('migration_token', [
            'token' => $json['access_token'],
            'expiresOn' => time() + $json['expires_in']
        ]);

        return $json['access_token'];
    }

    public function getGrantlessAuthToken()
    {
        $knownToken = $this->loadTokenFromStorage('grantless_auth_token');
        if (!is_null($knownToken)) {
            return $knownToken;
        }

        $client = $this->createHttpClient([
            'base_uri' => 'https://api.amazon.com'
        ]);

        try {
            $requestOptions = [
                'form_params' => [
                    'grant_type' => 'client_credentials',
                    'scope' => 'sellingpartnerapi::notifications',
                    'client_id' => $this->config['client_id'],
                    'client_secret' => $this->config['client_secret']
                ]
            ];
            $response = $client->post('/auth/o2/token', $requestOptions);

            $json = json_decode($response->getBody(), true);

        } catch (\Exception $e) {
            //log something
            throw $e;
        }

        if (!array_key_exists('access_token', $json)) {
            throw new IncorrectResponseException('Failed to load grantless auth token.');
        }

        $this->tokenStorage->storeToken('grantless_auth_token', [
            'token' => $json['access_token'],
            'expiresOn' => time() + $json['expires_in']
        ]);

        return $json['access_token'];
    }

    private function getStsTokens()
    {
        $knownToken = $this->loadTokenFromStorage('sts_credentials');
        if (!is_null($knownToken)) {
            return $knownToken;
        }

        $requestOptions = [
            'headers' => [
                'accept' => 'application/json'
            ],
            'form_params' => [
                'Action' => 'AssumeRole',
                'DurationSeconds' => $this->config['sts_session _longevity'] ?? 3600,
                'RoleArn' => $this->config['role_arn'],
                'RoleSessionName' => 'session1',
                'Version' => '2011-06-15',
            ]
        ];

        $host = 'sts.amazonaws.com';
        $uri = '/';

        $requestOptions = $this->signer->sign($requestOptions, [
            'service' => 'sts',
            'access_key' => $this->config['access_key'],
            'secret_key' => $this->config['secret_key'],
            'region' => 'us-east-1', //This should be hardcoded
            'host' => $host,
            'uri' => $uri,
            'payload' => \GuzzleHttp\Psr7\Query::build($requestOptions['form_params']),
            'method' => 'POST',
        ]);

        $client = $this->createHttpClient([
            'base_uri' => 'https://' . $host
        ]);

        try {
            $response = $client->post($uri, $requestOptions);

            $json = json_decode($response->getBody(), true);
            $credentials = $json['AssumeRoleResponse']['AssumeRoleResult']['Credentials'] ?? null;
            $tokens = [
                'access_key' => $credentials['AccessKeyId'],
                'secret_key' => $credentials['SecretAccessKey'],
                'session_token' => $credentials['SessionToken']
            ];
            $this->tokenStorage->storeToken('sts_credentials', [
                'token' => $tokens,
                'expiresOn' => $credentials['Expiration']
            ]);

            return $tokens;

        } catch (\Exception $e) {
            //log something
            throw $e;
        }

    }

    /**
     * Exchanges the LWA authorization code for an LWA refresh token
     * @see https://github.com/amzn/selling-partner-api-docs/blob/main/guides/developer-guide/SellingPartnerApiDeveloperGuide.md#step-5-your-application-exchanges-the-lwa-authorization-code-for-an-lwa-refresh-token
     * @param $authorizationCode
     * @throws \Exception
     */
    public function exchangesAuthorizationCodeForRefreshToken($authorizationCode)
    {
        $client = $this->createHttpClient([
            'base_uri' => 'https://api.amazon.com'
        ]);

        try {
            $requestOptions = [
                'form_params' => [
                    'grant_type' => 'authorization_code',
                    'code' => $authorizationCode,
                    'client_id' => $this->config['client_id'],
                    'client_secret' => $this->config['client_secret']
                ]
            ];
            $response = $client->post('/auth/o2/token', $requestOptions);

            return json_decode($response->getBody(), true);

        } catch (\Exception $e) {
            throw $e;
        }
    }

    private function loadTokenFromStorage($key)
    {
        $knownToken = $this->tokenStorage->getToken($key);
        if (!empty($knownToken)) {
            $expiresOn = $knownToken['expiresOn'];
            if ($expiresOn > time()) {
                return $knownToken['token'];
            }
        }
        return null;
    }
}
