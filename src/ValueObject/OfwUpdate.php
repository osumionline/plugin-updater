<?php declare(strict_types=1);

namespace Osumi\OsumiFramework\Plugins\ValueObject;

final class OfwUpdate {
  public function __construct(
    public readonly string $from,
    public readonly string $to
  ) {}
}
