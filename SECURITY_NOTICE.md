# Security Notice

## Sensitive Information Protection

As of version 2.1, the following security protections are in place:

### Current Protection

1. **config.php excluded** - Uses config.php.example template, actual config in .gitignore
2. **config.json excluded** - Uses config.json.example template, actual config in .gitignore
3. **Passwords sanitized** - No hardcoded passwords in current code
4. **IP addresses removed** - Generic localhost/example IPs only
5. **__pycache__ excluded** - Python cache files not tracked

### Important Note About Git History

**The git history may contain sensitive information from previous commits:**
- Database credentials from early development
- Internal IP addresses
- File paths

### Recommended Actions

**If this repository is or will be public:**

1. **Change all passwords immediately** on your production system
2. **Update IP addresses** if they were accurate
3. **Consider rewriting git history** to remove sensitive data:

```bash
# WARNING: This rewrites history and will affect all clones
# Only do this if you haven't shared the repository yet

# Option 1: Use BFG Repo-Cleaner (recommended)
brew install bfg  # or download from https://rtyley.github.io/bfg-repo-cleaner/
bfg --replace-text passwords.txt
git reflog expire --expire=now --all
git gc --prune=now --aggressive
git push --force

# Option 2: Use git filter-branch
git filter-branch --tree-filter 'find . -name "config.php" -exec rm -f {} \;' HEAD
git push --force
```

4. **Regenerate any exposed credentials** on your actual deployment
5. **Use environment variables** or external config files for production

### Current Security State

**Safe for public use:**
- All current code uses example/placeholder values
- Configuration is template-based
- .gitignore prevents future leaks
- README includes setup instructions

**Requires action:**
- Git history may contain old sensitive data
- Change production passwords if they matched any exposed values
- Consider force-push with cleaned history if repo will be public

### Going Forward

**All new installations must:**

1. Copy configuration templates:
   ```bash
   cp pidoorserv/includes/config.php.example pidoorserv/includes/config.php
   cp pidoors/conf/config.json.example pidoors/conf/config.json
   ```

2. Edit configuration files with your own credentials:
   ```bash
   nano pidoorserv/includes/config.php
   nano pidoors/conf/config.json
   ```

3. Secure configuration files:
   ```bash
   chmod 640 pidoorserv/includes/config.php
   chmod 600 pidoors/conf/config.json
   ```

4. Never commit configuration files (they're in .gitignore)

### Security Best Practices

1. **Use strong, unique passwords** for database accounts
2. **Enable HTTPS** in production (see nginx/pidoors.conf for SSL configuration)
3. **Keep software updated** - regularly update the system and PiDoors
4. **Monitor logs** - check audit logs and access logs regularly
5. **Backup regularly** - automated backups are configured in /var/backups/pidoors/
6. **Restrict network access** - use firewall rules to limit database access
7. **Use SSH keys** instead of passwords for remote access

### Reporting Security Issues

Please report security vulnerabilities to the repository owner directly, not via public issues. Include:

- Description of the vulnerability
- Steps to reproduce
- Potential impact
- Suggested fix (if any)

### Files That Should Never Be Committed

The following files contain sensitive data and are excluded via .gitignore:

```
pidoorserv/includes/config.php    # Server configuration
pidoors/conf/config.json          # Door controller configuration
*.log                             # Log files
*.bak                             # Backup files
```

**The repository is now secure for sharing**, but anyone with access to old commits could see historical sensitive data if git history has not been cleaned.
