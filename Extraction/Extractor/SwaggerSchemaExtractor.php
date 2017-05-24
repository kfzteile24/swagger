<?php

namespace Draw\Swagger\Extraction\Extractor;

use Draw\Swagger\Extraction\ExtractionContextInterface;
use Draw\Swagger\Extraction\ExtractionImpossibleException;
use Draw\Swagger\Extraction\ExtractorInterface;
use Draw\Swagger\Schema\Swagger;
use JMS\Serializer\Serializer;

class SwaggerSchemaExtractor implements ExtractorInterface
{
    /**
     * @var Serializer
     */
    private $serializer;

    public function __construct(Serializer $serializer)
    {
        $this->serializer = $serializer;
    }

    /**
     * Extract the requested data.
     *
     * The system is a incrementing extraction system. A extractor can be call before you and you must complete the
     * extraction.
     *
     * @param string $source
     * @param Swagger $swagger
     * @param ExtractionContextInterface $extractionContext
     *
     * @throws ExtractionImpossibleException
     * @return void
     */
    public function extract($source, $swagger, ExtractionContextInterface $extractionContext)
    {
        if (!$this->canExtract($source, $swagger, $extractionContext)) {
            throw new ExtractionImpossibleException();
        }

        $result = $this->serializer->deserialize($source, get_class($swagger), 'json');


        foreach ($result as $key => $value) {
            if (is_object($swagger->{$key})) {
                foreach (get_object_vars($result->{$key}) as $subKey => $subValue) {
                    if ($subValue !== null) {
                        $swagger->{$key}->{$subKey} = $subValue;
                    }
                }
            }

            if ($swagger->{$key} === null) {
                $swagger->{$key} = $value;
            }
        }
    }

    /**
     * Return if the extractor can extract the requested data or not.
     *
     * @param $source
     * @param $type
     * @param ExtractionContextInterface $extractionContext
     *
     * @return boolean
     */
    public function canExtract($source, $type, ExtractionContextInterface $extractionContext)
    {
        if (!is_string($source)) {
            return false;
        }

        if (!is_object($type)) {
            return false;
        }

        if (!$type instanceof Swagger) {
            return false;
        }

        $schema = json_decode($source, true);
        if (json_last_error() != JSON_ERROR_NONE) {
            return false;
        }

        if (!array_key_exists('swagger', $schema)) {
            return false;
        }

        if ($schema['swagger'] != '2.0') {
            return false;
        }

        return true;
    }
}
