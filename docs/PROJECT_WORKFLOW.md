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
- Via de eerste `GoodsDeliveryLine` wordt het echte Exact-verkoopordernummer opgehaald. De
  gekoppelde verkooporder levert waar beschikbaar de contactpersoon van **Besteld door**; de
  contactkaart levert e-mail en telefoon. Adresregel 2 en 3 worden uit het afleveradres gelezen en
  zo nodig aangevuld vanuit het hoofdadres van **Besteld door**.
- Alleen voldoende complete verzendgegevens worden lokaal als nieuwe levering opgeslagen.
- Deze stap maakt nadrukkelijk nog geen GLS-zending aan.

### C. Dozen en labels

- De medewerker opent een levering en voert per doos een gewicht in.
- Het overzicht toont leveringen als compacte tabel met status. Onder de geselecteerde regel staan
  de aflevergegevens per veld, vooraf ingevuld vanuit Exact. De medewerker kan het lokale adres
  vóór het maken van labels corrigeren; daarna zijn de adresvelden vergrendeld.
- Het type is standaard Parcel (`P`). Voor Freight (`F`) is de GLS-unitsoort apart te kiezen.
  De referentie is standaard de laatste vier cijfers uit het ordernummer en kan vóór
  de labelaanvraag worden aangepast. Als het ordernummer geen cijfers bevat, moet een referentie
  handmatig worden ingevuld.
- Als een oudere of afwijkende Exact-administratie geen gekoppelde verkooporder teruggeeft, zet de
  import als veilige terugval het leveringsnummer in `order_number`. Controleer de veldmapping met
  een herkenbare echte levering voordat de referentie als definitief wordt beschouwd.
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
| `POST /deliveries/{id}/address` | lokaal afleveradres vóór labelaanmaak aanpassen |
| `POST /deliveries/{id}/shipping` | Parcel/Freight en referentie vóór labelaanmaak opslaan |
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

Voor de nieuwe velden `ship_type` en `shipment_reference` staat de eenmalige migratie in
`cloud86/database/migrations/20261029_shipping_details.sql`. Voer die uit op de bestaande
database voordat de gewijzigde PHP-code wordt gedeployed. Bestaande leveringen krijgen Parcel
als type; hun referentie wordt bij het tonen afgeleid van het ordernummer totdat die wordt opgeslagen.

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

## 10. Actuele stand — 30 oktober 2026

### Bevestigd werkend op Cloud86

- HTTPS, correcte document root, PHP en MySQL;
- beveiligde login met het algemene magazijnaccount;
- Exact OAuth-koppeling met administratie `2875157`;
- handmatige testlevering;
- twee dozen invoeren en mocklabels maken;
- dubbele labelaanvraag blokkeren;
- Plesk Git-deployment vanuit de private GitHub-repository.

### Laatste applicatieversie

- 30 oktober 2026, lokaal in voorbereiding: de teksten in het menu **Onderhoud** zijn compacter.
  Bij een levering zonder opgeslagen dozen blijft altijd een lege rij **Doos 1** zichtbaar. Het
  gewicht is niet HTML-verplicht; een lege rij wordt door de bestaande serverfilter niet als doos
  opgeslagen en laat de status op `new`. De Exact-contactnaam wordt bij voorkeur opgebouwd als
  `Titel + Tussenvoegsel + Achternaam`, of zonder titel als
  `Voornaam + Tussenvoegsel + Achternaam`; alleen als die onderdelen ontbreken wordt `FullName`
  gebruikt. Er is geen databasemigratie nodig. `git diff --check` en de negen
  Python-referentietests zijn geslaagd. De PHP-smoketest bevat nieuwe controles voor de lege doos
  en beide naamvarianten, maar kan lokaal niet worden uitgevoerd omdat PHP ontbreekt. Deze wijziging
  is nog niet op GitHub of Cloud86 gepubliceerd.
