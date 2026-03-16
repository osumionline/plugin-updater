<?php declare(strict_types=1);

namespace Osumi\OsumiFramework\Plugins;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Installer\PackageEvents;
use Composer\Script\ScriptEvents;
use Composer\Installer\PackageEvent;
use Composer\Script\Event;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Osumi\OsumiFramework\Plugins\ValueObject\OfwUpdate;
use Osumi\OsumiFramework\Plugins\Util\Env;
use Osumi\OsumiFramework\Plugins\Util\Options;

final class OUpdater implements PluginInterface, EventSubscriberInterface {
  private const string FRAMEWORK_PACKAGE = 'osumionline/framework';

  private ?Composer $composer = null;
  private ?IOInterface $io = null;

  private ?OfwUpdate $ofw_update = null;

  public function activate(Composer $composer, IOInterface $io): void {
    $this->composer = $composer;
    $this->io = $io;
  }

  public function deactivate(Composer $composer, IOInterface $io): void {
    // nothing to do
  }

  public function uninstall(Composer $composer, IOInterface $io): void {
    // nothing to do
  }

  /**
   * @return array<string, string>
   */
  public static function getSubscribedEvents(): array {
    return [
      PackageEvents::POST_PACKAGE_UPDATE => 'onPostPackageUpdate',
      ScriptEvents::POST_AUTOLOAD_DUMP => 'onPostAutoloadDump',
    ];
  }

  public function onPostPackageUpdate(PackageEvent $event): void {
    $operation = $event->getOperation();

    // UpdateOperation has getInitialPackage/getTargetPackage, but we keep it safe.
    if (!method_exists($operation, 'getTargetPackage') || !method_exists($operation, 'getInitialPackage')) {
      return;
    }

    /** @var PackageInterface $target */
    $target = $operation->getTargetPackage();
    if ($target->getName() !== self::FRAMEWORK_PACKAGE) {
      return;
    }

    /** @var PackageInterface $initial */
    $initial = $operation->getInitialPackage();

    $this->ofw_update = new OfwUpdate(
      from: $initial->getPrettyVersion(),
      to: $target->getPrettyVersion()
    );
  }

  public function onPostAutoloadDump(Event $event): void {
    if (is_null($this->ofw_update)) {
      return;
    }
    if (is_null($this->composer) || is_null($this->io)) {
      return;
    }

    $project_root = $this->getProjectRoot($this->composer);

    $opts = Options::fromRootPackage(
      root_package: $this->composer->getPackage(),
      dry_run: Env::getBool('OFW_DRY_RUN'),
      force: Env::getBool('OFW_FORCE')
    );

    $this->runMigrations(
      project_root: $project_root,
      from: $this->ofw_update->from,
      to: $this->ofw_update->to,
      opts: $opts
    );

    // Reset so it doesn't run again in the same Composer execution.
    $this->ofw_update = null;
  }

  private function getProjectRoot(Composer $composer): string {
    /** @var string $vendor_dir */
    $vendor_dir = $composer->getConfig()->get('vendor-dir');
    return dirname($vendor_dir);
  }

  /**
   * @param array{
   *   dryRun: bool,
   *   force: bool,
   *   verbose: bool,
   *   extra: array<string, mixed>
   * } $opts
   */
  private function runMigrations(string $project_root, string $from, string $to, array $opts): void {
    if (is_null($this->io)) {
      return;
    }

    // Runner lives in the framework (core). We only call it if available.
    $runner_class = '\Osumi\Framework\Migrations\Runner';
    if (!class_exists($runner_class)) {
      // No output by default: plugin must be "quiet" unless needed.
      // Uncomment if you want debug logs:
      // $this->io->write('[OFW] Runner not found. Skipping migrations.');
      return;
    }

    /** @var callable $call */
    $call = [$runner_class, 'run'];
    if (!is_callable($call)) {
      return;
    }

    // Only log if something is actually happening (from != to) or verbose enabled.
    if ($opts['verbose'] || $from !== $to) {
      $this->io->write(sprintf('[OFW] Detected framework update (%s -> %s).', $from, $to));
    }

    $call($project_root, $from, $to, [
      'dryRun'  => $opts['dryRun'],
      'force'   => $opts['force'],
      'verbose' => $opts['verbose'],
      'io'      => $this->io,
      'extra'   => $opts['extra'],
    ]);
  }
}
