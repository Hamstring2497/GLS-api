# PietPakket: workflow en projectoverdracht

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
7. De app schrijft echte GLS-tracking terug naar `GoodsDeliveries.TrackingNumber` in Exact.
8. GLS-statusupdates kunnen via de Track & Trace-webhook binnenkomen.
9. Een medewerker kan een levering als **afgehaald** afronden; er wordt dan geen GLS-zending
   aangemaakt en Exact krijgt een herkenbaar intern afhaalnummer.

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
- **1 november 2026 is het vaste startpunt van PietPakket.** Iedere synchronisatie vraagt de
  `GoodsDeliveries` op die vanaf die datum in Exact zijn aangemaakt. `Created` is het moment waarop
  de goederenlevering/pakbon ontstaat; `DeliveryDate` blijft alleen de geplande leverdatum.
- Nieuwe pakbonnen van vandaag komen zo direct binnen. Eerdere pakbonnen zonder trackingnummer
  blijven als openstaande werkvoorraad beschikbaar; leveringen met tracking worden overgeslagen.
- De app volgt alle resultaatpagina's. `EXACT_SYNC_LIMIT` is alleen de paginagrootte en begrenst
  niet langer het totale aantal openstaande pakbonnen.
- Reeds lokaal aanwezige leveringen worden overgeslagen.
- Leveringen die in Exact al een `TrackingNumber` hebben worden overgeslagen.
- Het afleveradres wordt uit Exact opgehaald; bij ontbreken wordt het hoofdadres van de relatie
  geprobeerd.
- Via de eerste `GoodsDeliveryLine` wordt het echte Exact-verkoopordernummer opgehaald. De
  gekoppelde verkooporder levert waar beschikbaar de contactpersoon van **Besteld door**; de
  contactkaart levert e-mail en telefoon. Adresregel 2 en 3 worden uit het afleveradres gelezen en
  zo nodig aangevuld vanuit het hoofdadres van **Besteld door**.
- Ook leveringen met een onvolledig afleveradres worden lokaal opgeslagen. Het overzicht markeert
  de ontbrekende velden en blokkeert het GLS-label totdat een medewerker ze heeft aangevuld. Zo
  verdwijnt een in Exact afgedrukte pakbon niet stilzwijgend uit de PietPakket-werkvoorraad.
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
- Vóór iedere labelaanvraag wordt de levering atomair vergrendeld als `gls_processing`, zodat een
  dubbele klik of gelijktijdige aanvraag geen tweede GLS-zending kan maken.
- In `mock` ontstaan testcodes plus een duidelijk ongeldig A6-testlabel van 100 × 150 mm; er wordt
  geen echte GLS-zending gemaakt.
- In GLS-test/productiemodus wordt de officiële GLS API aangeroepen.
- Een onduidelijke timeout of serverrespons zet de levering op `gls_unknown`. Maak dan niet opnieuw
  aan: controleer eerst in GLS en laat alleen een beheerder de levering daarna vrijgeven.
- Base64-PDF-labels worden in `cloud86/storage/labels/` opgeslagen.
- Na een succesvolle echte GLS-labelaanvraag schrijft de app de GLS-track-en-tracecode naar
  `GoodsDeliveries.TrackingNumber` in Exact. Bij een samengevoegde zending wordt iedere
  onderliggende Exact-levering bijgewerkt. Meerdere pakketnummers worden uniek en komma-gescheiden
  opgeslagen, binnen de Exact-limiet van 100 tekens.
- Een storing bij dit terugschrijven maakt geen nieuwe GLS-zending: het label en trackingnummer
  blijven lokaal bewaard en de medewerker kan uitsluitend de Exact-update gecontroleerd herhalen.
- Mocktracking wordt nooit naar Exact geschreven, ook niet via de handmatige herhaalactie.
- Na status `labels_created` kunnen de dozen niet meer worden gewijzigd en kan niet nogmaals een
  labelaanvraag worden gedaan. Herafdrukken opent alleen de opgeslagen PDF.

### D. Afhalen zonder GLS

- In de balk **Zending** kan een medewerker **Afgehaald** aanvinken en de levering afronden.
- De app maakt geen GLS-aanvraag en geen label aan.
- Hoofdlevering en eventuele samengevoegde leveringen krijgen status `collected` en het stabiele
  interne nummer `AFHALEN-<leveringsnummer>`.
- Dit afhaalnummer wordt naar `GoodsDeliveries.TrackingNumber` in Exact geschreven. Bij een
  Exact-storing blijft de afhaalstatus lokaal bewaard en kan uitsluitend de Exact-update worden
  herhaald.

### E. Track & Trace

- GLS kan statusupdates sturen naar `POST /webhooks/gls` met een Bearer-token.
- Webhookberichten worden gededupliceerd en krijgen snel HTTP 200 terug, ook bij een GLS-retry.
- Trackingnummers en de laatste GLS-status worden lokaal opgeslagen.
- Terugschrijven naar Exact is geïmplementeerd voor echte GLS-labelresultaten; `ConfirmLabel` is nog
  niet afgebouwd.

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
| `POST /deliveries/{id}/shipping` | Parcel/Freight, referentie en GLS-product/services vóór labelaanmaak opslaan |
| `POST /deliveries/{id}/parcels` | gewichten opslaan |
| `POST /deliveries/{id}/collected` | levering zonder GLS als afgehaald afronden en Exact bijwerken |
| `POST /deliveries/{id}/labels` | GLS-labels maken |
| `POST /deliveries/{id}/tracking/exact` | opgeslagen echte GLS-tracking opnieuw naar Exact schrijven |
| `POST /deliveries/{id}/labels/release` | onzekere aanvraag na handmatige GLS-controle vrijgeven (beheerder) |
| `GET /deliveries/{id}/label` | opgeslagen label beschermd openen of opnieuw afdrukken |
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

