<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!isset($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No file uploaded']);
    exit;
}

$file = $_FILES['file'];
$fileTmpName = $file['tmp_name'];
$fileError = $file['error'];

if ($fileError !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'File upload error']);
    exit;
}

$fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if ($fileExt !== 'csv') {
    http_response_code(400);
    echo json_encode(['error' => 'Only CSV files supported. Save Excel as CSV first.']);
    exit;
}

// Parse CSV
$rows = [];
if (($handle = fopen($fileTmpName, 'r')) !== false) {
    $header = fgetcsv($handle);
    
    if (!$header || count($header) < 11) {
        fclose($handle);
        http_response_code(400);
        echo json_encode(['error' => 'Invalid template. Use official template.']);
        exit;
    }
    
    $lineNum = 1;
    while (($data = fgetcsv($handle)) !== false) {
        $lineNum++;
        if (empty(array_filter($data))) continue;
        
        $data = array_pad($data, 21, '');
        
        // Validate required
        if (empty($data[0])) {
            fclose($handle);
            die(json_encode(['error' => "Row $lineNum: Project Name required"]));
        }
        if (empty($data[2])) {
            fclose($handle);
            die(json_encode(['error' => "Row $lineNum: Feature Name required"]));
        }
        if (empty($data[3]) || floatval($data[3]) <= 0) {
            fclose($handle);
            die(json_encode(['error' => "Row $lineNum: Org Productivity must be positive"]));
        }
        if (empty($data[4]) || floatval($data[4]) <= 0) {
            fclose($handle);
            die(json_encode(['error' => "Row $lineNum: Man Days Hours must be positive"]));
        }
        if (empty($data[9])) {
            fclose($handle);
            die(json_encode(['error' => "Row $lineNum: Story Title required"]));
        }
        if (empty($data[10]) || floatval($data[10]) <= 0) {
            fclose($handle);
            die(json_encode(['error' => "Row $lineNum: Story Hours must be positive"]));
        }
        
        $rows[] = [
            'projectName' => trim($data[0]),
            'projectDesc' => trim($data[1] ?? ''),
            'featureName' => trim($data[2]),
            'orgProd' => floatval($data[3]),
            'mdHours' => floatval($data[4]),
            'featStart' => empty($data[5]) ? null : $data[5],
            'featEnd' => empty($data[6]) ? null : $data[6],
            'sitDefects' => empty($data[7]) ? 0 : intval($data[7]),
            'uatDefects' => empty($data[8]) ? 0 : intval($data[8]),
            'storyTitle' => trim($data[9]),
            'storyHrsEst' => floatval($data[10]),
            'storyStartEst' => empty($data[11]) ? null : $data[11],
            'storyEndEst' => empty($data[12]) ? null : $data[12],
            'completed' => !empty($data[13]) && (strtoupper(trim($data[13])) === 'TRUE' || trim($data[13]) === '1'),
            'storyHrsAct' => empty($data[14]) ? null : floatval($data[14]),
            'storyStartAct' => empty($data[15]) ? null : $data[15],
            'storyEndAct' => empty($data[16]) ? null : $data[16],
            'reqMD' => empty($data[17]) ? null : floatval($data[17]),
            'desMD' => empty($data[18]) ? null : floatval($data[18]),
            'testMD' => empty($data[19]) ? null : floatval($data[19]),
            'pmMD' => empty($data[20]) ? null : floatval($data[20])
        ];
    }
    fclose($handle);
}

if (empty($rows)) {
    die(json_encode(['error' => 'No data in file']));
}

// Group by project/feature
$projects = [];
foreach ($rows as $row) {
    $pName = $row['projectName'];
    $fName = $row['featureName'];
    
    if (!isset($projects[$pName])) {
        $projects[$pName] = [
            'name' => $pName,
            'desc' => $row['projectDesc'],
            'features' => []
        ];
    }
    
    if (!isset($projects[$pName]['features'][$fName])) {
        $projects[$pName]['features'][$fName] = [
            'name' => $fName,
            'orgProd' => $row['orgProd'],
            'mdHours' => $row['mdHours'],
            'start' => $row['featStart'],
            'end' => $row['featEnd'],
            'sit' => $row['sitDefects'],
            'uat' => $row['uatDefects'],
            'reqMD' => $row['reqMD'],
            'desMD' => $row['desMD'],
            'testMD' => $row['testMD'],
            'pmMD' => $row['pmMD'],
            'stories' => []
        ];
    }
    
    $md = $row['storyHrsEst'] / $row['mdHours'];
    $sp = $md * $row['orgProd'];
    
    $projects[$pName]['features'][$fName]['stories'][] = [
        'title' => $row['storyTitle'],
        'hours' => $row['storyHrsEst'],
        'md' => $md,
        'sp' => $sp,
        'startEst' => $row['storyStartEst'],
        'endEst' => $row['storyEndEst'],
        'done' => $row['completed'],
        'hrsAct' => $row['storyHrsAct'],
        'startAct' => $row['storyStartAct'],
        'endAct' => $row['storyEndAct']
    ];
}

