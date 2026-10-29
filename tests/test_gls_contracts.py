from datetime import UTC, datetime

import pytest

from gls_exact.gls_contracts import (
    GlsContractError,
    build_confirm_label_payload,
    build_track_details_payload,
    parse_create_label_response,
)
from gls_exact.gls_mapping import GlsRequestSettings


def test_parses_current_create_label_v1_response():
    result = parse_create_label_response(
        {
            "error": False,
            "transactionId": "a2db6468-30ad-453b-8c01-547c34e3df5a",
            "shipmentTrackingLink": "https://gls.example/shipment",
            "labels": "BASE64-PDF",
            "units": [
                {
                    "unitId": "A",
                    "unitNo": "35000001406746",
                    "uniqueNo": "UNIQUE-1",
                    "unitTrackingLink": "https://gls.example/unit/1",
                    "label": "BASE64-UNIT-LABEL",
                }
            ],
        }
    )

    assert result.units[0].unit_no == "35000001406746"
    assert result.units[0].label_base64 == "BASE64-UNIT-LABEL"


def test_rejects_create_label_response_without_unit_number():
    with pytest.raises(GlsContractError, match="unitNo"):
        parse_create_label_response({"error": False, "units": [{"unitId": "A"}]})


def test_builds_confirm_label_request():
    payload = build_confirm_label_payload(
        "35000001406746",
        GlsRequestSettings(username="user", password="secret"),
        shipping_date=datetime(2026, 10, 28, 12, 0, tzinfo=UTC),
    )

    assert payload["unitNo"] == "35000001406746"
    assert payload["shiptype"] == "p"
    assert payload["shippingDate"] == "2026-10-28T12:00:00+00:00"


def test_builds_track_details_request():
    assert build_track_details_payload(
        [" 35000001406746 "], username="track-user", password="secret"
    ) == {
        "userName": "track-user",
        "password": "secret",
        "parcelNumbers": ["35000001406746"],
    }