- 30 oktober 2026: applicatiecommit `e4a1dc2` staat lokaal, op GitHub en is via Plesk op Cloud86
  gepubliceerd. Synchronisatiemeldingen hebben een knop **Gezien**; geen nieuwe leveringen wordt
  blauw/informatief weergegeven, gedeeltelijke importproblemen amber en alleen een volledige fout
  rood. De hoofdknoppen hebben dezelfde maat en een subtiel 3D-effect. Onder **Onderhoud** staat de
  dubbel bevestigde actie **Tabel leegmaken en opnieuw ophalen**. Deze verwijdert uitsluitend lokale
  leveringen en dozen; Exact-tokens, gebruikers, instellingen en webhookhistorie blijven behouden.
  De productieactie verwijderde 20 lokale leveringen. De eerste herimport werd door Exact beperkt
  met HTTP 429; na een korte wachttijd leverde een nieuwe synchronisatie 9 actuele leveringen op.
  Leveringen `9080456`, `9080463` en `9080452` zijn bewust overgeslagen omdat in Exact het
  afleveradres geen huisnummer (`house_no`) bevat. De knop **Gezien** is live gecontroleerd en
  verbergt de melding. `/health` gaf `status: ok` en `gls_mode: mock`. Er was geen databasemigratie
  nodig. De negen Python-referentietests en `git diff --check` zijn geslaagd; lokale PHP is niet
  beschikbaar.
- 30 oktober 2026: applicatiecommit `a3683b7` staat lokaal, op GitHub en is via Plesk op Cloud86
  gepubliceerd. Onder
  **Dozen en gewicht** staan **Zending opslaan** en **Adres opslaan** naast elkaar. De bestaande,
  dubbel bevestigde verwijderactie staat voortaan in het bovenmenu **Onderhoud**. Vrachttype `CO`
  heet in de werkplek **Collo (max. 32 kg)** en actieve invoervelden hebben een dikkere, contrastrijke
  blauwe focusrand. Exact-import volgt nu de goederenleveringsregel naar de verkooporder voor het
  echte ordernummer en haalt waar beschikbaar adresregel 2/3, contactpersoon, e-mailadres en
  telefoon van **Besteld door** op. Aflevergegevens blijven leidend; bestelgegevens vullen alleen
  ontbrekende aanvullende gegevens aan. Er is geen databasemigratie nodig. De negen
  Python-referentietests en `git diff --check` zijn geslaagd; lokaal is geen PHP-runtime aanwezig.
  Plesk bevestigde pull en publicatie als voltooid. Live zijn het onderhoudsmenu, beide onderste
  opslaanknoppen, het collo-label en de dikke blauwe focusrand visueel gecontroleerd. `/health`
  gaf `status: ok` en `gls_mode: mock`. Er is tijdens deze controle geen Exact-synchronisatie
  gestart en er zijn geen productiegegevens opgeslagen of gewist.
- 30 oktober 2026: applicatiecommit `5f40412` staat lokaal, op GitHub en op Cloud86. De aparte
  kolom **Openen** is verwijderd; de volledige leveringrij blijft met muis en toetsenbord
  selecteerbaar. **Zending opslaan** en **Zending wissen** staan nu onder **Dozen en gewicht**.
  Wissen vereist twee browserbevestigingen. De tweede toont **Haal uw supervisor**; pas daarna
  worden beide vereiste servervelden op `yes` gezet. Opgeslagen aflevergegevens blijven in
  `deliveries.address_json` gekoppeld aan de levering. De tabel toont naam, straat, huisnummer met
  toevoeging, postcode, plaats en land. Naam 2/3 en notities blijven wel opgeslagen maar zijn niet
  als aparte tabelkolommen zichtbaar. Er is nog geen zoek- of filterfunctie. Plesk bevestigde pull
  en publicatie als voltooid; live zijn het ontbreken van de Openen-kolom, de adreswaarden in de
  eerste tabelrij en de verplaatste knoppen gecontroleerd. Er zijn geen productiegegevens gewist
  of opgeslagen tijdens deze controle. `git diff --check` en de negen Python-referentietests zijn
  geslaagd; de PHP-shelltest is niet uitgevoerd.
