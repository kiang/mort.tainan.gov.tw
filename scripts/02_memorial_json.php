<?php

$rawDir = dirname(__DIR__) . '/raw';
$outputDir = dirname(__DIR__) . '/docs/json/memorial';

$expectedVenues = [
    'S01' => '景行廳',
    'S02' => '明德廳', 
    'S03' => '至德廳',
    'S04' => '崇德廳',
    'S05' => '懷悌廳',
    'S06' => '懷澤廳',
    'S07' => '懷慈廳',
    'S08' => '懷親廳',
    'S09' => '懷恩廳',
    'S10' => '永安堂',
    'S12' => '光德廳',
    'S22' => '無煙豎靈區'
];

$hepingRanges = [
    'S15' => ['name' => '和平堂1-29', 'numbers' => range(1, 29)],
    'S16' => ['name' => '和平堂30-35', 'numbers' => range(30, 35)],
    'S17' => ['name' => '和平堂36-42', 'numbers' => range(36, 42)],
    'S18' => ['name' => '和平堂43-50', 'numbers' => range(43, 50)],
    'S19' => ['name' => '和平堂51-53', 'numbers' => range(51, 53)],
    'S20' => ['name' => '和平堂53-1-58', 'numbers' => ['53-1', 54, 55, 56, 57, 58]],
    'S23' => ['name' => '和平堂59', 'numbers' => [59]],
    'S24' => ['name' => '和平堂60', 'numbers' => [60]]
];

function getVenueCode($location) {
    global $expectedVenues, $hepingRanges;
    
    // Remove special notes like （婉拒民代公祭）
    $cleanLocation = preg_replace('/（.*?）/', '', $location);
    
    // Check direct venue matches
    foreach ($expectedVenues as $code => $venue) {
        if ($cleanLocation === $venue) {
            return $code;
        }
        
        // Special cases
        if ($code === 'S01' && (strpos($cleanLocation, '景行') !== false || $cleanLocation === '景德廳' || $cleanLocation === '景福廳')) {
            return $code;
        }
        
        if ($code === 'S12' && strpos($cleanLocation, '光德') !== false) {
            return $code;
        }
        
        if ($code === 'S10' && preg_match('/^永安堂-/', $cleanLocation)) {
            return $code;
        }
    }
    
    // Check 和平堂 ranges
    if (preg_match('/^和平堂-(.+)$/', $cleanLocation, $matches)) {
        $num = $matches[1];
        foreach ($hepingRanges as $code => $rangeData) {
            if (in_array($num, array_map('strval', $rangeData['numbers'])) || in_array((int)$num, $rangeData['numbers'])) {
                return $code;
            }
        }
    }
    
    return null;
}

function scanCsvFiles($directory) {
    global $outputDir;
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory)
    );
    
    $processedFiles = 0;
    $totalRecords = 0;
    $recordsWithCodes = 0;
    
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'csv') {
            $result = processCsvFile($file->getPathname());
            $processedFiles++;
            $totalRecords += $result['total'];
            $recordsWithCodes += $result['withCodes'];
        }
    }
    
    echo "Processed $processedFiles CSV files\n";
    echo "Total records: $totalRecords\n";
    echo "Records with venue codes: $recordsWithCodes\n";
    echo "Coverage: " . round(($recordsWithCodes / $totalRecords) * 100, 2) . "%\n";
}

function processCsvFile($filePath) {
    global $outputDir;
    
    $totalRecords = 0;
    $recordsWithCodes = 0;
    
    if (($handle = fopen($filePath, 'r')) !== false) {
        $header = fgetcsv($handle);
        
        if ($header === false) {
            fclose($handle);
            return ['total' => 0, 'withCodes' => 0];
        }
        
        // Find column indices
        $indices = [];
        $requiredFields = ['日期', '公祭時間', '公祭地點', '輓額', '往生者', '性別', '年次', '戶籍地', 'SID'];
        
        foreach ($requiredFields as $field) {
            $index = array_search($field, $header);
            if ($index === false) {
                echo "Warning: Field '$field' not found in $filePath\n";
                fclose($handle);
                return ['total' => 0, 'withCodes' => 0];
            }
            $indices[$field] = $index;
        }
        
        $dateRecords = [];
        
        while (($data = fgetcsv($handle)) !== false) {
            $totalRecords++;
            
            if (!isset($data[$indices['公祭地點']]) || empty(trim($data[$indices['公祭地點']]))) {
                continue;
            }
            
            $location = trim($data[$indices['公祭地點']]);
            $code = getVenueCode($location);
            
            if ($code !== null) {
                $recordsWithCodes++;
                
                // Parse date (format: 114/05/05)
                $dateStr = trim($data[$indices['日期']]);
                if (preg_match('/^(\d{3})\/(\d{2})\/(\d{2})$/', $dateStr, $matches)) {
                    $year = intval($matches[1]);
                    $month = intval($matches[2]);
                    $day = intval($matches[3]);
                    
                    $dateKey = sprintf('%03d-%02d-%02d', $year, $month, $day);
                    
                    if (!isset($dateRecords[$year])) {
                        $dateRecords[$year] = [];
                    }
                    if (!isset($dateRecords[$year][$dateKey])) {
                        $dateRecords[$year][$dateKey] = [];
                    }
                    
                    $record = [
                        'code' => $code,
                        '公祭時間' => trim($data[$indices['公祭時間']]),
                        '公祭地點' => $location,
                        '輓額' => trim($data[$indices['輓額']]),
                        '往生者' => trim($data[$indices['往生者']]),
                        '性別' => trim($data[$indices['性別']]),
                        '年次' => trim($data[$indices['年次']]),
                        '戶籍地' => trim($data[$indices['戶籍地']]),
                        'SID' => trim($data[$indices['SID']])
                    ];
                    
                    $dateRecords[$year][$dateKey][] = $record;
                }
            }
        }
        
        fclose($handle);
        
        // Write JSON files
        foreach ($dateRecords as $year => $yearData) {
            foreach ($yearData as $dateKey => $records) {
                writeJsonFile($year, $dateKey, $records);
            }
        }
    }
    
    return ['total' => $totalRecords, 'withCodes' => $recordsWithCodes];
}

function writeJsonFile($year, $dateKey, $records) {
    global $outputDir;
    
    $yearDir = $outputDir . '/' . $year;
    if (!is_dir($yearDir)) {
        mkdir($yearDir, 0755, true);
    }
    
    $filePath = $yearDir . '/' . $dateKey . '.json';
    
    // Sort records by 公祭時間
    usort($records, function($a, $b) {
        return strcmp($a['公祭時間'], $b['公祭時間']);
    });
    
    $jsonData = [
        'date' => $dateKey,
        'count' => count($records),
        'records' => $records
    ];
    
    $json = json_encode($jsonData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    file_put_contents($filePath, $json);
    
    echo "Created: $filePath (" . count($records) . " records)\n";
}

echo "Generating memorial JSON files...\n";
echo "=" . str_repeat("=", 50) . "\n";

scanCsvFiles($rawDir);

echo "\nJSON files generated in docs/json/memorial/\n";