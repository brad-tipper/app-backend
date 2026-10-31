<?php

namespace BradTipper\AppBackend\OpenApi;

/**
 * Tiny OpenAPI 3.1 builder. Controllers describe their endpoints in a static
 * `describe(Spec $spec)` method; OpenApiExportTask collects them into
 * `openapi.json`, which hey-api turns into the typed client.
 */
final class Spec
{
    /** @var array<string, array<string, mixed>> */
    private array $schemas = [];

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $paths = [];

    public function __construct(
        private readonly string $title,
        private readonly string $version = '1.0.0',
        private readonly string $serverUrl = 'http://localhost',
    ) {
    }

    // ---- schema helpers -------------------------------------------------

    /** @return array<string, mixed> */
    public static function string(?string $format = null, bool $nullable = false, array $extra = []): array
    {
        $schema = ['type' => $nullable ? ['string', 'null'] : 'string'] + $extra;
        if ($format !== null) {
            $schema['format'] = $format;
        }
        return $schema;
    }

    /** @return array<string, mixed> */
    public static function integer(bool $nullable = false, array $extra = []): array
    {
        return ['type' => $nullable ? ['integer', 'null'] : 'integer'] + $extra;
    }

    /** @return array<string, mixed> */
    public static function boolean(): array
    {
        return ['type' => 'boolean'];
    }

    /** @param string[] $values @return array<string, mixed> */
    public static function enum(array $values, bool $nullable = false): array
    {
        return ['type' => $nullable ? ['string', 'null'] : 'string', 'enum' => $nullable ? [...$values, null] : $values];
    }

    /** @return array<string, mixed> */
    public static function ref(string $name): array
    {
        return ['$ref' => '#/components/schemas/' . $name];
    }

    /** @param array<string, mixed> $items @return array<string, mixed> */
    public static function arrayOf(array $items): array
    {
        return ['type' => 'array', 'items' => $items];
    }

    /** @param array<string, array<string, mixed>> $properties @param string[]|null $required defaults to all properties @return array<string, mixed> */
    public static function object(array $properties, ?array $required = null): array
    {
        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required ?? array_keys($properties),
            'additionalProperties' => false,
        ];
    }

    /** A free-form JSON object. @return array<string, mixed> */
    public static function map(): array
    {
        return ['type' => 'object', 'additionalProperties' => true];
    }

    /** @param array<string, mixed> $schema @return array<string, mixed> */
    public static function nullable(array $schema): array
    {
        return ['oneOf' => [$schema, ['type' => 'null']]];
    }

    // ---- registration ---------------------------------------------------

    /** @param array<string, mixed> $definition */
    public function schema(string $name, array $definition): self
    {
        $this->schemas[$name] = $definition;
        return $this;
    }

    /**
     * @param array{
     *   tags?: string[],
     *   summary?: string,
     *   auth?: bool,
     *   body?: array<string, mixed>,
     *   path?: array<string, array<string, mixed>>,
     *   query?: array<string, array{schema: array<string, mixed>, required?: bool}>,
     *   responses?: array<int, array<string, mixed>|null>,
     * } $options
     */
    public function operation(string $method, string $path, string $operationId, array $options): self
    {
        $operation = [
            'operationId' => $operationId,
            'tags' => $options['tags'] ?? [],
            'summary' => $options['summary'] ?? $operationId,
        ];
        if (($options['auth'] ?? false) === true) {
            $operation['security'] = [['bearerAuth' => []]];
        }

        $parameters = [];
        foreach ($options['path'] ?? [] as $name => $schema) {
            $parameters[] = ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => $schema];
        }
        foreach ($options['query'] ?? [] as $name => $definition) {
            $parameters[] = [
                'name' => $name,
                'in' => 'query',
                'required' => $definition['required'] ?? false,
                'schema' => $definition['schema'],
            ];
        }
        if ($parameters) {
            $operation['parameters'] = $parameters;
        }

        if (isset($options['body'])) {
            $operation['requestBody'] = [
                'required' => true,
                'content' => ['application/json' => ['schema' => $options['body']]],
            ];
        }

        $responses = [];
        foreach ($options['responses'] ?? [200 => null] as $status => $schema) {
            $response = ['description' => $status >= 400 ? 'Error' : 'OK'];
            if ($schema !== null) {
                $response['content'] = ['application/json' => ['schema' => $schema]];
            }
            $responses[(string) $status] = $response;
        }
        // Every operation can fail with problem+json.
        $responses['default'] = [
            'description' => 'Error',
            'content' => ['application/problem+json' => ['schema' => self::ref('Problem')]],
        ];
        $operation['responses'] = $responses;

        $this->paths[$path][strtolower($method)] = $operation;
        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        ksort($this->schemas);
        ksort($this->paths);
        return [
            'openapi' => '3.1.0',
            'info' => ['title' => $this->title, 'version' => $this->version],
            'servers' => [['url' => $this->serverUrl]],
            'paths' => $this->paths ?: new \stdClass(),
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
                ],
                'schemas' => $this->schemas,
            ],
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }
}
