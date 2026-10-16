<?php

/* SPDX-License-Identifier: GPL-3.0-or-later */

require_once __DIR__.'/pmtoolsvalidator.class.php';
require_once DOL_DOCUMENT_ROOT.'/ticket/class/ticket.class.php';
require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';

/** A requested object does not exist or is not visible to the user. */
class PmToolsNotFoundException extends RuntimeException
{
}

/** The user lacks the right for the operation. */
class PmToolsDeniedException extends RuntimeException
{
}

/**
 * The single implementation behind the REST endpoints and the MCP tools. Every write goes through
 * the core classes Ticket and DoliDB with the caller's rights, so validation, triggers and the
 * ticket history stay the same as in the user interface.
 */
class PmToolsService
{
    /** @var DoliDB */
    private $db;

    /** @var User */
    private $user;

    /**
     * @param DoliDB $db
     * @param User   $user the user whose rights apply, the API user or the MCP service user
     */
    public function __construct($db, $user)
    {
        $this->db = $db;
        $this->user = $user;
    }

    // Dictionaries

    /**
     * Lists the entries of a ticket dictionary in the current entity, inactive ones included.
     *
     * @return list<array<string, mixed>>
     */
    public function listDictionary(string $name): array
    {
        $this->requireRight('ticket', 'read');
        $dictionary = PmToolsValidator::dictionary($name);
        $columns = array_merge(['rowid', 'code'], $dictionary['columns']);

        $sql = 'SELECT '.implode(', ', $columns).' FROM '.MAIN_DB_PREFIX.$dictionary['table'];
        $sql .= ' WHERE entity = '.((int) $this->entity());
        $sql .= ' ORDER BY pos, code';

        return $this->rows($sql);
    }

    /**
     * Creates a dictionary entry or updates the given fields of an existing one, matched by code.
     * Unchanged entries are left alone, so applying the same entry twice is harmless.
     *
     * @param array<string, mixed> $entry
     *
     * @return array{action: 'created'|'updated'|'unchanged', entry: array<string, mixed>}
     */
    public function upsertDictionaryEntry(string $name, array $entry): array
    {
        if (empty($this->user->admin) && !$this->user->hasRight('pmtools', 'dictionary')) {
            throw new PmToolsDeniedException('Writing ticket dictionaries needs admin or the pmtools dictionary right.');
        }
        $dictionary = PmToolsValidator::dictionary($name);
        $code = PmToolsValidator::dictionaryEntry($name, ['code' => $entry['code'] ?? ''], false)['code'];
        $stored = $this->dictionaryRow($dictionary['table'], $code);
        $clean = PmToolsValidator::dictionaryEntry($name, $entry, null === $stored);

        if (null === $stored) {
            $clean += ['active' => 1, 'use_default' => 0];
            if (!isset($clean['pos'])) {
                $clean['pos'] = 'c_ticket_category' === $dictionary['table'] ? 0 : '0';
            }
            $values = [];
            foreach ($clean as $value) {
                $values[] = is_int($value) ? (string) $value : "'".$this->db->escape($value)."'";
            }
            $sql = 'INSERT INTO '.MAIN_DB_PREFIX.$dictionary['table'].' (entity, '.implode(', ', array_keys($clean)).')';
            $sql .= ' VALUES ('.((int) $this->entity()).', '.implode(', ', $values).')';
            $this->execute($sql);
            dol_syslog('PmToolsService::upsertDictionaryEntry created '.$name.' '.$code);

            return ['action' => 'created', 'entry' => $this->dictionaryRow($dictionary['table'], $code)];
        }

        $changes = PmToolsValidator::changes($stored, $clean);
        if (!$changes) {
            return ['action' => 'unchanged', 'entry' => $stored];
        }
        $sets = [];
        foreach ($changes as $column => $value) {
            $sets[] = $column.' = '.(is_int($value) ? (string) $value : "'".$this->db->escape($value)."'");
        }
        $sql = 'UPDATE '.MAIN_DB_PREFIX.$dictionary['table'].' SET '.implode(', ', $sets);
        $sql .= ' WHERE rowid = '.((int) $stored['rowid']);
        $this->execute($sql);
        dol_syslog('PmToolsService::upsertDictionaryEntry updated '.$name.' '.$code.' '.implode(',', array_keys($changes)));

        return ['action' => 'updated', 'entry' => $this->dictionaryRow($dictionary['table'], $code)];
    }

