# NEXORA Distribution OS — Windows Local Development

From the repository root run:

Set-ExecutionPolicy -Scope Process Bypass
.\tools\setup-windows.ps1

The setup keeps XAMPP PHP unchanged. If active PHP is below 8.3, it downloads project-local PHP 8.3 under .tools\php83.

Requirements: Windows, XAMPP MySQL, Composer, Node.js/npm.

Start:
.\tools\dev-windows.ps1

PC: http://localhost:3000
Phone on same Wi-Fi: http://<PC-LAN-IP>:3000

The dev script writes frontend/.env.local with the LAN API URL and starts Laravel on 8000 plus Next.js on 3000.

NEXORA remains Laravel 13 / PHP 8.3+. We do not downgrade the architecture to match XAMPP's bundled PHP.
