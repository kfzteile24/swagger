<?php

namespace Draw\Swagger;

use Doctrine\Common\Annotations\AnnotationReader;
use Draw\Swagger\Extraction\ExtractionContext;
use Draw\Swagger\Extraction\ExtractionContextInterface;
use Draw\Swagger\Extraction\ExtractorInterface;
use Draw\Swagger\Extraction\Extractor\SwaggerSchemaExtractor;
use JMS\Serializer\EventDispatcher\EventDispatcher;
use JMS\Serializer\SerializerBuilder;
use JMS\Serializer\SerializerInterface;
use Draw\Swagger\Schema\Swagger as Schema;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Validation;

/**
 * Class Generator
 *
 * @author Martin Poirier Theoret <mpoiriert@gmail.com>
 */
class Swagger
{
    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @var array
     */
    private $extractors = array();

    /**
     * @var ExtractorInterface[]
     */
    private $sortedExtractors;

    public function __construct(SerializerInterface $serializer = null)
    {
        if (is_null($serializer)) {
            $serializer = SerializerBuilder::create()->configureListeners(
                function (EventDispatcher $dispatcher) {
                    $dispatcher->addSubscriber(new JMSSerializerListener());
                }
            )->build();

        }
        $this->serializer = $serializer;

        $this->registerExtractor(new SwaggerSchemaExtractor($this->serializer), -1, 'swagger');
    }

    public function registerExtractor(ExtractorInterface $extractorInterface, $position = 0, $section = 'default')
    {
        $this->extractors[$section][$position][] = $extractorInterface;
    }

    public function validate(Schema $schema){
        $validator = Validation::createValidatorBuilder()
            ->enableAnnotationMapping(new AnnotationReader())
            ->getValidator();
        $result = $validator->validate($schema, null, true);

        if(count($result)) {
            throw new \InvalidArgumentException("" . $result);
        }
    }

    /**
     * @param Schema $schema
     * @return string
     */
    public function dump(Schema $schema)
    {
        $this->validate($schema);
        return $this->serializer->serialize($schema, 'json');
    }

    /**
     * @api
     * @param string $jsonSchema
     * @return mixed
     */
    public function extract($source, $type = null, ExtractionContextInterface $extractionContext = null)
    {
        if (is_null($type)) {
            $type = new Schema();
        }

        if (is_null($extractionContext)) {
            $extractionContext = new ExtractionContext($this, $type);
        }

        $sortedExtractors = $this->getSortedExtractors();
        foreach ($sortedExtractors as $extractor) {
            if ($extractor->canExtract($source, $type, $extractionContext)) {
                $extractor->extract($source, $type, $extractionContext);
            }
        }

        return $type;
    }

    /**
     * @return ExtractorInterface[]
     */
    private function getSortedExtractors()
    {
        if (is_null($this->sortedExtractors)) {
            $this->sortedExtractors = array();
            foreach ($this->extractors as $section => $extractors) {
                ksort($extractors);
                $this->sortedExtractors = call_user_func_array('array_merge', $extractors);
            }
        }

        return $this->sortedExtractors;
    }
}
