<?php
namespace FeedonomicsWebHookSDK\services;

use JsonSchema\Validator;
use JsonSchema\SchemaStorage;
use JsonSchema\Constraints\Factory;

class JsonSchemaValidator
{
    const MISSING_REQUIRED_FIELD = 'MISSING_REQUIRED_FIELD';
    const FIELD_INVALID_VALUE = 'FIELD_INVALID_VALUE';
    const UNKNOWN_FIELD_PRESENT = 'UNKNOWN_FIELD_PRESENT';

    private Validator $validator;
    private $json_schema_object;

    public function __construct(string $json_schema_path)
    {
        $json_schema_contents = file_get_contents(realpath($json_schema_path));
        $this->json_schema_object = json_decode($json_schema_contents);

        $schema_storage = new SchemaStorage();
        $schema_storage->addSchema('file://mySchema', $this->json_schema_object);
        $factory = new Factory($schema_storage);
        $this->validator = new Validator($factory);
    }

    public function validate(array $payload, string $model, array $ignore_validation_errors = [])
    {
        $errors = [];

        //the JsonSchema\Validator needs the payload to be an object, slim decodes to an associative array.
        // If we use an array it will generate validation errors that value was an array, object expected
        if (!$payload) {
            $payload = new \stdClass();
        }
        else {
            $payload = json_decode(json_encode($payload), false);
        }

        $this->validator->validate($payload, (object)$this->json_schema_object->components->schemas->$model);
        if (!$this->validator->isValid()) {
            foreach ($this->validator->getErrors() as $validation_error) {
                $error = $this->get_response_for_validation_error($validation_error);
                $error = $this->filter_ignored_errors($error, $ignore_validation_errors);
                if ($error) {
                    $errors[] = $error;
                }
            }
        }

        return $errors;
    }

    private function filter_ignored_errors(array $error, array $ignore_validation_errors)
    {
        if (!$ignore_validation_errors) {
            return $error;
        }
        foreach ($ignore_validation_errors as $ignore) {
            if ($error == $ignore) {
                return null;
            }
        }
        return $error;
    }

    public function get_response_for_validation_error($validation_error)
    {
        $message = trim($validation_error['message']);

        if (!$validation_error['property'] && preg_match(
                "/(The property) ([^\s]+) (is not defined and the definition does not allow additional properties.*)/",
                $message,
                $matches
            )) {
            return [
                'code' => self::UNKNOWN_FIELD_PRESENT,
                'message' => "'{$matches[2]}' {$matches[1]} {$matches[3]}",
            ];
        }

        if (preg_match(
            "/The property [^\s]+ is required/",
            $message,
            $matches
        )) {
            $code = self::MISSING_REQUIRED_FIELD;
        }
        else {
            $code = self::FIELD_INVALID_VALUE;
        }
        return [
            'code' => $code,
            'message' => sprintf("'%s' %s", $validation_error['property'], $message),
        ];
    }

    /**
     * @param $model
     * @return array
     */
    public function get_schema_model_field_info($model)
    {
        $model = $this->json_schema_object->components->schemas->$model ?? null;

        if (!$model) {
            throw new \InvalidArgumentException("Requested schema model does not exist");
        }

        return [
            'properties' => array_keys((array)$model->properties),
            'required' => $model->required ?? [],
        ];
    }
}