// Insert
$db = getDB();
$db->begin_transaction();

$stats = ['projectsCreated' => 0, 'featuresCreated' => 0, 'storiesCreated' => 0, 'productivityDataCreated' => 0];

try {
    foreach ($projects as $pData) {
        $stmt = $db->prepare("INSERT INTO projects (name, description) VALUES (?, ?)");
        $stmt->bind_param('ss', $pData['name'], $pData['desc']);
        $stmt->execute();
        $projId = $db->insert_id;
        $stmt->close();
        $stats['projectsCreated']++;
        
        foreach ($pData['features'] as $fData) {
            $totSP = 0;
            $totMD = 0;
            $totActDevMD = 0;
            
            foreach ($fData['stories'] as $s) {
                $totSP += $s['sp'];
                $totMD += $s['md'];
                if ($s['hrsAct'] !== null) {
                    $totActDevMD += $s['hrsAct'] / $fData['mdHours'];
                }
            }
            
            $reqMD = $fData['reqMD'] ?? 0;
            $desMD = $fData['desMD'] ?? 0;
            $testMD = $fData['testMD'] ?? 0;
            $pmMD = $fData['pmMD'] ?? 0;
            $totActMD = $reqMD + $desMD + $totActDevMD + $testMD + $pmMD;
            
            $dre = 0;
            $totDef = $fData['sit'] + $fData['uat'];
            if ($totDef > 0) $dre = ($fData['sit'] / $totDef) * 100;
            
            $stmt = $db->prepare("INSERT INTO features (
                project_id, name, org_productivity, man_days_hours,
                total_story_points, total_man_days,
                estimated_start_date, target_end_date,
                sit_defects, uat_defects, defect_removal_efficiency,
                actual_req_man_days, actual_design_man_days, actual_dev_man_days,
                actual_testing_man_days, actual_pm_man_days, actual_total_man_days
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $stmt->bind_param('isddddssiiidddddd',
                $projId, $fData['name'], $fData['orgProd'], $fData['mdHours'],
                $totSP, $totMD, $fData['start'], $fData['end'],
                $fData['sit'], $fData['uat'], $dre,
                $reqMD, $desMD, $totActDevMD, $testMD, $pmMD, $totActMD
            );
            
            $stmt->execute();
            $featId = $db->insert_id;
            $stmt->close();
            $stats['featuresCreated']++;
            
            foreach ($fData['stories'] as $s) {
                $stmt = $db->prepare("INSERT INTO user_stories (
                    feature_id, title, hours, man_days, story_points,
                    estimated_start_date, target_end_date
                ) VALUES (?, ?, ?, ?, ?, ?, ?)");
                
                $stmt->bind_param('isdddss',
                    $featId, $s['title'], $s['hours'], $s['md'], $s['sp'],
                    $s['startEst'], $s['endEst']
                );
                
                $stmt->execute();
                $storyId = $db->insert_id;
                $stmt->close();
                $stats['storiesCreated']++;
                
                if ($s['hrsAct'] !== null) {
                    $effMD = $s['hrsAct'] / $fData['mdHours'];
                    $prod = $effMD > 0 ? $s['sp'] / $effMD : 0;
                    $done = $s['done'] ? 1 : 0;
                    
                    $stmt = $db->prepare("INSERT INTO productivity_data (
                        feature_id, story_id, hours_taken, efforts_man_days,
                        actual_start_date, actual_end_date, is_completed, productivity
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    
                    $stmt->bind_param('iiddssid',
                        $featId, $storyId, $s['hrsAct'], $effMD,
                        $s['startAct'], $s['endAct'], $done, $prod
                    );
                    
                    $stmt->execute();
                    $stmt->close();
                    $stats['productivityDataCreated']++;
                }
            }
        }
    }
    
    $db->commit();
    echo json_encode(['success' => true, 'message' => 'Import successful', 'stats' => $stats]);
    
} catch (Exception $e) {
    $db->rollback();
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}

$db->close();
?>
