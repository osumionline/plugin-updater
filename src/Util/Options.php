<?php declare(strict_types=1);

namespace Osumi\OsumiFramework\Plugins\Util;

use Composer\Package\RootPackageInterface;

final class Options {
  private function __construct() {}

  /**
   * Builds plugin options from root package "extra" and environment variables.
   *
   * Convention:
   * - Root composer.json can define:
   *   {
   *     "extra": {
   *       "ofw": {
   *         "verbose": true,
   *         "...": "..."
   *       }
   *     }
   *   }
   *
   * @return array{
   *   dryRun: bool,
   *   force: bool,
   *   verbose: bool,
   *   extra: array<string, mixed>
   * }
   */
  public static function fromRootPackage(RootPackageInterface $root_package, bool $dry_run, bool $force): array {
    $extra = $root_package->getExtra();

    $ofw_extra = [];
    if (isset($extra['ofw']) && is_array($extra['ofw'])) {
      /** @var array<string, mixed> $tmp */
      $tmp = $extra['ofw'];
      $ofw_extra = $tmp;
    }

    $verbose = false;
    if (array_key_exists('verbose', $ofw_extra)) {
      $verbose = (bool)$ofw_extra['verbose'];
      unset($ofw_extra['verbose']);
    }

    // Env var override for verbosity (optional, but handy)
    $verbose_env = Env::getString('OFW_VERBOSE', null);
    if ($verbose_env !== null) {
      $verbose = Env::getBool('OFW_VERBOSE', $verbose);
    }

    return [
      'dryRun' => $dry_run,
      'force' => $force,
      'verbose' => $verbose,
      'extra' => $ofw_extra,
    ];
  }
}
