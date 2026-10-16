<?php

/* SPDX-License-Identifier: GPL-3.0-or-later */

use Luracast\Restler\RestException;

require_once __DIR__.'/pmtoolsservice.class.php';

/**
 * Project management endpoints the core API lacks: writing ticket dictionaries and closing,
 * canceling and reopening tickets the way the ticket card does.
 *
 * @access protected
 *
 * @class  DolibarrApiAccess {@requires user,external}
 */
class Pmtools extends DolibarrApi
{
    public function __construct()
    {
        global $db;
        $this->db = $db;
    }

    /**
     * List the entries of a ticket dictionary.
     *
     * @param string $name ticket_types, ticket_severities or ticket_categories
     *
     * @return array<int, array<string, mixed>>
     *
     * @url GET dictionary/{name}
     *
     * @throws RestException 400
     * @throws RestException 403
     */
    public function getDictionary($name)
    {
        return $this->call(function (PmToolsService $service) use ($name) {
            return $service->listDictionary($name);
        });
    }

    /**
     * Create a ticket dictionary entry or update it by code. Applying the same entry twice
     * changes nothing.
     *
     * @param string $name         ticket_types, ticket_severities or ticket_categories
     * @param array  $request_data {@from body} code, label, pos, description, active, use_default
     *                             and, per dictionary, color or public
     *
     * @return array<string, mixed> action (created, updated or unchanged) and the stored entry
     *
     * @url POST dictionary/{name}
     *
     * @throws RestException 400
     * @throws RestException 403
     */
    public function postDictionary($name, $request_data = null)
    {
        return $this->call(function (PmToolsService $service) use ($name, $request_data) {
            return $service->upsertDictionaryEntry($name, (array) $request_data);
        });
    }

    /**
     * Same as POST, for clients that send updates with PUT.
     *
     * @param string $name         ticket_types, ticket_severities or ticket_categories
     * @param array  $request_data {@from body} the entry, see POST
     *
     * @return array<string, mixed>
     *
     * @url PUT dictionary/{name}
     *
     * @throws RestException 400
     * @throws RestException 403
     */
    public function putDictionary($name, $request_data = null)
    {
        return $this->postDictionary($name, $request_data);
    }

    /**
     * Close a ticket as solved.
     *
     * @param string $id ticket id or ref
     *
     * @return array<string, mixed> action (closed or unchanged) and the ticket
     *
     * @url POST tickets/{id}/close
     *
     * @throws RestException 403
     * @throws RestException 404
     * @throws RestException 409
     */
    public function closeTicket($id)
    {
        return $this->call(function (PmToolsService $service) use ($id) {
            return $service->closeTicket($id, 'closed');
        });
    }

    /**
     * Close a ticket as not solved (canceled).
     *
     * @param string $id ticket id or ref
     *
     * @return array<string, mixed> action (canceled or unchanged) and the ticket
     *
     * @url POST tickets/{id}/cancel
     *
     * @throws RestException 403
     * @throws RestException 404
     * @throws RestException 409
     */
    public function cancelTicket($id)
    {
        return $this->call(function (PmToolsService $service) use ($id) {
            return $service->closeTicket($id, 'canceled');
        });
    }

    /**
     * Reopen a closed or canceled ticket.
     *
     * @param string $id ticket id or ref
     *
     * @return array<string, mixed> action (reopened or unchanged) and the ticket
     *
     * @url POST tickets/{id}/reopen
     *
     * @throws RestException 403
     * @throws RestException 404
     */
    public function reopenTicket($id)
    {
        return $this->call(function (PmToolsService $service) use ($id) {
            return $service->reopenTicket($id);
        });
    }

    /**
     * Runs a service call and maps its exceptions onto HTTP status codes.
     */
    private function call(callable $operation)
    {
        if (!isModEnabled('pmtools')) {
            throw new RestException(501, 'Module PmTools is not enabled');
        }
        try {
            return $operation(new PmToolsService($this->db, DolibarrApiAccess::$user));
        } catch (PmToolsDeniedException $e) {
            throw new RestException(403, $e->getMessage());
        } catch (PmToolsNotFoundException $e) {
            throw new RestException(404, $e->getMessage());
        } catch (DomainException $e) {
            throw new RestException(409, $e->getMessage());
        } catch (InvalidArgumentException $e) {
            throw new RestException(400, $e->getMessage());
        }
    }
}
