<?php
namespace DoubleBreak\Spapi;

class Client {

  use HttpClientFactoryTrait;

  protected $credentials;
  protected $config;
  protected $signer;

  public function __construct(array $credentials = [], array $config = [])
  {
    $this->credentials = $credentials;
    $this->config = $config;
    $this->signer = new Signer(); //Should be injected :(
  }


  private function normalizeHeaders($headers)
  {
    return $result = array_combine(
       array_map(function($header) { return strtolower($header); }, array_keys($headers)),
       $headers
    );

  }

  private function headerValue($header)
  {
      return \GuzzleHttp\Psr7\Header::parse($header)[0];
  }

  public function send($uri, $requestOptions = [])
  {
    $requestOptions['headers'] = $requestOptions['headers'] ?? [];
    $requestOptions['headers']['accept'] = 'application/json';
    $requestOptions['headers'] = $this->normalizeHeaders($requestOptions['headers']);


    //Prepare for signing
    $signOptions = [
      'service' => 'execute-api',
      'access_token' => $this->credentials['lwa_access_token'],
      'access_key' => $this->credentials['sts_credentials']['access_key'],
      'secret_key' =>  $this->credentials['sts_credentials']['secret_key'],
      'security_token' =>  $this->credentials['sts_credentials']['session_token'],
      'region' =>  $this->config['region'] ?? null,
      'host' => $this->config['host'],
      'uri' =>  $uri,
      'method' => $requestOptions['method']
    ];

    if (isset($requestOptions['query'])) {
      $query = $requestOptions['query'];
      ksort($query);
      $signOptions['query_string'] =  \GuzzleHttp\Psr7\build_query($query);
    }

    if (isset($request['form_params'])) {
      $formParams = $reqest['form_params'];
      ksort($formParams);
      $signOptions['payload'] = \GuzzleHttp\Psr7\build_query($formParams);
    }

    if (isset($request['json'])) {
      $signOptions['payload'] = json_encode($requestOptions['json']);
    }

    //Sign
    $requestOptions = $this->signer->sign($requestOptions, $signOptions);

    //Prep client and send the request
    $client = $this->createHttpClient([
      'base_uri' => 'https://' . $this->config['host']
    ]);

    try {
      $method = $requestOptions['method'];
      unset($requestOptions['method']);
      $result = $client->request($method, $uri, $requestOptions);
      return json_decode($result->getBody(), true);
    } catch (\Exception $e) {
      //do some logging
      throw $e;
    }

  }


}