Voor de per-zending gekozen GLS-producten en services staat de eenmalige migratie in
`cloud86/database/migrations/20261030_gls_products_services.sql`. Deze voegt `gls_product` en
`gls_options_json` toe. Bestaande leveringen krijgen DirectDeliveryPlus zonder aanvullende services.

## 7. Configuratie zonder geheimen

Gebruik `cloud86/.env.example` als volledige namenlijst. De productie-`.env` bevat onder meer:

- algemene appinstellingen: `APP_ENV`, `APP_URL`, `APP_TIMEZONE`, `APP_KEY`;
- database: `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`;
- GLS: modus, endpoints, optionele subscription key, MyGLS-login, klantnummer, labeltype en webhooktoken;
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

## 10. Actuele stand — 4 november 2026

- De zichtbare applicatienaam is gewijzigd van **ParcelPilot** naar **PietPakket**. Het bestaande
  `PP`-beeldmerk blijft ongewijzigd. In de balk **Zending** kan een nog open levering voortaan met
  een expliciet vinkje als **Afgehaald** worden afgerond. PietPakket maakt dan geen GLS-zending en
  geen label aan, zet de levering (en eventuele samengevoegde leveringen) op `collected`, gebruikt
  het herkenbare fictieve nummer `AFHALEN-<leveringsnummer>` en probeert dit nummer naar Exact te
  schrijven. Bij een Exact-fout blijft de afhaalstatus bewaard en is **Opnieuw naar Exact**
  beschikbaar. Geen databasemigratie nodig. Applicatiecommit `5890f1d` staat lokaal, op GitHub en
  via Plesk op Cloud86. In de ingelogde productie-app zijn de naam **PietPakket** en de afhaaloptie
  zichtbaar; `/health` meldt `status: ok` en `gls_mode: live`. Er is bij deze controle geen levering
  als afgehaald gemarkeerd en niets naar Exact of GLS verstuurd.

- Adrescorrecties met een uit Exact geïmporteerde ontvangernaam van meer dan 30 tekens liepen nog
  vast op HTTP 422, ook als de medewerker het ontbrekende huisnummer en de straat correct had
  ingevuld. PietPakket begrenst iedere naamregel voortaan afzonderlijk tot maximaal 30 tekens,
  bij voorkeur op een woordgrens. Tekst uit **Naam** wordt niet naar **Naam 2** of **Naam 3**
  doorgeschoven. Dit gebeurt zowel bij nieuwe Exact-imports als bij het opslaan van bestaande
  adressen, zodat een vooraf ingevulde te lange waarde een andere adrescorrectie niet meer kan
  blokkeren. De formuliervelden tonen hun maximale lengte. Geen databasemigratie nodig.

- Exact-leveringen met een ontbrekend adresdeel worden niet meer volledig overgeslagen. Aanleiding
  waren leveringen `9080478` (Heijmans) en `9080477` (IBK): Exact leverde voor beide geen apart
  huisnummer, waardoor de oude importvalidatie ze weigerde. PietPakket importeert zulke regels nu
  wel en toont **Ontbreekt: huisnummer**; labelaanmaak blijft geblokkeerd totdat het veld is
  aangevuld. Tijdens **Leveringen ophalen** verschijnt bovendien direct een schermvullende melding
  met spinner en wordt de knop uitgeschakeld. Geen databasemigratie nodig.

- De Exact-import selecteert voortaan op `GoodsDeliveries.Created`, vanaf 1 november 2026 tot en met
  vandaag in `APP_TIMEZONE`. Nieuwe pakbonnen van vandaag worden opgehaald; eerdere pakbonnen zonder
  trackingnummer blijven beschikbaar als achterstand. `DeliveryDate` wordt nog wel als leverdatum
  opgeslagen, maar bepaalt niet meer of een pakbon in de werkvoorraad komt. De API-paginering wordt
  volledig gevolgd, waardoor de vroegere grens van 50 geen leveringen meer kan verbergen. Geen
  databasemigratie nodig.

- De Exact-import heeft een harde begindatum van **1 november 2026**; de actuele dagselectie kan
  nooit vóór deze grens vallen. `bin/delete-untracked-deliveries.php` biedt een controlemodus en
  verwijdert met `--execute` uitsluitend de vooraf getoonde leveringen die nog steeds geen
  trackingnummer hebben; leveringen met tracking blijven altijd bewaard. Geen databasemigratie
  nodig. Applicatiecommit `4d9effe` staat lokaal, op GitHub en via Plesk op Cloud86. Op Cloud86
  zijn de PHP-syntaxcontrole en de uitgebreide smoketest geslaagd. De controlemodus toonde tien
  leveringen zonder trackingnummer (`9080432`, `9080442`, `9080440`, `9080436`, `9080443`,
  `9080439`, `9080438`, `9080435`, `9080437` en `9080441`). Na expliciete bevestiging zijn exact
  deze tien productierijen verwijderd. De aansluitende nacontrole meldt: **Geen leveringen zonder
  trackingnummer gevonden**. Leveringen met trackingnummer zijn niet verwijderd.

