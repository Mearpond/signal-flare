# Signal Flare

A WordPress plugin that connects [Claude](https://claude.com/claude-code) to your site over MCP (Model Context Protocol), so Claude can build and maintain it: run PHP and WP-CLI, read and edit files, and check its own work.

> **This gives Claude full administrator access to your site, including running code on the server.** That's the point of it, but only install it on sites you own, keep it switched off when you're not building, and revoke the Application Password when you're done. See [Safeguards](#safeguards).

## Requirements

- WordPress 6.5 or later, PHP 8.0 or later
- HTTPS (WordPress only allows Application Passwords over HTTPS)
- [Claude Code](https://claude.com/claude-code) on your computer
- WP-CLI on the server for the `run_wp_cli` tool (most managed hosts have it)

## Install

1. Download `signal-flare-<version>.zip` from the latest [release](../../releases/latest). Don't use GitHub's "Source code" zip: it unpacks to the wrong folder name.
2. In WordPress: **Plugins › Add New › Upload Plugin**, choose the zip, then **Install** and **Activate**.
3. Open **Signal Flare** in the dashboard menu. Switch it on, and turn on code and file tools while you're building.
4. Follow **Connect Claude** on that page:
   - Create an Application Password named "Claude" (**Users › Profile › Application Passwords**).
   - Run the command shown in Terminal. It asks for the password with a hidden prompt and registers the site with Claude Code as `signal-flare-<your-site>`.
5. Start a new Claude Code session. The site's tools appear under that name.

To disconnect: switch Signal Flare off, or revoke the Application Password, then run `claude mcp remove signal-flare-<your-site> --scope user`.

## Tools

| Tool | What it does |
|---|---|
| `site_info` | WordPress, PHP and database versions, theme, plugins, pages |
| `execute_php` | Runs PHP inside WordPress and returns the result, printed output and warnings |
| `run_wp_cli` | Runs a WP-CLI command (arguments passed as a list, each shell-escaped) |
| `read_file`, `write_file`, `edit_file` | Read, create and edit files under the WordPress folder |
| `list_directory`, `delete_file` | Browse and delete files and folders |
| `create_upload_link` | One-time link for sending a large file (a plugin zip, media) straight to the site with curl |
| `create_login_link` | One-time link that logs a browser in as you, for testing pages |

## Safeguards

- **Off by default.** Nothing answers until an admin switches Signal Flare on. Code and file tools have their own switch.
- **Admins only, Application Passwords only.** The connecting user must be an administrator. Requests using a browser login are refused, so a malicious web page can't drive Signal Flare through your open dashboard session.
- **Everything is logged.** Every call is recorded with time, user, tool, arguments (file contents reduced to a size), outcome and IP address, under **Signal Flare › Activity**.
- **Stays inside WordPress.** File tools only reach paths under the WordPress folder, after resolving `..` and symlinks. Core folders can't be deleted, and Signal Flare can't change its own files.
- **No white screens from typos.** PHP files are syntax-checked before they're written.
- **Short-lived links.** Upload and login links expire within minutes, work once, and store only a hash of their token. Upload links refuse code files.
- **Self-check.** After every WordPress, plugin or theme update, Signal Flare checks that it still works (PHP version, Application Passwords, its endpoint, its log, WP-CLI) and shows a notice if not.

What it can't protect against: anyone holding a valid Application Password for an administrator can do anything an administrator with server access can. Treat that password like your hosting login.

## Updates

New versions show up on your **Plugins** page like any other plugin, and WordPress's auto-update switch works too. They come from this repository's GitHub Releases.

Every release is signed with an Ed25519 key that never leaves the author's computer. Before installing an update, Signal Flare checks the download against that signature, so a release can't be swapped or altered, even by someone who took over this GitHub account.

## Signal Tower (optional)

Signal Flare can also check in with a Signal Tower dashboard that watches many sites. This is **off unless you paste in a connection code** from a tower. When connected, the site reports once an hour: WordPress, PHP, plugin and theme versions, updates waiting, Signal Flare's switches, its self-check and an error count. It never sends passwords, file contents or code. A tower can only ask the site to install a signed release of Signal Flare (or its sister plugin Field Mark).

## Uninstall

Deleting the plugin from the Plugins page removes its settings, activity log and any unused links. Revoke the "Claude" Application Password yourself.

## Reporting a security problem

Please don't open a public issue. See [SECURITY.md](SECURITY.md).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
