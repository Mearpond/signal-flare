=== Signal Flare ===
Requires at least: 6.5
Requires PHP: 8.0
Stable tag: 0.2.1
License: GPL-2.0-or-later

Connects Claude to this site over MCP so it can build and maintain it. Full access, admins only, every action logged.

== Installation ==

1. Plugins › Add New › Upload Plugin, choose signal-flare.zip, then Install and Activate.
2. Open Signal Flare in the dashboard menu. Switch it on, and allow code and file tools while building.
3. Follow "Connect Claude" on that page: create an Application Password named "Claude", then run the command shown in Terminal on your Mac.
4. Start a new Claude session. This site's tools appear under its own name (signal-flare-yoursite-com).

To cut Claude off: switch Signal Flare off, or revoke the Application Password. Deleting the plugin removes its settings and activity log.

== Security ==

* Off until an admin switches it on; code and file tools have their own switch.
* Administrators only, logged in with an Application Password (browser sessions are refused).
* Every call is logged under Signal Flare › Activity.
* File tools stay inside the WordPress folder and cannot change Signal Flare itself.
* PHP is syntax-checked before it is written.
* Upload and login links expire within minutes and work once.

Application Passwords need HTTPS. Some security plugins turn them off; Signal Flare's page warns you if so.
