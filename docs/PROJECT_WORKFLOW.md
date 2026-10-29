# Exact → GLS Werkplek: workflow en projectoverdracht

Dit is het centrale overdrachtsdocument voor mensen en nieuwe Codex-sessies. Lees dit document
voordat je verder bouwt. Werk de projectstand bij na iedere betekenisvolle wijziging.

## 1. Doel van de applicatie

De webapp ondersteunt het magazijn bij het verzenden van Exact Online-goederenleveringen via GLS:

1. De app haalt een goederenlevering en het afleveradres uit Exact Online.
2. Een magazijnmedewerker controleert de levering.
3. De medewerker voert het aantal dozen en het gewicht per doos in.
4. De app maakt via de GLS Label API één zending met de benodigde labels aan.
5. De labels worden als PDF opgeslagen buiten de publieke webmap en kunnen worden afgedrukt.
6. De GLS-track-en-tracecode wordt lokaal opgeslagen.
7. In een latere fase schrijft de app de track-en-tracecode terug naar een afgesproken veld in Exact.
8. GLS-statusupdates kunnen via de Track & Trace-webhook binnenkomen.

De app is een zelfstandige magazijnwerkplek en wordt niet in Exact of WordPress geïnstalleerd.

## 2. Productieomgeving

| Onderdeel | Waarde |
|---|---|
| Publieke URL | `https://gls.beteccontrols.nl` |
| Hosting | Cloud86 WooCommerce Power / Plesk |
| PHP | 8.3 |
| Database | MySQL, aparte database voor deze app |
| GitHub | privé-repository `Hamstring2497/GLS-api` |
| Branch | `main` |
| Plesk Git | leest GitHub via een read-only deploy key |
| Plesk deploymentmap | `/gls.beteccontrols.nl/app` |
| Web document root | `gls.beteccontrols.nl/app/cloud86/public` |
| Exact-app | `BETEC GLS Werkplek` |
| Exact callback | `https://gls.beteccontrols.nl/oauth/exact/callback` |
| Exact-administratie | `2875157` (door OAuth vastgesteld) |

Geheimen staan uitsluitend in `cloud86/.env` op de server. Ze horen niet in GitHub, documentatie,
screenshots of chatberichten.

## 3. Technische architectuur

De productieversie is een kleine PHP 8/MySQL-app zonder Composer-afhankelijkheden:

```text
Browser magazijn
      |
      v
cloud86/public/index.php        router, sessie, CSRF en controllers
      |
      +--> ExactService.php     OAuth, tokenrefresh en goederenleveringen ophalen
      +--> GlsService.php       GLS-payload, labelaanroep en webhookverwerking
      +--> DeliveryRepository   leveringen, dozen en tracking opslaan
      +--> UserRepository       algemeen magazijnaccount en loginbeveiliging
      |
      +--> MySQL
      +--> storage/labels       gegenereerde labels, niet publiek bereikbaar
```

Belangrijkste mappen:

```text
cloud86/
├── public/                 enige publieke document root
├── src/                    applicatielogica
├── views/                  server-side HTML
├── database/schema.sql     initiële databasestructuur
├── bin/create-user.php     maakt het algemene magazijnaccount
├── storage/labels/         PDF-labels buiten de publieke webmap
├── storage/logs/           afgeschermde logs
└── .env                    productiegeheimen; wordt nooit gecommit
```

De Python-app in `src/gls_exact/` is het oorspronkelijke prototype en blijft alleen bestaan als
geteste referentie voor de GLS-contracten. Nieuwe productiewerkzaamheden horen in `cloud86/`.

## 4. Functionele workflow

### A. Aanmelden en Exact koppelen

- Eén algemeen magazijnaccount logt in op de webapp.
- Na vijf mislukte pogingen wordt het account 15 minuten geblokkeerd.
- Een beheerder start `/oauth/exact/start`.
- Exact stuurt de browser terug naar `/oauth/exact/callback`.
- Access- en refresh-token worden AES-256-GCM versleuteld in MySQL opgeslagen.
- De app vraagt via Exact `current/Me` de administratie/division op.
- Bij een verlopen access-token gebruikt de app het refresh-token en bewaart ook het geroteerde
  refresh-token.

### B. Leveringen ophalen