- De interne `shippingSystemName` voor GLS blijft bewust ongewijzigd, omdat de naamswijziging alleen
  de gebruikersinterface betreft.

- Echte numerieke GLS-trackingnummers zijn in zowel de leveringstabel als het detailblok klikbaar.
  De link opent de officiële Nederlandse GLS Track & Trace-pagina in een nieuw tabblad. Mockcodes
  en onverwachte waarden blijven bewust gewone tekst. De linkbediening onderschept de bestaande
  rijklik voor het openen van leveringsdetails niet. Geen databasemigratie nodig. Applicatiecommit
  `11e00ee` staat lokaal, op GitHub en via Plesk op Cloud86. De uitgebreide lokale regressietest
  slaagt met negen tests. Op Cloud86 zijn de PHP-syntaxcontrole, PHP-smoketest, live-gereedheidscheck
  en productie-logincontrole geslaagd zonder een GLS-zending te maken of Exact te wijzigen.
  In de ingelogde productie-app zijn links in tabel en detail bevestigd; een klik op pakketnummer
  `73220023887075` opende een apart GLS Track & Trace-tabblad met precies dat pakketnummer.
  `/health` meldt na publicatie `status: ok` en `gls_mode: live`.

- De detailvolgorde in de magazijnwerkplek is aangepast naar **Afleveradres → Zending → Dozen en
  gewicht**. Het zendingblok staat daardoor direct boven de dozen. Gewicht wordt voortaan in hele
  kilo's ingevoerd en getoond; de browser én server weigeren decimalen en blijven de bestaande
  maximumgewichten per vrachttype bewaken. Na succesvolle labelaanmaak krijgt de levering in tabel
  en details een groene markering met **Zending klaar ✓**. Geen databasemigratie nodig. Deze
  applicatiecommit `386f30b` staat lokaal, op GitHub en via Plesk op Cloud86. De bijbehorende
  smoketest is in `486e450` aangepast aan de bewust verwijderde extra productiebevestigingen;
  de verborgen verzenddatum blijft expliciet getest. Er was geen databasemigratie nodig.

- Contactpersonen uit Exact gebruiken voor de zichtbare aanspreektitel nu
  `TitleAbbreviation` (bijvoorbeeld `dhr.`) en nooit meer de interne `Title`-code. Als Exact geen
  titelafkorting levert, gebruikt de app voornaam + tussenvoegsel + achternaam. Geen migratie nodig;
  dit geldt voor leveringen die na publicatie opnieuw uit Exact worden opgehaald.

- Werkvoorraadoverzicht verbeterd: de server en tabel sorteren standaard op leverdatum met de
  nieuwste datum bovenaan. Bij openen selecteert de app de vroegst toegevoegde levering zonder
  trackingnummer. Order, datum, toegevoegd, ontvanger, straat en postcode zijn via de kolomkoppen
  oplopend/aflopend sorteerbaar. Bovenaan staan zoeken en **Reset** naast de twee hoofdacties;
  Reset herstelt het ongefilterde overzicht met datum aflopend. Meldingen met **Gezien** staan
  voortaan pas onder de volledige werkplek.
- Labelbediening vereenvoudigd: **Label maken en afdrukken** maakt de zending zonder extra
  bevestigingsvensters, opent direct de opgeslagen officiële GLS-PDF en probeert het afdrukvenster
  te starten. Een bestaand label gebruikt **Label openen en afdrukken** en doet geen nieuwe
  GLS-aanvraag. De bestaande serververgrendeling tegen dubbele of onzekere aanvragen blijft actief.
- Samenvoegen gebruikt per toegevoegde order uitsluitend de laatste vier cijfers, gescheiden door
  ` / `. De maximale gezamenlijke GLS-referentie is 20 tekens. De invoer en servervalidatie volgen
  nu ook de GLS-schemawaarden: naamregels 30, straat 40, huisnummer en toevoeging 5, postcode 8,
  plaats/contact 30, landcode 2, telefoon 25, e-mail 320 en notities 30 tekens. Geen migratie nodig.

- Eerste echte labelcontrole: de gebruiker heeft een productielabel gemaakt. De ingelogde app
  meldt een geslaagde GLS-aanvraag en tracking naar één Exact-levering geschreven; er is bij deze
  controle geen nieuwe GLS-aanvraag of Exact-mutatie uitgevoerd. De opgeslagen officiële PDF is
  ongewijzigd, exact 100 × 150 mm en bevat de onderste referentie- en trackingregel volledig.
  Beide Data Matrix-codes en de onderste ITF-streepjescode zijn lokaal uit zowel de PDF als de
  aangeleverde afdrukafbeelding gedecodeerd. Pakketnummer, ontvangeradres en referentie komen
  overeen met de zichtbare appgegevens. Dit is een leesbaarheidscontrole, geen formele
  barcodekwaliteitscertificering of bevestiging van GLS-contract/services.
  Het lege routevak linksboven is ook leeg in de originele GLS-PDF; de betekenis moet zo nodig
  door GLS worden bevestigd. De gebruiker bevestigt dat de onderste regel ook op het fysieke
  etiket is afgesneden: printerformaat, schaal en afdrukpositie controleren; geen eigen
  GLS-labelindeling maken. De originele PDF is volledig.
  Klantgegevens, barcode-inhoud en de originele PDF zijn niet in Git opgenomen. Applicatiecode
  blijft `28ad6a2` (laatst bevestigd op Cloud86); voorgaande documentatiecommit is `732f009`.

