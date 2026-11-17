# Store-Texte WPHub 0.18.2 (Beta, 17.11.2026)

Beschreibung unverändert gegenüber 0.18.1, nur die Versionsinformation ist neu. Doku-Link in beiden Sprachen auf die deutsche README.md.

## Release notes (de)

- Vaillant: Lehnt Vaillant eine Anfrage mit HTTP 403 und einer Webseite (Web-Firewall) ab, meldet das Modul das jetzt als „Vaillant hat die Anfrage abgelehnt“ statt fälschlich als „Kontingent aufgebraucht“. Die Pause von 5 Minuten bleibt, das Protokoll zeigt den Seitentitel statt des HTML-Textes.
- Hinweis: An der Anmeldung, den Abrufen und den Werten ändert sich nichts.

## Release notes (en)

- Vaillant: If Vaillant rejects a request with HTTP 403 and a web page (web firewall), the module now reports "Vaillant rejected the request" instead of wrongly reporting "quota used up". The 5-minute pause stays, the log shows the page title instead of the HTML text.
- Note: Sign-in, requests and values are unchanged.
