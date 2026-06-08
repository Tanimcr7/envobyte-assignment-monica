# Reliable Background Import System for Monica CRM

## Overview
This branch (`envobyte-assignment`) introduces a reliable, asynchronous, and scalable background import system for importing contacts from CSV files. It solves the issue of timeouts for large files, provides a real-time progress tracking API, ensures per-row error isolation without full-file failures, and includes concurrency and observability measures.

## Architecture & Implementation
- **Queue Engine:** Redis, utilizing Laravel's built-in `Bus::batch()` capabilities to chunk large files into manageable batch jobs.
- **Data Persistence:** An `import_jobs` table tracks status, metrics (processed/failed rows), and serialized per-row errors.
- **Idempotency:** The system calculates a SHA-256 hash of uploaded files to prevent identical, duplicate uploads.
- **Observability:** Added a console command `imports:check-stuck` to detect crashed/frozen imports and trigger team alerts for high failure rates.

## How to Set Up
1. Ensure Redis is running and `QUEUE_CONNECTION=redis` is set in your `.env`.
2. Run migrations:
```bash
php artisan migrate
```
3. Start your queue worker:
```bash
php artisan queue:listen
```

## How to Test
The submission includes comprehensive automated tests covering all main functionalities (Upload, Status, Cancellation, Error isolation, and CSV Generation).

Run the tests using:
```bash
php artisan test --filter ImportTest
```
