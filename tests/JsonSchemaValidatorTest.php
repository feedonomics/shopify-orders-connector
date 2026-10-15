<?php

namespace ShopifyOrdersConnector\tests;

use PHPUnit\Framework\TestCase;
use ShopifyOrdersConnector\services\JsonSchemaValidator;

class JsonSchemaValidatorTest extends TestCase
{
    private const SCHEMA = [
        'components' => ['schemas' => [
            'Thing' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'count' => ['type' => 'integer'],
                ],
                'required' => ['name'],
                'additionalProperties' => false,
            ],
            'Loose' => [
                'type' => 'object',
                'properties' => ['name' => ['type' => 'string']],
            ],
        ]],
    ];

    /** @var string[] */
    private array $schema_files = [];

    protected function tearDown(): void
    {
        foreach ($this->schema_files as $file) {
            unlink($file);
        }
    }

    private function validator(): JsonSchemaValidator
    {
        $file = tempnam(sys_get_temp_dir(), 'schema');
        file_put_contents($file, json_encode(self::SCHEMA));
        $this->schema_files[] = $file;
        return new JsonSchemaValidator($file);
    }

    public function test_a_valid_payload_has_no_errors(): void
    {
        $this->assertSame([], $this->validator()->validate(['name' => 'a', 'count' => 3], 'Thing'));
    }

    public function test_a_missing_required_field(): void
    {
        $errors = $this->validator()->validate(['count' => 3], 'Thing');

        $this->assertCount(1, $errors);
        $this->assertSame(JsonSchemaValidator::MISSING_REQUIRED_FIELD, $errors[0]['code']);
        $this->assertStringContainsString('name', $errors[0]['message']);
    }

    public function test_an_empty_payload_is_missing_the_required_fields(): void
    {
        $errors = $this->validator()->validate([], 'Thing');

        $this->assertSame([JsonSchemaValidator::MISSING_REQUIRED_FIELD], array_column($errors, 'code'));
    }

    public function test_an_invalid_value(): void
    {
        $errors = $this->validator()->validate(['name' => 'a', 'count' => 'many'], 'Thing');

        $this->assertCount(1, $errors);
        $this->assertSame(JsonSchemaValidator::FIELD_INVALID_VALUE, $errors[0]['code']);
        $this->assertStringContainsString("'count'", $errors[0]['message']);
    }

    public function test_an_unknown_field(): void
    {
        $errors = $this->validator()->validate(['name' => 'a', 'extra' => 1], 'Thing');

        $this->assertCount(1, $errors);
        $this->assertSame(JsonSchemaValidator::UNKNOWN_FIELD_PRESENT, $errors[0]['code']);
        $this->assertStringContainsString("'extra'", $errors[0]['message']);
    }

    public function test_unknown_fields_are_allowed_if_the_model_allows_them(): void
    {
        $this->assertSame([], $this->validator()->validate(['name' => 'a', 'extra' => 1], 'Loose'));
    }

    public function test_the_errors_of_a_payload_can_be_ignored(): void
    {
        $validator = $this->validator();
        $errors = $validator->validate(['name' => 'a', 'extra' => 1], 'Thing');

        $this->assertSame([], $validator->validate(['name' => 'a', 'extra' => 1], 'Thing', $errors));
    }

    public function test_only_the_errors_that_match_are_ignored(): void
    {
        $validator = $this->validator();
        $ignored = $validator->validate(['name' => 'a', 'extra' => 1], 'Thing');

        $errors = $validator->validate(['name' => 'a', 'count' => 'many'], 'Thing', $ignored);

        $this->assertSame([JsonSchemaValidator::FIELD_INVALID_VALUE], array_column($errors, 'code'));
    }

    public function test_the_validator_can_be_used_more_than_once(): void
    {
        $validator = $this->validator();

        $this->assertNotEmpty($validator->validate(['count' => 3], 'Thing'));
        $this->assertSame([], $validator->validate(['name' => 'a'], 'Thing'));
    }

    public function test_get_schema_model_field_info(): void
    {
        $info = $this->validator()->get_schema_model_field_info('Thing');

        $this->assertSame(['name', 'count'], $info['properties']);
        $this->assertSame(['name'], $info['required']);
    }

    public function test_get_schema_model_field_info_of_a_model_without_required_fields(): void
    {
        $info = $this->validator()->get_schema_model_field_info('Loose');

        $this->assertSame(['name'], $info['properties']);
        $this->assertSame([], $info['required']);
    }

    public function test_get_schema_model_field_info_of_an_unknown_model(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->validator()->get_schema_model_field_info('Nothing');
    }

    public function test_the_schema_of_the_sdk_validates_orders(): void
    {
        $validator = new JsonSchemaValidator(__DIR__ . '/../resources/json_schema.json');

        $errors = $validator->validate([], 'Order');

        $this->assertNotEmpty($errors);
        $this->assertContains(JsonSchemaValidator::MISSING_REQUIRED_FIELD, array_column($errors, 'code'));
    }
}
