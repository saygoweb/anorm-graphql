<?php

namespace Anorm\GraphQL;

class Mapper
{
    /**
     * The model's public properties as an associative array.
     *
     * A null property stays null. Turning it into '' is what made graphql-php's Int
     * serialiser throw on any row with an unset integer column.
     *
     * @param object $model
     * @param string[] $exclude Property names to leave out
     * @return array<string, mixed>
     */
    public static function toArray($model, $exclude = [])
    {
        $result = [];
        foreach (get_object_vars($model) as $key => $value) {
            if ($key[0] == '_' || in_array($key, $exclude)) {
                continue;
            }
            $result[$key] = $value;
        }
        return $result;
    }

    /**
     * Set the model's public properties from the matching keys of $array.
     *
     * @param object $model
     * @param array<string, mixed> $array
     * @param string[] $exclude Property names to leave alone
     * @return object The same model
     */
    public static function toModel(&$model, $array, $exclude = [])
    {
        foreach (get_object_vars($model) as $key => $value) {
            if ($key[0] == '_' || in_array($key, $exclude)) {
                continue;
            }
            if (array_key_exists($key, $array)) {
                $model->{$key} = $array[$key];
            }
        }
        return $model;
    }
}
