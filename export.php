<?php
require_once 'logger.php';
session_start();
if (!isset($_SESSION['username'])) {
    header('HTTP/1.1 401 Unauthorized');
    echo "Errore: Non autorizzato";
    exit;
}

date_default_timezone_set('Europe/Rome');
$dbPath = '/var/www/smarthome/sensor_data.db';

if (!file_exists($dbPath)) {
    write_log('ERROR', "Database not found at $dbPath");
    header('HTTP/1.1 500 Internal Server Error');
    echo "Errore: Database non trovato";
    exit;
}

function solvePolynomialRegression($data, $degree, $lambda = 0) {
    $validData = array_filter($data, function($p) { return $p['y'] !== null; });
    $validData = array_values($validData);
    $n = count($validData);
    if ($n <= $degree) return null;

    $xMin = $validData[0]['x'];
    $xMax = $validData[$n - 1]['x'];
    $xRange = $xMax - $xMin;

    if ($xRange == 0) return null;

    $X = [];
    $Y = [];

    foreach ($validData as $p) {
        $X[] = ($p['x'] - $xMin) / $xRange;
        $Y[] = $p['y'];
    }

    $m = count($X);
    $size = $degree + 1;
    $A = [];

    for ($i = 0; $i < $size; $i++) {
        $A[$i] = array_fill(0, $size + 1, 0.0);
        for ($j = 0; $j < $size; $j++) {
            $sum = 0;
            for ($k = 0; $k < $m; $k++) {
                $sum += pow($X[$k], $i + $j);
            }
            $A[$i][$j] = $sum;
        }
        if ($lambda > 0 && $i > 0) {
            $A[$i][$i] += $lambda;
        }

        $sumY = 0;
        for ($k = 0; $k < $m; $k++) {
            $sumY += $Y[$k] * pow($X[$k], $i);
        }
        $A[$i][$size] = $sumY;
    }

    for ($i = 0; $i < $size; $i++) {
        $max = abs($A[$i][$i]);
        $maxRow = $i;
        for ($k = $i + 1; $k < $size; $k++) {
            if (abs($A[$k][$i]) > $max) {
                $max = abs($A[$k][$i]);
                $maxRow = $k;
            }
        }

        $temp = $A[$i];
        $A[$i] = $A[$maxRow];
        $A[$maxRow] = $temp;

        if (abs($A[$i][$i]) < 1e-12) return null;

        for ($k = $i + 1; $k < $size; $k++) {
            $c = -$A[$k][$i] / $A[$i][$i];
            for ($j = $i; $j <= $size; $j++) {
                if ($i === $j) {
                    $A[$k][$j] = 0;
                } else {
                    $A[$k][$j] += $c * $A[$i][$j];
                }
            }
        }
    }

    $coeffs = array_fill(0, $size, 0.0);
    for ($i = $size - 1; $i >= 0; $i--) {
        $coeffs[$i] = $A[$i][$size] / $A[$i][$i];
        for ($k = $i - 1; $k >= 0; $k--) {
            $A[$k][$size] -= $A[$k][$i] * $coeffs[$i];
        }
    }

    return [
        'coeffs' => $coeffs,
        'xMin' => $xMin,
        'xRange' => $xRange,
        'predict' => function($ts) use ($coeffs, $xMin, $xRange) {
            $x = ($ts - $xMin) / $xRange;
            $y = 0;
            foreach ($coeffs as $i => $coeff) {
                $y += $coeff * pow($x, $i);
            }
            return $y;
        }
    ];
}

