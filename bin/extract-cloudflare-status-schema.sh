#!/usr/bin/env bash
# Retrieves the Cloudflare OpenAPI document and extracts the two custom-hostname
# status enums into a pinned snapshot. Developer tool: never run by the plugin.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
date_stamp="${1:?usage: extract-cloudflare-status-schema.sh YYYY-MM-DD}"
url='https://raw.githubusercontent.com/cloudflare/api-schemas/main/openapi.json'
work="$(mktemp -d)"
trap 'rm -rf "${work}"' EXIT

curl --fail --silent --show-error --location "${url}" --output "${work}/openapi.json"

jq --sort-keys '
	.paths["/zones/{zone_id}/custom_hostnames"].get.parameters as $p
	| {
		hostname_status: ( $p[] | select( .name == "hostname_status" ) | .schema.enum ),
		ssl_status:      ( $p[] | select( .name == "ssl_status" )      | .schema.enum )
	}
' "${work}/openapi.json" > "${root}/references/cloudflare-api-schema.${date_stamp}.json"

snapshot="cloudflare-api-schema.${date_stamp}.json"
digest="$( shasum -a 256 "${root}/references/${snapshot}" | cut -d' ' -f1 )"
api_version="$( jq -r '.info.version' "${work}/openapi.json" )"

jq -n \
	--arg file "${snapshot}" \
	--arg source_url "${url}" \
	--arg retrieved_at "$( date -u +%Y-%m-%dT%H:%M:%SZ )" \
	--arg sha256 "${digest}" \
	--arg api_version "${api_version}" \
	--arg extraction 'jq .paths["/zones/{zone_id}/custom_hostnames"].get.parameters[] | select(.name=="hostname_status" or .name=="ssl_status") | .schema.enum' \
	'{ file: $file, source_url: $source_url, retrieved_at: $retrieved_at, sha256: $sha256, api_version: $api_version, extraction: $extraction }' \
	> "${root}/references/cloudflare-schema-provenance.json"

echo "hostname_status: $( jq '.hostname_status | length' "${root}/references/${snapshot}" )"
echo "ssl_status:      $( jq '.ssl_status | length' "${root}/references/${snapshot}" )"
