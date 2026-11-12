#!/usr/bin/env bash
set -euo pipefail

echo "Checking production API and public pages..."

HEALTH_FILE="$(mktemp)"
trap 'rm -f "$HEALTH_FILE"' EXIT

curl --fail --location --silent --show-error   --retry 5 --retry-delay 4 --retry-all-errors   --connect-timeout 10 --max-time 30   "https://urovia.ru/api/health.php" > "$HEALTH_FILE"

php -r '
  $data = json_decode(file_get_contents($argv[1]), true);
  if (!is_array($data) || empty($data["ok"]) || ($data["service"] ?? "") !== "UROVIA API") {
      fwrite(STDERR, "Production health check failed.\n");
      exit(1);
  }
  echo "Production API + database OK\n";
' "$HEALTH_FILE"

for url in   "https://urovia.ru/login.html"   "https://urovia.ru/pricing.php"   "https://urovia.ru/payment-info.php"   "https://urovia.ru/offer.php"
do
  echo "Checking $url"
  curl --fail --location --silent --show-error     --retry 5 --retry-delay 4 --retry-all-errors     --connect-timeout 10 --max-time 30     "$url" -o /dev/null
done

echo "Live UROVIA deployment verified."