    // Tickets

    /**
     * Lists tickets, newest first.
     *
     * @param array<string, mixed> $filters status (open|closed|all), type_code, severity_code,
     *                                      category_code, project_ref, search, limit
     *
     * @return list<array<string, mixed>>
     */
    public function listTickets(array $filters = []): array
    {
        $this->requireRight('ticket', 'read');
        $status = $filters['status'] ?? 'open';
        if (!in_array($status, ['open', 'closed', 'all'], true)) {
            throw new InvalidArgumentException('The status filter must be open, closed or all.');
        }

        $sql = 'SELECT t.rowid FROM '.MAIN_DB_PREFIX.'ticket AS t';
        $sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'projet AS p ON p.rowid = t.fk_project';
        $sql .= ' WHERE t.entity IN ('.getEntity('ticket').')';
        if ('open' === $status) {
            $sql .= ' AND t.fk_statut NOT IN (8, 9)';
        } elseif ('closed' === $status) {
            $sql .= ' AND t.fk_statut IN (8, 9)';
        }
        foreach (['type_code', 'severity_code', 'category_code'] as $codeFilter) {
            if (!empty($filters[$codeFilter])) {
                $sql .= ' AND t.'.$codeFilter." = '".$this->db->escape((string) $filters[$codeFilter])."'";
            }
        }
        if (!empty($filters['project_ref'])) {
            $sql .= " AND p.ref = '".$this->db->escape((string) $filters['project_ref'])."'";
        }
        if (!empty($filters['search'])) {
            $sql .= natural_search(['t.ref', 't.subject', 't.message'], (string) $filters['search']);
        }
        $sql .= ' ORDER BY t.datec DESC, t.rowid DESC';
        $sql .= $this->db->plimit(PmToolsValidator::limit($filters['limit'] ?? null));

        $tickets = [];
        foreach ($this->rows($sql) as $row) {
            $ticket = new Ticket($this->db);
            if ($ticket->fetch((int) $row['rowid']) > 0) {
                $tickets[] = $this->summary($ticket);
            }
        }

        return $tickets;
    }

    /**
     * One ticket with its description and, on request, its event log (messages, creation, closing), newest first.
     *
     * @param int|string $ref
     *
     * @return array<string, mixed>
     */
    public function getTicket($ref, bool $withMessages = true): array
    {
        $this->requireRight('ticket', 'read');
        $ticket = $this->fetchTicket($ref);
        $data = $this->summary($ticket);
        $data['message'] = (string) $ticket->message;
        if ($withMessages) {
            $ticket->loadCacheMsgsTicket();
            $data['messages'] = [];
            foreach ($ticket->cache_msgs_ticket as $message) {
                $data['messages'][] = [
                    'id' => (int) $message['id'],
                    'date' => dol_print_date($message['datec'], 'dayhourrfc', 'gmt'),
                    'private' => (int) $message['private'],
                    'author_id' => (int) $message['fk_user_author'],
                    'message' => (string) $message['message'],
                ];
            }
        }

        return $data;
    }

