<?php

/* SPDX-License-Identifier: GPL-3.0-or-later */

/**
 * The MCP tool definitions of PmTools. Kept apart from the tool class so that they can be tested
 * without a Dolibarr bootstrap.
 */
class PmToolsMcpDefinitions
{
    private const TICKET = ['type' => 'string', 'description' => 'Ticket ref such as TS2610-0050, or the numeric id.'];

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        $codes = [
            'type_code' => ['type' => 'string', 'description' => 'Code from pm_ticket_dictionary_list ticket_types.'],
            'severity_code' => ['type' => 'string', 'description' => 'Code from pm_ticket_dictionary_list ticket_severities.'],
            'category_code' => ['type' => 'string', 'description' => 'Code from pm_ticket_dictionary_list ticket_categories.'],
            'project_ref' => ['type' => 'string', 'description' => 'Project ref; an empty string unlinks the project.'],
        ];

        return [
            self::tool('pm_tickets_list', 'List tickets, newest first.', [
                'status' => ['type' => 'string', 'enum' => ['open', 'closed', 'all'], 'description' => 'Defaults to open.'],
                'type_code' => $codes['type_code'],
                'severity_code' => $codes['severity_code'],
                'category_code' => $codes['category_code'],
                'project_ref' => ['type' => 'string', 'description' => 'Only tickets of this project.'],
                'search' => ['type' => 'string', 'description' => 'Words to find in ref, subject or description.'],
                'limit' => ['type' => 'integer', 'description' => '1 to 500, default 50.'],
            ], [], true, false),
            self::tool('pm_ticket_get', 'Get one ticket with its description and messages.', [
                'ticket' => self::TICKET,
                'with_messages' => ['type' => 'boolean', 'description' => 'Defaults to true.'],
            ], ['ticket'], true, false),
            self::tool('pm_ticket_create', 'Create a ticket.', [
                'subject' => ['type' => 'string'],
                'message' => ['type' => 'string', 'description' => 'The description.'],
            ] + $codes, ['subject', 'message'], false, false),
            self::tool('pm_ticket_update', 'Change subject, type, severity, category, project or progress of a ticket. Only given fields change.', [
                'ticket' => self::TICKET,
                'subject' => ['type' => 'string'],
                'progress' => ['type' => 'integer', 'description' => '0 to 100.'],
            ] + $codes, ['ticket'], false, false),
            self::tool('pm_ticket_add_message', 'Add a message to a ticket. No mail is sent.', [
                'ticket' => self::TICKET,
                'message' => ['type' => 'string'],
                'private' => ['type' => 'boolean', 'description' => 'Defaults to true.'],
            ], ['ticket', 'message'], false, false),
            self::tool('pm_ticket_close', 'Close a ticket as solved (closed) or not solved (canceled). Closing it again with the same mode changes nothing.', [
                'ticket' => self::TICKET,
                'mode' => ['type' => 'string', 'enum' => ['closed', 'canceled'], 'description' => 'Defaults to closed.'],
            ], ['ticket'], false, true),
            self::tool('pm_ticket_reopen', 'Reopen a closed or canceled ticket.', [
                'ticket' => self::TICKET,
            ], ['ticket'], false, false),
            self::tool('pm_projects_list', 'List projects the user may read.', [
                'status' => ['type' => 'string', 'enum' => ['draft', 'open', 'closed', 'all'], 'description' => 'Defaults to open.'],
                'limit' => ['type' => 'integer', 'description' => '1 to 500, default 50.'],
            ], [], true, false),
            self::tool('pm_ticket_dictionary_list', 'List the entries of a ticket dictionary, inactive ones included.', [
                'dictionary' => self::dictionaryProperty(),
            ], ['dictionary'], true, false),
            self::tool('pm_ticket_dictionary_upsert', 'Create a ticket dictionary entry or update it by code. Needs admin or the PmTools dictionary right.', [
                'dictionary' => self::dictionaryProperty(),
                'code' => ['type' => 'string', 'description' => 'A-Z, 0-9 and _, at most 32 characters.'],
                'label' => ['type' => 'string', 'description' => 'Required for a new entry.'],
                'pos' => ['type' => 'integer'],
                'description' => ['type' => 'string'],
                'active' => ['type' => 'integer', 'enum' => [0, 1]],
                'use_default' => ['type' => 'integer', 'enum' => [0, 1]],
                'color' => ['type' => 'string', 'description' => 'ticket_severities only, six hex digits.'],
                'public' => ['type' => 'integer', 'enum' => [0, 1], 'description' => 'ticket_categories only.'],
            ], ['dictionary', 'code'], false, false),
        ];
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_column(self::all(), 'name');
    }

    /**
     * @return array<string, mixed>
     */
    private static function dictionaryProperty(): array
    {
        return ['type' => 'string', 'enum' => array_keys(PmToolsValidator::DICTIONARIES)];
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     * @param list<string>                        $required
     *
     * @return array<string, mixed>
     */
    private static function tool(string $name, string $description, array $properties, array $required, bool $readOnly, bool $destructive): array
    {
        $schema = ['type' => 'object', 'properties' => (object) $properties];
        if ($required) {
            $schema['required'] = $required;
        }

        return [
            'name' => $name,
            'description' => $description,
            'inputSchema' => $schema,
            'annotations' => [
                'readOnlyHint' => $readOnly,
                'destructiveHint' => $destructive,
                'idempotentHint' => $readOnly || in_array($name, ['pm_ticket_close', 'pm_ticket_reopen', 'pm_ticket_dictionary_upsert', 'pm_ticket_update'], true),
            ],
        ];
    }
}
