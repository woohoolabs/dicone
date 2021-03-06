<?php

declare(strict_types=1);

namespace WoohooLabs\Zen\Container;

use PhpDocReader\PhpDocReader;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use WoohooLabs\Zen\Attribute\Inject;
use WoohooLabs\Zen\Config\AbstractCompilerConfig;
use WoohooLabs\Zen\Config\EntryPoint\EntryPointInterface;
use WoohooLabs\Zen\Config\FileBasedDefinition\FileBasedDefinitionConfigInterface;
use WoohooLabs\Zen\Config\Hint\DefinitionHintInterface;
use WoohooLabs\Zen\Container\Definition\ClassDefinition;
use WoohooLabs\Zen\Container\Definition\DefinitionInterface;
use WoohooLabs\Zen\Container\Definition\ReferenceDefinition;
use WoohooLabs\Zen\Container\Definition\SelfDefinition;
use WoohooLabs\Zen\Exception\ContainerException;
use WoohooLabs\Zen\Exception\NotFoundException;

use function array_diff;
use function array_flip;
use function array_key_exists;
use function implode;

final class ContainerDependencyResolver
{
    private PhpDocReader $typeHintReader;
    private AbstractCompilerConfig $compilerConfig;
    private bool $useConstructorInjection;
    private bool $usePropertyInjection;
    /** @var EntryPointInterface[] */
    private array $entryPoints;
    /** @var DefinitionHintInterface[] */
    private array $definitionHints;
    /** @var DefinitionInterface[] */
    private array $definitions;
    private FileBasedDefinitionConfigInterface $fileBasedDefinitionConfig;
    /** @var string[] */
    private array $excludedFileBasedDefinitions;

    public function __construct(AbstractCompilerConfig $compilerConfig)
    {
        $this->typeHintReader = new PhpDocReader();

        $this->compilerConfig = $compilerConfig;
        $this->useConstructorInjection = $compilerConfig->useConstructorInjection();
        $this->usePropertyInjection = $compilerConfig->usePropertyInjection();
        $this->entryPoints = $compilerConfig->getEntryPointMap();
        $this->definitionHints = $compilerConfig->getDefinitionHints();

        $this->fileBasedDefinitionConfig = $compilerConfig->getFileBasedDefinitionConfig();
        $this->excludedFileBasedDefinitions = array_flip($this->fileBasedDefinitionConfig->getExcludedDefinitions());
    }

    /**
     * @return DefinitionInterface[]
     */
    public function resolveEntryPoints(): array
    {
        $this->resetDefinitions();

        foreach ($this->entryPoints as $id => $entryPoint) {
            $this->resolve($id, "", $entryPoint, false);
        }

        return $this->definitions;
    }

    /**
     * @return DefinitionInterface[]
     * @throws NotFoundException
     */
    public function resolveEntryPoint(string $id): array
    {
        $this->resetDefinitions();

        if (array_key_exists($id, $this->entryPoints) === false) {
            throw new NotFoundException($id);
        }

        $this->resolve($id, "", $this->entryPoints[$id], true);

        return $this->definitions;
    }

    private function resolve(string $id, string $parentId, EntryPointInterface $parentEntryPoint, bool $runtime): void
    {
        if (array_key_exists($id, $this->definitions)) {
            if ($this->definitions[$id]->needsDependencyResolution()) {
                $this->resolveDependencies($id, $parentId, $parentEntryPoint, $runtime);
            }

            return;
        }

        $isFileBased = $runtime ? false : $this->isFileBased($id, $parentEntryPoint);

        if (array_key_exists($id, $this->definitionHints)) {
            $definitions = $this->definitionHints[$id]->toDefinitions(
                $this->entryPoints,
                $this->definitionHints,
                $id,
                $isFileBased
            );

            foreach ($definitions as $definitionId => $definition) {
                /** @var DefinitionInterface $definition */
                if (array_key_exists($definitionId, $this->definitions) === false) {
                    $this->definitions[$definitionId] = $definition;
                    $this->resolve($definitionId, $parentId, $parentEntryPoint, $runtime);
                }
            }

            return;
        }

        $this->definitions[$id] = new ClassDefinition($id, true, array_key_exists($id, $this->entryPoints), $isFileBased);
        $this->resolveDependencies($id, $parentId, $parentEntryPoint, $runtime);
    }

    /**
     * @throws ContainerException
     */
    private function resolveDependencies(string $id, string $parentId, EntryPointInterface $parentEntryPoint, bool $runtime): void
    {
        $definition = $this->definitions[$id];

        $definition->resolveDependencies();

        if ($definition instanceof ClassDefinition === false) {
            return;
        }

        if ($this->useConstructorInjection) {
            $this->resolveConstructorArguments($id, $parentId, $definition, $parentEntryPoint, $runtime);
        }

        if ($this->usePropertyInjection) {
            $this->resolveProperties($id, $parentId, $definition, $parentEntryPoint, $runtime);
        }
    }

