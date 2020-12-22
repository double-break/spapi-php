<?php
namespace DoubleBreak\Spapi;
use DoubleBreak\Spapi\TokenStorageInterface;

class SimpleTokenStorage implements TokenStorageInterface {

  private $filePath;
  public function __construct($filePath)
  {
    $this->filePath = $filePath;
  }


  public function getToken($key): ?array
  {
    $content = file_get_contents($this->filePath);

    if ($content != '') {
      $json = json_decode($content, true);
      return $json[$key] ?? null;
    }
    return null;
  }


  public function storeToken($key, $value)
  {

    $json = [];
    $content = file_get_contents($this->filePath);
    if ($content != '') {
      $json = json_decode($content, true);
    }
    $json[$key] = $value;
    file_put_contents($this->filePath, json_encode($json));

  }
}