- Een beheerder klikt op **Leveringen ophalen**; dit verstuurt `POST /exact/sync` met CSRF-controle.
- De app vraagt de recentste Exact `GoodsDeliveries` op, standaard maximaal 50.
- Reeds lokaal aanwezige leveringen worden overgeslagen.
- Leveringen die in Exact al een `TrackingNumber` hebben worden overgeslagen.
- Het afleveradres wordt uit Exact opgehaald; bij ontbreken wordt het hoofdadres van de relatie
  geprobeerd.
- Alleen voldoende complete verzendgegevens worden lokaal als nieuwe levering opgeslagen.
- Deze stap maakt nadrukkelijk nog geen GLS-zending aan.

### C. Dozen en labels

- De medewerker opent een levering en voert per doos een gewicht in.
- Elk gewicht moet groter dan 0 en maximaal 32 kg zijn.
- De app bouwt één GLS `CreateLabel`-request met het juiste aantal parcels.
- In `mock` ontstaan alleen testcodes; er wordt geen echte GLS-zending gemaakt.
- In GLS-test/productiemodus wordt de officiële GLS API aangeroepen.
- Base64-PDF-labels worden in `cloud86/storage/labels/` opgeslagen.
- Na status `labels_created` kunnen de dozen niet meer worden gewijzigd en kan niet nogmaals een
  labelaanvraag worden gedaan.

### D. Track & Trace

- GLS kan statusupdates sturen naar `POST /webhooks/gls` met een Bearer-token.
- Webhookberichten worden gededupliceerd en krijgen snel HTTP 200 terug, ook bij een GLS-retry.
- Trackingnummers en de laatste GLS-status worden lokaal opgeslagen.
- Terugschrijven naar Exact en `ConfirmLabel` zijn nog niet afgebouwd.

## 5. Belangrijkste routes

| Methode en route | Functie |
|---|---|
| `GET /health` | controleert app en databaseconfiguratie |
| `GET/POST /login` | magazijnauthenticatie |
| `POST /logout` | sessie beëindigen |
| `GET /deliveries` | overzicht |
| `GET/POST /deliveries/new` | alleen een handmatige testlevering |
| `GET /deliveries/{id}` | levering controleren en dozen beheren |
| `POST /deliveries/{id}/parcels` | gewichten opslaan |
| `POST /deliveries/{id}/labels` | GLS-labels maken |
| `GET /labels/{filename}` | beschermd label downloaden |
| `GET /oauth/exact/start` | Exact OAuth starten |
| `GET /oauth/exact/callback` | Exact OAuth afronden |
| `POST /exact/sync` | recente goederenleveringen ophalen |
| `POST /webhooks/gls` | GLS Track & Trace-webhook |

## 6. Database

De initiële tabellen staan in `cloud86/database/schema.sql`:

- `users`: magazijnaccount, wachtwoordhash en blokkade-informatie;
- `deliveries`: Exact-referentie, adres, status, tracking en GLS-status;
- `parcels`: doosnummer, gewicht en eventueel trackingnummer;
- `exact_tokens`: versleutelde OAuth-tokens en Exact-division;
- `webhook_events`: deduplicatie van GLS-webhookberichten.

Voer toekomstige wijzigingen uit met een afzonderlijke SQL-migratie. Plesk voert
`database/schema.sql` niet automatisch opnieuw uit en mag bestaande productiegegevens niet
overschrijven.

## 7. Configuratie zonder geheimen

Gebruik `cloud86/.env.example` als volledige namenlijst. De productie-`.env` bevat onder meer:

- algemene appinstellingen: `APP_ENV`, `APP_URL`, `APP_TIMEZONE`, `APP_KEY`;
- database: `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`;
- GLS: modus, endpoints, subscription key, MyGLS-login, klantnummer, labeltype en webhooktoken;
- Exact: base-URL, client-id, client-secret, callback, optionele division en synchronisatielimiet.

Nooit echte waarden in dit document zetten. `EXACT_DIVISION` mag leeg blijven: de app bepaalt deze
na OAuth. `GLS_MODE` blijft `mock` totdat de GLS-testacceptatie klaarstaat.

## 8. GitHub → Cloud86 deploymentworkflow

1. Werk lokaal in deze repository en controleer `git status`.
2. Test de wijziging passend bij het risico.
3. Werk zo nodig dit overdrachtsdocument bij.
4. Commit op `main` met een beschrijvende committekst.
5. Push via GitHub Desktop naar `origin/main`.
6. Open Plesk → `gls.beteccontrols.nl` → **Git**.
7. Klik **Nu pull uitvoeren**. De branch `main` wordt automatisch naar
   `/gls.beteccontrols.nl/app` gepubliceerd.