    /**
     * @throws ContainerException
     */
    private function resolveConstructorArguments(
        string $id,
        string $parentId,
        ClassDefinition $definition,
        EntryPointInterface $parentEntryPoint,
        bool $runtime
    ): void {
        try {
            $reflectionClass = new ReflectionClass($id);
        } catch (ReflectionException $e) {
            throw new ContainerException("Cannot inject class: " . $id);
        }

        $constructor = $reflectionClass->getConstructor();
        if ($constructor === null) {
            return;
        }

        $paramNames = [];
        foreach ($constructor->getParameters() as $parameter) {
            $paramName = $parameter->getName();
            $paramNames[] = $paramName;

            if ($definition->isConstructorParameterOverridden($paramName)) {
                $definition->addConstructorArgumentFromOverride($paramName);
                continue;
            }

            if ($parameter->isOptional()) {
                $definition->addConstructorArgumentFromValue($parameter->getDefaultValue());
                continue;
            }

            $parameterClass = null;
            $parameterType = $parameter->getType();
            if ($parameterType === null) {
                $parameterClass = $this->typeHintReader->getParameterClass($parameter);
            } elseif ($parameterType instanceof ReflectionNamedType && $parameterType->isBuiltin() === false) {
                $parameterClass = $parameterType->getName();
            }

            if ($parameterClass === null) {
                throw new ContainerException(
                    "Type declaration or PHPDoc type hint for constructor parameter $paramName of " .
                    "class {$definition->getClassName()} is missing or it is not a class!"
                );
            }

            $definition->addConstructorArgumentFromClass($parameterClass);
            $this->resolve($parameterClass, $id, $parentEntryPoint, $runtime);
            $this->definitions[$parameterClass]->increaseReferenceCount($id, $definition->isSingleton($parentId));
        }

        $invalidConstructorParameterOverrides = array_diff($definition->getOverriddenConstructorParameters(), $paramNames);
        if ($invalidConstructorParameterOverrides !== []) {
            throw new ContainerException(
                "Class {$definition->getClassName()} has the following overridden constructor parameters which don't exist: " .
                implode(", ", $invalidConstructorParameterOverrides) . "!"
            );
        }
    }

    /**
     * @throws ContainerException
     */
    private function resolveProperties(
        string $id,
        string $parentId,
        ClassDefinition $definition,
        EntryPointInterface $parentEntryPoint,
        bool $runtime
    ): void {
        $class = new ReflectionClass($id);

        $propertyNames = [];
        foreach ($class->getProperties() as $property) {
            $propertyName = $property->getName();

            $propertyNames[] = $propertyName;

            if ($definition->isPropertyOverridden($propertyName)) {
                $definition->addPropertyFromOverride($propertyName);
                continue;
            }

            if ($property->getAttributes(Inject::class) === []) {
                continue;
            }

            if ($property->isStatic()) {
                throw new ContainerException(
                    "Property {$class->getName()}::\$$propertyName is static and can't be injected upon!"
                );
            }

            $propertyClass = null;
            $propertyType = $property->getType();
            if ($propertyType === null) {
                $propertyClass = $this->typeHintReader->getPropertyClass($property);
            } elseif ($propertyType instanceof ReflectionNamedType && $propertyType->isBuiltin() === false) {
                $propertyClass = $propertyType->getName();
            }

            if ($propertyClass === null) {
                throw new ContainerException(
                    "Type declaration or PHPDoc type hint for property $id::\$$propertyName is missing or it is not a class!"
                );
            }

            $definition->addPropertyFromClass($propertyName, $propertyClass);
            $this->resolve($propertyClass, $id, $parentEntryPoint, $runtime);
            $this->definitions[$propertyClass]->increaseReferenceCount($id, $definition->isSingleton($parentId));
        }

        $invalidPropertyOverrides = array_diff($definition->getOverriddenProperties(), $propertyNames);
        if ($invalidPropertyOverrides !== []) {
            throw new ContainerException(
                "Class $id has the following overridden properties which don't exist: " .
                implode(", ", $invalidPropertyOverrides) . "!"
            );
        }
    }

    private function isFileBased(string $id, EntryPointInterface $parentEntryPoint): bool
    {
        if (array_key_exists($id, $this->excludedFileBasedDefinitions)) {
            return false;
        }

        return $parentEntryPoint->isFileBased($this->fileBasedDefinitionConfig);
    }

    private function resetDefinitions(): void
    {
        $this->definitions = [
            ContainerInterface::class => ReferenceDefinition::singleton(
                ContainerInterface::class,
                $this->compilerConfig->getContainerFqcn(),
                true
            ),
            $this->compilerConfig->getContainerFqcn() => new SelfDefinition($this->compilerConfig->getContainerFqcn()),
        ];
    }
}
