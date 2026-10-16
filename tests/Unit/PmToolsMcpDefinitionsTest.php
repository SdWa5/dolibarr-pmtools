<?php

/* SPDX-License-Identifier: GPL-3.0-or-later */

use PHPUnit\Framework\TestCase;

final class PmToolsMcpDefinitionsTest extends TestCase
{
    public function testNamesAreUniqueAndPrefixed(): void
    {
        $names = PmToolsMcpDefinitions::names();
        $this->assertCount(10, $names);
        $this->assertSame($names, array_unique($names));
        foreach ($names as $name) {
            $this->assertStringStartsWith('pm_', $name);
        }
    }

    public function testEveryDefinitionIsAValidToolSchema(): void
    {
        foreach (PmToolsMcpDefinitions::all() as $definition) {
            $this->assertNotEmpty($definition['description']);
            $this->assertSame('object', $definition['inputSchema']['type']);
            $this->assertIsBool($definition['annotations']['readOnlyHint']);
            $this->assertIsBool($definition['annotations']['destructiveHint']);
            $properties = (array) $definition['inputSchema']['properties'];
            foreach ($definition['inputSchema']['required'] ?? [] as $required) {
                $this->assertArrayHasKey($required, $properties, $definition['name']);
            }
            // An empty property list must encode as a JSON object, not an array.
            $this->assertStringContainsString('"properties":{', json_encode($definition['inputSchema']));
        }
    }

    public function testOnlyListAndGetToolsAreReadOnly(): void
    {
        $readOnly = [];
        foreach (PmToolsMcpDefinitions::all() as $definition) {
            if ($definition['annotations']['readOnlyHint']) {
                $readOnly[] = $definition['name'];
            }
        }
        $this->assertSame(['pm_tickets_list', 'pm_ticket_get', 'pm_projects_list', 'pm_ticket_dictionary_list'], $readOnly);
    }

    public function testDictionaryEnumFollowsTheWhitelist(): void
    {
        $definitions = array_column(PmToolsMcpDefinitions::all(), null, 'name');
        $properties = (array) $definitions['pm_ticket_dictionary_upsert']['inputSchema']['properties'];
        $this->assertSame(array_keys(PmToolsValidator::DICTIONARIES), $properties['dictionary']['enum']);
    }
}
