<?php

/* SPDX-License-Identifier: GPL-3.0-or-later */

require_once DOL_DOCUMENT_ROOT.'/ai/class/mcptool.class.php';
require_once __DIR__.'/../pmtoolsservice.class.php';
require_once __DIR__.'/pmtoolsmcpdefinitions.class.php';

/**
 * The PmTools tools for the core MCP server. Each call runs with the rights of the MCP service user.
 *
 * Failures come back as a readable text result. The core handler turns a thrown exception or an
 * 'error' key into a bare "Internal server error", which tells the client nothing.
 */
class PmToolsMcpTool extends McpTool
{
    public function getDefinitions(): array
    {
        return PmToolsMcpDefinitions::all();
    }

    public function getCategories(): array
    {
        return ['ticket', 'project'];
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public function execute(string $toolName, array $args)
    {
        $service = new PmToolsService($this->db, $this->user);
        try {
            return $this->dispatch($service, $toolName, $args);
        } catch (PmToolsDeniedException $e) {
            return $this->failure('Permission denied. '.$e->getMessage());
        } catch (PmToolsNotFoundException|DomainException|InvalidArgumentException $e) {
            return $this->failure($e->getMessage());
        } catch (Throwable $e) {
            dol_syslog('PmToolsMcpTool '.$toolName.': '.$e->getMessage(), LOG_ERR);

            return $this->failure('Unexpected error. Details are in the Dolibarr log.');
        }
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    private function dispatch(PmToolsService $service, string $toolName, array $args)
    {
        $ticket = $args['ticket'] ?? '';
        unset($args['ticket']);

        switch ($toolName) {
            case 'pm_tickets_list':
                return ['tickets' => $service->listTickets($args)];
            case 'pm_ticket_get':
                return $service->getTicket($ticket, (bool) ($args['with_messages'] ?? true));
            case 'pm_ticket_create':
                return $service->createTicket($args);
            case 'pm_ticket_update':
                return $service->updateTicket($ticket, $args);
            case 'pm_ticket_add_message':
                return $service->addMessage($ticket, (string) ($args['message'] ?? ''), (bool) ($args['private'] ?? true));
            case 'pm_ticket_close':
                return $service->closeTicket($ticket, (string) ($args['mode'] ?? 'closed'));
            case 'pm_ticket_reopen':
                return $service->reopenTicket($ticket);
            case 'pm_projects_list':
                return ['projects' => $service->listProjects((string) ($args['status'] ?? 'open'), $args['limit'] ?? null)];
            case 'pm_ticket_dictionary_list':
                return ['entries' => $service->listDictionary((string) ($args['dictionary'] ?? ''))];
            case 'pm_ticket_dictionary_upsert':
                $dictionary = (string) ($args['dictionary'] ?? '');
                unset($args['dictionary']);

                return $service->upsertDictionaryEntry($dictionary, $args);
        }

        throw new InvalidArgumentException('Unknown tool '.$toolName.'.');
    }

    /**
     * @return array{content: list<array{type: string, text: string}>}
     */
    private function failure(string $message): array
    {
        return ['content' => [['type' => 'text', 'text' => 'Error: '.$message]]];
    }
}
