#!/usr/bin/env python3
"""
PiDoors Database Migration: Convert doors from space-separated to comma-separated

This migration converts the 'doors' column in the cards table from
space-separated format to comma-separated format for compatibility
with FIND_IN_SET() SQL function.

Usage:
    python3 migrate_doors_format.py [--dry-run] [--config /path/to/config.json]

Options:
    --dry-run    Show what would be changed without making changes
    --config     Path to config.json (default: /opt/pidoors/conf/config.json)
"""

import argparse
import json
import sys
import re
from datetime import datetime

try:
    import pymysql
    import pymysql.cursors
except ImportError:
    print("Error: pymysql not installed. Run: pip install pymysql")
    sys.exit(1)


def load_config(config_path):
    """Load database configuration from config file"""
    try:
        with open(config_path, 'r') as f:
            config = json.load(f)

        # Get first zone's config for database settings
        for zone_name, zone_config in config.items():
            if isinstance(zone_config, dict) and 'sqladdr' in zone_config:
                return {
                    'host': zone_config.get('sqladdr', 'localhost'),
                    'user': zone_config.get('sqluser', 'pidoors'),
                    'password': zone_config.get('sqlpass', ''),
                    'database': zone_config.get('sqldb', 'access')
                }

        raise ValueError("No valid zone configuration found")
    except FileNotFoundError:
        print(f"Error: Config file not found: {config_path}")
        sys.exit(1)
    except json.JSONDecodeError as e:
        print(f"Error: Invalid JSON in config file: {e}")
        sys.exit(1)


def convert_doors_format(doors_str):
    """Convert space-separated doors to comma-separated"""
    if not doors_str or doors_str == '*':
        return doors_str

    # Split by spaces and/or commas, filter empty strings, rejoin with commas
    doors = re.split(r'[,\s]+', doors_str.strip())
    doors = [d.strip() for d in doors if d.strip()]
    return ','.join(doors)


def migrate(db_config, dry_run=False):
    """Perform the migration"""
    print(f"\n{'=' * 60}")
    print("PiDoors Doors Format Migration")
    print(f"{'=' * 60}")
    print(f"Date: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print(f"Mode: {'DRY RUN (no changes will be made)' if dry_run else 'LIVE'}")
    print(f"Database: {db_config['database']}@{db_config['host']}")
    print(f"{'=' * 60}\n")

    try:
        conn = pymysql.connect(
            host=db_config['host'],
            user=db_config['user'],
            password=db_config['password'],
            database=db_config['database'],
            cursorclass=pymysql.cursors.DictCursor,
            autocommit=False
        )
    except pymysql.Error as e:
        print(f"Error: Could not connect to database: {e}")
        sys.exit(1)

    try:
        with conn.cursor() as cursor:
            # Get all cards with doors
            cursor.execute("""
                SELECT card_id, user_id, firstname, lastname, doors
                FROM cards
                WHERE doors IS NOT NULL AND doors != ''
            """)
            cards = cursor.fetchall()

            print(f"Found {len(cards)} cards with door assignments\n")

            migrated = 0
            unchanged = 0
            changes = []

            for card in cards:
                old_doors = card['doors']
                new_doors = convert_doors_format(old_doors)

                if old_doors != new_doors:
                    name = f"{card['firstname'] or ''} {card['lastname'] or ''}".strip() or 'Unknown'
                    changes.append({
                        'card_id': card['card_id'],
                        'user_id': card['user_id'],
                        'name': name,
                        'old': old_doors,
                        'new': new_doors
                    })
                    migrated += 1
                else:
                    unchanged += 1

            # Show changes
            if changes:
                print("Changes to be made:")
                print("-" * 60)
                for i, change in enumerate(changes, 1):
                    print(f"{i}. Card: {change['user_id']} ({change['name']})")
                    print(f"   Old: '{change['old']}'")
                    print(f"   New: '{change['new']}'")
                    print()

                if not dry_run:
                    print("-" * 60)
                    confirm = input(f"Apply {migrated} changes? (yes/no): ")
                    if confirm.lower() != 'yes':
                        print("Migration cancelled.")
                        return

                    # Apply changes
                    print("\nApplying changes...")
                    for change in changes:
                        cursor.execute("""
                            UPDATE cards
                            SET doors = %s
                            WHERE card_id = %s
                        """, (change['new'], change['card_id']))

                    conn.commit()
                    print("Changes committed successfully!")
            else:
                print("No changes needed - all doors are already in correct format.")

            # Summary
            print(f"\n{'=' * 60}")
            print("MIGRATION SUMMARY")
            print(f"{'=' * 60}")
            print(f"Total cards processed: {len(cards)}")
            print(f"Cards migrated:        {migrated}")
            print(f"Cards unchanged:       {unchanged}")
            if dry_run and migrated > 0:
                print(f"\nRun without --dry-run to apply changes.")
            print(f"{'=' * 60}\n")

    except pymysql.Error as e:
        conn.rollback()
        print(f"Error during migration: {e}")
        sys.exit(1)
    finally:
        conn.close()


def main():
    parser = argparse.ArgumentParser(
        description='Migrate doors format from space-separated to comma-separated'
    )
    parser.add_argument(
        '--dry-run',
        action='store_true',
        help='Show what would be changed without making changes'
    )
    parser.add_argument(
        '--config',
        default='/opt/pidoors/conf/config.json',
        help='Path to config.json file'
    )

    args = parser.parse_args()

    db_config = load_config(args.config)
    migrate(db_config, dry_run=args.dry_run)


if __name__ == '__main__':
    main()
