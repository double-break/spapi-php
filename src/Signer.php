<?php
namespace DoubleBreak\Spapi;
use GuzzleHttp\Psr7\Request;

class Signer {

  /**
   * calculateSignature data
   *
   * @return array request with signed headers
   */

  public static function sign($request, $signOptions)
  {
    //required
    $service = $signOptions['service'] ?? null;
    $accessKey = $signOptions['access_key'] ?? null;
    $secretKey = $signOptions['secret_key'] ?? null;

    $region = $signOptions['region'] ?? null;
    $host = $signOptions['host'] ?? null;
    $method = $signOptions['method'] ?? null;

    //optionl
    $accessToken = $signOptions['access_token'] ?? null;
    $securityToken = $signOptions['security_token'] ?? null;
    $userAgent = $signOptions['user_agent'] ?? 'spapi_client';
    $queryString = $signOptions['query_string'] ?? '';
    $data = $signOptions['payload'] ?? [];
    $uri = $signOptions['uri'] ?? '';

    if (is_null($service)) throw new SignerException("Service is required");
    if (is_null($accessKey)) throw new SignerException("Access key is required");
    if (is_null($secretKey)) throw new SignerException("Secret key is required");
    if (is_null($region)) throw new SignerException("Region key is required");
    if (is_null($host)) throw new SignerException("Host key is required");
    if (is_null($method)) throw new SignerException("Method key is required");

    $terminationString = 'aws4_request';
    $algorithm = 'AWS4-HMAC-SHA256';
    $amzdate = gmdate('Ymd\THis\Z');
    $date = substr($amzdate, 0, 8);

    //Prepare payload hash
    if (is_array($data)) {
      $param = json_encode($data);
      if ($param == "[]") {
          $requestPayload = "";
      } else {
          $requestPayload = $param;
      }
    } else {
      $requestPayload = $data;
    }
    $hashedPayload = hash('sha256', $requestPayload);


    //Compute Canonical Headers
    $canonicalHeaders = [
      'host' => $host,
      'user-agent' => $userAgent,
    ];
    if (!is_null($accessToken)) {
      $canonicalHeaders['x-amz-access-token'] = $accessToken;
    }
    $canonicalHeaders['x-amz-date'] = $amzdate;
    if (!is_null($securityToken)) {
      $canonicalHeaders['x-amz-security-token'] = $securityToken;
    }

    $canonicalHeadersStr = '';
    foreach($canonicalHeaders as $h => $v) {
      $canonicalHeadersStr .= $h . ':' . $v . "\n";
    }
    $signedHeadersStr = join(';' , array_keys($canonicalHeaders));

    //Prepare credentials scope
    $credentialScope = $date . '/' . $region . '/' . $service . '/' . $terminationString;

    //prepare canonical request
    $canonicalRequest = $method . "\n" . $uri . "\n" . $queryString . "\n" . $canonicalHeadersStr . "\n" . $signedHeadersStr . "\n" . $hashedPayload;

    //Prepare the signature payload
    $stringToSign = $algorithm . "\n" . $amzdate . "\n" . $credentialScope . "\n" . hash('sha256', $canonicalRequest);

    //Prepare lockers
    $kSecret = "AWS4" . $secretKey;
    $kDate = hash_hmac('sha256', $date, $kSecret, true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    $kSigning = hash_hmac('sha256', $terminationString, $kService, true);

    //Compute the signature
    $signature = trim(hash_hmac('sha256', $stringToSign, $kSigning)); // Without fourth parameter passed as true, returns lowercase hexits as called for by docs

    $authorizationHeader = $algorithm . " Credential={$accessKey}/{$credentialScope}, SignedHeaders={$signedHeadersStr}, Signature={$signature}";


    $headers = array_merge($canonicalHeaders, [
        "Authorization" => $authorizationHeader,
    ]);

    $request['headers'] = array_merge($request['headers'], $headers);

    return $request;
  }
}