- 30 oktober 2026: applicatiecommit `9359fb4` staat lokaal, op GitHub en is via Plesk op Cloud86
  gepubliceerd. Het overzicht toont **Nieuwe levering** en **Leveringen ophalen**
  naast elkaar; de Exact-koppelstatus staat als informatie in de bovenbalk. De leveringskop is
  opgenomen in de balk **Zending**, waarvan Type, Vrachttype en Referentie compacter zijn. Het
  afleveradres ondersteunt zonder databasemigratie de extra JSON-velden Naam 2, Naam 3,
  huisnummertoevoeging, Notitie 1 en Notitie 2. Naam 2/3 worden als optionele `Name2`/`Name3`
  naar GLS gestuurd, huisnummer en toevoeging worden voor `HouseNo` samengevoegd en de notities
  worden per doos als `AdditionalInfo1`/`AdditionalInfo2` opgenomen. Beide notities zijn begrensd
  op 50 tekens. De gereedheidsinformatie staat rechts in de balk **Dozen en gewicht**. Gewichten
  gebruiken compacte velden, een zichtbare `kg`-suffix en stappen/opslag van 0,1 kg.
  `git diff --check` en de negen Python-referentietests zijn geslaagd. Plesk bevestigde pull en
  publicatie als voltooid. Live zijn de bovenbalk, gekoppelde knoppen, compacte verzendbalk,
  extra adresvelden en gereedheidsinformatie gecontroleerd. Bij levering `9080404` tonen de twee
  bestaande gewichtsvelden respectievelijk `4.0` en `2.0`, met HTML-stap en minimum `0.1` en een
  zichtbare `kg`-suffix. Er zijn tijdens deze controle geen productiegegevens opgeslagen. Lokale
  PHP-syntaxis- en smoketests waren niet mogelijk omdat lokaal geen PHP-runtime is geïnstalleerd;
  ook via de hosting is in deze ronde geen shelltest uitgevoerd.
- 30 oktober 2026: commit `158d3af` staat lokaal, op GitHub en is via Plesk op Cloud86
  gepubliceerd. De
  huisstijl gebruikt nu blauwe BETEC-accenten. Het scherm is horizontaal verdeeld in een compacte,
  zelfstandig scrollende leveringstabel boven en een brede, zelfstandig scrollende detailzone
  onder. De tabel toont ook de bestaande `created_at`-datum als **Toegevoegd**. Een volledige rij
  selecteert de levering zonder het venster naar een anker te verplaatsen. Als een adres-,
  zending- of doosveld is gewijzigd, waarschuwt de app vóór het wisselen van rij of verlaten van
  de pagina. Het afleveradres gebruikt op brede schermen twee compacte invoerrijen met groter
  invoerlettertype; **Dozen en gewicht** staat als onderste detailsectie en alle zichtbare
  benamingen **Eenheid** zijn vervangen door **Doos**. Hiervoor is geen databasemigratie nodig.
  `git diff --check` en de negen Python-referentietests zijn geslaagd. Live is visueel bevestigd
  dat de blauwe gesplitste werkplek, de kolom **Toegevoegd**, de zelfstandige tabelscroll en de
  compacte detailformulieren worden geladen. Zonder wijzigingen wisselde de selectie van
  levering `9080454` naar `9080457` zonder paginaverschuiving. Na een tijdelijke wijziging aan de
  referentie blokkeerde de waarschuwing de selectie van een andere regel; **Annuleren** behield
  levering `9080457`. De tijdelijke wijziging is daarna zonder opslag verworpen. De PHP-smoketest
  is bij deze publicatie niet via SSH uitgevoerd.
