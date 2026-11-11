# Exact GLS Werkplek voor Cloud86

Zelfstandige PHP 8/MySQL-webapp voor het verwerken van Exact-leveringen en het maken van GLS-labels.
Deze map is bedoeld voor het Cloud86 WooCommerce Power-pakket en heeft geen Composer-packages nodig.

## Wat al werkt

- beveiligde magazijnlogin;
- handmatig toevoegen van een testlevering;
- één of meer eenheden in hele kilo's: Parcel maximaal 32 kg per doos en Freight volgens de limiet
  van het gekozen vrachttype;
- GLS `CreateLabel`-payload volgens de aangeleverde Shipping API;
- veilige mockmodus zonder echte GLS-zendingen;
- echte HTTPS-aanroep met de GLS-login en, wanneer verstrekt, een optionele subscription key;
- blokkeren van een tweede labelaanvraag voor dezelfde levering;
- Base64-PDF-labels opslaan buiten de publieke webmap;
- GLS Track & Trace-webhook met Bearer-token, deduplicatie en snelle HTTP 200-response;
- opslag van track-en-tracecodes en GLS-statussen in MySQL.
- Exact Online OAuth 2.0 met versleutelde tokens en veilige refresh-tokenrotatie;
- handmatig ophalen van recente, nog niet verzonden goederenleveringen uit Exact.
- spreadsheetachtig leveringsoverzicht, bewerkbaar afleveradres, Parcel/Freight-keuze en
  bewerkbare GLS-referentie (standaard de laatste vier cijfers van het ordernummer).
- concrete melding en markering van ontbrekende verplichte verzendgegevens;
- compacte zendingregel met het standaardproduct DirectDeliveryPlus; aanvullende GLS-producten en
  services zijn via een ingeklapt keuzepaneel te openen;
- meerdere nog niet verzonden Exact-leveringen op exact hetzelfde afleveradres samenvoegen tot
  één GLS-zending. De hoofdlevering bevat alle gezamenlijke dozen; de GLS-referentie wordt
  aangevuld met de ordernummers van de gekoppelde leveringen.
- echte GLS-track-en-tracecodes na labelaanmaak automatisch terugschrijven naar
  `GoodsDeliveries.TrackingNumber` in Exact, met een veilige herhaalactie die geen nieuwe
  GLS-zending maakt. Mocktracking wordt altijd geblokkeerd.

## Nog te activeren

- periodieke automatische import van verwerkte goederenleveringen; handmatig ophalen is beschikbaar;
- `ConfirmLabel` aan het einde van de verzendhandeling of dag;
- productiekeuring met de echte GLS-testomgeving.

De resterende GLS-onderdelen vereisen de GLS-testgegevens en een gecontroleerde acceptatietest.

## Lokale mapstructuur

```text
cloud86/
├── public/                 Document root van het subdomein
│   ├── index.php           Router en webinterface
│   ├── .htaccess           Doorsturen naar de router
│   └── assets/app.css
├── src/                    Applicatielogica; niet publiek bereikbaar
├── views/                  HTML-templates; niet publiek bereikbaar
├── database/schema.sql     MySQL-tabellen
├── storage/labels/         Afgeschermde PDF-labels
└── .env                    Wachtwoorden en API-sleutels; nooit committen
```

## Installeren op Cloud86

1. Maak in Plesk een subdomein aan, bijvoorbeeld `verzenden.voorbeeld.nl`.
2. Upload of clone deze `cloud86`-map buiten de bestaande WordPress-installatie.
3. Stel de document root van het subdomein in op `cloud86/public`.
4. Selecteer PHP 8.2 of nieuwer en activeer HTTPS.
5. Maak in Plesk een aparte MySQL-database en databasegebruiker aan.
6. Importeer `database/schema.sql` via phpMyAdmin.
   Voor een bestaande installatie: voer de nog niet toegepaste bestanden in `database/migrations/`
   in datumvolgorde eenmalig uit. Voor de samenvoegfunctie is vóór publicatie specifiek
   `database/migrations/20261030_delivery_merges.sql` nodig.
7. Kopieer `.env.example` naar `.env` en vul de databasevelden in.
8. Maak via Plesk Terminal het algemene gebruikersaccount aan. Het script genereert een willekeurig
   wachtwoord van precies 10 tekens en toont dit precies één keer:

   ```bash
   php bin/create-user.php magazijn "BETEC Magazijn" admin
   ```

9. Zet `APP_ENV=production`, `APP_URL=https://gls.beteccontrols.nl` en vul `APP_KEY` in met 64
   willekeurige hextekens. Genereer deze bijvoorbeeld met `php -r "echo bin2hex(random_bytes(32));"`.
