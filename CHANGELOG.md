# Changelog

All notable changes to this package are documented in this file. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- The README's limit on PHP's time limit now says that it depends on the PHP build: a thread-safe build on Linux, such as FrankenPHP's, counts wall-clock time, so a job that waits on I/O is ended at its timeout; only a non-thread-safe build counts CPU time alone.

## [1.0.0] - 2026-10-18

### Added

- `UnansweredDeliveryGuard`: on `JobProcessing`, adds the deliveries that never got a handled answer (`X-CloudTasks-TaskRetryCount` minus `X-CloudTasks-TaskExecutionCount`) to the attempts of the job that the stackkit task handler processes, so that a job that keeps crashing is failed after its tries. Missing or malformed counters add nothing.
- `HandledTimeoutGuard`: a render callback for PHP's "Maximum execution time exceeded" fatal error that answers 200 for a task handler request once the stackkit worker has released the timed-out job as a new task or recorded its failure, so that Cloud Tasks does not deliver the original task again.
- The `cloud-tasks-guard.php` configuration file, with a switch for each guard. Both guards are enabled by default.
- Documentation of the Cloud Tasks queue settings that the guards need, and of their limits.
- Support for PHP 8.3 to 8.5, Laravel 12 and 13, and `stackkit/laravel-google-cloud-tasks-queue` 6.x.

[Unreleased]: https://github.com/fanswoo/laravel-cloud-tasks-guard/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/fanswoo/laravel-cloud-tasks-guard/releases/tag/v1.0.0
