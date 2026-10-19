# Nobash Backup

**Reliable WordPress backup, restore, and site recovery—without unnecessary complexity.**

[WordPress.org plugin](https://wordpress.org/plugins/nobash-backup/) · [Product website](https://backup.nobash.com/) · [Nobash](https://nobash.com/) · GPL-2.0-or-later

Nobash Backup helps WordPress site owners create recovery points, automate backup routines, and recover when updates, changes, or unexpected problems disrupt a website. The interface is designed for everyday use, with more advanced options available when needed.

## Features

| Capability | What it does |
| --- | --- |
| **Website backups** | Back up the database, files, or the complete supported website. |
| **Backup scheduling** | Configure recurring backups for ongoing protection. |
| **Recovery and restore** | Choose a saved backup and restore supported database and/or file content. |
| **Incremental and differential modes** | Choose supported backup strategies to fit the site. |
| **Resumable jobs** | Process longer backup and restore jobs in smaller steps. |
| **Backup verification** | Check supported archives before restoration. |
| **Password protection** | Protect supported archives with a password. |
| **Import and migration** | Import portable `.nbh` archives from supported local or HTTPS sources. |
| **Activity and reports** | Follow backup status and recent operations from the WordPress dashboard. |

Some capabilities depend on the site's hosting environment, configuration, and archive type. See the [WordPress.org readme](readme.txt) for complete usage information.

## Get started

1. Make an independent site backup before installing a new backup tool.
2. Install **Nobash Backup** from [WordPress.org](https://wordpress.org/plugins/nobash-backup/), or use its **official release ZIP** in **Plugins → Add New → Upload Plugin**.
3. Activate the plugin and open its settings in WordPress.
4. Choose what to back up, configure storage and scheduling as needed, and run a backup.
5. Verify your backup and test restoration on staging before relying on it for production recovery.

> **Important:** GitHub's automatic *Source code* download is intended for development, not as a WordPress release package. Use an official WordPress.org release archive for normal installation.

## Requirements

- **WordPress:** 6.2 or later
- **PHP:** 7.4 or later
- **License:** GPL v2 or later

Hosting resources, available storage, file permissions, PHP limits, and scheduling behavior can affect backup and restoration. Large or complex websites should be tested under realistic conditions.

## Development

This repository mirrors the WordPress plugin source for version **1.0.0.1** and includes additional GitHub project documentation and basic PHP syntax automation. Development additions do not alter the release's runtime PHP, JS, CSS or assets.

### Repository layout

- `nobash-backup.php` — plugin bootstrap
- `includes/` — PHP components and backup/restore implementation
- `assets/` — administrative interface assets
- `languages/` — localization resources, if supplied in the release
- `readme.txt` — official WordPress plugin-directory documentation
- `.github/workflows/` — automated syntax validation

### Syntax validation

```bash
find . -type f -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l
```

Linting is **not** a substitute for integration tests. Validate activation, backup creation, verification, restore, job resumption, multisite support where applicable, and hosting compatibility in an isolated WordPress environment before deploying changes.

## Contributing

Thoughtful bug reports and focused improvements are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md), and check existing issues before opening a new report. Never publish private backups, credentials, or database exports in public issues.

## Security

Please do not disclose vulnerabilities publicly before maintainers have had an opportunity to investigate. See [SECURITY.md](SECURITY.md).

## License

Original plugin code is licensed under **GPL v2 or later**. See [LICENSE](LICENSE) for the license text. Third-party components, if any, retain their respective licensing terms.

---

**Nobash Backup** is developed by [Nobash](https://nobash.com/). [Official plugin listing](https://wordpress.org/plugins/nobash-backup/).
