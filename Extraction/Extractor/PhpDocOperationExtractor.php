<?php

namespace Draw\Swagger\Extraction\Extractor;

use Draw\Swagger\Extraction\ExtractionContextInterface;
use Draw\Swagger\Extraction\ExtractionImpossibleException;
use Draw\Swagger\Extraction\ExtractorInterface;
use Draw\Swagger\Schema\BodyParameter;
use Draw\Swagger\Schema\Operation;
use Draw\Swagger\Schema\QueryParameter;
use Draw\Swagger\Schema\Response;
use Draw\Swagger\Schema\Schema;
use phpDocumentor\Reflection\DocBlock;
use phpDocumentor\Reflection\DocBlockFactory;
use ReflectionMethod;

class PhpDocOperationExtractor implements ExtractorInterface
{
    /**
     * @var array
     */
    private $exceptionResponseCodes = [];

    /**
     * Return if the extractor can extract the requested data or not.
     *
     * @param mixed $source
     * @param mixed $type
     * @param ExtractionContextInterface $extractionContext
     * @return boolean
     */
    public function canExtract($source, $type, ExtractionContextInterface $extractionContext)
    {
        if (!$source instanceof ReflectionMethod) {
            return false;
        }

        if (!$type instanceof Operation) {
            return false;
        }

        return true;
    }

    /**
     * Extract the requested data.
     *
     * The system is a incrementing extraction system. A extractor can be call before you and you must complete the
     * extraction.
     *
     * @param ReflectionMethod $method
     * @param Operation $operation
     * @param ExtractionContextInterface $extractionContext
     *
     * @throws ExtractionImpossibleException
     * @return void
     */
    public function extract($method, $operation, ExtractionContextInterface $extractionContext)
    {
        if (!$this->canExtract($method, $operation, $extractionContext)) {
            throw new ExtractionImpossibleException();
        }

        $factory = DocBlockFactory::createInstance();
        $docBlock = $factory->create($method->getDocComment());

        if(!$operation->summary) {
            $operation->summary = $docBlock->getSummary();
        }

        if($operation->description) {
            $operation->description = $docBlock->getDescription();
        }

        foreach ($docBlock->getTagsByName('return') as $returnTag) {
            if (isset($operation->responses[200]) && $operation->responses[200] instanceof Response) {
                continue;
            }
            /* @var $returnTag \phpDocumentor\Reflection\DocBlock\Tags\Return_ */
            $response = new Response();
            $response->schema = $responseSchema = new Schema();
            $response->description = $returnTag->getDescription();
            $operation->responses[200] = $response;

            $subContext = $extractionContext->createSubContext();
            $subContext->setParameter('direction', 'out');

            $extractionContext->getSwagger()->extract($returnTag->getType(), $responseSchema, $subContext);
        }

        if($docBlock->getTagsByName('deprecated')) {
           $operation->deprecated = true;
        }

        foreach ($docBlock->getTagsByName('throws') as $throwTag) {
            /* @var $throwTag \phpDocumentor\Reflection\DocBlock\Tags\Throws */
            $type = $throwTag->getType();
            /** @var \Exception $exception */
            $exceptionClass = new \ReflectionClass((string)$type);
            
            if ($exceptionClass->isInterface() || $exceptionClass->isAbstract() || $exceptionClass->isTrait()) {
                continue;
            }
            
            $exception = $exceptionClass->newInstanceWithoutConstructor();
            list($code, $message) = $this->getExceptionInformation($exception);
            $operation->responses[$code] = $exceptionResponse = new Response();

            if ($throwTag->getDescription()) {
                $message = $throwTag->getDescription();
            } else {
                if (!$message) {
                    $exceptionClassDocBlock = new DocBlock($exceptionClass->getDocComment());
                    $message = $exceptionClassDocBlock->getDescription();
                }
            }

            $exceptionResponse->description = $message;
        }

        $bodyParameter = null;

        foreach ($operation->parameters as $parameter) {
            if ($parameter instanceof BodyParameter) {
                $bodyParameter = $parameter;
                break;
            }
        }

        /** @var \phpDocumentor\Reflection\DocBlock\Tags\Param $paramTag */
        foreach ($docBlock->getTagsByName('param') as $paramTag) {
            $parameterName = trim($paramTag->getVariableName(), '$');

            /** @var QueryParameter $parameter */
            $parameter = null;
            foreach ($operation->parameters as $existingParameter) {
                if ($existingParameter->name == $parameterName) {
                    $parameter = $existingParameter;
                    break;
                }
            }

            if (!is_null($parameter)) {
                if (!$parameter->description) {
                    $parameter->description = $paramTag->getDescription();
                }

                if (!$parameter->type) {
                    $parameter->type = (string)$paramTag->getType();
                }
                continue;
            }

            if (!is_null($bodyParameter)) {
                /* @var BodyParameter $bodyParameter */
                if (isset($bodyParameter->schema->properties[$parameterName])) {
                    $parameter = $bodyParameter->schema->properties[$parameterName];

                    if (!$parameter->description) {
                        $parameter->description = $paramTag->getDescription();
                    }

                    if (!$parameter->type) {
                        $subContext = $extractionContext->createSubContext();
                        $subContext->setParameter('direction', 'in');
                        $extractionContext->getSwagger()->extract(
                            (string)$paramTag->getType(),
                            $parameter, $subContext
                        );
                    }

                    continue;
                }
            }
        }
    }

    /**
     * @param \Exception $exception
     *
     * @return array|mixed
     */
    private function getExceptionInformation(\Exception $exception)
    {
        foreach ($this->exceptionResponseCodes as $class => $information) {
            if ($exception instanceof $class) {
                return $information;
            }
        }

        return [500, null];
    }

    /**
     * @param string $exceptionClass
     * @param int $code
     * @param null $message
     *
     * @return void
     */
    public function registerExceptionResponseCodes($exceptionClass, $code = 500, $message = null)
    {
        $this->exceptionResponseCodes[$exceptionClass] = [$code, $message];
    }
}
