# AWS SNS & DynamoDB Testing Plan

- Status: Phase 1 (offline unit tests) implemented; Phase 2 (LocalStack integration)
  remains open
- Target: bring `BackQ\Adapter\DynamoSQS`, `BackQ\Adapter\Amazon\DynamoDb\QueueTableRow`
  and the `BackQ\Worker\Amazon\SNS` / `BackQ\Publisher\Amazon\SNS` classes to the same
  test coverage standard as the Redis/NSQ/Beanstalk adapters
- Related: `tests/Adapter/RedisAdapterTest.php`, `tests/Adapter/NsqAdapterTest.php`,
  `build/docker-compose.yaml`, `tests/Adapter/Amazon/DynamoDb/QueueTableRowTest.php`,
  `tests/Worker/Amazon/SNS/Application/PlatformEndpoint/PublishWorkerTest.php`

## 1. Goal

Add deterministic, offline unit tests plus optional dockerized integration tests for the
AWS-backed components, without requiring a real AWS account in CI. Every test must either
run against a local emulator (AWS SDK `MockHandler`, or LocalStack in docker) or skip
itself cleanly, following the pattern established by `RedisAdapterTest` / `NsqAdapterTest`.

## 2. Current state

| Target | Coverage today | Gap |
|---|---|---|
| `DynamoSQS` adapter (`putTask`, `pickTask`, `afterWorkSuccess`, TTL math, SQS URL generation) | none | full contract |
| `QueueTableRow` (`toArray`, `fromArray`, checksum verify) | `QueueTableRowTest` ✓ | mostly done; extend for TTL/`fromArray` metadata edge cases |
| SNS `PlatformEndpoint\Publish` worker | `PublishWorkerTest` ✓ (injected client double) | none critical |
| SNS `PlatformEndpoint\Register` worker | none | retry/`onSuccess`/error-branch paths |
| SNS `PlatformEndpoint\Remove` worker | none | retry/error-branch paths |
| SNS messages (`Publish`, `Register`, `Remove`) | `PublishMessageTest`, `RegisterRemoveMessageTest` ✓ | minimal |
| SNS publishers (`Register`, `Remove`, `Publish`) | none | queue-name derivation only |

The AWS SDK (`aws/aws-sdk-php` 3.395.7) is already a hard dependency and ships
`Aws\MockHandler`, so offline adapter tests need no new packages. The SDK since 3.378 also
honors `AWS_ENDPOINT_URL` / `AWS_ENDPOINT_URL_SNS|DYNAMODB|SQS` env overrides, which is what
LocalStack-based integration tests will rely on.

## 3. Test strategy

### 3.1 Unit tests (offline, always run)

- Inject fake AWS clients. `DynamoSQS::connect()` hard-builds real `DynamoDbClient` /
  `SqsClient` from constructor credentials, so unit tests must subclass `DynamoSQS` and
  override `connect()` to install clients backed by `Aws\MockHandler` (mirror the
  `TestAdapter` pattern used for workers). `dynamoDBClient`/`sqsClient` are `protected`, so
  the test subclass can also set `bindWrite`/`bindRead` table/URL via the public API.
- Verify request *shapes* (DynamoDB `putItem` item layout, SQS `receiveMessage`
  params incl. `WaitTimeSeconds`/`VisibilityTimeout`) and response handling (success
  status, `AwsException` → `false`, malformed JSON body → `false`, checksum-mismatched
  row → `false`).
- SNS workers: extend the `PublishWorkerTest` double-client approach to `Register`
  (`createPlatformEndpoint` shaping + `onSuccess` + retry branch via `AwsException`
  doubles) and `Remove` (`deleteEndpoint` + retry logic).
- `SnsException::getAwsErrorCode()` classification: unit-test the constants
  (`AUTHERROR`, `INVALID_PARAM`, `NOTFOUND`, `INTERNAL`) against coded exceptions.

### 3.2 Integration tests (dockerized, skip when unreachable)

Add a LocalStack service (`SERVICES=sqs,dynamodb,sns`) to `build/docker-compose.yaml` next
to `redis`/`nsq`, and set the SDK endpoint overrides
(`AWS_ENDPOINT_URL`, `AWS_ENDPOINT_URL_SQS`, `AWS_ENDPOINT_URL_DYNAMODB`,
`AWS_ENDPOINT_URL_SNS`) plus dummy credentials
(`AWS_ACCESS_KEY_ID=test`, `AWS_SECRET_ACCESS_KEY=test`, `AWS_DEFAULT_REGION=us-east-1`)
on the `app.php81` service. Environment variables used by the tests:

- `BACKQ_LOCALSTACK_HOST` / `BACKQ_LOCALSTACK_PORT` (healthcheck probe; ports 4566)
- `BACKQ_AWS_REGION` (default `us-east-1`)
- `BACKQ_AWS_ACCESS_KEY_ID` / `BACKQ_AWS_SECRET_ACCESS_KEY` (dummy, default `test`)

