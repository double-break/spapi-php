<?php
namespace DoubleBreak\Spapi;

interface TokenStorageInterface {
  public function getToken($key): ?array;
  public function storeToken($key, $value);
}
