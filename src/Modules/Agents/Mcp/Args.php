<?php

declare(strict_types=1);

namespace Kontor\Shop\Modules\Agents\Mcp;

/**
 * Argumente eines Werkzeug-Aufrufs, gegen das Eingabeschema des Werkzeugs
 * geprueft (Pflichtfelder, Typen, enum, minimum/maximum, maxLength,
 * maxItems). Unbekannte Felder werden abgelehnt, damit ein Tippfehler des
 * Assistenten ("prize" statt "price") nicht still ignoriert wird.
 *
 * Bewusst nur der Teil von JSON Schema, den die Werkzeuge hier nutzen.
 */
final class Args
{
    /**
     * @param array<string, mixed> $values
     */
    private function __construct(private readonly array $values)
    {
    }

    /**
     * @param array<string, mixed> $schema Eingabeschema (type object)
     * @throws ToolError
     */
    public static function check(array $schema, mixed $input): self
    {
        if ($input === null) {
            $input = [];
        }

        if (!is_array($input) || ($input !== [] && array_is_list($input))) {
            throw new ToolError(__('The arguments must be an object.', 'kontor-shop'));
        }

        return new self(self::object($schema, $input, ''));
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values) && $this->values[$key] !== null;
    }

    public function str(string $key, string $default = ''): string
    {
        return $this->has($key) ? trim((string) $this->values[$key]) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        return $this->has($key) ? (int) $this->values[$key] : $default;
    }

    public function float(string $key, float $default = 0.0): float
    {
        return $this->has($key) ? (float) $this->values[$key] : $default;
    }

    public function nullable_float(string $key): ?float
    {
        return $this->has($key) ? (float) $this->values[$key] : null;
    }

    public function bool(string $key, bool $default = false): bool
    {
        return $this->has($key) ? (bool) $this->values[$key] : $default;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function list(string $key): array
    {
        return $this->has($key) && is_array($this->values[$key]) ? $this->values[$key] : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private static function object(array $schema, array $input, string $path): array
    {
        $properties = (array) ($schema['properties'] ?? []);
        $out        = [];

        foreach ((array) ($schema['required'] ?? []) as $required) {
            if (!array_key_exists($required, $input) || $input[$required] === null || $input[$required] === '') {
                /* translators: %s: argument name */
                throw new ToolError(sprintf(__('Missing argument: %s', 'kontor-shop'), $path . $required));
            }
        }

        foreach ($input as $key => $value) {
            $key = (string) $key;

            if (!isset($properties[$key])) {
                throw new ToolError(sprintf(
                    /* translators: 1: argument name, 2: list of allowed arguments */
                    __('Unknown argument: %1$s. Allowed: %2$s', 'kontor-shop'),
                    $path . $key,
                    implode(', ', array_keys($properties))
                ));
            }

            $out[$key] = $value === null ? null : self::value((array) $properties[$key], $value, $path . $key);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function value(array $schema, mixed $value, string $path): mixed
    {
        $types = (array) ($schema['type'] ?? []);
        $value = self::coerce($types, $value);

        if ($types !== [] && !self::matches($types, $value)) {
            /* translators: 1: argument name, 2: expected type */
            throw new ToolError(sprintf(__('Argument %1$s must be of type %2$s.', 'kontor-shop'), $path, implode('|', $types)));
        }

        if (isset($schema['enum']) && !in_array($value, (array) $schema['enum'], true)) {
            /* translators: 1: argument name, 2: allowed values */
            throw new ToolError(sprintf(__('Argument %1$s must be one of: %2$s', 'kontor-shop'), $path, implode(', ', array_map('strval', (array) $schema['enum']))));
        }

        if ((is_int($value) || is_float($value)) && isset($schema['minimum']) && $value < $schema['minimum']) {
            /* translators: 1: argument name, 2: minimum */
            throw new ToolError(sprintf(__('Argument %1$s must be at least %2$s.', 'kontor-shop'), $path, (string) $schema['minimum']));
        }

        if ((is_int($value) || is_float($value)) && isset($schema['maximum']) && $value > $schema['maximum']) {
            /* translators: 1: argument name, 2: maximum */
            throw new ToolError(sprintf(__('Argument %1$s must be at most %2$s.', 'kontor-shop'), $path, (string) $schema['maximum']));
        }

        if (is_string($value) && isset($schema['maxLength']) && mb_strlen($value) > (int) $schema['maxLength']) {
            /* translators: 1: argument name, 2: maximum length */
            throw new ToolError(sprintf(__('Argument %1$s is too long (at most %2$d characters).', 'kontor-shop'), $path, (int) $schema['maxLength']));
        }

        if (is_array($value) && array_is_list($value) && in_array('array', $types, true)) {
            if (isset($schema['maxItems']) && count($value) > (int) $schema['maxItems']) {
                /* translators: 1: argument name, 2: maximum number of entries */
                throw new ToolError(sprintf(__('Argument %1$s has too many entries (at most %2$d).', 'kontor-shop'), $path, (int) $schema['maxItems']));
            }

            if (isset($schema['items']) && is_array($schema['items'])) {
                foreach ($value as $index => $item) {
                    $value[$index] = self::value($schema['items'], $item, $path . '[' . $index . ']');
                }
            }
        }

        if (is_array($value) && in_array('object', $types, true) && isset($schema['properties'])) {
            $value = self::object($schema, $value, $path . '.');
        }

        return $value;
    }

    /**
     * Manche Assistenten schicken Zahlen als Text ("12") oder ganze Zahlen als
     * 12.0 - das wird hier passend umgewandelt, alles andere bleibt, wie es ist.
     *
     * @param array<int, string> $types
     */
    private static function coerce(array $types, mixed $value): mixed
    {
        if (in_array('integer', $types, true) && !in_array('string', $types, true)) {
            if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
                return (int) trim($value);
            }

            if (is_float($value) && floor($value) === $value) {
                return (int) $value;
            }
        }

        if (in_array('number', $types, true) && !in_array('string', $types, true) && is_string($value)) {
            $normalized = str_replace(',', '.', trim($value));

            if (is_numeric($normalized)) {
                return (float) $normalized;
            }
        }

        if (in_array('boolean', $types, true) && is_string($value) && in_array(strtolower($value), ['true', 'false'], true)) {
            return strtolower($value) === 'true';
        }

        return $value;
    }

    /**
     * @param array<int, string> $types
     */
    private static function matches(array $types, mixed $value): bool
    {
        foreach ($types as $type) {
            $ok = match ($type) {
                'string'  => is_string($value),
                'integer' => is_int($value),
                'number'  => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'array'   => is_array($value) && array_is_list($value),
                'object'  => is_array($value) && ($value === [] || !array_is_list($value)),
                'null'    => $value === null,
                default   => false,
            };

            if ($ok) {
                return true;
            }
        }

        return false;
    }
}
