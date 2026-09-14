<?php

declare(strict_types=1);

namespace Endroid\QrCode\Builder;
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

interface BuilderRegistryInterface
{
    public function getBuilder(string $name): BuilderInterface;

    public function addBuilder(string $name, BuilderInterface $builder): void;
}