- Live-activatie 3 november 2026: applicatiecommits `95efab7` en `28ad6a2` staan lokaal,
  op GitHub en op Cloud86. De serverhash van `src/GlsService.php` komt overeen met de lokale
  versie. Alle PHP-bestanden slagen voor de syntaxcontrole en de uitgebreide PHP-smoketest is
  geslaagd; negen Python-regressietests slagen lokaal. De Cloud86-`.env` gebruikt nu `GLS_MODE=live`,
  de officiële productie-endpoints en `GLS_SEND_SHIPPING_DATE=true`; `pdfa6s` is behouden.
  De oude configuratie is uitsluitend op de server afgeschermd bewaard als
  `.env.before-live-20261103`. De echte GLS-productielogin is opnieuw bevestigd zonder labelaanmaak.
  De gereedheidscontrole bevestigt labelopslag, benodigde databasekolommen en de opgeslagen
  Exact-koppeling. `/health` geeft `status: ok` en `gls_mode: live`.
  Er is geen nieuwe GLS-zending aangemaakt en geen tracking naar Exact geschreven tijdens deze
  voorbereiding. De gebruiker voert nu zelf één echte Parcel/DirectDeliveryPlus-levering uit.
  De officiële PDF, printerafdruk en daadwerkelijke Exact-writeback moeten daarbij nog worden
  geaccepteerd. De huidige werkvoorraad bevat al gekoppelde bestaande GLS-zendingen: gebruik voor
  de proef een nieuw, nog niet elders verzonden Exact-document.

- Voorbereiding eerste echte verzending: de magazijnflow gebruikt bij ingeschakelde
  `GLS_SEND_SHIPPING_DATE` de datum van labelaanmaak in `APP_TIMEZONE`, niet de historische
  Exact-leverdatum. De datum staat naast de labelknop en in de productiebevestiging; een formulier
  dat over middernacht openblijft moet opnieuw geladen worden. GLS beschrijft in de aangeleverde
  Shipping API-handleiding §4.2.4 dat deze datum de labelgegevens vrijgeeft aan het TMS.
  `ConfirmLabel` is daarom niet nodig voor deze werkstroom van verzenden op de dag van labelaanmaak.
  Live blokkeert zonder verzenddatum, een passend officieel productie-endpoint, inloggegevens,
  `pdfa6s` en schrijfbare labelopslag. Afwijkende aantallen dozen of een ontbrekende PDF krijgen
  een onzekere uitkomst; er volgt geen automatische herhaling. GLS-testresultaten gaan niet naar
  Exact, ook niet als later live wordt ingeschakeld. Geen databasemigratie nodig.
  Het nieuwe `bin/check-live-readiness.php --validate-login` controleert configuratie, aanwezige
  databasekolommen, opgeslagen Exact-koppeling en optioneel uitsluitend GLS-authenticatie. Het
  maakt geen zending aan en bewijst nog geen succesvolle echte labelaanmaak of Exact-writeback.
  Publicatie en productiecontrole van deze wijziging staan in de actuele live-activatie hierboven.

- 3 november 2026, applicatiecommit `3dbe598` lokaal, op GitHub en via Plesk op Cloud86
  gepubliceerd: na een succesvolle echte GLS-labelaanvraag schrijft
  de PHP-app de tracking automatisch terug naar `GoodsDeliveries.TrackingNumber` in Exact. Een
  samengevoegde zending werkt alle onderliggende Exact-leveringen bij; meerdere pakketnummers worden
  uniek en komma-gescheiden opgeslagen. Iedere geslaagde Exact-update krijgt lokaal een tijdstempel,
  zodat de herhaalactie idempotent alleen de nog niet bevestigde leveringen verwerkt. Bij een
  Exact-storing blijven GLS-label en tracking veilig opgeslagen en verschijnt **Opnieuw naar Exact**;
  er wordt nadrukkelijk geen tweede GLS-zending gemaakt. Mockresultaten en `MOCK-`-codes zijn zowel
  in de service als de interface geblokkeerd. De al aanwezige kolom
  `exact_tracking_synced_at` wordt gebruikt; er is geen nieuwe databasemigratie nodig. De negen
  Python-regressietests zijn lokaal geslaagd. Op Cloud86 zijn alle PHP-bestanden zonder syntaxfout
  gecontroleerd en is de PHP-smoketest geslaagd. De live healthcheck geeft `status: ok` en
  `gls_mode: mock`; er is bij deze publicatie geen echte GLS- of Exact-mutatie uitgevoerd.