- 30 oktober 2026: de nieuwe vormgeving staat in commit `b28c827` op GitHub en Cloud86. De
  werkruimte gebruikt op brede schermen maximaal 1840 px, de
  leveringstabel is compacter en lange ontvanger-, straat- en trackingwaarden mogen afbreken. De
  navigatie en tabelkop gebruiken donker BETEC-groen met een lichtgroen accent. Formulierlabels
  zijn klein, vet en donkergroen; invoervelden hebben een lichte achtergrond, een duidelijke
  tweepixelrand en een zichtbare focusstatus. De stylesheet-URL bevat automatisch de
  wijzigingstijd van het CSS-bestand, zodat browsers na een deployment niet de oude vormgeving uit
  hun cache blijven tonen. Deze cache-oplossing staat in commit `e2ce4df` en is eveneens op
  GitHub en Cloud86 gepubliceerd. Live is gecontroleerd dat de versiegebonden CSS wordt geladen,
  de navigatie en tabelkop `rgb(0, 78, 53)` gebruiken en invoervelden een tweepixelrand hebben.
  De PHP- en databaselogica zijn verder niet gewijzigd. `git diff --check` en de negen
  Python-referentietests zijn geslaagd.
- 29 oktober 2026: de UI-uitbreiding staat lokaal en op GitHub in commit `ebcb9ba` en is
  via Plesk op Cloud86 gepubliceerd. Publicatie werd opnieuw gecontroleerd met de nieuwe CSS,
  `/health` HTTP 200 (`GLS_MODE=mock`) en de ingelogde magazijnwerkplek.
  De titel is compacter; de tabel is breder, horizontaal scrollbaar en heeft adres-, vracht-,
  gewicht- en trackingkolommen. Gereedregels zijn donkergrijs. Type en vrachttype staan naast
  elkaar. Eenheden kunnen worden verwijderd en opnieuw opgeslagen; ook nul eenheden is geldig
  en zet de levering terug op `new`. Zendingen zonder labels kunnen na bevestiging uit de
  werkvoorraad worden verwijderd (`discarded`); Exact importeert ze niet opnieuw. Zendingen
  met labels kunnen niet worden gewist. De nieuwe migratie
  `cloud86/database/migrations/20261029_freight_unit_type.sql` is volgens de gebruiker op de
  productiedatabase toegepast; de ingelogde pagina leest de nieuwe kolom zonder fout. PHP-smoketest,
  syntaxiscontrole en `git diff --check`
  zijn lokaal geslaagd; live magazijn- en GLS-test moeten nog gebeuren.
- Vervolg lokaal: e-mailadres is verplicht bij handmatige invoer, adresbewerking en labelaanmaak.
  Exact-import probeert het afleveradres en daarna het klantaccount; als beide geen geldig
  e-mailadres geven, kan de magazijnmedewerker het alsnog invullen. De koppen Zending,
  Afleveradres en Eenheden staan op brede schermen naast hun invoervelden. Referentie staat
  op dezelfde regel als Type en Vrachttype. Nog niet live getest.
- Laatste bevestigde applicatiecommit op GitHub en Cloud86: `e4a1dc2`. De documentatiecommit
  `0843e11` staat lokaal en op GitHub, maar hoefde als documentatiewijziging niet apart naar
  Cloud86 te worden gepubliceerd.

- Commit `cff3ecd` — **Add warehouse overview and editable GLS shipment details** — staat lokaal,
  op `origin/main` en is via Plesk op Cloud86 gepubliceerd. Plesk meldde voltooiing van pull en
  publicatie; `/health` gaf HTTP 200 in `mock`-modus en de nieuwe CSS is live bereikbaar.
- De SQL-migratie `20261029_shipping_details.sql` is in phpMyAdmin voor `gls_exact` als geslaagd
  bevestigd. De lokale PHP 8.3 smoke test controleert voorinvulling, adresopslag,
  Parcel/Freight-payload, mocklabels, HTML-weergave en vergrendeling. Een ingelogde live
  magazijnflow en echte GLS-aanroep zijn nog niet getest.
