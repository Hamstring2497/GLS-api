# Exact GLS Werkplek

> Nieuwe ontwikkelaar of Codex-sessie? Begin bij
> [`docs/PROJECT_WORKFLOW.md`](docs/PROJECT_WORKFLOW.md) voor de architectuur, productieomgeving,
> actuele stand en volgende stappen.

> De Cloud86-versie staat in [`cloud86/`](cloud86/README.md). Het oorspronkelijke Python-prototype
> blijft voorlopig aanwezig als gecontroleerde referentie voor de al geteste GLS-contracten.

Eerste MVP-basis voor een magazijnwerkplek tussen Exact Online en GLS Netherlands.

## Doel van deze eerste versie

1. Een goederenlevering vastleggen zoals die later uit Exact wordt geïmporteerd.
2. Handmatig één of meer dozen en het gewicht per doos toevoegen.
3. Een GLS `CreateLabel`-payload opbouwen volgens het actuele GLS v1-portalschema.
4. In mockmodus trackingnummers genereren zonder echte GLS-zendingen te maken.
5. Dubbele labelaanvragen voor dezelfde levering blokkeren.

De actuele `CreateLabel`-responsemapping, `ConfirmLabel`- en Track & Trace-requestcontracten zijn
aanwezig. De Exact OAuth/webhook-adapter, labelbestanden, het uitvoeren van `ConfirmLabel` en het
terugschrijven van `TrackingNumber` worden in de volgende stappen toegevoegd.

## Lokaal starten

```bash
python3 -m venv .venv
source .venv/bin/activate
pip install -e '.[dev]'
cp .env.example .env
uvicorn gls_exact.api:app --reload
```

Open daarna `http://127.0.0.1:8000/docs` voor de interactieve API.

De standaardmodus is `mock`. Zet `GLS_MODE` pas op `live` nadat de subscription-key en
MyGLS-testgegevens veilig zijn geconfigureerd. Live-aanroepen gebruiken standaard uitsluitend het
officiële HTTPS-testendpoint uit het GLS developerportal.

## Voorbeeldflow

Maak eerst een levering aan met `POST /api/deliveries`:

```json
{
  "exact_entry_id": "6db186b0-44e8-4b31-b522-a0345c1beaf8",
  "delivery_number": "202600123",
  "order_number": "202600456",
  "shipping_date": "2026-10-28",
  "address": {
    "name": "Voorbeeldbedrijf B.V.",
    "street": "Dorpsstraat",
    "house_no": "12A",
    "zip_code": "1234 AB",
    "city": "Utrecht",
    "country_code": "NL",
    "contact": "R. Voorbeeld",
    "email": "magazijn@example.nl"
  }
}
```

Voeg vervolgens dozen toe met `PUT /api/deliveries/{exact_entry_id}/parcels`:

```json
{
  "parcels": [
    {"weight_kg": 4.5},
    {"weight_kg": 7.2}
  ]
}
```

Roep ten slotte `POST /api/deliveries/{exact_entry_id}/labels` aan. In mockmodus wordt precies het
GLS-request getoond dat later naar het GLS-testendpoint gaat.

## Veiligheidsregels

- MyGLS-gebruikersnaam en wachtwoord worden nooit in de database of logs opgeslagen.
- Het opgeslagen request wordt automatisch ontdaan van inloggegevens.
- Een doos moet meer dan 0 en maximaal 32 kg wegen.
- Een levering met status `labels_created` kan niet nogmaals labels genereren.
- Een timeout bij een live `CreateLabel` mag niet blind worden herhaald, omdat GLS de zending al kan
  hebben aangemaakt.

