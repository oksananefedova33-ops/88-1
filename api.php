<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$db = dirname(dirname(__DIR__)) . '/data/zerro_blog.db';

if(!file_exists($db)) {
    echo json_encode(['ok' => false, 'error' => 'База данных не найдена']);
    exit;
}

try {
    $pdo = new PDO('sqlite:' . $db);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Ошибка подключения к БД']);
    exit;
}

$action = $_POST['action'] ?? '';

if ($action === 'search') {
    $query = trim($_POST['query'] ?? $_POST['url'] ?? '');
    
    if(empty($query)) {
        echo json_encode(['ok' => false, 'error' => 'Поисковый запрос не указан']);
        exit;
    }
    
    $stmt = $pdo->query("SELECT id, name, data_json FROM pages");
    $totalCount = 0;
    $pageCount = 0;
    $details = [];
    $foundFiles = [];
    
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $data = json_decode($row['data_json'], true);
        $count = 0;
        
        foreach($data['elements'] ?? [] as $element) {
            if(strtolower($element['type'] ?? '') === 'filebtn') {
                $fileUrl = $element['fileUrl'] ?? '';
                $fileName = $element['fileName'] ?? '';
                $text = $element['text'] ?? '';
                
                // Поиск по URL, имени файла или тексту кнопки
                if(stripos($fileUrl, $query) !== false || 
                   stripos($fileName, $query) !== false ||
                   stripos($text, $query) !== false) {
                    $count++;
                    $foundFiles[] = [
                        'url' => $fileUrl,
                        'name' => $fileName,
                        'text' => $text
                    ];
                }
            }
        }
        
        if($count > 0) {
            $totalCount += $count;
            $pageCount++;
            $details[] = [
                'page_id' => $row['id'],
                'page_name' => $row['name'],
                'count' => $count
            ];
        }
    }
    
    // Убираем дубликаты файлов
    $uniqueFiles = [];
    $seen = [];
    foreach($foundFiles as $file) {
        $key = $file['url'] . '|' . $file['name'];
        if(!isset($seen[$key])) {
            $seen[$key] = true;
            $uniqueFiles[] = $file;
        }
    }
    
    echo json_encode([
        'ok' => true,
        'count' => $totalCount,
        'pages' => $pageCount,
        'details' => $details,
        'files' => $uniqueFiles
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'replace') {
    $oldUrl = trim($_POST['oldUrl'] ?? '');
    $newUrl = trim($_POST['newUrl'] ?? '');
    $fileName = trim($_POST['fileName'] ?? '');
    $currentPageId = (int)($_POST['current_page'] ?? 0);
    
    if(empty($oldUrl) || empty($newUrl)) {
        echo json_encode(['ok' => false, 'error' => 'URL не указаны']);
        exit;
    }
    
    $replaced = 0;
    $currentPageAffected = false;
    $pdo->beginTransaction();
    
    try {
        $stmt = $pdo->query("SELECT id, data_json FROM pages");
        
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $data = json_decode($row['data_json'], true);
            $changed = false;
            
            for($i = 0; $i < count($data['elements'] ?? []); $i++) {
                if(strtolower($data['elements'][$i]['type'] ?? '') === 'filebtn') {
                    if(($data['elements'][$i]['fileUrl'] ?? '') === $oldUrl) {
                        $data['elements'][$i]['fileUrl'] = $newUrl;
                        if($fileName) {
                            $data['elements'][$i]['fileName'] = $fileName;
                        }
                        $replaced++;
                        $changed = true;
                        
                        if($row['id'] == $currentPageId) {
                            $currentPageAffected = true;
                        }
                    }
                }
            }
            
            if($changed) {
                $updateStmt = $pdo->prepare("UPDATE pages SET data_json = :json WHERE id = :id");
                $updateStmt->execute([
                    ':json' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ':id' => $row['id']
                ]);
            }
        }
        
        $pdo->commit();
        
        echo json_encode([
            'ok' => true,
            'replaced' => $replaced,
            'current_page_affected' => $currentPageAffected
        ], JSON_UNESCAPED_UNICODE);
    } catch(Exception $e) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'error' => 'Ошибка замены: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'list') {
    // Получаем список всех файлов
    $stmt = $pdo->query("SELECT id, name, data_json FROM pages");
    $files = [];
    
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $data = json_decode($row['data_json'], true);
        
        foreach($data['elements'] ?? [] as $element) {
            if(strtolower($element['type'] ?? '') === 'filebtn') {
                $fileUrl = $element['fileUrl'] ?? '';
                $fileName = $element['fileName'] ?? '';
                
                if($fileUrl && $fileUrl !== '#') {
                    $files[] = [
                        'url' => $fileUrl,
                        'name' => $fileName ?: basename($fileUrl),
                        'page_id' => $row['id'],
                        'page_name' => $row['name']
                    ];
                }
            }
        }
    }
    
    // Группируем по уникальным файлам
    $uniqueFiles = [];
    foreach($files as $file) {
        $key = $file['url'];
        if(!isset($uniqueFiles[$key])) {
            $uniqueFiles[$key] = [
                'url' => $file['url'],
                'name' => $file['name'],
                'pages' => []
            ];
        }
        $uniqueFiles[$key]['pages'][] = [
            'id' => $file['page_id'],
            'name' => $file['page_name']
        ];
    }
    
    echo json_encode([
        'ok' => true,
        'files' => array_values($uniqueFiles)
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Неизвестное действие']);