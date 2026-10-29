from tempfile import TemporaryDirectory

from fastapi.testclient import TestClient

from gls_exact.api import create_app


def test_complete_mock_label_flow(monkeypatch):
    monkeypatch.setenv("GLS_MODE", "mock")
    with TemporaryDirectory() as temp_dir:
        client = TestClient(create_app(f"{temp_dir}/api.sqlite3"))
        entry_id = "6db186b0-44e8-4b31-b522-a0345c1beaf8"

        created = client.post(
            "/api/deliveries",
            json={
                "exact_entry_id": entry_id,
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
                },
            },
        )
        assert created.status_code == 201

        packed = client.put(
            f"/api/deliveries/{entry_id}/parcels",
            json={"parcels": [{"weight_kg": 4.5}, {"weight_kg": 7.2}]},
        )
        assert packed.status_code == 200
        assert packed.json()["status"] == "ready"

        labels = client.post(f"/api/deliveries/{entry_id}/labels")
        assert labels.status_code == 200
        assert len(labels.json()["tracking_numbers"]) == 2
        assert labels.json()["gls_request"]["units"][1]["weight"] == 7.2
        assert labels.json()["gls_request"]["username"] == "***"
        assert labels.json()["exact_tracking_update"] == "pending"

        duplicate = client.post(f"/api/deliveries/{entry_id}/labels")
        assert duplicate.status_code == 409
