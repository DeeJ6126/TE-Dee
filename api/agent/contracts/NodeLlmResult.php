<?php
declare(strict_types=1);

final class NodeLlmResult
{
    public string $stage;
    public string $raw_text;
    public ?array $parsed_json;
    public bool $ok;
    /** @var string[] */
    public array $errors;
    public ?string $schema_version;

    /**
     * @param string[] $errors
     */
    public function __construct(
        string $stage,
        string $rawText,
        ?array $parsedJson,
        bool $ok,
        array $errors,
        ?string $schemaVersion
    ) {
        $this->stage = $stage;
        $this->raw_text = $rawText;
        $this->parsed_json = $parsedJson;
        $this->ok = $ok;
        $this->errors = array_values($errors);
        $this->schema_version = $schemaVersion;
    }

    public static function fromRawJson(string $stage, string $rawText, array $schema): self
    {
        $decoded = json_decode($rawText, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new self(
                $stage,
                $rawText,
                null,
                false,
                ['parse: ' . json_last_error_msg()],
                is_string($schema['version'] ?? null) ? $schema['version'] : null
            );
        }

        if (!is_array($decoded)) {
            return new self(
                $stage,
                $rawText,
                null,
                false,
                ['parse: JSON root must be an object'],
                is_string($schema['version'] ?? null) ? $schema['version'] : null
            );
        }

        $result = new self(
            $stage,
            $rawText,
            $decoded,
            false,
            [],
            is_string($decoded['schema_version'] ?? null) ? $decoded['schema_version'] : (is_string($schema['version'] ?? null) ? $schema['version'] : null)
        );

        return $result->validateAgainstSchema($schema);
    }

    public function validateAgainstSchema(array $schema): self
    {
        $errors = [];
        $data = $this->parsed_json;
        if (!is_array($data)) {
            $errors[] = 'schema: parsed_json must be an object';
            return new self($this->stage, $this->raw_text, $this->parsed_json, false, array_merge($this->errors, $errors), $this->schema_version);
        }

        $schemaStage = $schema['stage'] ?? null;
        if (is_string($schemaStage) && $this->stage !== $schemaStage) {
            $errors[] = "schema: caller stage must match schema stage {$schemaStage}";
        }

        foreach ((array)($schema['required'] ?? []) as $key) {
            if (!is_string($key) || !array_key_exists($key, $data)) {
                $errors[] = "schema: {$key} is required";
            }
        }

        foreach ((array)($schema['properties'] ?? []) as $key => $definition) {
            if (!is_array($definition) || !array_key_exists($key, $data)) {
                continue;
            }
            self::validateProperty($data[$key], $definition, $key, $errors);
        }

        foreach ((array)($schema['conditional_required'] ?? []) as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $conditions = (array)($rule['if'] ?? []);
            $matches = true;
            foreach ($conditions as $key => $expected) {
                if (!array_key_exists((string)$key, $data) || $data[(string)$key] !== $expected) {
                    $matches = false;
                    break;
                }
            }
            if (!$matches) {
                continue;
            }
            foreach ((array)($rule['required'] ?? []) as $key) {
                if (!is_string($key) || !array_key_exists($key, $data) || self::isEmptyString($data[$key])) {
                    $errors[] = "schema: {$key} is required";
                }
            }
        }

        $schemaVersion = is_string($data['schema_version'] ?? null)
            ? $data['schema_version']
            : (is_string($schema['version'] ?? null) ? $schema['version'] : $this->schema_version);

        $allErrors = array_values(array_merge($this->errors, $errors));
        return new self($this->stage, $this->raw_text, $data, $allErrors === [], $allErrors, $schemaVersion);
    }

    /**
     * @param string[] $errors
     */
    private static function validateProperty(mixed $value, array $definition, string $path, array &$errors): void
    {
        if (array_key_exists('type', $definition)) {
            $allowedTypes = is_array($definition['type']) ? $definition['type'] : [$definition['type']];
            $matchedType = false;
            foreach ($allowedTypes as $type) {
                if (self::matchesType($value, (string)$type)) {
                    $matchedType = true;
                    break;
                }
            }
            if (!$matchedType) {
                $errors[] = "schema: {$path} must be " . implode('|', array_map('strval', $allowedTypes));
                return;
            }
        }

        if (array_key_exists('const', $definition) && $value !== $definition['const']) {
            $errors[] = "schema: {$path} must be " . self::stringValue($definition['const']);
        }

        if (array_key_exists('enum', $definition) && !in_array($value, (array)$definition['enum'], true)) {
            $errors[] = "schema: {$path} must be one of " . implode(', ', array_map([self::class, 'stringValue'], (array)$definition['enum']));
        }
    }

    private static function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'object' => is_array($value) && !array_is_list($value),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            'number' => is_int($value) || is_float($value),
            'integer' => is_int($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => false,
        };
    }

    private static function isEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) === '';
    }

    private static function stringValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_scalar($value)) {
            return (string)$value;
        }
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }
}
