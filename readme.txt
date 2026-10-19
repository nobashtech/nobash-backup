=== Nobash Backup ===
Contributors: shofiqul
Tags: backup, restore, recovery, scheduled backup, migration
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Reliable WordPress backup and restore with scheduled backups, recovery points, verification, and simple site recovery.

== Description ==

#### Reliable WordPress Backups Without the Complexity

Nobash Backup makes it easy to back up, restore, and recover your WordPress website.

Create complete site backups, automate protection with scheduled backups, restore after a failed update or unexpected problem, and move a site using a portable Nobash Backup `.nbh` archive.

The plugin is designed to feel simple for beginners while still giving freelancers, agencies, developers, business owners, and WooCommerce users the control they need for everyday backup and recovery.

#### Why Choose Nobash Backup?

- **Complete Website Backups** – Back up your WordPress database, uploads, plugins, themes, and supported site files
- **Easy Restore** – Recover your website from a saved backup when an update, plugin, theme, or manual change causes problems
- **Scheduled Backups** – Create automatic backup schedules so recovery points are generated regularly
- **Full, Incremental & Differential Modes** – Choose the backup method that best fits your site and workflow
- **Resumable Processing** – Long-running jobs are processed in smaller steps to work more reliably on normal hosting environments
- **Backup Verification** – Check backup archives before relying on them for recovery
- **Password Protection** – Protect supported backup archives with a password
- **Simple Import** – Import native `.nbh` backup files from your computer or a secure HTTPS URL
- **Activity & Reports** – Review backup activity, job status, and recent operations from the WordPress dashboard

#### Backup Your WordPress Site

Nobash Backup can create recovery points for the important parts of your website.

You can create:

- Full website backups
- Database-only backups
- Files-only backups
- Full backups
- Incremental backups
- Differential backups

This makes it easy to create a complete recovery copy before major changes or use regular backups as part of your normal website maintenance.

#### Restore When Something Goes Wrong

WordPress websites change constantly. Plugin updates, theme changes, server problems, accidental edits, or failed deployments can all cause unexpected issues.

Nobash Backup gives you a clear recovery workflow so you can select a saved backup, verify it, choose what to restore, and recover the site from WordPress.

Where supported by the selected backup, you can restore the database, files, or both.

#### Automatic Backup Schedules

You do not need to remember to create every backup manually.

Create scheduled backups and let Nobash Backup generate recovery points automatically based on your configured schedule.

This is especially useful for:

- Business websites
- Blogs and content sites
- WooCommerce stores
- Membership websites
- Client websites
- Sites that change frequently

#### Built for Real Hosting Environments

Large WordPress sites can take time to back up or restore.

Nobash Backup processes supported long-running operations in smaller resumable steps. This helps reduce problems caused by common hosting limits such as execution time and limited server resources.

Actual backup and restore performance depends on your website size, database, hosting environment, available disk space, and server resources.

#### Import and Move a Site

Nobash Backup uses its own `.nbh` archive format.

You can import a compatible `.nbh` backup from your computer or provide a secure HTTPS URL to retrieve the archive.

This can be useful when recovering a website or moving a compatible Nobash Backup archive to another WordPress installation.

#### Built for WordPress Users of All Levels

Nobash Backup is suitable for:

- Beginners who want a simple backup and restore workflow
- Website owners who want recovery points before making changes
- Freelancers managing WordPress websites
- Agencies maintaining client sites
- Developers who want more control over backup jobs
- WooCommerce site owners who need a dependable recovery plan

#### Need WordPress or WooCommerce Help?

Get friendly, professional help from the Nobash team when you need it.

https://nobash.com/

#### Learn More

https://backup.nobash.com/

== External Services ==

Nobash Backup can use its main local backup, restore, scheduling, and import features without requiring a Nobash account.

Some optional features connect to external services only when a WordPress administrator chooses to use them.

#### Import from URL

When **Import from URL** is used, Nobash Backup connects to the HTTPS address entered by the administrator to retrieve the requested `.nbh` backup archive.

#### Support Requests

If an administrator sends a support request from inside the plugin, the submitted message and basic website environment information may be sent to Nobash support.

Do not include passwords, access tokens, backup archives, or private credentials in support messages.

Support: https://backup.nobash.com/  
Terms: https://nobash.com/terms/  
Privacy: https://nobash.com/privacy/

#### Administrator-Configured Destinations

Webhook notifications and other administrator-configured destinations are contacted only when those features are enabled and configured by the site administrator.

== Privacy ==

Backup archives may contain the same private or personal information stored on your WordPress website.

Treat backup files as sensitive data and store important recovery copies in a secure location.

Nobash Backup does not require a Nobash account for its main local backup and restore features.

== Installation ==

1. Install **Nobash Backup** from the WordPress Plugin Directory.
2. Activate the plugin.
3. Open **Nobash Backup** from the WordPress admin menu.
4. Create your first backup or configure an automatic schedule.

== Frequently Asked Questions ==

#### Is Nobash Backup free?

Yes. The WordPress.org version includes the backup, restore, import, scheduling, and recovery features included in this plugin package.

#### Do I need a Nobash account?

No. The main local backup and restore features work without a Nobash account.

#### What does Nobash Backup back up?

A backup can include your WordPress database, uploads, plugins, themes, WordPress files, and supported site settings.

#### Can I schedule automatic backups?

Yes. Backup schedules can be created and managed from the **Schedules** screen.

#### What is an `.nbh` file?

`.nbh` is the native backup archive format used by Nobash Backup.

#### Can I restore only files or only the database?

Yes, when those components are available in the selected backup.

#### Does Nobash Backup work with WooCommerce?

Yes. Nobash Backup can back up and restore WordPress websites that use WooCommerce.

For busy stores, use an appropriate maintenance or low-activity window before a full restore so new orders or changes are not missed during recovery.

#### Is Nobash Backup suitable for larger websites?

Nobash Backup is designed to process supported long-running jobs in smaller resumable steps.

Practical limits depend on your hosting environment, available disk space, database performance, and server resources.

#### Can I import backups created by other backup plugins?

No. Import currently supports native Nobash Backup `.nbh` archives.

#### Where can I get help?

Visit:

https://backup.nobash.com/

== Screenshots ==

1. Overview dashboard with backup status and quick actions.
2. Export screen for creating a backup.
3. Backups screen for managing recovery points.
4. Restore screen with verification and recovery options.
5. Import screen for native `.nbh` backup archives.
6. Schedules screen for automatic backups.
7. Activity & Reports screen for backup history and reports.
8. Settings screen for Nobash Backup configuration.

== Changelog ==

= 1.0.0.1 =
* Refreshed the WordPress admin interface.
* Improved Export, Backups, Restore, Import, Schedules, and Activity & Reports screens.
* Fixed schedule form submission and editing behavior.
* Improved backup filtering, sorting, and selection controls.
* Improved Import from File and Import from URL workflows.
* Improved restore summaries and reporting controls.
* Reorganized settings for a simpler administration experience.
* Updated release documentation and packaging.

= 1.0.0 =
* Initial public release.
* Added full website, database, and file backups.
* Added full, incremental, and differential backup modes.
* Added restore and recovery tools.
* Added native `.nbh` backup import.
* Added secure HTTPS URL import.
* Added automatic backup schedules.
* Added archive verification and password protection.
* Added local backup management and resumable processing.

== Upgrade Notice ==

= 1.0.0.1 =
Improved the admin experience, backup workflows, reporting, and schedule handling.
