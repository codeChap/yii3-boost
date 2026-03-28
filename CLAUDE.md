# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Yii3 AI Boost** (`codechap/yii3-boost`) is a Model Context Protocol (MCP) server for Yii3 applications. It gives AI assistants tools to introspect the framework, inspect databases, query configuration, and more -- all via JSON-RPC 2.0 over STDIO.

The package integrates through Yii3's config-plugin system (`yiisoft/config`). It ships **16 built-in tools**, a set of Symfony Console commands (`boost:mcp`, `boost:install`, `boost:info`, `boost:update`), and FTS5-powered semantic search over Yii3 documentation.

Requires PHP 8.1+.

## Development Commands

```bash
# Run all tests
composer test

# Generate code coverage report
composer test:coverage

# Run PHPStan static analysis (level 8)
composer analyze

# Start MCP server (for manual testing)
./yii boost:mcp

# View installation status
./yii boost:info

# Run installation wizard
./yii boost:install

# Update guidelines index
./yii boost:update
```

## Architecture

### High-Level Layers

```
Symfony Console Commands (src/Command/)
    |
    v
MCP Server (src/Mcp/Server.php) -- JSON-RPC 2.0 dispatcher
    |-- Tools  (src/Mcp/Tool/)       -- domain logic, introspection
    |-- Search (src/Mcp/Search/)     -- FTS5-powered semantic search
    '-- Transport (src/Mcp/Transport/) -- STDIO I/O
         |
         v
Yii3 Application (DI Container, Config, Router, DB, ActiveRecord)
```

### Config-Plugin Integration

The package registers itself via `composer.json` > `extra.config-plugin`:

- **`config/params.php`** -- default parameters (server name, tool enable/disable flags, model paths)
- **`config/params-console.php`** -- console command registration (`boost:mcp`, `boost:install`, etc.)
- **`config/di.php`** -- DI definitions wiring `Server`, `TransportInterface -> StdioTransport`, and constructor args from params

When a host Yii3 application runs `composer install`, the config-plugin merges these files into the application's merged config automatically.

### DI-Based Tool Resolution

Tools are **not** instantiated upfront. The `Server` holds a static name-to-class map (`TOOL_MAP`). When `tools/list` or `tools/call` is invoked, each tool class is resolved from the PSR-11 `ContainerInterface` on demand:

```php
$tool = $this->container->get(ApplicationInfoTool::class);
```

This means tools only need their dependencies declared in their constructor. The Yii3 DI container auto-wires them. If a tool's dependency is missing (e.g., `yiisoft/db` not installed), resolution fails gracefully and the tool is reported as unavailable rather than crashing the server.

### Graceful Degradation

Many tools depend on optional packages (`yiisoft/db`, `yiisoft/router`, `yiisoft/active-record`). The server catches resolution failures during `tools/list` and records them in `$unavailableTools`. The client sees only the tools that can actually be instantiated.

## Request Flow

```
1. Client sends JSON-RPC request via STDIN
2. StdioTransport::listen() reads line via fgets()
3. Server::handleRequest() parses JSON, validates structure
4. Server::dispatch() routes method via match expression:
     initialize  -> handshake + capabilities
     tools/list  -> resolve each tool from DI, collect metadata
     tools/call  -> resolve named tool from DI, call execute()
5. Tool executes, calling Yii3 services (Config, DB, Router, etc.)
6. AbstractTool::sanitize() strips sensitive keys from output
7. Server wraps result in JSON-RPC 2.0 response envelope
8. Response written to STDOUT (one line, newline-terminated)
```

STDOUT is reserved exclusively for JSON-RPC. All debug output, errors, and logging go to STDERR and log files.

## Adding a New Tool

1. **Create the class** in `src/Mcp/Tool/MyNewTool.php`:
   - Extend `AbstractTool` (which implements `ToolInterface`)
   - Inject any Yii3 services you need via the constructor
   - Implement `getName()`, `getDescription()`, `getInputSchema()`, `execute()`
   - Use `$this->sanitize($data)` before returning data that may contain secrets

2. **Register in the tool map** -- add an entry to `Server::TOOL_MAP`:
   ```php
   'my_new_tool' => MyNewTool::class,
   ```

3. **Add a default enable flag** in `config/params.php` under `tools`:
   ```php
   'my_new_tool' => true,
   ```

4. **If the tool depends on an optional package**, add it to `composer.json` > `suggest` with a description. The tool will degrade gracefully if the package is missing.

5. **Write tests** in `tests/`.

6. **Update README.md** with the new tool's description.

No DI configuration is needed -- the Yii3 container auto-wires the tool's constructor dependencies.

## Tools

