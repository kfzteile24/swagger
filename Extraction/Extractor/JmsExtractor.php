<?php

namespace Draw\Swagger\Extraction\Extractor;

use Draw\Swagger\Extraction\ExtractionContextInterface;
use Draw\Swagger\Extraction\ExtractionImpossibleException;
use Draw\Swagger\Extraction\ExtractorInterface;
use Draw\Swagger\Schema\Schema;
use JMS\Serializer\Exclusion\GroupsExclusionStrategy;
use JMS\Serializer\Metadata\VirtualPropertyMetadata;
use JMS\Serializer\Metadata\PropertyMetadata;
use JMS\Serializer\Naming\PropertyNamingStrategyInterface;
use JMS\Serializer\SerializationContext;
use Metadata\MetadataFactoryInterface;
use phpDocumentor\Reflection\DocBlockFactory;
use ReflectionClass;

class JmsExtractor implements ExtractorInterface
{
    /**
     * @var \Metadata\MetadataFactoryInterface
     */
    private $factory;

    /**
     * @var \JMS\Serializer\Naming\PropertyNamingStrategyInterface
     */
    private $namingStrategy;

    /**
     * Constructor, requires JMS Metadata factory
     *
     * @param \Metadata\MetadataFactoryInterface $factory
     * @param \JMS\Serializer\Naming\PropertyNamingStrategyInterface $namingStrategy
     */
    public function __construct(
        MetadataFactoryInterface $factory,
        PropertyNamingStrategyInterface $namingStrategy
    ) {
        $this->factory = $factory;
        $this->namingStrategy = $namingStrategy;
    }

    /**
     * Return if the extractor can extract the requested data or not.
     *
     * @param $source
     * @param $type
     * @param ExtractionContextInterface $extractionContext
     * @return boolean
     */
    public function canExtract($source, $type, ExtractionContextInterface $extractionContext)
    {
        if (!$source instanceof ReflectionClass) {
            return false;
        }

        if (!$type instanceof Schema) {
            return false;
        }

        return !is_null($this->factory->getMetadataForClass($source->getName()));
    }

    /**
     *
     * Extract the requested data.
     *
     * The system is a incrementing extraction system. A extractor can be call before you and you must complete the
     * extraction.
     *
     * @param ReflectionClass $reflectionClass
     * @param \Draw\Swagger\Schema\Schema $schema
     * @param \Draw\Swagger\Extraction\ExtractionContextInterface $extractionContext
     * @throws \Draw\Swagger\Extraction\ExtractionImpossibleException
     */
    public function extract($reflectionClass, $schema, ExtractionContextInterface $extractionContext)
    {
        if (!$this->canExtract($reflectionClass, $schema, $extractionContext)) {
            throw new ExtractionImpossibleException();
        }

        $meta = $this->factory->getMetadataForClass($reflectionClass->getName());

        $exclusionStrategies = array();

        $subContext = $extractionContext->createSubContext();

        switch ($extractionContext->getParameter('direction')) {
            case 'in':
                $modelContext = $extractionContext->getParameter('in-model-context', []);
                break;
            case 'out';
                $modelContext = $extractionContext->getParameter('out-model-context', []);
                break;
            default:
                $modelContext = [];
        }

        $groups = [];
        if(array_key_exists('serializer-groups', $modelContext)) {
            $groups = $modelContext['serializer-groups'];
        }

        if ($groups) {
            $exclusionStrategies[] = new GroupsExclusionStrategy($groups);
        }

        foreach ($meta->propertyMetadata as $property => $item) {
            if ($this->shouldSkipProperty($exclusionStrategies, $item)) {
                continue;
            }

            $propertySchema = new Schema();
            if ($type = $this->getNestedTypeInArray($item)) {
                $propertySchema->type = 'array';
                $propertySchema->items = $this->extractTypeSchema($type, $propertySchema, $subContext);
            } else {
                $propertySchema = $this->extractTypeSchema($item->type['name'], $propertySchema, $subContext);
            }

            if ($item->readOnly) {
                $propertySchema->readOnly = true;
            }

            $name = $this->namingStrategy->translateName($item);
            $schema->properties[$name] = $propertySchema;
            $propertySchema->description = $this->getDescription($item);
        }
    }

    /**
     * @param string $type
     * @param \Draw\Swagger\Schema\Schema $schema
     * @param \Draw\Swagger\Extraction\ExtractionContextInterface $extractionContext
     *
     * @return mixed
     */
    private function extractTypeSchema($type, $schema, ExtractionContextInterface $extractionContext)
    {
        $extractionContext->getSwagger()->extract($type, $schema, $extractionContext);

        return $schema;
    }

    /**
     * Check the various ways JMS describes values in arrays, and
     * get the value type in the array
     *
     * @param \JMS\Serializer\Metadata\PropertyMetadata $item
     *
     * @return string|null
     */
    private function getNestedTypeInArray(PropertyMetadata $item)
    {
        if (isset($item->type['name']) && in_array($item->type['name'], array('array', 'ArrayCollection'))) {
            if (isset($item->type['params'][1]['name'])) {
                // E.g. array<string, MyNamespaceMyObject>
                return $item->type['params'][1]['name'];
            }
            if (isset($item->type['params'][0]['name'])) {
                // E.g. array<MyNamespaceMyObject>
                return $item->type['params'][0]['name'];
            }
        }

        return null;
    }

    /**
     * @param \JMS\Serializer\Metadata\PropertyMetadata $item
     *
     * @return \phpDocumentor\Reflection\DocBlock\Description|string
     */
    private function getDescription(PropertyMetadata $item)
    {
        $ref = new \ReflectionClass($item->class);
        $factory = DocBlockFactory::createInstance();
        if ($item instanceof VirtualPropertyMetadata) {
            try {
                $docBlock = $factory->create($ref->getMethod($item->getter)->getDocComment());
            } catch (\ReflectionException $e) {
                return '';
            }
        } else {
            $docBlock = $factory->create($ref->getProperty($item->name)->getDocComment());
        }

        return $docBlock->getDescription();
    }

    /**
     * @param \JMS\Serializer\Exclusion\ExclusionStrategyInterface[] $exclusionStrategies
     * @param \JMS\Serializer\Metadata\PropertyMetadata $item
     * @return bool
     */
    private function shouldSkipProperty($exclusionStrategies, $item)
    {
        foreach ($exclusionStrategies as $strategy) {
            if (true === $strategy->shouldSkipProperty($item, SerializationContext::create())) {
                return true;
            }
        }

        return false;
    }
}
