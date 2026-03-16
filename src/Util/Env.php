<?php declare(strict_types=1);

namespace Osumi\OsumiFramework\Plugins\Util;

final class Env {
  private function __construct() {}

  public static function getString(string $key, ?string $default = null): ?string {
    $value = getenv($key);
    if ($value === false) {
      return $default;
    }
    $value = trim((string)$value);
    return $value === '' ? $default : $value;
  }

  public static function getBool(string $key, bool $default = false): bool {
    $raw = self::getString($key, null);
    if ($raw === null) {
      return $default;
    }

    $val = strtolower($raw);

    // Common truthy values
    if (in_array($val, ['1', 'true', 'yes', 'y', 'on'], true)) {
      return true;
    }

    // Common falsy values
    if (in_array($val, ['0', 'false', 'no', 'n', 'off'], true)) {
      return false;
    }

    // If it's set but unknown, fallback to default
    return $default;
  }
}