8. Controleer `https://gls.beteccontrols.nl/health`.
9. Test login en alleen de gewijzigde workflow.
10. Controleer bij HTTP 500 eerst Plesk **Logbestanden** en lint zo nodig via SSH:

```bash
cd gls.beteccontrols.nl/app/cloud86
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

Een code-deployment wijzigt de productie-`.env` niet. Databasewijzigingen moeten apart en
gecontroleerd worden toegepast.

## 9. Test- en veiligheidsregels

- Gebruik voor ontwikkeling en eerste acceptatie `GLS_MODE=mock`.
- Test Exact-import eerst met enkele herkenbare, niet-verzonden goederenleveringen.
- Controleer adres- en veldmapping voordat een echte GLS-call wordt toegestaan.
- Zet nooit OAuth-tokens, API-responses met persoonsgegevens of wachtwoorden in Git.
- Een timeout bij `CreateLabel` heeft een onzekere uitkomst. Probeer niet blind opnieuw; controleer
  eerst bij GLS om dubbele zendingen te voorkomen.
- Houd de maximale GLS-doosgrens van 32 kg aan.
- Gebruik HTTPS en houd `src`, `views`, `database`, `storage` en `.env` buiten de document root.

## 10. Actuele stand — 29 oktober 2026

### Bevestigd werkend op Cloud86

- HTTPS, correcte document root, PHP en MySQL;
- beveiligde login met het algemene magazijnaccount;
- Exact OAuth-koppeling met administratie `2875157`;
- handmatige testlevering;
- twee dozen invoeren en mocklabels maken;
- dubbele labelaanvraag blokkeren;
- Plesk Git-deployment vanuit de private GitHub-repository.

### Laatste applicatieversie

- Commit `c9a1c9a` — **Import goods deliveries from Exact Online**.
- Deze versie voegt tokenrefresh, `POST /exact/sync` en de knop **Leveringen ophalen** toe.
- De commit staat lokaal en op `origin/main` in GitHub.
- Bij de laatste overdracht was nog niet bevestigd dat Plesk deze commit had opgehaald en dat de
  synchronisatie succesvol met echte Exact-data was getest.

### Eerdere commits

- `e52a1d1` — Exact OAuth-callback toestaan met `SameSite=Lax`;
- `f3023da` — eerste Cloud86 PHP-app.

## 11. Volgende stappen

Werk deze lijst in deze volgorde af:

1. Bevestig dat Plesk/Cloud86 commit `c9a1c9a` heeft opgehaald.
2. Klik in productie op **Leveringen ophalen** en noteer alleen de resultaatmelding, geen geheimen of
   volledige persoonsgegevens.
3. Controleer de Exact-mapping met een echte levering: leveringsnummer, ordernummer, datum,
   ontvanger en afleveradres.
4. Pas de mapping defensief aan als de Exact-response andere velden gebruikt.
5. Vraag/controleer GLS-testgegevens en de definitieve testendpoints.
6. Voer een GLS-acceptatietest uit met een expliciete testlevering en controleer PDF/labelprinter.
7. Spreek af naar welk Exact-veld de track-en-tracecode wordt teruggeschreven en implementeer dit.
8. Implementeer en test `ConfirmLabel` op het overeengekomen moment.
9. Activeer en test de GLS-webhook met een nieuw sterk Bearer-token.
10. Voeg pas daarna geplande automatische Exact-import toe.

## 12. Overdrachtssjabloon voor een nieuwe Codex-sessie

Een volgende sessie kan beginnen met:

> Lees eerst `AGENTS.md` en `docs/PROJECT_WORKFLOW.md`. Controleer daarna `git status`, de laatste vijf
> commits en de relevante code. Ga verder vanaf **Actuele stand** en **Volgende stappen**. Neem geen
> deployment als voltooid aan zonder bevestiging en plaats nooit geheimen in Git of chat.

Werk bij het afsluiten minimaal deze gegevens in dit document bij:

- datum;
- laatste lokale commit;
- laatste bevestigde GitHub-commit;
- laatste bevestigde Cloud86-commit;
- uitgevoerde productiecontrole;
- eerstvolgende concrete stap en eventuele blokkade.

