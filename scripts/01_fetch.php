<?php
require 'vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Symfony\Component\DomCrawler\Crawler;

class MortuaryDataFetcher {
    private $client;
    private $baseUrl = 'https://mort.tainan.gov.tw/Inquire/I101.aspx?mid=1';
    private $cookieJar;

    public function __construct() {
        $this->cookieJar = new CookieJar();
        $this->client = new Client([
            'cookies' => $this->cookieJar,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
                'Accept-Language' => 'zh-TW,zh;q=0.9,en-US;q=0.8,en;q=0.7',
                'Cache-Control' => 'no-cache',
                'Origin' => 'https://mort.tainan.gov.tw',
                'Referer' => 'https://mort.tainan.gov.tw/Inquire/I101.aspx?mid=1',
            ]
        ]);
    }

    public function fetchInitialPage() {
        try {
            $response = $this->client->get($this->baseUrl);
            $html = $response->getBody()->getContents();
            
            // Parse the HTML to get form fields
            $crawler = new Crawler($html);
            
            // Get all available dates from the date dropdown
            $dates = $crawler->filter('#Matter_wT02 option')->each(function (Crawler $node) {
                return $node->attr('value');
            });

            // Extract hidden form fields
            $formData = [
                '__EVENTTARGET' => '',
                '__EVENTARGUMENT' => '',
                '__LASTFOCUS' => '',
                '__VIEWSTATE' => $crawler->filter('#__VIEWSTATE')->attr('value'),
                '__VIEWSTATEGENERATOR' => $crawler->filter('#__VIEWSTATEGENERATOR')->attr('value'),
                '__EVENTVALIDATION' => $crawler->filter('#__EVENTVALIDATION')->attr('value'),
                'ctl00$Matter$wT03' => '臺南市',   // City
                'ctl00$Matter$wT04' => '',         // District
                'ctl00$Matter$Btn_Search' => '查詢'
            ];

            return [
                'formData' => $formData,
                'dates' => $dates
            ];
        } catch (Exception $e) {
            echo "Error fetching initial page: " . $e->getMessage() . "\n";
            return null;
        }
    }

    private function extractSidFromUrl($url) {
        if (preg_match('/sid=([^&]+)/', $url, $matches)) {
            return $matches[1];
        }
        return '';
    }

    private function extractTableData($html) {
        $crawler = new Crawler($html);
        $table = $crawler->filter('#printlist');
        
        if ($table->count() === 0) {
            return [];
        }

        $headers = $table->filter('tr:first-child th')->each(function (Crawler $node) {
            return trim($node->text());
        });
        $headers[] = 'SID'; // Add SID column

        $rows = [];
        $table->filter('tr:not(:first-child)')->each(function (Crawler $row) use (&$rows) {
            try {
                $rowData = $row->filter('td')->each(function (Crawler $cell) {
                    return trim($cell->text());
                });
                
                if (!empty($rowData)) {
                    // Find the 往生者 field (sixth column)
                    $deceasedCell = $row->filter('td:nth-child(6)');
                    $sid = '';
                    
                    // Check if there's a link in the cell
                    if ($deceasedCell->filter('a')->count() > 0) {
                        $deceasedLink = $deceasedCell->filter('a')->attr('href');
                        $sid = $this->extractSidFromUrl($deceasedLink);
                    }
                    
                    // Add SID to the end of the row
                    $rowData[] = $sid;
                    $rows[] = $rowData;
                }
            } catch (Exception $e) {
                echo "Warning: Error processing row: " . $e->getMessage() . "\n";
            }
        });

        return [
            'headers' => $headers,
            'rows' => $rows
        ];
    }

    private function saveAsCsv($data, $filename) {
        $fp = fopen($filename, 'w');
        
        // Write headers
        fputcsv($fp, $data['headers']);
        
        // Write rows
        foreach ($data['rows'] as $row) {
            fputcsv($fp, $row);
        }
        
        fclose($fp);
    }

    public function submitSearch($formData, $date) {
        try {
            // Update form data with the current date
            $formData['ctl00$Matter$wT01'] = $date;
            $formData['ctl00$Matter$wT02'] = $date;

            $response = $this->client->post($this->baseUrl, [
                'form_params' => $formData,
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ]
            ]);

            $html = $response->getBody()->getContents();
            
            // Parse the date to create directory structure
            $year = substr($date, 0, 3);
            $month = substr($date, 3, 2);
            $day = substr($date, 5, 2);
            
            // Create directory structure if it doesn't exist
            $dir = __DIR__ . "/../raw/{$year}/{$month}";
            if (!file_exists($dir)) {
                mkdir($dir, 0777, true);
            }
            
            // Extract table data
            $tableData = $this->extractTableData($html);
            
            if (!empty($tableData['rows'])) {
                // Save as CSV
                $csvFilename = "{$dir}/{$year}-{$month}-{$day}.csv";
                $this->saveAsCsv($tableData, $csvFilename);
                echo "Saved CSV data for date {$date} to {$csvFilename}\n";
            } else {
                echo "No data found for date {$date}\n";
            }
            
            return $tableData;
        } catch (Exception $e) {
            echo "Error submitting search for date {$date}: " . $e->getMessage() . "\n";
            return null;
        }
    }
}

// Usage
$fetcher = new MortuaryDataFetcher();
$result = $fetcher->fetchInitialPage();

if ($result) {
    $formData = $result['formData'];
    $dates = $result['dates'];
    
    foreach ($dates as $date) {
        $fetcher->submitSearch($formData, $date);
        // Add a small delay between requests to be polite
        sleep(1);
    }
    
    echo "Completed fetching all dates\n";
}
