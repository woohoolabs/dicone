<?php

declare(strict_types=1);

namespace WoohooLabs\Zen\Config\EntryPoint;

use WoohooLabs\Zen\Config\FileBasedDefinition\FileBasedDefinitionConfigInterface;

interface EntryPointInterface
{
    /**
     * @internal
     *
     * @return string[]
     */
    public function getClassNames(): array;

    /**
     * @internal
     */
    public function isFileBased(FileBasedDefinitionConfigInterface $fileBasedDefinitionConfig): bool;
}
