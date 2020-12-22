<?php
namespace DoubleBreak\Spapi;

trait HttpClientFactoryTrait {
  private function createHttpClient($config)
  {
    $httpConfig = $this->config['http'] ?? [];
    $httpConfig = array_merge($httpConfig, $config);
    $client = new \GuzzleHttp\Client($httpConfig);
    return $client;
  }

}
