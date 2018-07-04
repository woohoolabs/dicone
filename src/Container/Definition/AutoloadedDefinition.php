<?php
declare(strict_types=1);

namespace WoohooLabs\Zen\Container\Definition;

use WoohooLabs\Zen\Config\Autoload\AutoloadConfigInterface;
use WoohooLabs\Zen\Utils\FileSystemUtil;

final class AutoloadedDefinition extends AbstractDefinition
{
    /**
     * @var AutoloadConfigInterface
     */
    private $autoloadConfig;

    /**
     * @var string
     */
    private $id;

    /**
     * @param DefinitionInterface[] $definitions
     */
    public function __construct(AutoloadConfigInterface $autoloadConfig, string $id)
    {
        $this->autoloadConfig = $autoloadConfig;
        $this->id = $id;
        parent::__construct($id, str_replace("\\", "__", $id), "");
    }

    public function getScope(): string
    {
        return "";
    }

    public function needsDependencyResolution(): bool
    {
        return false;
    }

    public function isAutoloaded(): bool
    {
        return true;
    }

    public function resolveDependencies(): DefinitionInterface
    {
        return $this;
    }

    public function getClassDependencies(): array
    {
        return [];
    }

    /**
     * @param DefinitionInterface[] $definitions
     */
    public function toPhpCode(array $definitions): string
    {
        $definition = $definitions[$this->id];
        $id = $definition->getId();

        $code = $this->includeDependency($definitions, $this->id);

        $code .= "\n";
        $code .= "        self::\$entryPoints[\\$id::class] = '{$definition->getHash()}';\n\n";
        $code .= "        return \$this->{$definition->getHash()}();\n";

        return $code;
    }

    /**
     * @param DefinitionInterface[] $definitions
     */
    private function includeDependency(array $definitions, string $id): string
    {
        $definition = $definitions[$id];

        $dependencies = [];
        $this->collectDependencies($definitions, $id, $dependencies);
        $dependencies = array_reverse($dependencies);

        $code = "";
        foreach ($dependencies as $dependency) {
            $filename = FileSystemUtil::getRelativeFilename($this->autoloadConfig->getRootDirectory(), $dependency);
            if ($filename === "") {
                continue;
            }

            $code .= "        include_once \$this->rootDirectory . '$filename';\n";
        }

        $filename = FileSystemUtil::getRelativeFilename($this->autoloadConfig->getRootDirectory(), $definition->getId());
        if ($filename !== "") {
            $code .= "        include_once \$this->rootDirectory . '$filename';\n";
        }

        return $code;
    }

    /**
     * @param DefinitionInterface[] $definitions
     */
    private function collectDependencies(array $definitions, string $id, array &$dependencies): void
    {
        $definition = $definitions[$id];

        foreach ($definition->getClassDependencies() as $dependency) {
            if (isset($dependencies[$dependency])) {
                continue;
            }

            $dependencies[$dependency] = $dependency;
            $this->collectDependencies($definitions, $dependency, $dependencies);
        }
    }
}