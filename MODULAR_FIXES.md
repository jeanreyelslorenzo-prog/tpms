# Modular Fix Summary

- Grouped all main screens into domain modules.
- Grouped teacher, school, chat, planning, retirement, chatbot, and logout
  handlers with their corresponding features.
- Added a single application bootstrap for configuration, PDO, authentication,
  and helpers.
- Moved the shared header and footer into an application view layout.
- Retained thin root and `actions/` compatibility files so current URLs and
  JavaScript endpoints continue to work.
- Moved debug, migration, test, and old page copies out of the public root.
- Restricted internal directories in `.htaccess` and added a bootstrap guard for
  servers where rewrite protection is unavailable.
- Removed the hard-coded live database credentials and encryption secret from
  the distributable configuration.
- Added an ignored `config/local.php` workflow and environment-variable support.
- Removed the unauthenticated public account-activation endpoint; its retained
  maintenance script is now CLI-only.
- Removed the hard-coded `/tpms/` rewrite base to support flexible subfolder and
  domain-root deployments.
- Excluded the copied live database dump and uploaded personnel photos from the
  distributable source archive.
