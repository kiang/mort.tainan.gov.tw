# Tainan Mortuary Data Fetcher

A PHP script to fetch and process data from the Tainan Mortuary Information Service website.

## Description

This script automatically fetches mortuary data from the Tainan City Government's mortuary information service website. It:

1. Fetches available dates from the website
2. Retrieves data for each date
3. Extracts relevant information including SID from the deceased person's record
4. Saves the data in CSV format with a structured directory layout

## Requirements

- PHP 7.4 or higher
- Composer
- Required PHP packages:
  - guzzlehttp/guzzle
  - symfony/dom-crawler
  - symfony/css-selector

## Installation

1. Clone this repository
2. Install dependencies:
```bash
composer install
```

## Usage

Run the script:
```bash
php scripts/01_fetch.php
```

The script will:
1. Create a directory structure: `raw/{year}/{month}/`
2. Save CSV files in the format: `{year}-{month}-{day}.csv`
3. Each CSV file contains the mortuary data for that specific date

## Data Structure

The CSV files contain the following columns:
- Original table columns from the website
- Additional SID column at the end (extracted from the deceased person's record URL)

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Author

Finjon Kiang 