Integration tests then reproduce the `DynamoSQS` contract against LocalStack:

1. `CreateTable` a DynamoDB table with a single `id: S` hash key; `putTask` a
   `QueueTableRow`; verify via `Scan` that the row landed with the expected
   `id`/`metadata`/`payload`/`time_ready` attributes.
2. Seed an SQS queue with a JSON body matching `QueueTableRow::fromArray()`;
   `pickTask` must return `[ReceiptHandle, payload]`; `afterWorkSuccess` deletes it
   (verify `Deleted` on the queue).
3. Push a malformed body; assert `pickTask` → `false` (no crash).
4. SNS: against LocalStack, publish/register/remove round-trip using a real
   `Aws\Sns\SnsClient` configured with the endpoint override (LocalStack emulates
   platform endpoints without real APNS/FCM).

The `DynamoDB -> Streams -> Lambda -> SQS` TTL path is **not** replicable in LocalStack
(out of scope, see §7); the end-to-end TTL scheduling remains exercised by the unit tests
on `pickTask`/`putTask` TTL math and by manual/CI AWS runs.

### Phase 1A — Implementation record (offline unit tests, Sep 2026)

What was actually shipped (verified live on PHP 8.5, full suite 171 tests / 393
assertions / exit 0 with the 2 docker-only Redis/Nsq integration tests skipped):

- `tests/Support/TestDynamoSQS.php`: `DynamoSQS` double whose `connect()` installs
  pre-built `DynamoDbClient`/`SqsClient` backed by `Aws\MockHandler` (the double is
  passed directly as the client `handler`, no `HandlerStack` wrapper — the AWS pipeline
  calls the handler with `(Command, Request)`); `bindRead`/`bindWrite` set the SQS URL /
  DynamoDB table name through the public API, per §3.1.
- `tests/Adapter/Amazon/DynamoDb/DynamoSQSAdapterTest.php` (16 tests): `putTask` success
  asserts the request shape straight off the mock (TableName, `id`/`payload`/`metadata`
  incl. `payload_checksum`, and string-`N` `time_ready`), default-vs-`PARAM_MESSAGE_ID`
  id prefix, closed pipeline on `DynamoDbException` and when not connected; TTL math for
  `PARAM_READYWAIT` < `DYNAMODB_ESTIMATED_DELAY` (used verbatim), ≥ delay (reduced by
  720s) and the `InvalidArgumentException` past `DYNAMODB_MAXIMUM_PROCESSABLE_TIME`;
  `pickTask` success (ReceiptHandle+payload round-trip) with `ReceiveMessage` param
  assertions (`WaitTimeSeconds`/`VisibilityTimeout` incl. `setWorkTimeout(30)` long-poll),
  malformed-body → `[handle, null]`, checksum-mismatch → `[handle, null]`, empty list →
  `false`, `AwsException` → `false`; `afterWorkSuccess` issues `DeleteMessage` by
  `ReceiptHandle`, idempotent without a client; `afterWorkFailed` no-op. `MockHandler`
  auto-populates `@metadata.statusCode=200`, so `putTask`'s 200-branch is exercised.
- `tests/Worker/Amazon/SNS/Application/PlatformEndpoint/RegisterWorkerTest.php` and
  `RemoveWorkerTest.php` (mirror `PublishWorkerTest`): queue-name derivation
  (`aws_sns_endpoints_register_` / `_remove_`), happy path asserting the exact
  `createPlatformEndpoint` / `deleteEndpoint` payload and `afterWorkSuccess`;
  unsupported payload → processed; AWS `SnsException` `InternalError` → `afterWorkFailed`
  (retry), `AuthorizationError`/`NotFound` → `afterWorkSuccess` (terminal).
  `Register::onSuccess` returning `false` was found to abandon the generator via
  `break` — no ack is ever emitted (job returns by visibility timeout), so the test
  asserts the ARN hook ran and no ack was recorded.
- `tests/Worker/Amazon/SNS/Client/Exception/SnsExceptionTest.php`: constant values and
  `getAwsErrorCode()`/`getAwsErrorType()` mapping from `context` (`code`/`type`), nulls
  when absent.
- `tests/Publisher/Amazon/SNS/Application/PlatformEndpoint/PlatformEndpointPublisherTest.php`:
  anonymous subclasses assert the `_publish_`/`_register_`/`_remove_` queue-name prefixes.
- `tests/Adapter/Amazon/DynamoDb/QueueTableRowTest.php` extended: `fromArray` preserves
  `time_ready` in `toArray` output; extra `metadata` keys are tolerated.
