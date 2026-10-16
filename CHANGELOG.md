# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-10-16

### Added

- REST endpoints `GET|POST|PUT dictionary/{ticket_types|ticket_severities|ticket_categories}`,
  idempotent on `code`
- REST endpoints `POST tickets/{id}/close`, `cancel` and `reopen`, through `Ticket::close()` and the
  ticket card's reopen path
- MCP tools for the Dolibarr 24 core MCP server: tickets list, get, create, update, add message,
  close and reopen, projects list, ticket dictionary list and upsert
- Right 5003021 "Manage ticket dictionaries"
- PHPUnit unit suite and an integration suite that runs in a Dolibarr container and rolls back

[Unreleased]: https://github.com/SdWa5/dolibarr-pmtools/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/SdWa5/dolibarr-pmtools/releases/tag/v1.0.0
