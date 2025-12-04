#!/usr/bin/env bash
set -euo pipefail

# Usage: ./import_participants.sh [path/to/participants.csv]
CSV_FILE="${1:-participants.csv}"

DB_CONTAINER="secretsanta_db"
DB_NAME="secret_santa"
DB_USER="root"         # or 'secretsanta' if that works for you
DB_PASS="changeme"     # adjust if different

if [[ ! -f "$CSV_FILE" ]]; then
  echo "CSV file not found: $CSV_FILE" >&2
  exit 1
fi

echo "Importing participants from: $CSV_FILE"
echo

# Open the file once, skip header, then read each row
{
  # Read and discard the header line
  read -r header

  # Now process each CSV line: first_name,last_name,email,family_unit
  while IFS=, read -r first_name last_name email family_unit; do
    # Trim whitespace
    first_name=$(echo "$first_name" | xargs)
    last_name=$(echo "$last_name"   | xargs)
    email=$(echo "$email"           | xargs)
    family_unit=$(echo "$family_unit" | xargs)

    # Skip empty lines
    if [[ -z "$first_name" || -z "$email" || -z "$family_unit" ]]; then
      echo "Skipping incomplete row: '$first_name,$last_name,$email,$family_unit'"
      continue
    fi

    # Escape single quotes for SQL safety (basic)
    esc_first_name=${first_name//\'/\'\'}
    esc_last_name=${last_name//\'/\'\'}
    esc_email=${email//\'/\'\'}

    echo "Inserting: $first_name $last_name <$email> (family $family_unit)"

    docker exec -i "$DB_CONTAINER" \
      mariadb -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" \
      -e "INSERT INTO participants (first_name, last_name, email, family_unit)
          VALUES ('$esc_first_name', '$esc_last_name', '$esc_email', $family_unit);"
  done
} < "$CSV_FILE"

echo
echo "Import complete."