- 3 november 2026, applicatiecommits `fcd084f` en `82f6d5d` lokaal, op GitHub en Cloud86: via de
  nieuwe trackingimport zijn tien eerder rechtstreeks in GLS Print & Ship aangemaakte leveringen
  gekoppeld aan zeven bestaande GLS-zendingen. De import omvat één samengevoegde zending met drie
  Exact-leveringen, één GLS-zending die bij twee identieke Exact-leveringen hoort en één zending met
  twee pakketnummers. De twee leveringen bij order `40065243` zijn bewust niet gekoppeld, omdat GLS
  Track & Trace voor die referentie geen zending vond. De productie-healthcheck is groen,
  `GLS_MODE=mock` is behouden en de PHP-syntax- en smoketest zijn op Cloud86 geslaagd.

- 3 november 2026: bestaande zendingen die eerder rechtstreeks in GLS Print & Ship zijn gemaakt,
  kunnen door een beheerder via **Onderhoud → Bestaande GLS-zending koppelen** aan één of meer
  Exact-leveringen worden gekoppeld. Eén koppeling ondersteunt zowel samengevoegde orders met één
  trackingnummer als een zending met meerdere pakketnummers. De levering krijgt status
  `labels_created`, zodat de werkplek niet per ongeluk nogmaals een GLS-zending maakt. Er wordt geen
  lokaal labelbestand verzonnen: alleen de bestaande trackingnummers en de herkomst GLS Track & Trace
  worden opgeslagen. De import is atomair, controleert bestaande afwijkende tracking en weigert
  leveringen met een onzekere of lopende GLS-aanvraag. Hiervoor is geen databasemigratie nodig.

- 3 november 2026: de ingeklapte keuze **Aanvullende producten** gebruikt nu dezelfde veldopbouw
  als **Type**, **Vrachttype** en **Referentie**: een los label boven een invoervak van dezelfde
  hoogte, rand, tekstgrootte en focusmarkering. DirectDeliveryPlus blijft de standaard. Het mocklabel
  blijft nadrukkelijk ongeldig; alleen een ongewijzigd PDF-label uit de officiële GLS Label API kan
  als geautoriseerd verzendlabel worden gebruikt. Hiervoor is geen databasemigratie nodig.

- 3 november 2026: het mocklabel is opnieuw ontworpen als herkenbaar GLS-achtig verzendetiket op
  exact 100 × 150 mm. Iedere doos krijgt een eigen A6-pagina met een zwart-witte vakindeling,
  sorteercodes, afleveradres, referentie, doosnummer, gewicht, mocktrackingnummer, streepjescode en
  twee 2D-codes. Lange adressen en samengestelde orderreferenties worden automatisch verkleind zodat
  ze binnen hun vak blijven. De machinecodes zijn bewust niet scanbare visuele testcodes en het
  label vermeldt prominent **GLS TEST**, **ONGELDIG - NIET VERZENDEN** en
  **TESTLABEL - GEEN GELDIGE GLS-ZENDING**. Er is geen echte GLS-aanvraag nodig en er is geen
  databasemigratie. De officiële test- en productielabels blijven ongewijzigd de PDF uit de
  GLS-respons. In het zendingformulier staat **Aanvullende producten** nu ingeklapt tussen
  **Vrachttype** en **Referentie**; de samenvatting toont het gekozen product en
  DirectDeliveryPlus blijft de standaard.

- 2 november 2026, applicatiecommits `59accd9` en `8c7be51` lokaal, op GitHub en Cloud86: de
  labelworkflow is voorbereid op een gecontroleerde
  productieacceptatie. Een labelaanvraag vergrendelt de hoofdlevering en eventuele samengevoegde
  leveringen vóór de GLS-call. Een duidelijke afwijzing geeft de levering weer vrij; een timeout,
  HTTP 408/409/425/429, serverfout of ongeldige respons krijgt status `gls_unknown` en mag pas na
  controle in GLS door een beheerder worden vrijgegeven. Test- en productieaanvragen vragen een
  expliciete browserbevestiging; productie toont een extra waarschuwing dat een betaalde zending kan
  ontstaan. Mockmodus maakt nu een duidelijk ongeldig A6-PDF-testlabel van 100 × 150 mm. Het
  opgeslagen PDF-bestand kan worden geopend en opnieuw afgedrukt zonder een nieuwe `CreateLabel`-
  aanvraag. Hiervoor is geen databasemigratie nodig: de bestaande status-, request-, response-,
  label- en laatste-statusvelden worden gebruikt. De negen Python-referentietests en
  `git diff --check` slagen. Op Cloud86 zijn alle PHP-bestanden zonder syntaxfouten gecontroleerd en
  de uitgebreide PHP-smoketest is geslaagd. Die controle bracht eerst een foutieve aanhaling in het
  testschema aan het licht; dit is apart hersteld in `8c7be51` en daarna opnieuw groen getest. De
  publieke healthcheck geeft HTTP 200 met `status: ok` en `gls_mode: mock`. Er is geen echte
  GLS-aanvraag gedaan en er was geen databasemigratie nodig.
