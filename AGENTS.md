# Codex projectinstructies

Lees bij iedere nieuwe werksessie eerst [`docs/PROJECT_WORKFLOW.md`](docs/PROJECT_WORKFLOW.md).
Dit document bevat het doel, de architectuur, de productieomgeving, de actuele projectstand en de
eerstvolgende werkzaamheden.

Werkafspraken:

- Werk primair aan de PHP-app in `cloud86/`. De Python-code is alleen een eerder prototype en
  contractreferentie, tenzij de gebruiker uitdrukkelijk anders vraagt.
- Bewaar nooit wachtwoorden, OAuth-tokens, API-sleutels, klantgegevens of de productie-`.env` in Git.
- Laat `GLS_MODE=mock` staan totdat de GLS-testgegevens zijn ontvangen en de acceptatietest is
  afgesproken.
- Maak na een onduidelijke timeout bij GLS niet automatisch opnieuw een zending aan; controleer eerst
  of GLS de eerdere aanvraag heeft verwerkt.
- Databasewijzigingen worden niet automatisch door Plesk uitgevoerd. Lever daarvoor een expliciete,
  controleerbare migratie en bijbehorende instructie aan.
- Werk na iedere betekenisvolle wijziging de secties **Actuele stand** en **Volgende stappen** in
  `docs/PROJECT_WORKFLOW.md` bij.
- Vermeld bij overdracht welke commit lokaal, op GitHub en op Cloud86/Plesk staat. Neem bij twijfel
  geen deployment als voltooid aan.