    /**
     * Creates a ticket. subject and message are required; type_code, severity_code and
     * category_code must exist in their dictionaries; project_ref links a project.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function createTicket(array $data): array
    {
        $this->requireRight('ticket', 'write');
        $this->rejectUnknown($data, ['subject', 'message', 'type_code', 'severity_code', 'category_code', 'project_ref']);
        $subject = trim((string) ($data['subject'] ?? ''));
        $message = trim((string) ($data['message'] ?? ''));
        if ('' === $subject || '' === $message) {
            throw new InvalidArgumentException('A ticket needs a subject and a message.');
        }

        $ticket = new Ticket($this->db);
        $ticket->ref = $ticket->getDefaultRef();
        $ticket->track_id = generate_random_id(16);
        $ticket->subject = $subject;
        $ticket->message = $message;
        $ticket->fk_user_create = $this->user->id;
        $this->applyCodesAndProject($ticket, $data);

        if ($ticket->create($this->user) <= 0) {
            throw new RuntimeException('Creating the ticket failed: '.$this->errorsOf($ticket));
        }

        return $this->summary($this->fetchTicket((int) $ticket->id));
    }

    /**
     * Updates subject, type, severity, category, project or progress of a ticket. Only the given
     * fields change.
     *
     * @param int|string           $ref
     * @param array<string, mixed> $data
     *
     * @return array{action: 'updated'|'unchanged', ticket: array<string, mixed>}
     */
    public function updateTicket($ref, array $data): array
    {
        $this->requireRight('ticket', 'write');
        $this->rejectUnknown($data, ['subject', 'type_code', 'severity_code', 'category_code', 'project_ref', 'progress']);
        $ticket = $this->fetchTicket($ref);
        $before = $this->summary($ticket);

        if (array_key_exists('subject', $data)) {
            $subject = trim((string) $data['subject']);
            if ('' === $subject) {
                throw new InvalidArgumentException('The subject must not be empty.');
            }
            $ticket->subject = $subject;
        }
        if (array_key_exists('progress', $data)) {
            if (!is_numeric($data['progress']) || (int) $data['progress'] < 0 || (int) $data['progress'] > 100) {
                throw new InvalidArgumentException('The progress must be 0 to 100.');
            }
            $ticket->progress = (int) $data['progress'];
        }
        $this->applyCodesAndProject($ticket, $data);

        $after = $this->summary($ticket);
        if ($after === $before) {
            return ['action' => 'unchanged', 'ticket' => $before];
        }
        if ($ticket->update($this->user) <= 0) {
            throw new RuntimeException('Updating the ticket failed: '.$this->errorsOf($ticket));
        }

        return ['action' => 'updated', 'ticket' => $this->summary($this->fetchTicket((int) $ticket->id))];
    }

    /**
     * Adds a message to a ticket, private by default, without sending mail.
     *
     * @param int|string $ref
     *
     * @return array<string, mixed>
     */
    public function addMessage($ref, string $message, bool $private = true): array
    {
        $this->requireRight('ticket', 'write');
        $message = trim($message);
        if ('' === $message) {
            throw new InvalidArgumentException('The message must not be empty.');
        }
        $ticket = $this->fetchTicket($ref);
        $ticket->message = $message;
        $ticket->private = $private ? 1 : 0;
        if ($ticket->createTicketMessage($this->user, 0, [], [], [], false, $private ? 0 : 1) <= 0) {
            throw new RuntimeException('Adding the message failed: '.$this->errorsOf($ticket));
        }

        return $this->getTicket((int) $ticket->id);
    }

    /**
     * Closes a ticket as solved (closed) or not solved (canceled) through Ticket::close(), which
     * sets progress and date_close and calls the TICKET_CLOSE trigger like the ticket card.
     *
     * @param int|string $ref
     *
     * @return array{action: 'closed'|'canceled'|'unchanged', ticket: array<string, mixed>}
     */
    public function closeTicket($ref, string $mode = 'closed'): array
    {
        $this->requireTicketManage();
        $modeValue = PmToolsValidator::closeMode($mode);
        $ticket = $this->fetchTicket($ref);
        if ('unchanged' === PmToolsValidator::closeDecision((int) $ticket->status, $modeValue)) {
            return ['action' => 'unchanged', 'ticket' => $this->summary($ticket)];
        }
        if ($ticket->close($this->user, $modeValue) <= 0) {
            throw new RuntimeException('Closing the ticket failed: '.$this->errorsOf($ticket));
        }

        return ['action' => $mode, 'ticket' => $this->summary($this->fetchTicket((int) $ticket->id))];
    }

