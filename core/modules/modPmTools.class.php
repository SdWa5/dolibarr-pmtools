<?php

/* SPDX-License-Identifier: GPL-3.0-or-later */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Description and activation class for PmTools.
 */
class modPmTools extends DolibarrModules
{
    /**
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        global $conf;

        $this->db = $db;
        $this->numero = 500302;
        $this->rights_class = 'pmtools';
        $this->family = 'projects';
        $this->module_position = '90';
        $this->name = preg_replace('/^mod/i', '', get_class($this));
        $this->description = 'PmToolsDescription';
        $this->descriptionlong = 'PmToolsDescriptionLong';
        $this->version = '1.0.0';
        $this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
        $this->picto = 'ticket';

        $this->module_parts = [
            'triggers' => 0,
            'login' => 0,
            'substitutions' => 0,
            'menus' => 0,
            'tpl' => 0,
            'barcode' => 0,
            'models' => 0,
            'printing' => 0,
            'theme' => 0,
            'css' => [],
            'js' => [],
            'hooks' => ['aimcp'],
            'moduleforexternal' => 0,
        ];

        $this->dirs = [];
        $this->config_page_url = [];
        $this->hidden = false;
        $this->depends = ['modTicket'];
        $this->requiredby = [];
        $this->conflictwith = [];
        $this->langfiles = ['pmtools@pmtools'];
        $this->phpmin = [8, 1];
        $this->need_dolibarr_version = [24, 0];
        $this->need_javascript_ajax = 0;
        $this->const = [];

        if (!isModEnabled('pmtools')) {
            $conf->pmtools = new stdClass();
            $conf->pmtools->enabled = 0;
        }

        $this->rights = [];
        $this->rights[0][0] = 5003021;
        $this->rights[0][1] = 'Manage ticket dictionaries (types, severities, categories)';
        $this->rights[0][2] = 'w';
        $this->rights[0][3] = 0;
        $this->rights[0][4] = 'dictionary';

        $this->menu = [];
    }

    /**
     * @param string $options
     */
    public function init($options = ''): int
    {
        return $this->_init([], $options);
    }

    /**
     * @param string $options
     */
    public function remove($options = ''): int
    {
        return $this->_remove([], $options);
    }
}