- Notes: `composer dump-autoload` is required after adding test classes (composer config
  uses `classmap-authoritative`). No new packages were added. Local phpcs with the
  repo `build/phpcs-ruleset.xml` fails on a sniff the installed slevomat lacks
  (`SlevomatCodingStandard.Files.FunctionLength`); touched files were checked against a
  PSR12-based probe ruleset instead (0 errors).

## 4. Implementation phases

### Phase 1 — Offline unit tests (no docker, no AWS)

1. `tests/Adapter/Amazon/DynamoDb/DynamoSQSAdapterTest.php` with a `MockHandler`-backed
   `DynamoSQS` subclass:
   - `putTask` success (statusCode 200, item structure, `$msgid` default vs
     `PARAM_MESSAGE_ID`)
   - `putTask` failure → `false` on `DynamoDbException`
   - TTL math: `PARAM_READYWAIT` < `DYNAMODB_ESTIMATED_DELAY`, ≥ delay, and the
     `InvalidArgumentException` past `DYNAMODB_MAXIMUM_PROCESSABLE_TIME`
   - `pickTask` success (valid row), malformed body, empty message list, `AwsException`
   - `afterWorkSuccess` deletes by `ReceiptHandle`
   - `generateSqsEndpointUrl` output (public via `bindRead`)
2. `tests/Worker/Amazon/SNS/Application/PlatformEndpoint/RegisterWorkerTest.php` and
   `.../RemoveWorkerTest.php` mirroring `PublishWorkerTest` (client double + `TestAdapter`,
   `retry` via `restartThreshold`, `onSuccess` override).
3. `tests/Worker/Amazon/SNS/Client/Exception/SnsExceptionTest.php` for error-code mapping.
4. `tests/Publisher/Amazon/SNS/Application/PlatformEndpoint/*Test.php` asserting the
   `$queueName` prefixes (`aws_sns_endpoints_publish_`, `_register_`, `_remove_`).
5. Extend `QueueTableRowTest` for `time_ready`/`metadata` edge cases if gaps are found.

### Phase 2 — LocalStack integration (dockerized)

1. Add `localstack` service (`localstack/localstack`) with
   `SERVICES=sqs,dynamodb,sns`, healthcheck, host port `4566`; wire the app-service env
   as in §3.2; port `APP_SERVICES_START_TIMEOUT`/depends_on `service_healthy`.
2. `AWS_ENDPOINT_URL*` must reach the container service name `localstack` (not a host
   loopback) inside the compose network.
3. `tests/Adapter/Amazon/DynamoDb/DynamoSQSIntegrationTest.php` +
   `tests/Worker/Amazon/SNS/SnsIntegrationTest.php`, both skip-guarded on a socket probe to
   `BACKQ_LOCALSTACK_HOST:4566` (reuse the `fsockopen` + `getenv` pattern).
4. Wire `composer app-tests` to `up -d --wait redis nsq localstack app.php81` (or make the
   LocalStack tests skip when the service is absent, keeping the wait list unchanged).
5. Document the endpoints/behavior deltas if LocalStack emulation diverges from AWS.

## 5. Risk register

| Risk | Mitigation |
|---|---|
| `MockHandler` mimics responses but not signature validation (e.g. AWS would 400 a wrong param) | keep request-shape assertions in unit tests; LocalStack integration catches gross shape errors; real AWS remains the ultimate arbiter |
| LocalStack emulates SNS platform endpoints imperfectly (APNS/FCM payload validation) | integration test asserts the SNS API *call* succeeded and echoes ARNs, not provider delivery |
| DynamoDB TTL Streams→Lambda→SQS unavailable in LocalStack | scoped out; covered by TTL-math unit tests + documented manual AWS run |
| `AWS_ENDPOINT_URL*` env reads vary by SDK minor version | pin `aws/aws-sdk-php` floor; verify env override in the plan's Phase 2 PR and fall back to explicit `endpoint` client config if the env is ignored |
| Extra container makes the docker suite heavier/slower | keep LocalStack out of the default `app-tests` wait list if flaky; rely on skip guards |

## 6. Acceptance criteria

1. New unit tests pass on host without docker and without AWS (`composer app-tests-local`).
2. LocalStack-backed integration tests pass in docker (`composer app-tests`) and skip
   cleanly when LocalStack is unreachable.
3. No live AWS calls are made by any test in this repo.
4. `php -l` clean on all touched test files; phpunit suite stays green with zero skips in
   the dockerized runs (except documented, optional LocalStack skips).
5. `UPGRADING`/README note the new test commands and any public API additions (none are
   planned — tests use subclasses and env vars only).

## 7. Out of scope

- Real AWS end-to-end `DynamoDB Streams -> Lambda -> SQS` TTL pipeline testing (this
  requires real AWS infra; provide a manual runbook instead).
- Refactoring `DynamoSQS` to accept injected clients via constructor/DI (testability
  change is deferred; the existing `protected` surface + `connect()` override suffices).
- Introducing a new test framework or coverage metrics gate.