10. Laat `GLS_MODE=mock` staan totdat de acceptatietest volledig is afgerond.
11. Controleer `https://gls.beteccontrols.nl/health`; dit moet JSON met `status: ok` geven.

## GitHub en Cloud86 Git

Gebruik een **privé GitHub-repository**. De applicatiecode mag in Git; `.env`, gebruikerswachtwoorden,
API-sleutels, tokens en gegenereerde labels nooit. De meegeleverde `.gitignore` sluit deze bestanden uit.

Eerste publicatie vanaf de ontwikkelcomputer:

```bash
git init
git add .
git commit -m "Eerste Exact GLS Cloud86-app"
git branch -M main
git remote add origin git@github.com:ORGANISATIE/NAAM-VAN-PRIVE-REPOSITORY.git
git push -u origin main
```

Voeg daarna in Plesk bij het subdomein via **Git** de GitHub-repository toe. Gebruik voor een privé
repository de door Plesk getoonde SSH public key als deploy key in GitHub, met alleen leesrechten.
Stel automatische deployment in naar de applicatiemap, maar niet naar een map waarin `.env` wordt
overschreven. Na een update:

```bash
git push origin main
```

Laat Plesk vervolgens de laatste `main`-versie ophalen/deployen. Databasewijzigingen worden bewust
niet automatisch uitgevoerd; importeer een nieuwe migratie eerst gecontroleerd.

## Exact Online-appregistratie

Registreer in Exact Online/App Center een private webapp voor de eigen administratie:

- **Naam:** `BETEC GLS Werkplek`
- **Applicatie-URL:** `https://gls.beteccontrols.nl`
- **Redirect URI:** `https://gls.beteccontrols.nl/oauth/exact/callback`
- **Doel:** verwerkte goederenleveringen ophalen, verzendgegevens gebruiken voor GLS en
  track-en-tracecodes terugschrijven.
- **Scopes/rechten:** alleen de minimaal aangeboden scopes voor verkooporders/goederenleveringen,
  afleveradressen en het bijwerken van leveringen. De exacte namen worden door het actuele
  registratiescherm bepaald.

Exact geeft daarna een Client ID, Client Secret en eventueel Webhook Secret. Plaats deze uitsluitend in
`.env`. Deel het Client Secret niet via e-mail, GitHub of screenshots. De app gebruikt de OAuth 2.0
authorization-code flow; een Exact-gebruiker met toegang tot de administratie geeft eenmalig toestemming.

Relevante `.env`-waarden:

```dotenv
APP_ENV=production
APP_URL=https://gls.beteccontrols.nl
APP_TIMEZONE=Europe/Amsterdam
APP_KEY=<64 willekeurige hextekens>

DB_HOST=<server uit Plesk>
DB_PORT=3306
DB_DATABASE=<databasenaam uit Plesk>
DB_USERNAME=<databasegebruiker uit Plesk>
DB_PASSWORD=<uniek sterk databasewachtwoord>

EXACT_BASE_URL=https://start.exactonline.nl
EXACT_CLIENT_ID=<client-id uit Exact>
EXACT_CLIENT_SECRET=<client-secret uit Exact>
EXACT_REDIRECT_URI=https://gls.beteccontrols.nl/oauth/exact/callback
EXACT_DIVISION=
EXACT_WEBHOOK_SECRET=<webhook-secret uit Exact, indien gebruikt>
```

`EXACT_DIVISION` hoeft niet te worden gegokt. Na de eerste OAuth-verbinding vraagt de app deze op via
Exact `current/Me` en bewaart hem versleuteld bij de OAuth-koppeling. Het `.env`-veld blijft voorlopig
leeg en is alleen beschikbaar als expliciete noodconfiguratie.

De mappen `src`, `views`, `database` en `storage` horen niet onder een zelfstandig publiek domein te
vallen. De document root moet exact op `public` staan. Kan dat niet in Plesk, plaats dan de applicatie
buiten `httpdocs` en laat Cloud86 de veilige document root instellen.

## Testprocedure

Voer lokaal `php -d zend.assertions=1 -d assert.exception=1 tests/smoke.php` uit voor de
opslag-, payload- en weergavetest. Deze test gebruikt een tijdelijke SQLite-database en
mocklabels; hij maakt geen zending bij GLS en raakt de productie-MySQL-database niet.