| Tool | Description | Optional Dependency |
|------|-------------|---------------------|
| `application_info` | App version, environment, packages, extensions | -- |
| `config_inspector` | DI definitions and params (sanitized) | -- |
| `console_command_inspector` | Registered console commands | `yiisoft/yii-console` |
| `database_query` | Execute SELECT queries (disabled by default) | `yiisoft/db` |
| `database_schema` | Table/column/index metadata | `yiisoft/db` |
| `dev_server` | Development server management | -- |
| `env_inspector` | Environment variables, PHP config | -- |
| `log_inspector` | Application log reading | `yiisoft/log-target-file` |
| `middleware_inspector` | HTTP middleware stack | `yiisoft/middleware-dispatcher` |
| `migration_inspector` | Migration status and history | `yiisoft/db-migration` |
| `model_inspector` | ActiveRecord model analysis | `yiisoft/active-record` |
| `performance_profiler` | EXPLAIN plans, index analysis | `yiisoft/db` |
| `route_inspector` | Registered HTTP routes | `yiisoft/router` |
| `semantic_search` | FTS5 search over Yii3 guides | -- |
| `service_inspector` | DI container service listing | -- |
| `tinker` | Execute PHP in app context (disabled by default) | -- |

Tools disabled by default in `config/params.php`: `database_query`, `tinker`.

## Key Architectural Decisions

**ConfigInterface for introspection** -- Tools like `ApplicationInfoTool` and `ConfigInspectorTool` use `Yiisoft\Config\ConfigInterface` to read merged config groups (`params`, `di`, `di-web`, `di-console`). This provides safe, read-only access to the full application configuration without accessing raw files.

**Lazy tool resolution** -- Tools are resolved from the DI container only when requested, not at server startup. This keeps startup fast and avoids failures from missing optional packages until a tool is actually needed.

**Graceful degradation** -- If a tool's dependencies cannot be satisfied (missing package, misconfigured service), the server catches the exception and excludes that tool from `tools/list`. The server continues operating with the remaining tools.

**STDIO-only transport** -- Simplifies IDE integration (no port conflicts, no network config). `TransportInterface` exists for future extensibility but only `StdioTransport` is implemented.

**AbstractTool sanitization** -- All tools inherit `sanitize()` which recursively redacts keys matching sensitive patterns (password, token, secret, dsn, etc.). This prevents accidental credential leaks in tool output.

**Symfony Console commands** -- Uses Symfony Console (via `yiisoft/yii-console`) with PHP 8.1 attributes (`#[AsCommand]`) for command registration.

**No auto-discovery** -- Tools are explicitly listed in `Server::TOOL_MAP`. This makes it clear which tools exist, allows conditional enable/disable via config, and avoids filesystem scanning.

## Testing Strategy

- **Framework**: PHPUnit 10+/11+, bootstrap in `tests/bootstrap.php` (loads autoloader only)
- **Static analysis**: PHPStan level 8 (`composer analyze`), tool files excluded from analysis via `phpstan.neon`
- **Code coverage**: `composer test:coverage` generates HTML report in `coverage/`

Tests should verify:
- JSON-RPC protocol compliance (parse errors, method routing, error codes)
- Tool `execute()` output structure and sanitization
- Graceful handling of missing dependencies
- Transport read/write behavior

## File Structure

```
config/
  di.php                  -- DI definitions (Server, TransportInterface)
  params.php              -- Default params (tool flags, model paths)
  params-console.php      -- Console command registration
src/
  Command/
    McpCommand.php        -- boost:mcp (starts MCP server)
    InstallCommand.php    -- boost:install (IDE config wizard)
    InfoCommand.php       -- boost:info (status display)
    UpdateCommand.php     -- boost:update (guidelines management)
  Mcp/
    Server.php            -- Core JSON-RPC dispatcher, tool registry
    Tool/
      ToolInterface.php   -- Contract: getName, getDescription, getInputSchema, execute
      AbstractTool.php    -- Base class: sanitize(), getClassNameFromFile(), scanForSubclasses()
      *Tool.php           -- 16 concrete tool implementations
    Transport/
      TransportInterface.php -- Contract: listen(callable)
      StdioTransport.php     -- STDIO implementation (fgets/fwrite)
    Search/
      SearchIndexManager.php     -- FTS5 index (raw PDO to SQLite)
      MarkdownSectionParser.php  -- Markdown to H2-bounded sections
      GitHubGuideDownloader.php  -- Yii3 guide fetcher/cache
tests/
  bootstrap.php           -- Autoloader bootstrap
phpunit.xml               -- Test suite config
phpstan.neon              -- Static analysis config (level 8)
composer.json             -- Package metadata, scripts, config-plugin
```

## Logging

Server logs to `/tmp/mcp-server/`:
- **`mcp-requests.log`** -- all JSON-RPC requests and dispatch info
- **`mcp-transport.log`** -- low-level STDIO read/write events

The `McpCommand` redirects PHP errors to STDERR and clears output buffers to keep STDOUT clean for the protocol.

## Debugging Tips

- **STDOUT corruption**: Never `echo`, `var_dump`, or `print_r` in tool code. Use `fwrite(STDERR, ...)` for debug output.
- **Tool not appearing**: Check `/tmp/mcp-server/mcp-requests.log` for resolution failures. Verify the optional dependency is installed.
- **Protocol issues**: Send a raw JSON-RPC request to the running server and inspect the response line.
- **Config not merging**: Run `composer dump-autoload` to regenerate the config merge plan.
