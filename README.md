# LessonSchedule production backup

Private backup of the deployment served from:

- Host: `118.190.147.249` (`ssh bm`)
- Source directory: `/www/server/nginx/html/LessonSchedule`
- Public URL: `https://debug.91nongye.cn/LessonSchedule/`
- Capture date: 2026-07-18 (Asia/Shanghai)

## Contents

- `deployment/`: browsable production snapshot.
- `artifacts/*.aesgcm`: encrypted, byte-preserving archive of the original snapshot.
- `SHA256SUMS`: hashes for the committed snapshot and encrypted artifact.
- `tools/decrypt_backup.py`: restores the encrypted archive using the separately stored key.

Runtime logs are excluded. The browsable PHP snapshot replaces literal database connection values with these environment variables:

- `LESSON_SCHEDULE_DB_HOST`
- `LESSON_SCHEDULE_DB_USER`
- `LESSON_SCHEDULE_DB_PASS`
- `LESSON_SCHEDULE_DB_NAME`

The encrypted artifact retains the original values. Its key is intentionally not stored in Git or GitHub.

## Decrypt the original archive

Install Python 3 and `cryptography`, then run:

```powershell
python tools/decrypt_backup.py artifacts/<backup>.aesgcm <local-key-file> restored.tar.gz
```

Verify the restored archive against `ORIGINAL_ARCHIVE_SHA256.txt` before extracting it.