- 2 november 2026, gepubliceerd tot en met commit `2e71c2b`: GLS heeft een API-gebruikersnaam, wachtwoord en
  afzonderlijke operationele klantnummers voor Parcel en Freight verstrekt. De werkelijke waarden
  staan bewust niet in Git of dit document. De payload kiest voortaan automatisch
  `GLS_PARCEL_CUSTOMER_NO` bij Parcel en `GLS_FREIGHT_CUSTOMER_NO` bij Freight, met het bestaande
  `GLS_CUSTOMER_NO` alleen als achterwaarts compatibele terugval. De openbare test-`ValidateLogin`
  is op 2 november met uitsluitend fictieve gegevens en zonder subscription key aangeroepen. GLS
  antwoordde met HTTP 401 en fout `A005` voor de fictieve gebruiker; daarmee is bevestigd dat de
  aanvraag de gebruikersauthenticatie bereikt zonder API-key. De key is daarom optioneel gemaakt.
  De geheime waarden staan uitsluitend in de productie-`.env`; die staat correct naast `bin`,
  `src`, `public` en de overige applicatiemappen. De echte login is via `ValidateLogin` zonder
  labelaanmaak gecontroleerd: de GLS-testomgeving weigerde deze met HTTP 401/A005, terwijl de
  productie-`ValidateLogin` slaagde. De ontvangen login is dus voor productie bedoeld. De app is
  daarna bewust teruggezet naar `GLS_MODE=mock`; de healthcheck geeft HTTP 200 met `status: ok` en
  `gls_mode: mock`. Er is nog geen echte GLS-zending aangemaakt.
- 2 november 2026, commit `ad2e6e6` lokaal, op GitHub en Cloud86: de vier
  teksten rechts in de bovenbalk — **BETEC Magazijn**, **Leveringen**, **Onderhoud** en **Uitloggen** —
  gebruiken nu hetzelfde compacte letterformaat, gewicht en dezelfde regelhoogte. De merknaam
  **Exact → GLS** links blijft bewust groter.
- 2 november 2026, commit `3669a3c` lokaal, op GitHub en Cloud86: in de sectie **Zending** staan
  de Print & Ship-keuzes voor DirectDeliveryPlus, DirectDelivery, LetterboxDelivery,
  PointDelivery en ExpressDelivery, plus Saturday, AddresseeOnly, PointDeliveryFlex en
  PointReturn. DirectDeliveryPlus is de standaard. PointDelivery vraagt het verplichte GLS
  Point-ID; ExpressDelivery vraagt een geldige GLS-tijd. De keuzes worden per levering opgeslagen
  en naar de actuele Label API v1-velden vertaald (`economyParcel`, `letterBoxParcel`,
  `shopDeliveryService`, `expressService`, `saturdayService`, `addresseeOnlyService`,
  `shopDeliveryPlusService` en `shopReturnService`). De opties zijn voor Freight uitgeschakeld.
  De migratie `cloud86/database/migrations/20261030_gls_products_services.sql` is volgens de
  gebruiker uitgevoerd. Contractbeschikbaarheid moet nog met de echte GLS-testlogin worden
  gecontroleerd.
- 2 november 2026, commits `0bf1958` en `86fc8e3` lokaal, op GitHub en Cloud86: ontbrekende verplichte gegevens worden
  nu per levering concreet genoemd en de bijbehorende invoervelden krijgen een amberkleurige
  markering. De tabel toont pas **Klaar voor labels** als adres, geldig e-mailadres, referentie en
  minimaal één doos met gewicht compleet zijn. Leveringen op hetzelfde genormaliseerde afleveradres
  kunnen aan één hoofdlevering worden gekoppeld. De gekoppelde levering mag geen eigen dozen hebben;
  alle gezamenlijke dozen worden bij de hoofdlevering ingevoerd. De GLS-referentie bestaat uit de
  bestaande referentie plus de volledige ordernummers van de toegevoegde leveringen (maximaal 64
  tekens). Na labelaanmaak krijgen hoofd- en gekoppelde leveringen dezelfde tracking-, response- en
  labelstatus. De migratie `cloud86/database/migrations/20261030_delivery_merges.sql` is volgens
  de gebruiker uitgevoerd.
- 2 november 2026, commit `86fc8e3` lokaal, op GitHub en Cloud86: een nog niet gekoppelde levering met
  hetzelfde afleveradres, status `new` en zonder eigen dozen wordt in de tabel lichtgeel gemarkeerd
  met **Samenvoegen?**. Een klik vraagt om bevestiging. **OK** koppelt de levering direct aan de
  geselecteerde hoofdlevering; **Annuleren** opent haar afzonderlijk. Bestaande gekoppelde
  leveringen blijven bij de snelle actie behouden.

### Bevestigd werkend op Cloud86

- HTTPS, correcte document root, PHP en MySQL;
- beveiligde login met het algemene magazijnaccount;
- Exact OAuth-koppeling met administratie `2875157`;
- handmatige testlevering;
- twee dozen invoeren en mocklabels maken;
- dubbele labelaanvraag blokkeren;
- Plesk Git-deployment vanuit de private GitHub-repository.
- publicatie van commit `2e71c2b` en een live healthcheck in GLS-testmodus;
- live stylesheet met de GLS-product/servicekeuzes en de gelijkgetrokken navigatieteksten.

### Laatste applicatieversie

