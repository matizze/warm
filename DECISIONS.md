# Decisions

## Object storage provider for media

**Status:** pending / revisited later (2026-09-27)

**Context.** Media (documents, images, audio) received via WhatsApp is downloaded and
persisted automatically on arrival (`ReceiveWebhook` dispatches `DownloadMedia`, which
stores every blob in the `warm` disk). The storage must be durable: the WhatsApp/Evolution
media links expire, so the stored copy is the only recoverable original.

**Current setup.** Local dev uses RustFS/MinIO (`STORAGE_DRIVER=s3`, endpoint
`http://localhost:9000`, bucket `warm-media`). The `warm` disk
(`config/filesystems.php`) is already S3-compatible and fully env-driven, so switching
providers is an env-only change — no application code changes.

**Candidates considered** (per Reddit/community pricing, 2026):

| Provider | Storage | Egress | Notes |
| --- | --- | --- | --- |
| MEGA S4 | ~$2.63/TB | free up to 5x stored | Cheapest; requires Pro plan (~€15/mo floor); regions NL/LU/CA; no min retention; consumer-oriented, privacy concerns raised; 5x egress cap |
| Backblaze B2 | ~$6.95/TB | free via Cloudflare | Best cost/reliability; pay-as-you-go, no floor; API calls now free; US-only datacenters |
| Cloudflare R2 | ~$15/TB | zero, unconditional | Most reliable; best if download volume grows |
| iDrive e2 | ~$4/TB | free up to 3x stored | Cheapest raw, but reliability/complaint reports; slower with many small files |
| Wasabi | ~$6.99/TB | free (1:1 ratio) | 90-day minimum retention — conflicts with early pruning plans |

**Decision.** Defer. Keep the current setup for now; there is not enough data stored to
justify a paid provider.

**Next step / when to revisit.** When stored volume grows, evaluate **MEGA S4** —
**currently preferred on price** (~2.63/TB, no min retention, free 5x egress), accepting
the monthly floor and the consumer-plan caveat. Fall back to **Backblaze B2** when volume
is low or US-only + pay-as-you-go matters more (no monthly floor).

**Migration sketch (when the time comes).**
1. Create bucket + S3 credentials at the provider.
2. Update `.env` only: `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`,
   `AWS_BUCKET`, `AWS_ENDPOINT`, `AWS_USE_PATH_STYLE_ENDPOINT=true` (keep `STORAGE_DRIVER=s3`).
   - R2: region `auto`; iDrive e2: region `e2`; MEGA S4: endpoint/region from the S4 console.
3. Copy existing objects with `rclone`/`aws s3 sync` (no downtime).
4. Validate `media.upload` → `media.download` (open the signed URL) → `message.send`,
   then `php artisan config:clear` and flip the env.

**Deletion/retention note.** Avoid deleting only the blob while keeping the `media` row:
`DownloadMedia` is idempotent by `storage_key`, so it would return a broken signed URL
instead of re-fetching. If pruning is ever needed, delete row + blob together (and prefer
a bucket lifecycle rule). Keep documents; prune old audio/images only. Provider media
links expire, so deleted originals are unrecoverable.
