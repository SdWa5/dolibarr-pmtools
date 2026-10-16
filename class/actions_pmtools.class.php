<?php

/* SPDX-License-Identifier: GPL-3.0-or-later */

/**
 * Hook class of PmTools. Registers the module's MCP tools with the core MCP server.
 */
class ActionsPmTools
{
    /** @var DoliDB */
    public $db;

    /** @var array<int|string, mixed> */
    public $results = [];

    /** @var string */
    public $resprints = '';

    /** @var string */
    public $error = '';

    /** @var string[] */
    public $errors = [];

    /**
     * @param DoliDB $db
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Hook addMcpTools in context aimcp. The handler expects a list of tool lists.
     *
     * @param array<string, mixed> $parameters  db, user and conf of the MCP request
     * @param object               $object      the McpHandler
     * @param string               $action
     * @param HookManager          $hookmanager
     */
    public function addMcpTools($parameters, &$object, &$action, $hookmanager): int
    {
        if (!isModEnabled('pmtools')) {
            return 0;
        }
        require_once __DIR__.'/mcp/pmtoolsmcptool.class.php';

        $this->results = [[new PmToolsMcpTool($parameters['db'], $parameters['user'], $parameters['conf'])]];

        return 0;
    }
}