1. Log in.
2. Voeg een levering toe met een fictief adres.
3. Voeg twee dozen toe, bijvoorbeeld 4,5 en 7,2 kg.
4. Klik op **Testlabel maken en afdrukken**.
5. Controleer dat er twee `MOCK-...` codes ontstaan.
6. Open de opgeslagen A6-PDF en controleer dat iedere doos een eigen pagina heeft met afleveradres,
   referentie, doosnummer, gewicht, testtrackingnummer en een duidelijke ongeldigheidsmarkering.
7. Controleer dat **Label openen en afdrukken** hetzelfde opgeslagen bestand opent en geen
   nieuwe zending aanmaakt.
8. Controleer dat een tweede labelaanvraag of directe herhaling wordt geblokkeerd.

Mockmodus maakt een GLS-achtige zwart-witte PDF van 100 × 150 mm, met één A6-pagina per doos,
duidelijke vakken, sorteercodes en machinecodes. De getoonde streepjescode en 2D-codes zijn bewust
niet scanbare visuele testcodes. Dit bestand is uitsluitend bedoeld om de opslag- en afdrukroute en
de Zebra-printer te testen en is nadrukkelijk geen geldig GLS-label. In test- en productiemodus wordt
altijd het officiële label-PDF uit de GLS-respons opgeslagen; de app tekent dat label niet zelf.

## GLS-testomgeving activeren

Vraag GLS eerst om toegang tot de **Label API-testomgeving** en plaats de ontvangen geheimen alleen
in `cloud86/.env` op Cloud86. De niet-geheime testendpoints staan al in `.env.example`. Benodigd zijn:

- indien GLS die voor de gekozen omgeving verstrekt: de API-subscriptionkey voor header
  `Ocp-Apim-Subscription-Key`;
- een geldige MyGLS-testgebruikersnaam en -wachtwoord;
- de afzonderlijke operationele klantnummers voor Parcel (`GLS_PARCEL_CUSTOMER_NO`) en Freight
  (`GLS_FREIGHT_CUSTOMER_NO`);
- bevestiging dat `pdfa6s` het gewenste A6-PDF-labeltype is.

Zet daarna tijdelijk `GLS_MODE=test` en controleer uitsluitend de inlog, zonder een zending te maken:

```bash
cd gls.beteccontrols.nl/app/cloud86
php bin/test-gls-connection.php
```

Het script toont alleen of de login is geslaagd en eventueel een GLS-transactie-ID; het toont geen
wachtwoord of eventuele subscriptionkey. Zet bij een mislukte of uitgestelde acceptatietest `GLS_MODE` terug
op `mock`.

`GLS_SEND_SHIPPING_DATE=false` is de veilige standaard. Dan maakt `CreateLabel` wel labels en
unitnummers, maar moet de werkelijk verzonden unit later met `ConfirmLabel` worden vrijgegeven.
Kies pas na afstemming met GLS voor `true`; het meesturen van `shippingDate` geeft de zending direct
vrij aan GLS.

## GLS-webhook

De URL wordt:

```text
POST https://verzenden.voorbeeld.nl/webhooks/gls
Authorization: Bearer <GLS_WEBHOOK_BEARER_TOKEN>
Content-Type: application/json
```

Gebruik een willekeurig geheim van minimaal 32 bytes. Het endpoint accepteert een bericht slechts één
keer, maar geeft bij een herhaling opnieuw HTTP 200 zodat het retrymechanisme van GLS niet vastloopt.

## Belangrijke veiligheidsregels

- `.env` mag nooit via de browser bereikbaar of in Git opgeslagen zijn.
- Gebruik in productie uitsluitend HTTPS.
- Er is één algemeen account voor het magazijn. Na vijf mislukte pogingen wordt dit account 15 minuten
  geblokkeerd. Deel het wachtwoord alleen met medewerkers die de verzendapp gebruiken.
- Geef de databasegebruiker alleen rechten op deze ene database.
- Begin met de GLS-testomgeving; zet nooit direct productiegegevens in de app.
- Herhaal een mislukte `CreateLabel` na een timeout niet blind. Controleer eerst in MyGLS of de zending
  al bestaat, anders kunnen dubbele pakketten ontstaan.
- Vóór een echte GLS-call krijgt de levering status `gls_processing`. Bij een timeout of onduidelijke
  respons blijft zij vergrendeld als `gls_unknown`. Alleen een beheerder mag haar na controle in GLS
  weer vrijgeven.
- Opnieuw afdrukken opent altijd de opgeslagen PDF en voert nooit opnieuw `CreateLabel` uit.
- De applicatie bewaart GLS-gebruikersnaam en wachtwoord niet in de database of logs.

