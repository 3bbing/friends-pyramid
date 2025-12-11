# Pyramiden-Fragenspiel

Mobile-first Multiplayer-Webapp für Shared Webspace (PHP 8+, ohne SQL). Teams erstellen eine Lobby, spielen Runden mit Pyramidenfragen, tippen Pfade und vergleichen Ergebnisse.

## Features
- Team-/Invite-System ohne Accounts: Teamname + Passwort, Invite-Link mit Token.
- JSON-Persistenz im Dateisystem mit Locking.
- Runden mit rotierender aktiver Person, Pfadabgaben aller Spieler, automatische Auflösung und Scoring.
- Optionaler Runden-Timer und kumulativer Zwischenstand über mehrere Runden.
- Fragenpool mit alltagsnahen 2-Wege-Entscheidungen + Hinzufügen eigener Fragen (lobbyweit oder global).
- Optional: OpenAI-gestützte Fragengenerierung (bei konfiguriertem API-Key) im Admin-Bereich.
- Mobile UI mit großen Buttons, View-Wechsel für Lobby/Antwort/Reveal.
- Admin-Dashboard mit Systemstatus (Schreibrechte/Pools) und Katalogverwaltung.

## Struktur
```
index.php            Landing, Team erstellen oder beitreten
create_team.php      legt Team an
join.php             Join-Formular per Team-ID/Invite
lobby.php            Single-Page-Lobby & Spielansicht
api/state.php        JSON-State-Polling
api/action.php       Aktionen (start_game, submit_answers, next_round, ...)
assets/css/main.css  Styles
assets/js/app.js     Frontend-Logik
questions/           Basisfragen (questions.json) + optionale Pools (spicy/university/couples) und Custom-Fragen
admin.php            Rudimentärer Frageneditor (Admin-Passwort)
includes/utils.php   Storage-, Session- und Game-Helper
config.sample.php    Beispielkonfiguration
```

## Setup
1. PHP 8+ Webspace ohne Build-Schritte bereitstellen.
2. `config.sample.php` nach `config.local.php` kopieren und anpassen:
   - `admin_password`: Zugriff auf admin.php (Kataloge verwalten & generieren).
   - `question_pools`: Pfade/Labels der Pools, `default=true` markiert Vorbelegung.
   - `default_timer_seconds` + `round_timer_options`: Timer-Auswahl für Hosts.
   - `openai.api_key`: Optionaler Key zum Generieren.
   - Pfade zu Datenordnern bei Bedarf anpassen.
3. Schreibrechte für `data/` und `questions/custom/` vergeben (Server-Benutzer).
4. Fragenpool liegt in `questions/questions.json` (Standard) + optionale Pools; eigene Fragen werden unter `questions/custom/` abgelegt und zählen automatisch mit.
5. `admin.php` nutzen, um Status (Schreibrechte/Pools) zu prüfen, Karten zu editieren, neue Kataloge (auch KI-basiert) anzulegen oder Custom-Pools in den Basis-Pool zu kopieren.
6. Site aufrufen, Team erstellen, Invite-Link teilen, Passwort an Mitspieler weitergeben.

## Spielablauf
1. Host erstellt Team, bekommt Invite-Link mit Token.
2. Spieler treten mit Name + Passwort bei; Ersteller ist Host.
3. Host startet Spiel: wählt die gewünschte Tiefe (sofern genug Karten vorhanden), welche Fragepools einfließen und optional einen Timer; Pyramide wird mit Zufallsfragen gebaut.
4. Alle Spieler beantworten Pfad (L/R). Sobald alle fertig sind, der Host forced reveal oder der Timer abläuft, erfolgt Auflösung mit Pfadvergleich + Endblatt-Bonus.
5. Host startet nächste Runde; aktive Person rotiert automatisch, kann erneut Tiefe/Timer/Pools wählen und der Zwischenstand summiert Punkte über alle Runden.

## Sicherheit & Persistenz
- Keine SQL-DB, alle Daten als JSON im nicht-öffentlichen Dateisystem, geschrieben mit `flock`.
- Team-Passwörter werden gehasht gespeichert (`password_hash`).
- Session-Cookie merkt player_id/team_id.
- OpenAI-API-Key gehört in `config.local.php`, nicht ins Repo.

## OpenAI-Generierung
- Nur wenn `openai.api_key` gesetzt ist.
- Zugriff nur über `admin.php` mit Admin-Passwort.
- Rate-Limit (Default 30s) verhindert Spam.
- Rückgabe wird auf Schema Frage/Optionen geprüft und gekürzt.

## Lizenz
MIT
