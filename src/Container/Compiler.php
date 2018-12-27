<?php
declare(strict_types=1);

namespace WoohooLabs\Zen\Container;

use WoohooLabs\Zen\Config\AbstractCompilerConfig;
use WoohooLabs\Zen\Container\Definition\AutoloadedDefinition;
use WoohooLabs\Zen\Container\Definition\DefinitionInterface;
use WoohooLabs\Zen\Utils\FileSystemUtil;
use function str_replace;

class Compiler
{
    /**
     * @param DefinitionInterface[] $definitions
     * @return string[]
     */
    public function compile(AbstractCompilerConfig $compilerConfig, array $definitions): array
    {
        $fileBasedDefinitionDirectory = $compilerConfig->getFileBasedDefinitionConfig()->getRelativeDirectory();
        $definitionFiles = [];

        $container = "<?php\n";
        if ($compilerConfig->getContainerNamespace()) {
            $container .= "namespace " . $compilerConfig->getContainerNamespace() . ";\n";
        }
        $container .= "\nuse WoohooLabs\\Zen\\AbstractCompiledContainer;\n\n";
        $container .= "class " . $compilerConfig->getContainerClassName() . " extends AbstractCompiledContainer\n";
        $container .= "{\n";

        // Entry points
        $entryPoints = array_keys($compilerConfig->getEntryPointMap());

        $container .= "    /**\n";
        $container .= "     * @var string[]\n";
        $container .= "     */\n";
        $container .= "    protected static \$entryPoints = [\n";
        foreach ($entryPoints as $id) {
            $methodName = $this->getHash($id);

            if (isset($definitions[$id]) && $definitions[$id]->isAutoloaded()) {
                $methodName = "_proxy__$methodName";
            }

            $container .= "        \\$id::class => '" . $methodName . "',\n";
        }
        $container .= "    ];\n\n";

        // Root directory property
        $container .= "    /**\n";
        $container .= "     * @var string\n";
        $container .= "     */\n";
        $container .= "    protected \$rootDirectory;\n\n";

        // Constructor
        $container .= "    public function __construct(string \$rootDirectory = '')\n";
        $container .= "    {\n";
        $container .= "        \$this->rootDirectory = \$rootDirectory;\n";
        foreach ($compilerConfig->getAutoloadConfig()->getAlwaysAutoloadedClasses() as $autoloadedClass) {
            $filename = FileSystemUtil::getRelativeFilename($compilerConfig->getAutoloadConfig()->getRootDirectory(), $autoloadedClass);
            $container .= "        include_once \$this->rootDirectory . '$filename';\n";
        }
        $container .= "    }\n";

        // Custom autoloading of container definitions
        foreach ($entryPoints as $id) {
            if (isset($definitions[$id]) === false) {
                continue;
            }

            $definition = $definitions[$id];
            if ($definition->isAutoloaded() === false) {
                continue;
            }

            $autoloadedDefinition = new AutoloadedDefinition($compilerConfig->getAutoloadConfig(), $id, true, $definition->isFileBased());

            $container .= "\n    public function _proxy__" . $this->getHash($id) . "()\n    {\n";
            if ($autoloadedDefinition->isFileBased()) {
                $filename = "_proxy__" . $this->getHash($id) . ".php";
                $definitionFiles[$filename] = "<?php\n\n";
                $definitionFiles[$filename] .= $autoloadedDefinition->toPhpCode($definitions);

                $container .= "        return require __DIR__ . '/$fileBasedDefinitionDirectory/$filename';\n";
            } else {
                $container .= $autoloadedDefinition->toPhpCode($definitions);
            }
            $container .= "    }\n";
        }

        // Container definitions
        foreach ($definitions as $id => $definition) {
            if ($definition->isFileBased()) {
                $filename = $this->getHash($id) . ".php";
                $definitionFiles[$filename] = "<?php\n\n";
                $definitionFiles[$filename] .= $definition->toPhpCode($definitions);

                if ($definition->isEntryPoint()) {
                    $container .= "\n    public function " . $this->getHash($id) . "()\n    {\n";
                    $container .= "        return require __DIR__ . '/$fileBasedDefinitionDirectory/$filename';\n";
                    $container .= "    }\n";
                }
            } else {
                $container .= "\n    public function " . $this->getHash($id) . "()\n    {\n";
                $container .= $definitionCode = $definition->toPhpCode($definitions);
                $container .= "    }\n";
            }
        }

        $container .= "}\n";

        return [
            "container" => $container,
            "definitions" => $definitionFiles,
        ];
    }

    private function getHash(string $id): string
    {
        return str_replace("\\", "__", $id);
    }
}
