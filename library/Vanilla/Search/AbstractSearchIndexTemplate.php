<?php
/**
 * @copyright 2009-2020 Vanilla Forums Inc.
 * @license Proprietary
 */

namespace Vanilla\Search;

use Garden\Schema\Schema;

/**
 * Class AbstractSearchIndexTemplate
 *
 * @package Vanilla\Search
 */
abstract class AbstractSearchIndexTemplate {

    const OPT_NO_INDEX = "x-no-index-field";

    const SIMPLE_FIELD_TYPE_MAPPING = [
        "integer"  => ["type" => "integer"],
        "keyword"  => ["type" => "keyword"],
        "constant_keyword"  => ["type" => "constant_keyword"],
        "string"   => ["type" => "text", "fields" => ["keyword" => ["type" => "keyword", "ignore_above" => 256]]],
        "boolean"  => ["type" =>  "boolean"],
        "datetime" => ["type" => "date"],
    ];

    const COMPLEX_FIELD_TYPE_MAPPING = [
        "array",
        "object",
    ];

    /**
     * Common expand fields that we can't trust to be up-to-date in the engine.
     */
    const IGNORE_LIST = [
        'breadcrumbs',
        'insertUser',
        'updateUser',
        'lastInsertUser',
        'lastPost',
        'role',
        'parent',
        'siteSections',
        'allowedDiscussionTypes',
        'title',
        'private',
        'punished',
    ];

    /**
     * Get indexTemplate.
     *
     * returns array
     *
     */
    abstract public function getTemplate(): array;

    /**
     * Get the index name.
     *
     * @return string
     */
    abstract public function getTemplateName(): string;

    /**
     * Convert a schema to the template.
     *
     * @param Schema $schema
     *
     * @return array
     */
    protected function convertSchema(Schema $schema): array {
        $schemaArray = $schema->getSchemaArray();
        $fields = $schemaArray['properties'];
        ksort($fields);
        $template = [];
        foreach ($fields as $fieldName => $properties) {
            if (in_array($fieldName, self::IGNORE_LIST)) {
                continue;
            }

            if ($properties[self::OPT_NO_INDEX] ?? false) {
                continue;
            }

            $type = $properties['type'] ?? '';
            $analyzer = $properties['x-analyzer'] ?? null;
            $nullValue = $properties['x-null-value'] ?? null;

            // ignore nullables fields for now.
            if (is_array($type)) {
                $type = $type[0];
            }

            // Case specific. Do not apply onto to lowercase ID
            // Lowercase IDs could be `bodyPlainText_id` (locale is "id").
            if (str_ends_with($fieldName, "ID")) {
                // ID fields don't need to support range queries.
                // As a result elastic will query them more efficiently as a keyword type.
                // See https://www.elastic.co/guide/en/elasticsearch/reference/current/keyword.html#keyword-field-type
                $type = "keyword";
            }

            if ($fieldName === "siteID") {
                // Every index shares content from the same site
                // constant keyword allows better optimization of queries using this field.
                $type = "constant_keyword";
            }

            if (array_key_exists($type, self::SIMPLE_FIELD_TYPE_MAPPING)) {
                $template[$fieldName] = self::SIMPLE_FIELD_TYPE_MAPPING[$type];
                if ($type === "string" && $analyzer) {
                    $template[$fieldName]['analyzer'] = $analyzer;
                }
                if (!is_null($nullValue)) {
                    $template[$fieldName]['null_value'] = $nullValue;
                }
            }

            if (in_array($type, self::COMPLEX_FIELD_TYPE_MAPPING)) {
                $items = $properties['items'] ?? [];
                if ($items && is_array($items) && count($items) === 1) {
                    $template[$fieldName] = self::SIMPLE_FIELD_TYPE_MAPPING[$properties['items']['type']];
                } else {
                    $propertiesItems = $this->parseMultiDimensionalTypes($properties, $type);
                    $template[$fieldName]['properties'] = $propertiesItems;
                }
            }
        }
        return $template;
    }

    /**
     * Parse MultiDimensional Types (objects, arrays).
     *
     * @param mixed $properties
     * @param string $type
     * @return array
     */
    protected function parseMultiDimensionalTypes($properties, string $type): array {
        $items = [];

        if ($type === 'array') {
            /** @var Schema $items */
            $items = $properties['items'] ?? [];
            $items = ($items instanceof Schema) ? $items->getSchemaArray() : [];
            $items = $items['properties'] ?? [];
            $items = $this->filterItems($items);
        }

        if ($type === 'object') {
            $items = $properties['properties'] ?? [];
            $items = $this->filterItems($items);
        }

        $subItems = [];
        foreach ($items as $itemName => $value) {
            $itemType = $value['type'] ?? '';

            if (is_array($itemType)) {
                $itemType = $itemType[0];
            }

            if (array_key_exists($itemType, self::SIMPLE_FIELD_TYPE_MAPPING)) {
                $subItems[$itemName] = self::SIMPLE_FIELD_TYPE_MAPPING[$itemType];
            }
        }
        return  $subItems;
    }

    /**
     * Filter items list.
     *
     * @param array $items
     *
     * @return array
     */
    protected function filterItems(array $items = []): array {
        foreach ($items as $key => $item) {
            if (in_array($key, self::IGNORE_LIST)) {
                unset($items[$key]);
            }
        }
        return $items;
    }
}