- De keuze van GLS-producten en services, met DirectDeliveryPlus als standaard,
  vraagt nog validatie van de actuele GLS-requestvelden.

- Commit `c9a1c9a` — **Import goods deliveries from Exact Online**.
- Deze versie voegt tokenrefresh, `POST /exact/sync` en de knop **Leveringen ophalen** toe.
- De commit staat lokaal en op `origin/main` in GitHub.
- Plesk heeft deze versie opgehaald; de eerste synchronisatie eindigde met HTTP 500.
- Commit `be97150` toont veilige Exact-fouten op het overzicht en probeert bij een Exact HTTP 400
  opnieuw zonder `DeliveryDate`-sortering. De eerste zichtbare API-fout meldde dat Exact een
  expliciete `$select` of `$top=1` vereist.
- De huidige, nog te publiceren reparatie vraagt daarom alleen de benodigde geldige velden op met
  `$select` en ondersteunt ook Exact-responses waarin `d` direct een lijst bevat.

### Eerdere commits

- `e52a1d1` — Exact OAuth-callback toestaan met `SameSite=Lax`;
- `f3023da` — eerste Cloud86 PHP-app.

## 11. Volgende stappen

Werk deze lijst in deze volgorde af:

1. Publiceer na akkoord de compacte onderhoudstekst, permanente lege rij **Doos 1** en de nieuwe
   Exact-contactnaamopbouw. Controleer daarna met een nieuw opgehaalde levering of Exact `Title`,
   `FirstName`, `MiddleName` en `LastName` levert zoals verwacht.
2. Voer zodra shelltoegang beschikbaar is op Cloud86 de PHP-syntaxiscontrole en
   `cloud86/tests/smoke.php` uit.
3. Voeg na akkoord een zoekveld boven de tabel toe voor minimaal leveringsnummer, ordernummer,
   ontvanger, straat, postcode en plaats; bepaal of Naam 2/3 en notities ook doorzoekbaar moeten zijn.
4. Beoordeel de nieuwe vormgeving ook op een kleiner scherm.
5. Test de volledige magazijnflow in `mock`-modus met een bestaande levering, inclusief het
   aanvullen van een ontbrekend e-mailadres en het opnieuw opslaan van dozen.
6. Controleer de uitgebreide Exact-mapping met een echte nieuwe levering: leveringsnummer,
   ordernummer, datum, ontvanger, adresregel 2/3, contactpersoon en e-mailadres. Bestaande lokaal
   opgeslagen leveringen worden bij synchronisatie bewust niet overschreven.
7. Bevestig de GLS-veldmapping voor
   DirectDeliveryPlus en de overige gewenste leveringsopties.
8. Pas de mapping defensief aan als de Exact-response andere velden gebruikt.
9. Vraag/controleer GLS-testgegevens: test-`CreateLabel`-URL, API-subscriptionkey, MyGLS-testlogin,
   eventueel klantnummer en de toegestane producten/services. Spreek ook het labeltype af: A6-PDF
   voor afdrukken via de browser of ZPL voor een geschikte thermische printer.
10. Leg vast of `ShippingDate` bij labelaanmaak wordt meegestuurd of dat iedere werkelijk verzonden
    unit later via `ConfirmLabel` wordt bevestigd. Maak daarna van de magazijnactie één duidelijke,
    idempotente stap **Zending opslaan en labels maken**; open het opgeslagen label voor afdrukken.
11. Voer een GLS-acceptatietest uit met een expliciete testlevering en controleer PDF/labelprinter.
12. Spreek af naar welk Exact-veld de track-en-tracecode wordt teruggeschreven en implementeer dit.
13. Activeer en test de GLS-webhook met een nieuw sterk Bearer-token.
14. Voeg pas daarna geplande automatische Exact-import toe.

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