try {
    $db = new PDO("sqlite:$dbPath");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $hours = isset($_GET['hours']) ? intval($_GET['hours']) : 0;
    $start = $_GET['start'] ?? null;
    $end   = $_GET['end']   ?? null;

    $discardThreshold = isset($_GET['discardThreshold']) ? floatval($_GET['discardThreshold']) : 10;
    $movingAverageWindow = isset($_GET['movingAverageWindow']) ? intval($_GET['movingAverageWindow']) : 5;
    $enableInterpolation = isset($_GET['enableInterpolation']) ? (bool)$_GET['enableInterpolation'] : false;
    $polyDegree = isset($_GET['polyDegree']) ? intval($_GET['polyDegree']) : 3;
    $polyLambda = isset($_GET['polyLambda']) ? floatval($_GET['polyLambda']) : 0.01;

    $count = 0;
    if ($start && $end) {
        $countStmt = $db->prepare("SELECT COUNT(*) FROM measurements WHERE timestamp BETWEEN :start AND :end");
        $countStmt->execute([':start' => $start, ':end' => $end]);
        $count = $countStmt->fetchColumn();
    } elseif ($hours > 0) {
        $utcThreshold = gmdate('Y-m-d H:i:s', time() - ($hours * 3600));
        $countStmt = $db->prepare("SELECT COUNT(*) FROM measurements WHERE timestamp >= :threshold");
        $countStmt->execute([':threshold' => $utcThreshold]);
        $count = $countStmt->fetchColumn();
    } else {
        $count = $db->query("SELECT COUNT(*) FROM measurements")->fetchColumn();
    }

    $samplingRate = ($count > 1500 && !($start && $end)) ? ceil($count / 1000) : 1;
    // If it's a specific range (Export Vista), we match the dashboard's sampling
    if ($start && $end && $count > 1500) {
        $samplingRate = ceil($count / 1000);
    }

    if ($start && $end) {
        write_log('INFO', "Exporting data between $start and $end for user '{$_SESSION['username']}' (sampling: $samplingRate)");
        if ($samplingRate > 1) {
            $stmt = $db->prepare("
                SELECT timestamp, temperature, humidity, battery_voltage, usb_powered FROM (
                    SELECT timestamp, temperature, humidity, battery_voltage, usb_powered,
                    ROW_NUMBER() OVER (ORDER BY timestamp ASC) as rn
                    FROM measurements
                    WHERE timestamp BETWEEN :start AND :end
                ) WHERE (rn - 1) % :rate = 0
            ");
            $stmt->execute([':start' => $start, ':end' => $end, ':rate' => $samplingRate]);
        } else {
            $stmt = $db->prepare("SELECT timestamp, temperature, humidity, battery_voltage, usb_powered FROM measurements WHERE timestamp BETWEEN :start AND :end ORDER BY timestamp ASC");
            $stmt->execute([':start' => $start, ':end' => $end]);
        }
    } elseif ($hours > 0) {
        write_log('INFO', "Exporting data for last $hours hours for user '{$_SESSION['username']}' (sampling: $samplingRate)");
        if ($samplingRate > 1) {
            $stmt = $db->prepare("
                SELECT timestamp, temperature, humidity, battery_voltage, usb_powered FROM (
                    SELECT timestamp, temperature, humidity, battery_voltage, usb_powered,
                    ROW_NUMBER() OVER (ORDER BY timestamp ASC) as rn
                    FROM measurements
                    WHERE timestamp >= :threshold
                ) WHERE (rn - 1) % :rate = 0
            ");
            $stmt->execute([':threshold' => $utcThreshold, ':rate' => $samplingRate]);
        } else {
            $stmt = $db->prepare("SELECT timestamp, temperature, humidity, battery_voltage, usb_powered FROM measurements WHERE timestamp >= :threshold ORDER BY timestamp ASC");
            $stmt->execute([':threshold' => $utcThreshold]);
        }
    } else {
        write_log('INFO', "Exporting all data for user '{$_SESSION['username']}' (sampling: $samplingRate)");
        if ($samplingRate > 1) {
            $stmt = $db->prepare("
                SELECT timestamp, temperature, humidity, battery_voltage, usb_powered FROM (
                    SELECT timestamp, temperature, humidity, battery_voltage, usb_powered,
                    ROW_NUMBER() OVER (ORDER BY timestamp ASC) as rn
                    FROM measurements
                ) WHERE (rn - 1) % :rate = 0
            ");
            $stmt->execute([':rate' => $samplingRate]);
        } else {
            $stmt = $db->query("SELECT timestamp, temperature, humidity, battery_voltage, usb_powered FROM measurements ORDER BY timestamp ASC");
        }
    }

    $rawData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Data Processing matching index.html
    $filteredData = [];
    $lastValidTemp = null;
    $lastValidHum = null;
    $lastRawTemp = null;
    $lastRawHum = null;

    foreach ($rawData as $r) {
        $acceptTemp = true;
        $acceptHum = true;

        if ($lastValidTemp !== null && $discardThreshold > 0) {
            $diffV = abs($r['temperature'] - $lastValidTemp);
            $limitV = max(abs($lastValidTemp), 0.5) * ($discardThreshold / 100);
            if ($diffV > $limitV) {
                if ($lastRawTemp !== null) {
                    $diffR = abs($r['temperature'] - $lastRawTemp);
                    $limitR = max(abs($lastRawTemp), 0.5) * ($discardThreshold / 100);
                    if ($diffR > $limitR) $acceptTemp = false;
                } else {
                    $acceptTemp = false;
                }
            }
        }

        if ($lastValidHum !== null && $discardThreshold > 0) {
            $diffV = abs($r['humidity'] - $lastValidHum);
            $limitV = max(abs($lastValidHum), 0.5) * ($discardThreshold / 100);
            if ($diffV > $limitV) {
                if ($lastRawHum !== null) {
                    $diffR = abs($r['humidity'] - $lastRawHum);
                    $limitR = max(abs($lastRawHum), 0.5) * ($discardThreshold / 100);
                    if ($diffR > $limitR) $acceptHum = false;
                } else {
                    $acceptHum = false;
                }
            }
        }

        if ($acceptTemp && $acceptHum) {
            $lastValidTemp = $r['temperature'];
            $lastValidHum = $r['humidity'];
            $filteredData[] = $r;
        }
        $lastRawTemp = $r['temperature'];
        $lastRawHum = $r['humidity'];
    }

    $processedData = [];
    $tempWindow = [];
    $humWindow = [];
    $lastTs = null;
    $effectiveGap = 120000 * $samplingRate;
    $effectiveWindow = max(1, round($movingAverageWindow / $samplingRate));

    $utcTz = new DateTimeZone('UTC');
    $romeTz = new DateTimeZone('Europe/Rome');

    $tempsForPoly = [];
    $humsForPoly = [];

    foreach ($filteredData as $r) {
        $dt = new DateTime($r['timestamp'], $utcTz);
        $ts = $dt->getTimestamp() * 1000; // ms

        if ($lastTs !== null && ($ts - $lastTs) > $effectiveGap) {
            $tempWindow = [];
            $humWindow = [];
        }

        $tempWindow[] = $r['temperature'];
        $humWindow[] = $r['humidity'];
        if (count($tempWindow) > $effectiveWindow) array_shift($tempWindow);
        if (count($humWindow) > $effectiveWindow) array_shift($humWindow);

        $avgTemp = array_sum($tempWindow) / count($tempWindow);
        $avgHum = array_sum($humWindow) / count($humWindow);

        $dt->setTimezone($romeTz);
        $processedData[] = [
            'timestamp' => $dt->format('Y-m-d H:i:s'),
            'ts_ms' => $ts,
            'raw_temp' => $r['temperature'],
            'raw_hum' => $r['humidity'],
            'avg_temp' => $avgTemp,
            'avg_hum' => $avgHum
            'vbat' => $r['battery_voltage'],
            'usb' => $r['usb_powered']
        ];

        $tempsForPoly[] = ['x' => $ts, 'y' => $avgTemp];
        $humsForPoly[] = ['x' => $ts, 'y' => $avgHum];
        $lastTs = $ts;
    }

    $modelTemp = null;
    $modelHum = null;
    if ($enableInterpolation && count($processedData) > 0) {
        $modelTemp = solvePolynomialRegression($tempsForPoly, $polyDegree, $polyLambda);
        $modelHum = solvePolynomialRegression($humsForPoly, $polyDegree, $polyLambda);
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=sensor_data.csv');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($output, [
        'Data e Ora (Italian Time)',
        'Temp Raw (°C)',
        'Umid Raw (%)',
        'Media Mobile Temp (°C)',
        'Media Mobile Umid (%)',
        'Interpolazione Temp (°C)',
        'Interpolazione Umid (%)'
        'Volt Batteria (V)',
        'Alimentazione USB'
    ], ';');

    foreach ($processedData as $row) {
        $pTemp = $modelTemp ? ($modelTemp['predict'])($row['ts_ms']) : '';
        $pHum = $modelHum ? ($modelHum['predict'])($row['ts_ms']) : '';

        fputcsv($output, [
            $row['timestamp'],
            number_format($row['raw_temp'], 1, '.', ''),
            number_format($row['raw_hum'], 1, '.', ''),
            number_format($row['avg_temp'], 2, '.', ''),
            number_format($row['avg_hum'], 2, '.', ''),
            $pTemp !== '' ? number_format($pTemp, 2, '.', '') : '',
            $pHum !== '' ? number_format($pHum, 2, '.', '') : ''
            number_format($row['vbat'], 2, '.', ''),
            $row['usb'] ? 'USB' : 'Batteria'
        ], ';');
    }

    fclose($output);

} catch (Throwable $e) {
    write_log('ERROR', "Data export error for user '{$_SESSION['username']}': " . $e->getMessage());
    http_response_code(500);
    echo "Errore: " . $e->getMessage();
}
?>