- 2 november 2026, GLS-voorbereiding gepubliceerd via commits `fe8ec86`, `24fdc11` en `2e71c2b`:
  de PHP-payload is gecorrigeerd
  naar het actuele GLS Label API v1-schema. Requestvelden gebruiken nu exact lower camelCase,
  waaronder `username`, `shiptype`, `units`, `weight`, `addresses` en `deliveryAddress`.
  Interne Parcel/Freight-codes worden bij verzending omgezet naar de kleine GLS-codes `p`/`f`.
  Unittypes zoals `co` worden uitsluitend voor Freight meegestuurd. Huisnummer en toevoeging gaan apart als `houseNo` en `houseNoExt`; zakelijke
  Exact-adressen gebruiken `addresseeType=b`. De officiële testendpoints voor `ValidateLogin`,
  `CreateLabel` en `ConfirmLabel` staan zonder geheimen in `.env.example`. Het nieuwe CLI-script
  `cloud86/bin/test-gls-connection.php` valideert de GLS-testlogin zonder een zending aan te maken.
  `GLS_SEND_SHIPPING_DATE=false` is toegevoegd als veilige standaard, zodat pas na een expliciete
  keuze zendingen direct met een verzenddatum aan GLS worden vrijgegeven. De negen
  Python-referentietests en `git diff --check` zijn geslaagd; lokaal ontbreekt nog steeds een
  PHP-runtime. De echte login is geldig op de GLS-productieomgeving en niet op de testomgeving.
  `GLS_MODE=mock` is daarom weer actief totdat de productie-acceptatie expliciet is afgesproken;
  er is nog geen echte GLS-zending aangemaakt.
- 30 oktober 2026, applicatiecommit `c22b0c6` lokaal, op GitHub en via Plesk op Cloud86
  gepubliceerd: de teksten in het menu **Onderhoud** zijn compacter.
  Bij een levering zonder opgeslagen dozen blijft altijd een lege rij **Doos 1** zichtbaar. Het
  gewicht is niet HTML-verplicht; een lege rij wordt door de bestaande serverfilter niet als doos
  opgeslagen en laat de status op `new`. De Exact-contactnaam wordt bij voorkeur opgebouwd als
  `Titel + Tussenvoegsel + Achternaam`, of zonder titel als
  `Voornaam + Tussenvoegsel + Achternaam`; alleen als die onderdelen ontbreken wordt `FullName`
  gebruikt. Er is geen databasemigratie nodig. `git diff --check` en de negen
  Python-referentietests zijn geslaagd. De PHP-smoketest bevat nieuwe controles voor de lege doos
  en beide naamvarianten, maar kan lokaal niet worden uitgevoerd omdat PHP ontbreekt. Plesk
  bevestigde pull en publicatie als voltooid. Live zijn de compactere onderhoudsteksten en de lege,
  niet-verplichte rij **Doos 1** gecontroleerd. `/health` gaf `status: ok` en `gls_mode: mock`.
  De nieuwe naamopbouw geldt bij een volgende Exact-import; bestaande lokale leveringen worden
  bewust niet overschreven.
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
- De GLS-product- en servicevelden zijn tegen de actuele openbare Label API v1-OpenAPI
  gecontroleerd. De beschikbaarheid van iedere combinatie blijft contract-, bestemming- en
  verzenddatumafhankelijk en moet daarom in de GLS-testomgeving worden geaccepteerd.

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

- Test de gepubliceerde afhaalroute bij de eerstvolgende echte afhaallevering. Controleer dat geen
  GLS-aanvraag of label ontstaat, de status **Afgehaald** wordt, Exact het nummer
  `AFHALEN-<leveringsnummer>` ontvangt en een mislukte Exact-update veilig opnieuw kan worden
  aangeboden.

- Publiceer de naamregelcorrectie en open levering `9080478` (Heijmans). Sla het gecorrigeerde adres
  met `Laan van Westenenk` en huisnummer `490` opnieuw op. Controleer dat geen HTTP 422 meer volgt,
  dat iedere GLS-naamregel binnen zijn eigen invoerveld blijft en zo nodig op maximaal 30 tekens
  wordt ingekort (zonder doorschuiven naar **Naam 2** of **Naam 3**), en dat het adres daarna
  gereed kan worden gemaakt met minimaal één doos en gewicht. Maak bij deze controle nog geen
  tweede GLS-zending als al een aanvraag is gestart.

- Controleer na iedere toekomstige wijziging aan de Exact-import dat 1 november 2026 de vaste
  ondergrens blijft. Gebruik het opschoonscript altijd eerst zonder `--execute` en vergelijk de
  getoonde leveringnummers voordat de uitvoeroptie wordt gebruikt. De eenmalige productieopschoning
  van 3 november 2026 is voltooid en hoeft niet te worden herhaald.

- Test ingelogd één nog niet verzonden levering en één reeds gemaakte zending zonder een nieuwe
  GLS-aanvraag te doen. Controleer daarbij de nieuwe detailvolgorde, hele-kilo-invoer en groene
  gereedmarkering.
- Test ingelogd zoeken, alle zes sorteerkoppen, **Reset**, de standaardselectie en
  één samenvoeging met een referentie als `5256 / 5261`. Controleer daarna met een reeds gemaakt
  label dat **Label openen en afdrukken** geen nieuwe GLS-zending maakt. Maak pas daarna één nieuw
  label en controleer dat het PDF-venster en het afdrukscherm direct openen; sta pop-ups voor het
  domein toe als de browser dit blokkeert.
