# AGENTS.md

Guidance for AI agents and contributors working in this repository.

## Project

BackQ is a PHP library for background queue processing: jobs are published to a queue
(Beanstalkd, Redis, NSQ, DynamoDB + SQS) and consumed by long-running workers. It sends
push notifications via AWS SNS, executes PSR-7 requests asynchronously via Guzzle,
and runs OS processes via `symfony/process`.

## Layout

- `src/` — library code, PSR-4 namespace `BackQ\` (see `autoload` in `composer.json`)
  - `src/Adapter/` — queue adapters. `AbstractAdapter` is the base contract; concrete:
    `Redis`, `Nsq`, `DynamoSQS`, `Beanstalk`
  - `src/Worker/` — job workers. Extend `AbstractWorker`, implement `run(): void`
  - `src/Publisher/` — job publishers. Extend `AbstractPublisher`, implement `setupAdapter()`
  - `src/Message/` — job payloads implementing `ConsumeInterface`
- `example/` — runnable publisher/worker examples (not shipped)
- `build/` — code-quality configuration: `psalm.xml`, `phpstan.neon`, `phpcs-ruleset.xml`,
  `phan.php`, `phpmd-rulesets.xml`, Dockerfiles
- `plans/` — design/modernization plans to be implemented in future changes

## Environment

- PHP >= 8.1 (see `plans/php-8.1-modernization.md`); `vendor/` and `composer.lock`
  are gitignored; install and verify via composer
- Changes are made on dedicated branches and land as pull requests
- Integration tests for the `Redis` and `Nsq` adapters (`tests/Adapter/RedisAdapterTest.php`,
  `tests/Adapter/NsqAdapterTest.php`) require running services; they are exercised via the
  dockerized app in `build/` and skip themselves when the services are unreachable

## Commands

- `composer install` — install dependencies
- `composer app-tests` — run the PHPUnit suite inside the dockerized app (`build/Dockerfile.php81`)
  with `redis` + `nsq` services from `build/docker-compose.yaml`; requires Docker
- `composer app-tests-local` — run the PHPUnit suite on the host (Redis/Nsq integration
  tests skip without the services)
- `docker compose -f build/docker-compose.yaml up -d --build` — build and start app.php81,
  redis, nsq containers
- `composer app-code-quality` — run the full quality suite (phpcs, phpcbf, phpstan, psalm,
  phan, phpmd, pdepend)
- Syntax check: `php -l <file>`

## Conventions

- Follow the PSR-12 ruleset in `build/phpcs-ruleset.xml`; 4-space indentation, LF endings
- Prefer typed properties and parameters
- Do not add comments unless they add real value; keep code self-documenting
- Do not commit `vendor/`, `composer.lock`, or secrets
- New public API must be accompanied by an entry in `UPGRADING`

## Verification

- After changing code run `php -l` on touched files and the relevant code-quality tool(s)
  (phpcs/phpstan/psalm) before considering work done