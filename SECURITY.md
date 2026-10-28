# Security

Signal Flare gives an AI agent administrator access to a WordPress site, so security reports matter a lot here.

## Reporting a problem

Please report vulnerabilities privately through GitHub: the **Security** tab of this repository › **Report a vulnerability**. Don't open a public issue or pull request for a security problem.

Include what you found, the steps to reproduce it, and the Signal Flare, WordPress and PHP versions. You'll get a reply within a few days, and a fix will be released as soon as it's ready.

## What counts

Anything that lets someone do more than the design allows, for example:

- using any tool without an administrator's Application Password, or with a browser login
- reaching files outside the WordPress folder, or changing Signal Flare's own files through it
- calls that skip the activity log
- reusing, guessing or widening upload and login links
- getting a site to install an update that isn't signed with the release key
- a Signal Tower connection doing anything other than receiving reports and asking for signed releases

Not a vulnerability: an administrator with a valid Application Password running code on their own site. That is what Signal Flare is for.

## Supported versions

Only the latest release gets fixes. Updates are signed and show up on the Plugins page, so please stay current.
