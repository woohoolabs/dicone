<?php
declare(strict_types=1);

namespace WoohooLabs\Zen\Config\DefinitionHint;

use WoohooLabs\Zen\Container\Definition\DefinitionInterface;

interface DefinitionHintInterface
{
    /**
     * @return DefinitionInterface[]
     */
    public function toDefinitions(string $id): array;
}