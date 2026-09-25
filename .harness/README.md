# Harness

| Fil | Formål |
|---|---|
| `autopilot.on` | slår stop-vagten til; slet den for at stoppe autopilot |
| `waiting` | en workflow kører i baggrunden; sessionen må vente |
| `iterations` | antal gange stop-vagten har blokeret i denne kørsel |
| `max-iterations` | loft over blokeringer, standard 300 |
| `stop-hook.sh` | Stop-hooken, registreret i `.claude/settings.json` |

Stop-vagten er inaktiv, når `autopilot.on` mangler, så almindelige sessioner i repoet påvirkes ikke.
