<?php

namespace Anorm\GraphQL\Type;

use GraphQL\Error\Error;
use GraphQL\Error\SerializationError;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\StringValueNode;
use GraphQL\Language\Printer;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Utils\Utils;

/**
 * A calendar date as ISO 8601 `YYYY-MM-DD`: out of a model's \DateTimeInterface
 * property (or a date string straight from the database), in as a
 * \DateTimeImmutable at midnight UTC.
 *
 * Use instance(), never a container: a schema may hold only one type named `Date`,
 * and every generated Type and Input refers to this one.
 */
class DateType extends ScalarType
{
    /** @var DateType|null */
    private static $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self([
                'name' => 'Date',
                'description' => 'A calendar date, as ISO 8601 `YYYY-MM-DD`.',
            ]);
        }
        return self::$instance;
    }

    /**
     * @param mixed $value
     * @return string|null
     * @throws SerializationError
     */
    public function serialize($value)
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_string($value)) {
            // MySQL's zero date is how older schemas say "no date".
            if (strncmp($value, '0000-00-00', 10) === 0) {
                return null;
            }
            $day = substr($value, 0, 10);
            $rest = (string) substr($value, 10);
            if (self::fromString($day) !== null && ($rest === '' || $rest[0] === ' ' || $rest[0] === 'T')) {
                return $day;
            }
        }
        throw new SerializationError('Date cannot represent value: ' . Utils::printSafe($value));
    }

    /**
     * @param mixed $value
     * @throws Error
     */
    public function parseValue($value): \DateTimeImmutable
    {
        $date = is_string($value) ? self::fromString($value) : null;
        if ($date === null) {
            throw new Error('Date must be a date as YYYY-MM-DD: ' . Utils::printSafeJson($value));
        }
        return $date;
    }

    /**
     * @param array<string, mixed>|null $variables
     * @throws Error
     */
    public function parseLiteral(Node $valueNode, ?array $variables = null): \DateTimeImmutable
    {
        if ($valueNode instanceof StringValueNode) {
            $date = self::fromString($valueNode->value);
            if ($date !== null) {
                return $date;
            }
        }
        throw new Error('Date must be a date as YYYY-MM-DD: ' . Printer::doPrint($valueNode), $valueNode);
    }

    /** A real calendar day written exactly as YYYY-MM-DD, or null. */
    private static function fromString(string $value): ?\DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}\z/', $value) !== 1 || $value === '0000-00-00') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        // createFromFormat rolls 2026-02-30 over to March; a date that does not print back is not one.
        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }
}