    /**
     * Reopens a closed or canceled ticket the way the ticket card does.
     *
     * @param int|string $ref
     *
     * @return array{action: 'reopened'|'unchanged', ticket: array<string, mixed>}
     */
    public function reopenTicket($ref): array
    {
        $this->requireTicketManage();
        $ticket = $this->fetchTicket($ref);
        $status = PmToolsValidator::reopenStatus((int) $ticket->status, (int) $ticket->fk_user_assign);
        if (null === $status) {
            return ['action' => 'unchanged', 'ticket' => $this->summary($ticket)];
        }
        if ($ticket->setStatut($status, null, '', 'TICKET_MODIFY') <= 0) {
            throw new RuntimeException('Reopening the ticket failed: '.$this->errorsOf($ticket));
        }

        return ['action' => 'reopened', 'ticket' => $this->summary($this->fetchTicket((int) $ticket->id))];
    }

    // Projects

    /**
     * Lists the projects the user may read.
     *
     * @return list<array<string, mixed>>
     */
    public function listProjects(string $status = 'open', $limit = null): array
    {
        $this->requireRight('projet', 'lire');
        $statuses = ['draft' => [0], 'open' => [1], 'closed' => [2], 'all' => [0, 1, 2]];
        if (!isset($statuses[$status])) {
            throw new InvalidArgumentException('The status filter must be draft, open, closed or all.');
        }

        $sql = 'SELECT p.rowid, p.ref, p.title, p.fk_statut, p.dateo, p.datee FROM '.MAIN_DB_PREFIX.'projet AS p';
        $sql .= ' WHERE p.entity IN ('.getEntity('project').')';
        $sql .= ' AND p.fk_statut IN ('.implode(',', $statuses[$status]).')';
        if (!$this->user->hasRight('projet', 'all', 'lire')) {
            $project = new Project($this->db);
            $visible = $project->getProjectsAuthorizedForUser($this->user, 0, 1);
            $sql .= ' AND p.rowid IN ('.($visible ? $this->db->sanitize($visible) : '0').')';
        }
        $sql .= ' ORDER BY p.ref';
        $sql .= $this->db->plimit(PmToolsValidator::limit($limit));

        $projects = [];
        foreach ($this->rows($sql) as $row) {
            $projects[] = [
                'id' => (int) $row['rowid'],
                'ref' => $row['ref'],
                'title' => $row['title'],
                'status' => [0 => 'draft', 1 => 'open', 2 => 'closed'][(int) $row['fk_statut']] ?? (string) $row['fk_statut'],
                'date_start' => $row['dateo'],
                'date_end' => $row['datee'],
            ];
        }

        return $projects;
    }

    // Helpers

