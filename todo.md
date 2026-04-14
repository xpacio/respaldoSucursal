# TODO

## Refactor to v1 architecture

- [x] Implement `shared/Backup/FileSystemBackupRepository.php` and make it compatible with `BackupSessionRepositoryInterface`
- [x] Update server API routes to use `Shared\Backup\BackupApiController`
- [x] Move `v1/srv.command.php` logic into shared classes under `shared/Backup/`
- [x] Ensure `cli.php` remains the client entry point and `index.php` remains the server entry point
- [x] Keep shared business logic in `shared/` so client and server can reuse it

## Current integration work

- [x] Create shared backup interfaces and DTOs
- [x] Add `ProcessChunkCommand` and `BackupApiController` under `shared/Backup/`
- [x] Update `shared/autoload.php` to map backup classes
- [x] Create minimal `ClientService` for AR registration compatibility

## Next steps

- [x] Validate the new shared module by wiring a simple API endpoint
- [x] Test client registration flow against the shared server logic
- [x] Clean up legacy `require_once` paths and dependency loading
- [x] Document the new client/server entrypoint separation in README
- [x] Implement `shared/Backup/FileSystemBackupRepository.php` and make it compatible with `BackupSessionRepositoryInterface`
- [x] Add the new backup route to `index.php` and ensure `BackupApiController` is constructed with shared dependencies
- [x] Add unit tests for `Shared\Backup\ProcessChunkCommand` and `Shared\Backup\BackupApiController`
- [x] Remove or archive legacy `v1/srv.command.php` backup-specific logic once shared path is stable
- [x] Update README with example backup API calls and expected client/server handshake
- [x] Run shared backup flow validation via `php -f shared\Backup\test_backup_flow.php`
