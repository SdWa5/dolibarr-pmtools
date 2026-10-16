<?php

/* SPDX-License-Identifier: GPL-3.0-or-later */

/**
 * Input rules shared by the REST API and the MCP tools. Runs without a Dolibarr bootstrap.
 */
class PmToolsValidator
{
    /**
     * The ticket dictionaries this module may write, by public name, with their table and the
     * columns a caller may set besides the code.
     */
    public const DICTIONARIES = [
        'ticket_types' => ['table' => 'c_ticket_type', 'columns' => ['label', 'pos', 'description', 'active', 'use_default']],
        'ticket_severities' => ['table' => 'c_ticket_severity', 'columns' => ['label', 'pos', 'description', 'active', 'use_default', 'color']],
        'ticket_categories' => ['table' => 'c_ticket_category', 'columns' => ['label', 'pos', 'description', 'active', 'use_default', 'public']],
    ];

    public const CLOSE_MODES = ['closed' => 0, 'canceled' => 1];

    /**
     * Returns the table and columns of a dictionary, or throws for a name outside the whitelist.
     *
     * @return array{table: string, columns: list<string>}
     */
    public static function dictionary(string $name): array
    {
        if (!isset(self::DICTIONARIES[$name])) {
            throw new InvalidArgumentException('Unknown dictionary "'.$name.'". Allowed: '.implode(', ', array_keys(self::DICTIONARIES)).'.');
        }

        return self::DICTIONARIES[$name];
    }

    /**
     * Validates one dictionary entry and returns it normalized. The code is required, the label is
     * required for a new entry only, and unknown keys are rejected rather than ignored.
     *
     * @param array<string, mixed> $entry
     *
     * @return array<string, int|string>
     */
    public static function dictionaryEntry(string $name, array $entry, bool $isNew): array
    {
        $columns = self::dictionary($name)['columns'];
        $unknown = array_diff(array_keys($entry), array_merge(['code'], $columns));
        if ($unknown) {
            throw new InvalidArgumentException('Unknown field(s): '.implode(', ', $unknown).'.');
        }

        $code = isset($entry['code']) ? strtoupper(trim((string) $entry['code'])) : '';
        if (!preg_match('/^[A-Z0-9_]{1,32}$/', $code)) {
            throw new InvalidArgumentException('The code must be 1 to 32 characters of A-Z, 0-9 and _.');
        }
        $clean = ['code' => $code];

        if (array_key_exists('label', $entry)) {
            $label = trim((string) $entry['label']);
            if ('' === $label || mb_strlen($label) > 128) {
                throw new InvalidArgumentException('The label must be 1 to 128 characters.');
            }
            $clean['label'] = $label;
        } elseif ($isNew) {
            throw new InvalidArgumentException('A new entry needs a label.');
        }

        if (array_key_exists('description', $entry)) {
            $description = trim((string) $entry['description']);
            if (mb_strlen($description) > 255) {
                throw new InvalidArgumentException('The description must be at most 255 characters.');
            }
            $clean['description'] = $description;
        }

        foreach (['pos', 'active', 'use_default', 'public'] as $intColumn) {
            if (array_key_exists($intColumn, $entry)) {
                if (!is_numeric($entry[$intColumn]) || (int) $entry[$intColumn] != $entry[$intColumn] || (int) $entry[$intColumn] < 0) {
                    throw new InvalidArgumentException('The field '.$intColumn.' must be a non-negative integer.');
                }
                $clean[$intColumn] = (int) $entry[$intColumn];
            }
        }
        foreach (['active', 'use_default', 'public'] as $flag) {
            if (isset($clean[$flag]) && $clean[$flag] > 1) {
                throw new InvalidArgumentException('The field '.$flag.' must be 0 or 1.');
            }
        }

        if (array_key_exists('color', $entry)) {
            $color = ltrim(trim((string) $entry['color']), '#');
            if ('' !== $color && !preg_match('/^[0-9a-fA-F]{6}$/', $color)) {
                throw new InvalidArgumentException('The color must be six hex digits.');
            }
            $clean['color'] = $color;
        }

        return $clean;
    }

    /**
     * The columns of a stored row that an update would change, with their new values.
     *
     * @param array<string, mixed>      $stored
     * @param array<string, int|string> $wanted
     *
     * @return array<string, int|string>
     */
    public static function changes(array $stored, array $wanted): array
    {
        $changes = [];
        foreach ($wanted as $column => $value) {
            if ('code' === $column) {
                continue;
            }
            if (!array_key_exists($column, $stored) || (string) $stored[$column] !== (string) $value) {
                $changes[$column] = $value;
            }
        }

        return $changes;
    }

    /**
     * Maps a close mode name onto the argument of Ticket::close().
     */
    public static function closeMode(string $mode): int
    {
        if (!isset(self::CLOSE_MODES[$mode])) {
            throw new InvalidArgumentException('The mode must be "closed" or "canceled".');
        }

        return self::CLOSE_MODES[$mode];
    }

    /**
     * Decides what closing a ticket in a given status does. Closing a ticket that already has the
     * wanted end status is a no-op, so a rerun of a sync is harmless. Turning a solved ticket into
     * a canceled one or back is refused, because it needs a reopen first.
     *
     * @return 'close'|'unchanged'
     */
    public static function closeDecision(int $status, int $mode): string
    {
        $wanted = $mode ? 9 : 8;
        if ($status === $wanted) {
            return 'unchanged';
        }
        if (8 === $status || 9 === $status) {
            throw new DomainException('The ticket is already '.(8 === $status ? 'closed' : 'canceled').'. Reopen it first.');
        }

        return 'close';
    }

    /**
     * The status a reopened ticket gets, matching the ticket card: assigned when someone is
     * assigned, otherwise new. Null means the ticket is open already.
     */
    public static function reopenStatus(int $status, int $assignedUserId): ?int
    {
        if (8 !== $status && 9 !== $status) {
            return null;
        }

        return $assignedUserId > 0 ? 2 : 0;
    }

    /**
     * A ticket reference as ref (TS2610-0050) or numeric id.
     *
     * @return array{id: int, ref: string}
     */
    public static function ticketRef($value): array
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            if ((int) $value <= 0) {
                throw new InvalidArgumentException('A ticket id must be positive.');
            }

            return ['id' => (int) $value, 'ref' => ''];
        }
        $ref = trim((string) $value);
        if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/', $ref)) {
            throw new InvalidArgumentException('Not a ticket reference: "'.$ref.'".');
        }

        return ['id' => 0, 'ref' => $ref];
    }

    /**
     * A list limit between 1 and 500, 50 by default.
     */
    public static function limit($value): int
    {
        if (null === $value || '' === $value) {
            return 50;
        }
        if (!is_numeric($value) || (int) $value < 1) {
            throw new InvalidArgumentException('The limit must be a positive integer.');
        }

        return min(500, (int) $value);
    }
}
