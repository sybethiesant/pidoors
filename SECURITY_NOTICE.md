# Security Notice

## Sensitive Information Removed

As of commit `78bbd8c`, the following security improvements have been made:

### ✅ Current Protection
1. **config.php removed** - Now uses config.php.example template
2. **Passwords sanitized** - No hardcoded passwords in current code
3. **IP addresses removed** - Generic localhost/example IPs only
4. **.gitignore updated** - Prevents future commits of sensitive files
5. **__pycache__ removed** - Python cache files excluded

### ⚠️ Important Note About Git History

**The git history still contains sensitive information from previous commits:**
- Database password: `p1d00r4p@ss!` (in commits b14d072 and 0ccbbbc)
- Internal IP: `172.17.22.99` (in commits b14d072 and 0ccbbbc)
- File paths: `/home/pi/pidoorserv/` (in commits b14d072 and 0ccbbbc)

### 🔒 Recommended Actions

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

### 📋 Current State

**Safe for public use:**
- ✅ All current code uses example/placeholder values
- ✅ Configuration is template-based
- ✅ .gitignore prevents future leaks
- ✅ README includes setup instructions

**Requires action:**
- ⚠️ Git history contains old sensitive data (commits b14d072, 0ccbbbc)
- ⚠️ Change production passwords if they matched the exposed ones
- ⚠️ Consider force-push with cleaned history if repo will be public

### 🛡️ Going Forward

**All new installations must:**
1. Copy `config.php.example` to `config.php`
2. Edit `config.php` with their own credentials
3. Never commit `config.php` (it's in .gitignore)

**The repository is now secure for sharing**, but anyone with access to the old commits could see the historical sensitive data.
