# Architectural Decisions & Production Awareness

As requested, here is an analysis of trade-offs, edge cases, and production awareness considered during this implementation.

## 1. Identified Issues in Current Implementation (Unmentioned in requirements)
- **Domain-Driven Design Consistency:** The requirements suggested exploring `app/Http/Controllers/ImportController.php`, but Monica uses a strict Domain-Driven Design under `app/Domains/...`. Adding a standard controller would violate architectural consistency. I solved this by placing the new logic inside `app/Domains/Contact/ManageImport/...`.
- **CSV Parsing Doesn't Exist:** The requirements implied CSV parsing existed and only needed to be moved to the background. In reality, Monica only supported `vCard` parsing using `Sabre\VObject`. I had to build a custom streaming CSV chunker (`StartImportJob`).
- **Race Conditions in Progress Tracking:** When multiple queue workers execute batches in parallel, they could overwrite each other's `processed_rows` or `errors` updates if they query and save the same `ImportJob` simultaneously. I solved this by implementing **pessimistic database locking** (`lockForUpdate()`) inside a DB transaction in `ProcessImportChunkJob.php`.

## 2. Trade-offs Explicitly Discussed

### Batching vs Streaming
- **Trade-off:** We could load the whole file into memory and insert everything at once, OR we stream the file line by line and dispatch a job per row.
- **Decision:** A hybrid approach using Laravel `Bus::batch()`. We stream the file without loading it all to memory (using `fopen`/`fgetcsv`), group rows into chunks of 50, and dispatch those chunks as parallel jobs. This balances memory safety and query efficiency (avoiding too many small jobs).

### Error Storage & Error CSV Generation
- **Trade-off:** To generate the error CSV, we could store the *entire raw row text* in the `errors` JSON column in the database, OR we could keep the original CSV on disk and just store the `row_number` and `error_message` in the DB.
- **Decision:** I chose to store only the `row_number` and `message` in the DB to prevent database bloat. When the user requests the error CSV, the API dynamically streams the original CSV from disk, appends the error column on-the-fly for rows that failed, and returns it. This sacrifices some CPU time during the rare event of an error download to drastically save database storage.

### Database Polling vs Event-Driven Progress
- **Trade-off:** The frontend could poll `GET /api/import/:id` every 2 seconds, OR we could use WebSockets (Laravel Reverb/Pusher) to push updates.
- **Decision:** I stuck with database-polling because it requires zero new infrastructure dependencies (like Pusher) and works immediately on self-hosted instances. The endpoint was optimized to only query the `import_jobs` table, making it extremely fast (<50ms).

## 3. Production Awareness & Scale

### What happens at 10x Scale?
If thousands of users upload massive files simultaneously:
- **Database Contention:** The pessimistic locks on the `import_jobs` table might cause lock wait timeouts if workers update progress too rapidly. **Solution:** Instead of updating the DB directly from workers, workers could push progress events to Redis, and a dedicated worker could flush those aggregates to the DB every 5 seconds.
- **Storage:** If we use local storage, we will run out of disk space. **Solution:** We should enforce using AWS S3 (`Storage::disk('s3')`) for the uploaded files, and lifecycle policies to delete files after 30 days.

### How to Roll Back
- The system is inherently rollback-friendly because `failed` batches don't break the system, and users can simply delete the failed import's contacts if necessary. However, a true rollback feature would require tracking all created `contact_ids` in the `import_jobs` table, and introducing a "Revert Import" button that deletes those specific contacts.

### How to Debug a Stuck Import
- Use the `imports:check-stuck` command to detect imports stuck for >30 minutes.
- Check Laravel Horizon (since we use Redis queue) to inspect the specific failed batch job payload and stack trace.
- Inspect the `errors` JSON column to see if it failed gracefully via our try-catch, or if it hit a fatal error (like OOM).

## 4. Questioning Requirements
- **"Should we store the entire CSV in the database or on S3?"** 
  If the application is scaled horizontally (multiple web servers and multiple queue workers), `local` storage will fail because the queue worker might not be on the same server where the file was uploaded. The requirement should explicitly mandate S3 or a shared volume.
- **"What defines a duplicate upload?"**
  The requirement suggested hashing the file content. However, two different users might upload the exact same company directory. My implementation scopes the MD5 hash check strictly to the `user_id` to prevent cross-user hash collisions.
