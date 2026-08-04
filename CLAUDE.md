<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4
- laravel/framework (LARAVEL) - v13
- laravel/prompts (PROMPTS) - v0
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- phpunit/phpunit (PHPUNIT) - v12

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This application uses PHPUnit for testing. All tests must be written as PHPUnit classes. Use `php artisan make:test --phpunit {name}` to create a new test.
- If you see a test using "Pest", convert it to PHPUnit.
- Every time a test has been updated, run that singular test.
- When the tests relating to your feature are passing, ask the user if they would like to also run the entire test suite to make sure everything is still passing.
- Tests should cover all happy paths, failure paths, and edge cases.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files; these are core to the application.

## Running Tests

- Run the minimal number of tests, using an appropriate filter, before finalizing.
- To run all tests: `php artisan test --compact`.
- To run all tests in a file: `php artisan test --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `php artisan test --compact --filter=testName` (recommended after making a change to a related file).

</laravel-boost-guidelines>

## Project rules (nexolu-pos-api)

### Language

- All identifiers (classes, methods, variables, route names, config keys, table/column aliases you introduce) must be in **English**, even when porting logic from the legacy monolith that mixes English and Spanish. Rename as you port.
- Code comments may be written in **Spanish**.
- User-facing strings (validation messages, notification text) follow whatever the legacy app already did (Spanish, since the product is Colombia-only) unless told otherwise.

### Tests are mandatory per module

- Every migrated module must ship with feature and/or unit tests before it's considered done - no exceptions. The legacy monolith has large untested areas (see `CONTEXT.md` from the legacy repo); do not repeat that here.
- Use `DatabaseTransactions`, not `RefreshDatabase`, since the schema comes from `database/legacy-schema/schema.sql` (loaded once into the `testing` database), not from migrations.
- Writing tests for legacy behavior is also a bug hunt: if a test reveals the legacy logic was wrong (see the "BUGS CONOCIDOS" section of the legacy `CONTEXT.md`), fix it in the new implementation and call it out - don't silently port a known bug.

### Improve as you go - don't just port

- This is a migration, not a transcription. When you notice duplicated logic (e.g. the legacy pattern of the same calculation reimplemented in 4 different services/controllers - see "BUGS CONOCIDOS" in the legacy `CONTEXT.md`), a missing abstraction, a naming inconsistency, or any other real improvement while working on a module, make it - don't just flag it and move on, and don't wait for explicit approval on straightforward, well-scoped refactors.
- This applies both to legacy code being ported and to code already written in this repo: if a later module reveals that an earlier one should be refactored (shared trait, extracted service, deduplicated validation), do that refactor as part of the current work.
- Keep changes proportional: refactor what the current module's logic actually touches, not a speculative rewrite of unrelated areas. Every behavior change still needs test coverage.

### Database & migrations - never run `php artisan migrate` against this app's databases

- The schema (85 tables) comes entirely from `database/legacy-schema/schema.sql`, loaded once directly via `mysql` into both the dev (`pos_saas`) and `testing` databases. `database/migrations/` must stay empty of anything that touches a table already in that dump - `php artisan migrate` is never part of this app's setup or deploy flow.
- Running it anyway is actively dangerous: on a DB with schema.sql already loaded, Laravel's default skeleton migrations (`users`, `cache`, `jobs`, etc.) fail with "table already exists"; on a truly empty DB they'd create the wrong structure (e.g. a `users` table missing `business_id` and every other legacy column our code depends on). These skeleton migrations were removed from the repo for exactly this reason - don't reintroduce them via `laravel new`-style scaffolding or `make:migration` for a table that already exists in `schema.sql`. Check `schema.sql` first.
- `QUEUE_CONNECTION` must stay `redis`, matching the legacy monolith - the shared schema has no `jobs`/`job_batches` tables (legacy never used the database queue driver), so `QUEUE_CONNECTION=database` will crash the moment anything dispatches a job.
- A migration in this repo is only ever appropriate for a table that is genuinely new (not in `schema.sql`) and that the legacy monolith will never read or write - e.g. pure internal infrastructure. It must never create or alter a table the legacy app already uses; see "Issues that need the monolith retired first" below for why.

### Issues that need the monolith retired first

- Some inconsistencies (e.g. `payment_method` vocabulary diverging across shared tables, `linkable_type` storing a bare FQCN instead of a morph-map alias) can't be fixed module-by-module: the legacy monolith still reads/writes the same tables, so silently fixing the data here just reintroduces the inconsistency on its next write. Never write a migration in this repo that mutates shared legacy tables to fix these.
- Track them in `docs/CUTOVER_TODO.md` instead: the exact problem, the fix (SQL/config), and the precondition for when it's safe to run (after the monolith stops touching that table, or a coordinated legacy-side change). Append to it whenever a module reveals a new one - don't just mention it in a commit message and move on.
