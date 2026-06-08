# Monica CRM: Reliable Background Import System

## Overview
This repository contains my submission for the Senior Backend Developer assignment. I have designed and implemented a reliable, asynchronous background import system for Monica CRM. It completely replaces synchronous CSV imports with a robust, queue-driven pipeline that supports real-time progress tracking, per-row error isolation, concurrency controls, and failure alerting.

---

## My Contribution Highlights
- **Schema Design:** Designed the `import_jobs` table to robustly handle state (`pending`, `processing`, `completed`, `failed`, `cancelled`), track metrics (`total_rows`, `processed_rows`, `failed_rows`), and serialize errors on a per-row basis.
- **Background Pipeline:** Built a chunked streaming reader (`StartImportJob`) that delegates processing to parallel workers (`ProcessImportChunkJob`) using Laravel's `Bus::batch()`. 
- **Error Isolation & Generation:** Implemented a system where a single bad row doesn't fail the entire file. Bad rows are isolated, and users can download a dynamically generated CSV (`/api/import/:id/errors.csv`) that appends error messages directly to the original failed rows.
- **Race Condition Prevention:** Implemented pessimistic database locking (`lockForUpdate()`) to prevent parallel queue workers from overwriting each other's progress counters.
- **Observability:** Added a cron-ready console command (`imports:check-stuck`) to detect crashed imports and alert the team of high failure rates.

---

## Setup and Test Instructions

### Requirements
- PHP 8.1+ / Laravel 10+
- Redis (Required for Queue functionality)
- MySQL 8.0+

### Installation
1. Ensure your `.env` file is properly configured with your database and queue credentials:
```env
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```
2. Run the database migrations to create the `import_jobs` table:
```bash
php artisan migrate
```
3. Start the queue worker to process the background imports:
```bash
php artisan queue:listen
```

### Running Automated Tests
I have included comprehensive PHPUnit/Pest tests covering import initiation, batch processing with error handling, cancellation, and error CSV generation. 

To run the tests, execute the following command:
```bash
php artisan test --filter ImportTest
```

---

## Assumptions & Approach
- **Assumption:** Since Monica heavily utilizes Domain-Driven Design (DDD) under `app/Domains/...`, I assumed that placing the new API endpoints in a standard `app/Http/Controllers/` directory would violate architectural consistency. My approach was to create a new dedicated domain context: `app/Domains/Contact/ManageImport/...` to house the Controllers and Jobs.
- **Assumption:** Users might upload extremely large files. Thus, I approached the file reading sequentially using `fopen/fgetcsv` rather than loading the entire file into memory using `file_get_contents`.
- **Approach:** To prevent duplicate uploads, the system calculates an MD5 hash of the file contents. The check is scoped to the `user_id` to prevent cross-account collisions.

---

## Architecture Decision Records (ADR)

Here are the 4 most significant architectural decisions made during development:

### 1. Database Polling vs WebSockets for Progress Tracking
**Context:** We need to provide real-time updates to the UI (<50ms response time).
**Decision:** I chose a Database Polling approach (via `GET /api/import/:id`) over WebSockets (Pusher/Reverb). 
**Why:** The API endpoint only queries a single indexed row in the `import_jobs` table, making it incredibly fast. WebSockets would require introducing new infrastructure requirements for self-hosted users (who represent a large portion of Monica's user base). Polling is stateless, scalable, and requires zero additional setup.

### 2. Batch Size Selection & `Bus::batch()`
**Context:** Processing thousands of rows synchronously causes timeouts. 
**Decision:** I utilized Laravel's `Bus::batch()` to group the CSV processing into chunks of 50 rows per job.
**Why:** Processing 1 row per job would overload Redis with thousands of tiny jobs and massive database overhead. Processing 1000 rows per job risks memory exhaustion. 50 rows per batch is the perfect middle ground, ensuring fast execution per worker while keeping memory usage stable.

### 3. Pessimistic Database Locking for Progress Updates
**Context:** Multiple `ProcessImportChunkJob` workers will complete concurrently and try to increment `processed_rows` on the same `ImportJob` record.
**Decision:** I wrapped the progress increment logic in a `DB::transaction` using `$job->lockForUpdate()`.
**Why:** Without pessimistic locking, we would face race conditions where Worker A and Worker B read the `processed_rows` as `100` at the exact same millisecond. Both would increment by 50 and save `150`, causing us to "lose" 50 rows of progress.

### 4. Dynamic Generation of the Error CSV
**Context:** When a row fails, we must allow the user to download an error CSV containing the original row data plus an error column.
**Decision:** Instead of storing the full original row strings in the `errors` JSON database column, I store *only* the `row_number` and the `error_message`.
**Why:** If a 100MB CSV file fails completely, storing every row in JSON would bloat the MySQL database immensely. By storing only the row numbers, the API can dynamically seek through the original file on disk and build the Error CSV on-the-fly. This trades a few CPU cycles during download for massive database storage savings.