    /**
     * @param int|string $ref
     */
    public function fetchTicket($ref): Ticket
    {
        $key = PmToolsValidator::ticketRef($ref);
        $ticket = new Ticket($this->db);
        $result = $ticket->fetch($key['id'], $key['ref']);
        if ($result <= 0 || !in_array((int) $ticket->entity, array_map('intval', explode(',', getEntity('ticket'))), true)) {
            throw new PmToolsNotFoundException('Ticket '.$ref.' not found.');
        }

        return $ticket;
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Ticket $ticket): array
    {
        $project = '';
        if ($ticket->fk_project > 0) {
            $projectObject = new Project($this->db);
            if ($projectObject->fetch((int) $ticket->fk_project) > 0) {
                $project = $projectObject->ref;
            }
        }
        $statusNames = [0 => 'new', 1 => 'read', 2 => 'assigned', 3 => 'in_progress', 5 => 'need_more_info', 7 => 'waiting', 8 => 'closed', 9 => 'canceled'];

        return [
            'id' => (int) $ticket->id,
            'ref' => $ticket->ref,
            'subject' => $ticket->subject,
            'type_code' => $ticket->type_code,
            'severity_code' => $ticket->severity_code,
            'category_code' => $ticket->category_code,
            'status' => $statusNames[(int) $ticket->status] ?? (string) $ticket->status,
            'progress' => (int) $ticket->progress,
            'project_ref' => $project,
            'assigned_user_id' => (int) $ticket->fk_user_assign,
            'date_created' => $ticket->datec ? dol_print_date($ticket->datec, 'dayhourrfc', 'gmt') : '',
            'date_closed' => $ticket->date_close ? dol_print_date($ticket->date_close, 'dayhourrfc', 'gmt') : '',
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyCodesAndProject(Ticket $ticket, array $data): void
    {
        $dictionaries = ['type_code' => 'c_ticket_type', 'severity_code' => 'c_ticket_severity', 'category_code' => 'c_ticket_category'];
        foreach ($dictionaries as $field => $table) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $code = strtoupper(trim((string) $data[$field]));
            $row = $this->dictionaryRow($table, $code);
            if (null === $row || 1 !== (int) $row['active']) {
                throw new InvalidArgumentException('No active '.$field.' "'.$code.'".');
            }
            $ticket->$field = $code;
        }
        if (array_key_exists('project_ref', $data)) {
            $ref = trim((string) $data['project_ref']);
            if ('' === $ref) {
                $ticket->fk_project = 0;
            } else {
                $project = new Project($this->db);
                if ($project->fetch(0, $ref) <= 0) {
                    throw new InvalidArgumentException('No project "'.$ref.'".');
                }
                $ticket->fk_project = (int) $project->id;
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function dictionaryRow(string $table, string $code): ?array
    {
        $sql = 'SELECT * FROM '.MAIN_DB_PREFIX.$table;
        $sql .= " WHERE code = '".$this->db->escape($code)."' AND entity = ".((int) $this->entity());
        $rows = $this->rows($sql);

        return $rows[0] ?? null;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $allowed
     */
    private function rejectUnknown(array $data, array $allowed): void
    {
        $unknown = array_diff(array_keys($data), $allowed);
        if ($unknown) {
            throw new InvalidArgumentException('Unknown field(s): '.implode(', ', $unknown).'.');
        }
    }

    private function requireRight(string $module, string $permission): void
    {
        if (!$this->user->hasRight($module, $permission)) {
            throw new PmToolsDeniedException('Missing right '.$module.'->'.$permission.'.');
        }
    }

    /**
     * The same test the ticket card uses for close, abandon and reopen.
     */
    private function requireTicketManage(): void
    {
        $allowed = getDolGlobalString('MAIN_USE_ADVANCED_PERMS')
            ? $this->user->hasRight('ticket', 'manage_advance')
            : $this->user->hasRight('ticket', 'write');
        if (!$allowed) {
            throw new PmToolsDeniedException('Missing the right to manage tickets.');
        }
    }

    private function entity(): int
    {
        global $conf;

        return (int) $conf->entity;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql): array
    {
        $resql = $this->execute($sql);
        $rows = [];
        while ($row = $this->db->fetch_array($resql)) {
            $rows[] = array_filter($row, 'is_string', ARRAY_FILTER_USE_KEY);
        }
        $this->db->free($resql);

        return $rows;
    }

    /**
     * @return resource|object|bool
     */
    private function execute(string $sql)
    {
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new RuntimeException('Database error: '.$this->db->lasterror());
        }

        return $resql;
    }

    private function errorsOf(CommonObject $object): string
    {
        return trim($object->error.' '.implode(', ', (array) $object->errors));
    }
}
