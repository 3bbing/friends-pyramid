<?php
require __DIR__ . '/includes/utils.php';
ensure_directories();
$config = load_config();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pyramiden-Fragenspiel</title>
    <link rel="stylesheet" href="assets/css/main.css">
</head>
<body>
<div class="page">
    <header>
        <h1>Pyramiden-Fragenspiel</h1>
        <p>Erstelle ein Team oder trete per Invite-Link bei.</p>
    </header>
    <main class="card-grid">
        <section class="card">
            <h2>Team erstellen</h2>
            <form action="create_team.php" method="post" class="stack">
                <label>Teamname<input type="text" name="team_name" required maxlength="60"></label>
                <label>Team-Passwort<input type="password" name="team_password" required minlength="4"></label>
                <button type="submit" class="primary">Team erstellen</button>
            </form>
        </section>
        <section class="card">
            <h2>Team beitreten</h2>
            <form action="join.php" method="get" class="stack">
                <label>Team-ID<input type="text" name="team" required></label>
                <label>Invite-Token (optional)<input type="text" name="token"></label>
                <button type="submit" class="secondary">Weiter</button>
            </form>
        </section>
    </main>
    <footer></footer>
</div>
</body>
</html>
