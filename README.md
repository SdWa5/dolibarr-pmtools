# Dolibarr PmTools

PmTools is a Dolibarr external module that fills the gaps of the core REST API and the core MCP
server for ticket and project work. It adds:

- **REST endpoints** for writing the ticket dictionaries (types, severities, categories) and for
  closing, canceling and reopening tickets the way the ticket card does;
- **MCP tools** for the MCP server that ships with Dolibarr 24 (module `ai`), covering tickets,
  projects and the ticket dictionaries, which the core server does not offer.

Both sides call one service class, so validation, rights and behaviour are the same whichever way a
client comes in. Every write goes through the core classes with the caller's rights, so triggers,
the ticket history and `date_close` match what the user interface does.

The module follows SemVer from 1.0.0. A minor release changes nothing a deployment relies on, so
pinning the major version is safe. Deployments check out a tag.

## Requirements

- Dolibarr 24.0 or later, with the modules Tickets and, for the MCP tools, `ai` enabled
- PHP 8.1 or later

## Installation

Clone the repository as `custom/pmtools` and check out a tag, then enable **PmTools** in
Setup → Modules. On erp.sdwa5.org this is `tools/dolibarr/deploy-module.sh pmtools
https://github.com/SdWa5/dolibarr-pmtools.git v1.0.0` from `sdwa5-vps`.

Writing a dictionary needs an admin or the right **Manage ticket dictionaries** (5003021).
Closing, canceling and reopening needs the same right the ticket card asks for, which is the ticket
write right, or "manage advanced" when `MAIN_USE_ADVANCED_PERMS` is on.

## REST API

All paths are below `/api/index.php/pmtools` and take the usual `DOLAPIKEY` header.

| Method | Path | Does |
|---|---|---|
| GET | `dictionary/{name}` | Lists the entries of `ticket_types`, `ticket_severities` or `ticket_categories`, inactive ones included |
| POST, PUT | `dictionary/{name}` | Creates an entry or updates it by `code`. Body: `code`, `label` (required when new), `pos`, `description`, `active`, `use_default`, plus `color` for severities and `public` for categories |
| POST | `tickets/{id}/close` | Closes a ticket as solved |
| POST | `tickets/{id}/cancel` | Closes a ticket as not solved |
| POST | `tickets/{id}/reopen` | Reopens a closed or canceled ticket |

`{id}` is a ticket ref such as `TS2610-0050` or the numeric id. Every write answers with an
`action` of `created`, `updated`, `closed`, `canceled`, `reopened` or `unchanged`, so repeating a call
is harmless. Turning a closed ticket into a canceled one, or back, answers 409, because it needs a
reopen first. Bad input answers 400, a missing right 403 and an unknown ticket 404.

```sh
curl -H "DOLAPIKEY: $KEY" -H 'Content-Type: application/json' \
  --data '{"code":"RECURRING","label":"Dauerauftrag","pos":50}' \
  https://erp.example.org/api/index.php/pmtools/dictionary/ticket_types
```

## MCP tools

The module registers these tools through the `addMcpTools` hook. They run with the rights of the
MCP service user (`AI_MCP_USER_ID`). Each definition carries `readOnlyHint` and `destructiveHint`.

| Tool | Read-only |
|---|---|
| `pm_tickets_list` | yes |
| `pm_ticket_get` | yes |
| `pm_ticket_create` | no |
| `pm_ticket_update` | no |
| `pm_ticket_add_message` | no, private and without mail by default |
| `pm_ticket_close` | no, marked destructive |
| `pm_ticket_reopen` | no |
| `pm_projects_list` | yes |
| `pm_ticket_dictionary_list` | yes |
| `pm_ticket_dictionary_upsert` | no |

A failing call returns a text result that starts with `Error:` and says why. The core server would
turn a thrown error into a bare "Internal server error".

## Development

```sh
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app composer:2 install
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app php:8.1-cli vendor/bin/phpunit --testsuite unit
```

The integration suite needs a Dolibarr 24 container with this checkout mounted as `custom/pmtools`.
Each test runs inside a transaction that is rolled back.

```sh
docker exec -u www-data -e DOLIBARR_MAIN_INC=/var/www/html/master.inc.php \
  -w /var/www/html/custom/pmtools <container> vendor/bin/phpunit --testsuite integration
```

Code follows the Symfony coding standards (`.php-cs-fixer.dist.php`). Changes are listed in
[CHANGELOG.md](CHANGELOG.md).

## License

GPL-3.0-or-later, see [LICENSE](LICENSE).