- Eerste echte label is gecontroleerd: de drie codes zijn leesbaar en de oorspronkelijke PDF is
  volledig. De onderste regel valt volgens de gebruiker ook op het fysieke etiket weg. Controleer
  printerpapier 100 × 150 mm, schaal/afdrukpositie en labelkalibratie. Herdruk uitsluitend de
  bestaande PDF. Vraag GLS zo nodig naar het lege routevak linksboven en verifieer de ontvangen
  productieaanmelding/productkeuze; leid dit niet uitsluitend af uit scanbare barcodes.
- Controleer het trackingveld desgewenst rechtstreeks in Exact: de app meldt dat de update is
  geslaagd, maar het Exact-scherm is bij de labelcontrole niet opnieuw geopend.

- De webapp is na geslaagde servercontroles en GLS-authenticatie op `GLS_MODE=live` gezet voor de
  door de gebruiker gevraagde eerste echte levering. De gebruiker maakt die levering zelf.
  Gebruik standaard Parcel/DirectDeliveryPlus en alleen dozen die op de getoonde verzenddatum meegaan.
- Bij die eerste levering: officiële GLS-PDF, aantal labels, 100 × 150 mm afdruk en
  `GoodsDeliveries.TrackingNumber` in Exact controleren. Bij een onzekere aanvraag nooit opnieuw
  aanmaken. Alleen dezelfde opgeslagen PDF opnieuw openen/afdrukken.

1. Publiceer en test ingelogd in mockmodus met één bestaande levering dat het vernieuwde testlabel
   per doos een afzonderlijke A6-pagina bevat, wordt opgeslagen, geopend en opnieuw afgedrukt zonder
   een tweede labelaanvraag. Druk één pagina op de Zebra GK420d af en controleer de schaal op
   100 × 150 mm.
2. Vraag GLS om bevestiging dat een gecontroleerde productie-acceptatietest mag worden uitgevoerd
   en welke product/servicecombinaties voor klantnummer Parcel zijn geactiveerd. De productielogin
   is reeds veilig met `ValidateLogin` bevestigd; de testomgeving accepteert deze login niet.
3. Controleer met een nieuw opgehaalde levering of Exact `TitleAbbreviation`,
   `FirstName`, `MiddleName` en `LastName` levert zoals verwacht en de interne `Title`-code niet
   meer in de contactpersoon verschijnt.
4. Beoordeel de nieuwe zoek-, sorteer- en afdrukbediening ook op een kleiner scherm.
6. Test de volledige magazijnflow in `mock`-modus met een bestaande levering, inclusief het
   aanvullen van een ontbrekend e-mailadres en het opnieuw opslaan van dozen.
7. Controleer de uitgebreide Exact-mapping met een echte nieuwe levering: leveringsnummer,
   ordernummer, datum, ontvanger, adresregel 2/3, contactpersoon en e-mailadres. Bestaande lokaal
   opgeslagen leveringen worden bij synchronisatie bewust niet overschreven.
8. Test de product- en servicekeuzes eerst zonder echte verzending. Test daarna met GLS per
   gecontracteerde combinatie minimaal de standaard,
   Saturday, AddresseeOnly, PointDelivery met Point-ID en ExpressDelivery.
9. Pas de mapping defensief aan als de Exact-response andere velden gebruikt.
10. De ontvangen API-login is door de gecontroleerde `ValidateLogin` als productie-login bevestigd.
   Bevestig nog welke producten/services zijn toegestaan. De Nederlandse test-Label API accepteert technisch
   aanvragen zonder subscription key; vul een key alleen in als GLS die expliciet verstrekt.
   A6-PDF (`pdfa6s`) is gekozen voor de Zebra-printer. Gebruikersnaam, wachtwoord en beide
   klantnummers staan uitsluitend in de Cloud86-`.env`; `GLS_MODE=mock` is live actief. Schakel
   nog niet naar `live` voordat de productie-acceptatietest expliciet is afgesproken.
11. Voor de eerste livewerkstroom wordt `ShippingDate` bij labelaanmaak meegestuurd
    (`GLS_SEND_SHIPPING_DATE=true`, vandaag). Labelaanmaak betekent dus verzenden op die datum.
    Een aparte latere `ConfirmLabel`-werkstroom is niet geïmplementeerd. Maak van de magazijnactie één duidelijke,
    idempotente stap **Zending opslaan en labels maken**; open het opgeslagen label voor afdrukken.
12. Voer een GLS-acceptatietest uit met een expliciete testlevering en controleer PDF/labelprinter.
13. Controleer tijdens de eerste afgesproken echte GLS-acceptatietest dat
    `GoodsDeliveries.TrackingNumber` in Exact direct na labelaanmaak is gevuld. Simuleer daarna één
    mislukte Exact-update en controleer dat **Opnieuw naar Exact** werkt zonder een tweede
    GLS-zending te maken.
14. Activeer en test de GLS-webhook met een nieuw sterk Bearer-token.
15. Voeg pas daarna geplande automatische Exact-import toe.

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

