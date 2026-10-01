<?php

declare(strict_types=1);

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

  /**
   * Run pending framework migrations after Composer has rebuilt the autoloader.
   *
   * The handler runs even when no framework update event was captured. This is
   * required when plugin-updater itself is installed during the same Composer
   * operation as the framework version that introduces migrations.
   *
   * @param Event $event Composer script event.
   *
   * @return void
   */
  public function onPostAutoloadDump(Event $event): void {
    if (
      $this->composer === null ||
      $this->io === null
    ) {
      return;
    }

    $target_version = $this->ofw_update?->to
      ?? $this->getInstalledFrameworkVersion(
        $this->composer
      );

    if ($target_version === null) {
      return;
    }

    $project_root = $this->getProjectRoot();

    $options = Options::fromRootPackage(
      root_package: $this->composer->getPackage(),
      dry_run: Env::getBool(
        'OFW_DRY_RUN'
      ),
      force: Env::getBool(
        'OFW_FORCE'
      )
    );

    try {
      $this->runMigrations(
        project_root: $project_root,
        to: $target_version,
        opts: $options
      );
    } finally {
      /*
		 * The migration state is authoritative. Clearing this event-local
		 * information prevents duplicate update messages if Composer emits
		 * another autoload event in the same process.
		 */
      $this->ofw_update = null;
    }
  }

  /**
   * Get the Composer project root.
   *
   * Composer executes plugins using the active project working directory, so
   * this remains correct even when the application uses a custom vendor-dir.
   *
   * @return string Canonical project root path.
   *
   * @throws \RuntimeException If the current working directory cannot be resolved.
   */
  private function getProjectRoot(): string {
    $project_root = getcwd();

    if ($project_root === false) {
      throw new \RuntimeException(
        'Unable to determine Composer project root.'
      );
    }

    $resolved_root = realpath(
      $project_root
    );

    if ($resolved_root === false) {
      throw new \RuntimeException(
        "Unable to resolve Composer project root '{$project_root}'."
      );
    }

    return rtrim(
      str_replace(
        '\\',
        '/',
        $resolved_root
      ),
      '/'
    );
  }

  /**
   * Get the framework version currently installed in Composer's local repository.
   *
   * This provides a target version when plugin-updater is being installed for
   * the first time and therefore did not observe the framework package update.
   *
   * @param Composer $composer Active Composer instance.
   *
   * @return string|null Installed framework version, or null when the framework
   *                     package is not installed.
   */
  private function getInstalledFrameworkVersion(
    Composer $composer
  ): ?string {
    $package = $composer
      ->getRepositoryManager()
      ->getLocalRepository()
      ->findPackage(
        self::FRAMEWORK_PACKAGE,
        '*'
      );

    return $package?->getPrettyVersion();
  }

  /**
   * Run all framework migrations pending for the installed target version.
   *
   * The framework migration state is authoritative. Composer-managed root files
   * are explicitly excluded from the Git cleanliness check because they may
   * legitimately change during the Composer operation that triggered this hook.
   *
   * @param string $project_root Application project root.
   * @param string $to Installed target framework version.
   * @param array{
   *     dryRun: bool,
   *     force: bool,
   *     verbose: bool,
   *     extra: array<string, mixed>
   * } $opts Plugin migration options.
   *
   * @return void
   */
  private function runMigrations(
    string $project_root,
    string $to,
    array $opts
  ): void {
    if ($this->io === null) {
      return;
    }

    $runner_class = '\Osumi\OsumiFramework\Migrations\Runner';

    if (!class_exists($runner_class)) {
      /*
		 * Framework versions before the migration engine are valid. The plugin
		 * must remain silent when there is nothing it can execute.
		 */
      return;
    }

    $call = [
      $runner_class,
      'runPending'
    ];

    if (!is_callable($call)) {
      return;
    }

    if ($this->ofw_update !== null) {
      if (
        $opts['verbose'] ||
        $this->ofw_update->from !== $this->ofw_update->to
      ) {
        $this->io->write(
          sprintf(
            '[OFW] Detected framework update (%s -> %s).',
            $this->ofw_update->from,
            $this->ofw_update->to
          )
        );
      }
    } elseif ($opts['verbose']) {
      $this->io->write(
        sprintf(
          '[OFW] Checking pending framework migrations for %s.',
          $to
        )
      );
    }

    $io = $this->io;

    $logger = static function (string $message) use ($io): void {
      $io->write(
        $message
      );
    };

    $call(
      $project_root,
      $to,
      [
        'dryRun' => $opts['dryRun'],
        'force' => $opts['force'],
        'verbose' => $opts['verbose'],
        'interactive' => $io->isInteractive(),
        'gitIgnoredPaths' => [
          'composer.json',
          'composer.lock'
        ],
        'logger' => $logger,
        'extra' => $opts['extra']
      ]
    );
  }
}
