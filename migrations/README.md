# PiDoors Database Migrations

This directory contains database migration scripts for PiDoors.

## migrate_doors_format (v2.2.1)

Converts the `doors` column in the `cards` table from space-separated to comma-separated format.

**Why this migration is needed:**
- The security fix in v2.2.1 changed zone matching to use `FIND_IN_SET()` SQL function
- `FIND_IN_SET()` requires comma-separated values, not space-separated
- Existing database records need to be converted for access control to work correctly

### Option 1: Python Script (Recommended)

The Python script provides a safe migration with preview, confirmation, and rollback support.

```bash
# Preview changes (dry run)
python3 migrate_doors_format.py --dry-run

# Apply changes
python3 migrate_doors_format.py

# With custom config path
python3 migrate_doors_format.py --config /path/to/config.json
```

### Option 2: SQL Script

Direct SQL migration for advanced users.

```bash
# Backup first!
mysqldump -u pidoors -p access > access_backup_$(date +%Y%m%d).sql

# Run migration
mysql -u pidoors -p access < migrate_doors_format.sql
```

### Before/After Example

| Before (space-separated) | After (comma-separated) |
|-------------------------|------------------------|
| `front_door back_door`  | `front_door,back_door` |
| `lobby entrance exit`   | `lobby,entrance,exit`  |
| `*`                     | `*` (unchanged)        |
| `single_door`           | `single_door` (unchanged) |

### Verification

After migration, verify no space-separated values remain:

```sql
SELECT COUNT(*) FROM cards WHERE doors LIKE '% %' AND doors != '*';
-- Should return 0
```
