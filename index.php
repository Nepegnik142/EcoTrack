<?php
// =================================================================
// EcoTrack – Полная система с автоустановкой, поддержкой SQLite/MySQL/PostgreSQL
// и автообновлением через SSE + проверка интернета
// Версия для PHP 8.3
// =================================================================

session_start();

// ---------- Загрузка конфигурации ----------
$configFile = __DIR__ . '/config.php';
if (file_exists($configFile)) {
    $config = require $configFile;
} else {
    $config = null;
}

// Если конфига нет – перенаправляем на установку (кроме самого action=setup)
$action = $_GET['action'] ?? 'home';
if (!$config && $action !== 'setup') {
    header('Location: ?action=setup');
    exit;
}

// ---------- Подключение к БД (если конфиг есть) ----------
if ($config) {
    try {
        $dsn = $config['dsn'];
        $user = $config['user'] ?? null;
        $pass = $config['pass'] ?? null;
        $pdo = new PDO($dsn, $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if ($config['driver'] === 'mysql') {
            $pdo->exec("SET NAMES utf8");
        } elseif ($config['driver'] === 'pgsql') {
            $pdo->exec("SET NAMES 'UTF8'");
        } elseif ($config['driver'] === 'sqlite') {
            $pdo->exec("PRAGMA foreign_keys = ON");
        }
    } catch (PDOException $e) {
        die('Ошибка подключения к базе данных: ' . $e->getMessage());
    }
} else {
    $pdo = null;
}

// ---------- Вспомогательные функции ----------
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function getUser($pdo, $id) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getEmissionFactor($type, $subtype) {
    $factors = [
        'travel' => ['car' => 0.21, 'bus' => 0.05, 'train' => 0.03, 'bike' => 0.0, 'walk' => 0.0],
        'diet'   => ['meat' => 6.0, 'vegetarian' => 2.0, 'vegan' => 1.0],
        'energy' => ['electricity' => 0.5],
    ];
    return $factors[$type][$subtype] ?? 0;
}

function calculateTotalFootprint($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT type, subtype, value FROM activities WHERE user_id = ?");
    $stmt->execute([$userId]);
    $total = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $total += $row['value'] * getEmissionFactor($row['type'], $row['subtype']);
    }
    return $total;
}

function getActivitiesByType($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT type, subtype, value FROM activities WHERE user_id = ?");
    $stmt->execute([$userId]);
    $breakdown = ['travel' => 0, 'diet' => 0, 'energy' => 0];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $breakdown[$row['type']] += $row['value'] * getEmissionFactor($row['type'], $row['subtype']);
    }
    return $breakdown;
}

function getPersonalizedTips($pdo, $userId) {
    $breakdown = getActivitiesByType($pdo, $userId);
    arsort($breakdown);
    $topCategory = key($breakdown);
    if ($breakdown[$topCategory] == 0) $topCategory = 'general';
    $stmt = $pdo->prepare("SELECT * FROM tips WHERE category = ? OR category = 'general' ORDER BY RANDOM() LIMIT 3");
    $stmt->execute([$topCategory]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Обновление времени последнего изменения данных пользователя
function setUserUpdate($pdo, $userId) {
    $now = time();
    $stmt = $pdo->prepare("INSERT INTO user_updates (user_id, last_update) VALUES (?, ?) 
                           ON CONFLICT(user_id) DO UPDATE SET last_update = ?");
    $stmt->execute([$userId, $now, $now]);
}

// Получение времени последнего обновления
function getUserUpdate($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT last_update FROM user_updates WHERE user_id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['last_update'] : 0;
}

// ---------- Обработка POST-запросов ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- Установка (создание конфига и БД) ---
    if ($action === 'setup') {
        $driver = $_POST['driver'] ?? '';
        $adminUser = trim($_POST['admin_user'] ?? '');
        $adminPass = $_POST['admin_pass'] ?? '';
        $adminConfirm = $_POST['admin_confirm'] ?? '';

        $errors = [];
        if (!$driver || !in_array($driver, ['sqlite', 'mysql', 'pgsql'])) {
            $errors[] = 'Выберите тип базы данных.';
        }
        if (strlen($adminUser) < 3) {
            $errors[] = 'Имя администратора должно содержать минимум 3 символа.';
        }
        if (strlen($adminPass) < 6) {
            $errors[] = 'Пароль должен содержать минимум 6 символов.';
        }
        if ($adminPass !== $adminConfirm) {
            $errors[] = 'Пароли не совпадают.';
        }

        $dbParams = [];
        if ($driver === 'sqlite') {
            $dbPath = trim($_POST['sqlite_path'] ?? '');
            if (empty($dbPath)) {
                $errors[] = 'Укажите путь к файлу SQLite.';
            } else {
                $dir = dirname($dbPath);
                if (!is_writable($dir)) {
                    $errors[] = "Папка '$dir' недоступна для записи.";
                }
                $dbParams['path'] = $dbPath;
                $dsn = "sqlite:$dbPath";
                $user = null;
                $pass = null;
            }
        } elseif ($driver === 'mysql' || $driver === 'pgsql') {
            $host = trim($_POST['host'] ?? '');
            $dbname = trim($_POST['dbname'] ?? '');
            $user = trim($_POST['user'] ?? '');
            $pass = trim($_POST['pass'] ?? '');
            if (!$host || !$dbname) {
                $errors[] = 'Заполните хост и имя базы данных.';
            }
            if ($driver === 'mysql') {
                $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8";
            } else {
                $dsn = "pgsql:host=$host;dbname=$dbname";
            }
            $dbParams = ['host' => $host, 'dbname' => $dbname, 'user' => $user, 'pass' => $pass];
        }

        if (empty($errors)) {
            try {
                $testPdo = new PDO($dsn, $user, $pass);
                $testPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $sqlQueries = getCreateTableQueries($driver);
                foreach ($sqlQueries as $query) {
                    $testPdo->exec($query);
                }
                insertDefaultData($testPdo, $driver);
                $hash = password_hash($adminPass, PASSWORD_DEFAULT);
                $stmt = $testPdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'admin')");
                $stmt->execute([$adminUser, $hash]);
                $userId = $testPdo->lastInsertId();
                // Создаём запись в user_updates
                $testPdo->prepare("INSERT INTO user_updates (user_id, last_update) VALUES (?, ?)")->execute([$userId, time()]);

                $configData = [
                    'driver' => $driver,
                    'dsn'    => $dsn,
                    'user'   => $user,
                    'pass'   => $pass,
                ];
                if ($driver === 'sqlite') {
                    $configData['path'] = $dbPath;
                } else {
                    $configData['host'] = $host;
                    $configData['dbname'] = $dbname;
                }
                $configContent = "<?php\nreturn " . var_export($configData, true) . ";\n";
                file_put_contents($configFile, $configContent);

                $_SESSION['user_id'] = $userId;
                $_SESSION['username'] = $adminUser;
                $_SESSION['role'] = 'admin';
                header('Location: ?action=home');
                exit;
            } catch (PDOException $e) {
                $errors[] = 'Ошибка базы данных: ' . $e->getMessage();
            }
        }
        $setupError = $errors;
    }

    // --- Остальные действия (если есть PDO и пользователь залогинен) ---
    if (isset($pdo) && isLoggedIn()) {
        $userId = $_SESSION['user_id'];

        if ($action === 'add_activity') {
            $type = $_POST['type'] ?? '';
            $subtype = $_POST['subtype'] ?? '';
            $value = (float)($_POST['value'] ?? 0);
            if ($type && $subtype && $value > 0) {
                $stmt = $pdo->prepare("INSERT INTO activities (user_id, type, subtype, value) VALUES (?, ?, ?, ?)");
                $stmt->execute([$userId, $type, $subtype, $value]);
                setUserUpdate($pdo, $userId);
                $_SESSION['message'] = 'Активность добавлена!';
            } else {
                $error = 'Заполните все поля положительным числом.';
            }
            header('Location: ?action=add');
            exit;
        } elseif ($action === 'update_challenge') {
            $challengeId = (int)$_POST['challenge_id'];
            $progress = (float)$_POST['progress'];
            $stmt = $pdo->prepare("SELECT target FROM challenges WHERE id = ?");
            $stmt->execute([$challengeId]);
            $target = $stmt->fetchColumn();
            if ($target !== false) {
                $completed = ($progress >= $target) ? 1 : 0;
                $stmt = $pdo->prepare("INSERT INTO user_challenges (user_id, challenge_id, progress, completed) 
                                       VALUES (?, ?, ?, ?) 
                                       ON CONFLICT(user_id, challenge_id) 
                                       DO UPDATE SET progress = excluded.progress, completed = excluded.completed");
                $stmt->execute([$userId, $challengeId, $progress, $completed]);
                setUserUpdate($pdo, $userId);
                $_SESSION['message'] = 'Прогресс челленджа обновлён!';
            }
            header('Location: ?action=challenges');
            exit;
        } elseif ($action === 'join_challenge') {
            $challengeId = (int)$_POST['challenge_id'];
            $stmt = $pdo->prepare("SELECT id FROM user_challenges WHERE user_id = ? AND challenge_id = ?");
            $stmt->execute([$userId, $challengeId]);
            if (!$stmt->fetch()) {
                $stmt = $pdo->prepare("INSERT INTO user_challenges (user_id, challenge_id, progress) VALUES (?, ?, 0)");
                $stmt->execute([$userId, $challengeId]);
                setUserUpdate($pdo, $userId);
                $_SESSION['message'] = 'Вы присоединились к челленджу!';
            } else {
                $_SESSION['message'] = 'Вы уже участвуете в этом челлендже.';
            }
            header('Location: ?action=challenges');
            exit;
        }
    }

    // --- Административные действия ---
    if (isset($pdo) && isAdmin()) {
        if ($action === 'admin_tip_add') {
            $category = $_POST['category'] ?? '';
            $tip = trim($_POST['tip'] ?? '');
            if ($category && $tip) {
                $stmt = $pdo->prepare("INSERT INTO tips (category, tip) VALUES (?, ?)");
                $stmt->execute([$category, $tip]);
                $_SESSION['message'] = 'Совет добавлен.';
            } else {
                $_SESSION['error'] = 'Заполните все поля.';
            }
            header('Location: ?action=admin_tips');
            exit;
        } elseif ($action === 'admin_tip_edit') {
            $id = (int)$_POST['id'];
            $category = $_POST['category'] ?? '';
            $tip = trim($_POST['tip'] ?? '');
            if ($id && $category && $tip) {
                $stmt = $pdo->prepare("UPDATE tips SET category = ?, tip = ? WHERE id = ?");
                $stmt->execute([$category, $tip, $id]);
                $_SESSION['message'] = 'Совет обновлён.';
            } else {
                $_SESSION['error'] = 'Неверные данные.';
            }
            header('Location: ?action=admin_tips');
            exit;
        } elseif ($action === 'admin_tip_delete') {
            $id = (int)$_POST['id'];
            $stmt = $pdo->prepare("DELETE FROM tips WHERE id = ?");
            $stmt->execute([$id]);
            $_SESSION['message'] = 'Совет удалён.';
            header('Location: ?action=admin_tips');
            exit;
        }

        if ($action === 'admin_challenge_add') {
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $category = $_POST['category'] ?? '';
            $target = (float)($_POST['target'] ?? 0);
            $unit = trim($_POST['unit'] ?? '');
            if ($name && $description && $category && $target > 0 && $unit) {
                $stmt = $pdo->prepare("INSERT INTO challenges (name, description, category, target, unit) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$name, $description, $category, $target, $unit]);
                $_SESSION['message'] = 'Челлендж добавлен.';
            } else {
                $_SESSION['error'] = 'Заполните все поля корректно.';
            }
            header('Location: ?action=admin_challenges');
            exit;
        } elseif ($action === 'admin_challenge_edit') {
            $id = (int)$_POST['id'];
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $category = $_POST['category'] ?? '';
            $target = (float)($_POST['target'] ?? 0);
            $unit = trim($_POST['unit'] ?? '');
            if ($id && $name && $description && $category && $target > 0 && $unit) {
                $stmt = $pdo->prepare("UPDATE challenges SET name = ?, description = ?, category = ?, target = ?, unit = ? WHERE id = ?");
                $stmt->execute([$name, $description, $category, $target, $unit, $id]);
                $_SESSION['message'] = 'Челлендж обновлён.';
            } else {
                $_SESSION['error'] = 'Неверные данные.';
            }
            header('Location: ?action=admin_challenges');
            exit;
        } elseif ($action === 'admin_challenge_delete') {
            $id = (int)$_POST['id'];
            $stmt = $pdo->prepare("DELETE FROM challenges WHERE id = ?");
            $stmt->execute([$id]);
            $_SESSION['message'] = 'Челлендж удалён.';
            header('Location: ?action=admin_challenges');
            exit;
        }

        if ($action === 'admin_user_delete') {
            $id = (int)$_POST['id'];
            if ($id != $_SESSION['user_id']) {
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$id]);
                $_SESSION['message'] = 'Пользователь удалён.';
            } else {
                $_SESSION['error'] = 'Нельзя удалить самого себя.';
            }
            header('Location: ?action=admin_users');
            exit;
        }
    }

    // --- Вход и регистрация ---
    if (isset($pdo)) {
        if ($action === 'register') {
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            if ($username && $password) {
                $userCount = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
                $role = ($userCount == 0) ? 'admin' : 'user';
                $hash = password_hash($password, PASSWORD_DEFAULT);
                try {
                    $stmt = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
                    $stmt->execute([$username, $hash, $role]);
                    $_SESSION['message'] = 'Регистрация успешна. Теперь войдите.';
                    header('Location: ?action=login');
                    exit;
                } catch (PDOException $e) {
                    $error = 'Пользователь с таким именем уже существует.';
                }
            } else {
                $error = 'Заполните все поля.';
            }
        } elseif ($action === 'login') {
            $username = $_POST['username'] ?? '';
            $password = $_POST['password'] ?? '';
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                // Создаём запись в user_updates, если её нет
                $pdo->prepare("INSERT OR IGNORE INTO user_updates (user_id, last_update) VALUES (?, ?)")
                    ->execute([$user['id'], time()]);
                header('Location: ?action=home');
                exit;
            } else {
                $error = 'Неверное имя пользователя или пароль.';
            }
        } elseif ($action === 'logout') {
            session_destroy();
            header('Location: ?action=home');
            exit;
        }
    }
}

// ---------- Функции для создания таблиц в разных СУБД ----------
function getCreateTableQueries($driver) {
    $queries = [];
    if ($driver === 'sqlite') {
        $queries[] = "
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE NOT NULL,
                password TEXT NOT NULL,
                role TEXT DEFAULT 'user',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )";
        $queries[] = "
            CREATE TABLE IF NOT EXISTS activities (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                type TEXT NOT NULL,
                subtype TEXT NOT NULL,
                value REAL NOT NULL,
                date DATE DEFAULT CURRENT_DATE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )";
        $queries[] = "
            CREATE TABLE IF NOT EXISTS tips (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                category TEXT NOT NULL,
                tip TEXT NOT NULL
            )";
        $queries[] = "
            CREATE TABLE IF NOT EXISTS challenges (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                description TEXT NOT NULL,
                category TEXT NOT NULL,
                target REAL NOT NULL,
                unit TEXT NOT NULL
            )";
        $queries[] = "
            CREATE TABLE IF NOT EXISTS user_challenges (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                challenge_id INTEGER NOT NULL,
                progress REAL DEFAULT 0,
                completed BOOLEAN DEFAULT 0,
                started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (challenge_id) REFERENCES challenges(id) ON DELETE CASCADE,
                UNIQUE(user_id, challenge_id)
            )";
        $queries[] = "
            CREATE TABLE IF NOT EXISTS user_updates (
                user_id INTEGER PRIMARY KEY,
                last_update INTEGER DEFAULT 0,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )";
    } elseif ($driver === 'mysql') {
        $queries[] = "
            CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(255) UNIQUE NOT NULL,
                password VARCHAR(255) NOT NULL,
                role VARCHAR(50) DEFAULT 'user',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8";
        $queries[] = "
            CREATE TABLE IF NOT EXISTS activities (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                type VARCHAR(50) NOT NULL,
                subtype VARCHAR(50) NOT NULL,
                value DECIMAL(10,2) NOT NULL,
                date DATE DEFAULT CURRENT_DATE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8";
        $queries[] = "
            CREATE TABLE IF NOT EXISTS tips (
                id INT AUTO_INCREMENT PRIMARY KEY,
                category VARCHAR(50) NOT NULL,
                tip TEXT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8";
        $queries[] = "
            CREATE TABLE IF NOT EXISTS challenges (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                description TEXT NOT NULL,
                category VARCHAR(50) NOT NULL,
                target DECIMAL(10,2) NOT NULL,
                unit VARCHAR(50) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8";
        $queries[] = "
            CREATE TABLE IF NOT EXISTS user_challenges (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                challenge_id INT NOT NULL,
                progress DECIMAL(10,2) DEFAULT 0,
                completed TINYINT(1) DEFAULT 0,
                started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (challenge_id) REFERENCES challenges(id) ON DELETE CASCADE,
                UNIQUE KEY unique_user_challenge (user_id, challenge_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8";
        $queries[] = "
            CREATE TABLE IF NOT EXISTS user_updates (
                user_id INT PRIMARY KEY,
                last_update INT DEFAULT 0,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8";
    } elseif ($driver === 'pgsql') {
        $queries[] = "
            CREATE TABLE IF NOT EXISTS users (
                id SERIAL PRIMARY KEY,
                username VARCHAR(255) UNIQUE NOT NULL,
                password VARCHAR(255) NOT NULL,
                role VARCHAR(50) DEFAULT 'user',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )";
        $queries[] = "
            CREATE TABLE IF NOT EXISTS activities (
                id SERIAL PRIMARY KEY,
                user_id INT NOT NULL,
                type VARCHAR(50) NOT NULL,
                subtype VARCHAR(50) NOT NULL,
                value DECIMAL(10,2) NOT NULL,
                date DATE DEFAULT CURRENT_DATE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )";
        $queries[] = "
            CREATE TABLE IF NOT EXISTS tips (
                id SERIAL PRIMARY KEY,
                category VARCHAR(50) NOT NULL,
                tip TEXT NOT NULL
            )";
        $queries[] = "
            CREATE TABLE IF NOT EXISTS challenges (
                id SERIAL PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                description TEXT NOT NULL,
                category VARCHAR(50) NOT NULL,
                target DECIMAL(10,2) NOT NULL,
                unit VARCHAR(50) NOT NULL
            )";
        $queries[] = "
            CREATE TABLE IF NOT EXISTS user_challenges (
                id SERIAL PRIMARY KEY,
                user_id INT NOT NULL,
                challenge_id INT NOT NULL,
                progress DECIMAL(10,2) DEFAULT 0,
                completed SMALLINT DEFAULT 0,
                started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (challenge_id) REFERENCES challenges(id) ON DELETE CASCADE,
                UNIQUE(user_id, challenge_id)
            )";
        $queries[] = "
            CREATE TABLE IF NOT EXISTS user_updates (
                user_id INT PRIMARY KEY,
                last_update INT DEFAULT 0,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )";
    }
    return $queries;
}

function insertDefaultData($pdo, $driver) {
    // Советы
    $tips = [
        ['travel', 'Ходите пешком или ездите на велосипеде на короткие расстояния.'],
        ['travel', 'Пользуйтесь общественным транспортом.'],
        ['travel', 'Делите поездки с коллегами (карпулинг).'],
        ['diet', 'Сократите потребление мяса – устройте "безмясной" понедельник.'],
        ['diet', 'Выбирайте местные продукты – меньше выбросов от транспорта.'],
        ['diet', 'Планируйте питание, чтобы избегать пищевых отходов.'],
        ['energy', 'Перейдите на светодиодные лампы – экономят 75% энергии.'],
        ['energy', 'Отключайте технику от розетки, когда не используете.'],
        ['energy', 'Используйте программируемый термостат для экономии.'],
        ['general', 'Посадите дерево – оно поглощает CO₂ и даёт тень.'],
        ['general', 'Поддерживайте возобновляемые источники энергии.'],
        ['general', 'Покупайте вещи б/у – снижайте производственные выбросы.'],
    ];
    $stmt = $pdo->prepare("INSERT INTO tips (category, tip) VALUES (?, ?)");
    foreach ($tips as $t) {
        $stmt->execute($t);
    }

    // Челленджи
    $challenges = [
        ['Пройдите 5 км пешком вместо автомобиля', 'Замените 5 км поездок на автомобиле на ходьбу.', 'travel', 5, 'км'],
        ['Три дня без мяса', 'Питайтесь вегетарианской пищей 3 дня на этой неделе.', 'diet', 3, 'порций'],
        ['Сэкономьте 10 кВт·ч электроэнергии', 'Снизьте ежедневное потребление электроэнергии на 10 кВт·ч.', 'energy', 10, 'кВт·ч'],
        ['Неделя на велосипеде', 'Ездите на работу на велосипеде 5 дней.', 'travel', 5, 'дней'],
        ['Попробуйте веганское блюдо', 'Съешьте хотя бы одно веганское блюдо за неделю.', 'diet', 1, 'блюдо'],
    ];
    $stmt = $pdo->prepare("INSERT INTO challenges (name, description, category, target, unit) VALUES (?, ?, ?, ?, ?)");
    foreach ($challenges as $c) {
        $stmt->execute($c);
    }
}

// ---------- Обработка SSE (Server-Sent Events) ----------
if ($action === 'sse') {
    if (!isset($pdo) || !isLoggedIn()) {
        header('HTTP/1.1 403 Forbidden');
        exit;
    }
    $userId = $_SESSION['user_id'];
    // Закрываем сессию, чтобы не блокировать другие запросы
    session_write_close();

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no'); // Отключаем буферизацию nginx

    $lastUpdate = getUserUpdate($pdo, $userId);
    while (true) {
        // Проверяем, не изменились ли данные
        $current = getUserUpdate($pdo, $userId);
        if ($current !== $lastUpdate) {
            echo "event: update\n";
            echo "data: " . json_encode(['time' => $current]) . "\n\n";
            ob_flush();
            flush();
            $lastUpdate = $current;
        }
        // Ждём 1 секунду
        sleep(1);
        // Проверяем, не завершено ли соединение
        if (connection_aborted()) {
            break;
        }
    }
    exit;
}

// ---------- Генерация страниц (GET) ----------
$pageTitle = 'EcoTrack';
$content = '';

// Если конфига нет и мы на установке
if (!$config && $action === 'setup') {
    $pageTitle = 'Установка EcoTrack';
    ob_start();
    ?>
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h3 class="mb-0">🌱 Установка EcoTrack</h3>
                </div>
                <div class="card-body">
                    <p>Выберите тип базы данных и заполните параметры подключения. После установки будет создан администратор.</p>
                    <?php if (!empty($setupError)): ?>
                        <div class="alert alert-danger">
                            <?php foreach ($setupError as $err) echo htmlspecialchars($err) . '<br>'; ?>
                        </div>
                    <?php endif; ?>
                    <form method="post" id="setupForm">
                        <div class="mb-3">
                            <label for="driver" class="form-label">Тип базы данных</label>
                            <select class="form-select" id="driver" name="driver" required onchange="toggleDbFields()">
                                <option value="sqlite" selected>SQLite (встроенная)</option>
                                <option value="mysql">MySQL / MariaDB</option>
                                <option value="pgsql">PostgreSQL</option>
                            </select>
                        </div>

                        <!-- SQLite -->
                        <div id="sqliteFields">
                            <div class="mb-3">
                                <label for="sqlite_path" class="form-label">Путь к файлу SQLite</label>
                                <input type="text" class="form-control" id="sqlite_path" name="sqlite_path" value="<?= __DIR__ . '/ecotrack.db' ?>" required>
                                <small class="text-muted">Папка должна быть доступна для записи.</small>
                            </div>
                        </div>

                        <!-- MySQL / PostgreSQL -->
                        <div id="sqlDbFields" style="display:none;">
                            <div class="mb-3">
                                <label for="host" class="form-label">Хост</label>
                                <input type="text" class="form-control" id="host" name="host" value="localhost">
                            </div>
                            <div class="mb-3">
                                <label for="dbname" class="form-label">Имя базы данных</label>
                                <input type="text" class="form-control" id="dbname" name="dbname" required>
                            </div>
                            <div class="mb-3">
                                <label for="user" class="form-label">Пользователь БД</label>
                                <input type="text" class="form-control" id="user" name="user" required>
                            </div>
                            <div class="mb-3">
                                <label for="pass" class="form-label">Пароль БД</label>
                                <input type="password" class="form-control" id="pass" name="pass">
                            </div>
                        </div>

                        <hr>
                        <h5>Учётная запись администратора</h5>
                        <div class="mb-3">
                            <label for="admin_user" class="form-label">Имя администратора</label>
                            <input type="text" class="form-control" id="admin_user" name="admin_user" required minlength="3">
                        </div>
                        <div class="mb-3">
                            <label for="admin_pass" class="form-label">Пароль (минимум 6 символов)</label>
                            <input type="password" class="form-control" id="admin_pass" name="admin_pass" required minlength="6">
                        </div>
                        <div class="mb-3">
                            <label for="admin_confirm" class="form-label">Подтвердите пароль</label>
                            <input type="password" class="form-control" id="admin_confirm" name="admin_confirm" required>
                        </div>

                        <button type="submit" class="btn btn-success">Установить</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <script>
        function toggleDbFields() {
            const driver = document.getElementById('driver').value;
            document.getElementById('sqliteFields').style.display = (driver === 'sqlite') ? 'block' : 'none';
            document.getElementById('sqlDbFields').style.display = (driver !== 'sqlite') ? 'block' : 'none';
            document.getElementById('sqlite_path').required = (driver === 'sqlite');
            document.getElementById('host').required = (driver !== 'sqlite');
            document.getElementById('dbname').required = (driver !== 'sqlite');
            document.getElementById('user').required = (driver !== 'sqlite');
        }
        document.addEventListener('DOMContentLoaded', toggleDbFields);
    </script>
    <?php
    $content = ob_get_clean();
} else {
    switch ($action) {
        case 'home':
            $pageTitle = 'Главная - EcoTrack';
            ob_start();
            if (isLoggedIn()) {
                $userId = $_SESSION['user_id'];
                $totalFootprint = calculateTotalFootprint($pdo, $userId);
                $breakdown = getActivitiesByType($pdo, $userId);
                $tips = getPersonalizedTips($pdo, $userId);
                ?>
                <div class="jumbotron">
                    <h1 class="display-4">Привет, <?= htmlspecialchars($_SESSION['username']) ?>!</h1>
                    <p class="lead">Ваш текущий углеродный след: <strong><?= number_format($totalFootprint, 2) ?> кг CO₂</strong></p>
                    <hr class="my-4">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="card text-white bg-primary mb-3">
                                <div class="card-header">Транспорт</div>
                                <div class="card-body"><h5><?= number_format($breakdown['travel'], 2) ?> кг</h5></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card text-white bg-success mb-3">
                                <div class="card-header">Питание</div>
                                <div class="card-body"><h5><?= number_format($breakdown['diet'], 2) ?> кг</h5></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card text-white bg-warning mb-3">
                                <div class="card-header">Энергия</div>
                                <div class="card-body"><h5><?= number_format($breakdown['energy'], 2) ?> кг</h5></div>
                            </div>
                        </div>
                    </div>
                    <h3>💡 Советы для вас</h3>
                    <ul class="list-group">
                        <?php foreach ($tips as $tip): ?>
                            <li class="list-group-item"><?= htmlspecialchars($tip['tip']) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <a class="btn btn-primary btn-lg mt-3" href="?action=add">Добавить активность</a>
                </div>
                <?php
            } else {
                ?>
                <div class="jumbotron">
                    <h1 class="display-4">EcoTrack</h1>
                    <p class="lead">Отслеживайте свои ежедневные привычки и снижайте экологический след.</p>
                    <hr class="my-4">
                    <p>Присоединяйтесь к сообществу и начните делать зелёный выбор уже сегодня!</p>
                    <a class="btn btn-primary btn-lg" href="?action=register">Регистрация</a>
                    <a class="btn btn-outline-secondary btn-lg" href="?action=login">Вход</a>
                </div>
                <?php
            }
            $content = ob_get_clean();
            break;

        case 'login':
            $pageTitle = 'Вход - EcoTrack';
            ob_start();
            ?>
            <h2>Вход</h2>
            <?php if (isset($error)) echo '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>'; ?>
            <form method="post">
                <div class="mb-3"><label for="username" class="form-label">Имя пользователя</label><input type="text" class="form-control" id="username" name="username" required></div>
                <div class="mb-3"><label for="password" class="form-label">Пароль</label><input type="password" class="form-control" id="password" name="password" required></div>
                <button type="submit" class="btn btn-primary">Войти</button>
                <a href="?action=register" class="btn btn-link">Регистрация</a>
            </form>
            <?php
            $content = ob_get_clean();
            break;

        case 'register':
            $pageTitle = 'Регистрация - EcoTrack';
            ob_start();
            ?>
            <h2>Регистрация</h2>
            <?php if (isset($error)) echo '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>'; ?>
            <form method="post">
                <div class="mb-3"><label for="username" class="form-label">Имя пользователя</label><input type="text" class="form-control" id="username" name="username" required></div>
                <div class="mb-3"><label for="password" class="form-label">Пароль</label><input type="password" class="form-control" id="password" name="password" required></div>
                <button type="submit" class="btn btn-primary">Зарегистрироваться</button>
                <a href="?action=login" class="btn btn-link">Уже есть аккаунт?</a>
            </form>
            <?php
            $content = ob_get_clean();
            break;

        case 'add':
            if (!isLoggedIn()) { header('Location: ?action=login'); exit; }
            $pageTitle = 'Добавить активность - EcoTrack';
            ob_start();
            if (isset($_SESSION['message'])) { echo '<div class="alert alert-success">' . htmlspecialchars($_SESSION['message']) . '</div>'; unset($_SESSION['message']); }
            ?>
            <h2>Добавить активность</h2>
            <form method="post">
                <div class="mb-3">
                    <label for="type" class="form-label">Тип</label>
                    <select class="form-select" id="type" name="type" required onchange="updateSubtypes()">
                        <option value="travel">Транспорт</option>
                        <option value="diet">Питание</option>
                        <option value="energy">Энергия</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label for="subtype" class="form-label">Подтип</label>
                    <select class="form-select" id="subtype" name="subtype" required></select>
                </div>
                <div class="mb-3">
                    <label for="value" class="form-label">Значение (км, порций, кВт·ч и т.д.)</label>
                    <input type="number" step="0.01" class="form-control" id="value" name="value" required>
                </div>
                <button type="submit" class="btn btn-primary">Добавить</button>
                <a href="?action=home" class="btn btn-secondary">Назад</a>
            </form>
            <script>
                const subtypeOptions = {
                    travel: ['car', 'bus', 'train', 'bike', 'walk'],
                    diet: ['meat', 'vegetarian', 'vegan'],
                    energy: ['electricity']
                };
                function updateSubtypes() {
                    const type = document.getElementById('type').value;
                    const sel = document.getElementById('subtype');
                    sel.innerHTML = '';
                    subtypeOptions[type].forEach(sub => {
                        const opt = document.createElement('option');
                        opt.value = sub;
                        opt.textContent = sub.charAt(0).toUpperCase() + sub.slice(1);
                        sel.appendChild(opt);
                    });
                }
                document.addEventListener('DOMContentLoaded', updateSubtypes);
            </script>
            <?php
            $content = ob_get_clean();
            break;

        case 'dashboard':
            if (!isLoggedIn()) { header('Location: ?action=login'); exit; }
            $pageTitle = 'Панель управления - EcoTrack';
            $userId = $_SESSION['user_id'];
            $total = calculateTotalFootprint($pdo, $userId);
            $breakdown = getActivitiesByType($pdo, $userId);
            $stmt = $pdo->prepare("SELECT type, subtype, value, date FROM activities WHERE user_id = ? ORDER BY date DESC LIMIT 10");
            $stmt->execute([$userId]);
            $recent = $stmt->fetchAll(PDO::FETCH_ASSOC);
            ob_start();
            ?>
            <h2>Ваша панель</h2>
            <div class="row">
                <div class="col-md-6">
                    <div class="card"><div class="card-body"><h5>Общий след</h5><p class="display-4"><?= number_format($total, 2) ?> кг CO₂</p></div></div>
                </div>
                <div class="col-md-6">
                    <div class="card"><div class="card-body"><h5>Разбивка</h5>
                        <ul class="list-group">
                            <li class="list-group-item d-flex justify-content-between"><span>Транспорт</span><span class="badge bg-primary"><?= number_format($breakdown['travel'], 2) ?> кг</span></li>
                            <li class="list-group-item d-flex justify-content-between"><span>Питание</span><span class="badge bg-success"><?= number_format($breakdown['diet'], 2) ?> кг</span></li>
                            <li class="list-group-item d-flex justify-content-between"><span>Энергия</span><span class="badge bg-warning"><?= number_format($breakdown['energy'], 2) ?> кг</span></li>
                        </ul>
                    </div></div>
                </div>
            </div>
            <h3 class="mt-4">Последние активности</h3>
            <table class="table table-striped">
                <thead><tr><th>Тип</th><th>Подтип</th><th>Значение</th><th>Дата</th></tr></thead>
                <tbody>
                    <?php foreach ($recent as $act): ?>
                        <tr><td><?= htmlspecialchars($act['type']) ?></td><td><?= htmlspecialchars($act['subtype']) ?></td><td><?= htmlspecialchars($act['value']) ?></td><td><?= htmlspecialchars($act['date']) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (empty($recent)) echo '<tr><td colspan="4">Нет активностей.</td></tr>'; ?>
                </tbody>
            </table>
            <a href="?action=add" class="btn btn-primary">Добавить</a>
            <?php
            $content = ob_get_clean();
            break;

        case 'tips':
            if (!isLoggedIn()) { header('Location: ?action=login'); exit; }
            $pageTitle = 'Советы - EcoTrack';
            $stmt = $pdo->query("SELECT category, tip FROM tips ORDER BY category");
            $tips = $stmt->fetchAll(PDO::FETCH_ASSOC);
            ob_start();
            ?>
            <h2>Зелёные советы</h2>
            <div class="accordion" id="tipsAccordion">
                <?php foreach (['travel','diet','energy','general'] as $cat):
                    $catTips = array_filter($tips, fn($t) => $t['category'] === $cat);
                    if (empty($catTips)) continue; ?>
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="heading<?= $cat ?>">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?= $cat ?>" aria-expanded="false" aria-controls="collapse<?= $cat ?>">
                                <?= ucfirst($cat) ?>
                            </button>
                        </h2>
                        <div id="collapse<?= $cat ?>" class="accordion-collapse collapse" aria-labelledby="heading<?= $cat ?>" data-bs-parent="#tipsAccordion">
                            <div class="accordion-body"><ul class="list-group">
                                <?php foreach ($catTips as $t): ?><li class="list-group-item"><?= htmlspecialchars($t['tip']) ?></li><?php endforeach; ?>
                            </ul></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php
            $content = ob_get_clean();
            break;

        case 'challenges':
            if (!isLoggedIn()) { header('Location: ?action=login'); exit; }
            $pageTitle = 'Челленджи - EcoTrack';
            $userId = $_SESSION['user_id'];
            $stmt = $pdo->prepare("
                SELECT c.*, uc.progress, uc.completed, uc.id as uc_id 
                FROM challenges c
                LEFT JOIN user_challenges uc ON c.id = uc.challenge_id AND uc.user_id = ?
            ");
            $stmt->execute([$userId]);
            $challenges = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (isset($_SESSION['message'])) { echo '<div class="alert alert-success">' . htmlspecialchars($_SESSION['message']) . '</div>'; unset($_SESSION['message']); }
            ob_start();
            ?>
            <h2>Эко-челленджи</h2>
            <div class="row">
                <?php foreach ($challenges as $ch): ?>
                    <div class="col-md-6 col-lg-4 mb-4">
                        <div class="card h-100">
                            <div class="card-body">
                                <h5 class="card-title"><?= htmlspecialchars($ch['name']) ?></h5>
                                <p class="card-text"><?= htmlspecialchars($ch['description']) ?></p>
                                <p><strong>Цель:</strong> <?= $ch['target'] ?> <?= $ch['unit'] ?></p>
                                <?php if ($ch['progress'] !== null): ?>
                                    <p><strong>Прогресс:</strong> <?= $ch['progress'] ?> / <?= $ch['target'] ?> <?= $ch['unit'] ?></p>
                                    <div class="progress mb-2">
                                        <div class="progress-bar <?= $ch['completed'] ? 'bg-success' : 'bg-info' ?>" 
                                             role="progressbar" 
                                             style="width: <?= min(100, ($ch['progress'] / $ch['target']) * 100) ?>%">
                                        </div>
                                    </div>
                                    <?php if ($ch['completed']): ?>
                                        <span class="badge bg-success">Выполнено!</span>
                                    <?php else: ?>
                                        <form method="post" class="mt-2">
                                            <input type="hidden" name="challenge_id" value="<?= $ch['id'] ?>">
                                            <div class="input-group">
                                                <input type="number" step="0.01" class="form-control" name="progress" placeholder="Обновить прогресс" required>
                                                <button type="submit" class="btn btn-outline-primary" name="action" value="update_challenge">Обновить</button>
                                            </div>
                                        </form>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <form method="post">
                                        <input type="hidden" name="challenge_id" value="<?= $ch['id'] ?>">
                                        <button type="submit" class="btn btn-success" name="action" value="join_challenge">Присоединиться</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php
            $content = ob_get_clean();
            break;

        case 'admin':
            if (!isAdmin()) { header('Location: ?action=home'); exit; }
            $pageTitle = 'Админ-панель - EcoTrack';
            $totalUsers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            $totalActivities = $pdo->query("SELECT COUNT(*) FROM activities")->fetchColumn();
            $avgFootprint = $pdo->query("SELECT AVG(
                (SELECT SUM(value * (
                    CASE type
                        WHEN 'travel' THEN CASE subtype WHEN 'car' THEN 0.21 WHEN 'bus' THEN 0.05 WHEN 'train' THEN 0.03 WHEN 'bike' THEN 0 WHEN 'walk' THEN 0 END
                        WHEN 'diet' THEN CASE subtype WHEN 'meat' THEN 6.0 WHEN 'vegetarian' THEN 2.0 WHEN 'vegan' THEN 1.0 END
                        WHEN 'energy' THEN CASE subtype WHEN 'electricity' THEN 0.5 END
                    END
                )) FROM activities WHERE user_id = users.id)
            ) FROM users")->fetchColumn();
            $avgFootprint = $avgFootprint ?: 0;
            ob_start();
            ?>
            <h2>Админ-панель</h2>
            <div class="row">
                <div class="col-md-4"><div class="card text-white bg-info mb-3"><div class="card-header">Пользователей</div><div class="card-body"><h3><?= $totalUsers ?></h3></div></div></div>
                <div class="col-md-4"><div class="card text-white bg-secondary mb-3"><div class="card-header">Активностей</div><div class="card-body"><h3><?= $totalActivities ?></h3></div></div></div>
                <div class="col-md-4"><div class="card text-white bg-dark mb-3"><div class="card-header">Средний след (кг CO₂)</div><div class="card-body"><h3><?= number_format($avgFootprint, 2) ?></h3></div></div></div>
            </div>
            <div class="mt-4">
                <a href="?action=admin_users" class="btn btn-primary">Пользователи</a>
                <a href="?action=admin_tips" class="btn btn-success">Советы</a>
                <a href="?action=admin_challenges" class="btn btn-warning">Челленджи</a>
            </div>
            <?php
            $content = ob_get_clean();
            break;

        case 'admin_users':
            if (!isAdmin()) { header('Location: ?action=home'); exit; }
            $pageTitle = 'Управление пользователями - EcoTrack';
            $users = $pdo->query("SELECT id, username, role, created_at FROM users ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
            ob_start();
            if (isset($_SESSION['message'])) { echo '<div class="alert alert-success">' . htmlspecialchars($_SESSION['message']) . '</div>'; unset($_SESSION['message']); }
            if (isset($_SESSION['error'])) { echo '<div class="alert alert-danger">' . htmlspecialchars($_SESSION['error']) . '</div>'; unset($_SESSION['error']); }
            ?>
            <h2>Пользователи</h2>
            <table class="table table-striped">
                <thead><tr><th>ID</th><th>Имя</th><th>Роль</th><th>Дата регистрации</th><th>Действия</th></tr></thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td><?= $u['id'] ?></td>
                            <td><?= htmlspecialchars($u['username']) ?></td>
                            <td><?= htmlspecialchars($u['role']) ?></td>
                            <td><?= htmlspecialchars($u['created_at']) ?></td>
                            <td>
                                <?php if ($u['id'] != $_SESSION['user_id']): ?>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Удалить пользователя?');">
                                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" name="action" value="admin_user_delete">Удалить</button>
                                    </form>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Вы</span>
                                <?php endif; ?>
                                <a href="?action=admin_user_activities&id=<?= $u['id'] ?>" class="btn btn-sm btn-info">Активности</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <a href="?action=admin" class="btn btn-secondary">Назад</a>
            <?php
            $content = ob_get_clean();
            break;

        case 'admin_user_activities':
            if (!isAdmin()) { header('Location: ?action=home'); exit; }
            $userId = (int)$_GET['id'] ?? 0;
            if (!$userId) { header('Location: ?action=admin_users'); exit; }
            $user = getUser($pdo, $userId);
            if (!$user) { header('Location: ?action=admin_users'); exit; }
            $pageTitle = 'Активности ' . htmlspecialchars($user['username']);
            $stmt = $pdo->prepare("SELECT type, subtype, value, date FROM activities WHERE user_id = ? ORDER BY date DESC");
            $stmt->execute([$userId]);
            $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $total = calculateTotalFootprint($pdo, $userId);
            ob_start();
            ?>
            <h2>Активности пользователя <?= htmlspecialchars($user['username']) ?></h2>
            <p><strong>Общий след:</strong> <?= number_format($total, 2) ?> кг CO₂</p>
            <table class="table table-striped">
                <thead><tr><th>Тип</th><th>Подтип</th><th>Значение</th><th>Дата</th></tr></thead>
                <tbody>
                    <?php foreach ($activities as $act): ?>
                        <tr><td><?= htmlspecialchars($act['type']) ?></td><td><?= htmlspecialchars($act['subtype']) ?></td><td><?= htmlspecialchars($act['value']) ?></td><td><?= htmlspecialchars($act['date']) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (empty($activities)) echo '<tr><td colspan="4">Нет активностей.</td></tr>'; ?>
                </tbody>
            </table>
            <a href="?action=admin_users" class="btn btn-secondary">Назад</a>
            <?php
            $content = ob_get_clean();
            break;

        case 'admin_tips':
            if (!isAdmin()) { header('Location: ?action=home'); exit; }
            $pageTitle = 'Управление советами - EcoTrack';
            $tips = $pdo->query("SELECT * FROM tips ORDER BY category, id")->fetchAll(PDO::FETCH_ASSOC);
            ob_start();
            if (isset($_SESSION['message'])) { echo '<div class="alert alert-success">' . htmlspecialchars($_SESSION['message']) . '</div>'; unset($_SESSION['message']); }
            if (isset($_SESSION['error'])) { echo '<div class="alert alert-danger">' . htmlspecialchars($_SESSION['error']) . '</div>'; unset($_SESSION['error']); }
            ?>
            <h2>Советы</h2>
            <div class="card mb-4">
                <div class="card-header">Добавить совет</div>
                <div class="card-body">
                    <form method="post">
                        <div class="row">
                            <div class="col-md-3">
                                <select class="form-select" name="category" required>
                                    <option value="travel">Транспорт</option><option value="diet">Питание</option>
                                    <option value="energy">Энергия</option><option value="general">Общее</option>
                                </select>
                            </div>
                            <div class="col-md-7"><input type="text" class="form-control" name="tip" placeholder="Текст совета" required></div>
                            <div class="col-md-2"><button type="submit" class="btn btn-primary" name="action" value="admin_tip_add">Добавить</button></div>
                        </div>
                    </form>
                </div>
            </div>
            <table class="table table-striped">
                <thead><tr><th>ID</th><th>Категория</th><th>Совет</th><th>Действия</th></tr></thead>
                <tbody>
                    <?php foreach ($tips as $t): ?>
                        <tr>
                            <td><?= $t['id'] ?></td>
                            <td><?= htmlspecialchars($t['category']) ?></td>
                            <td><?= htmlspecialchars($t['tip']) ?></td>
                            <td>
                                <button class="btn btn-sm btn-warning" data-bs-toggle="collapse" data-bs-target="#editTip<?= $t['id'] ?>">Редактировать</button>
                                <form method="post" style="display:inline;" onsubmit="return confirm('Удалить совет?');">
                                    <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" name="action" value="admin_tip_delete">Удалить</button>
                                </form>
                                <div class="collapse mt-2" id="editTip<?= $t['id'] ?>">
                                    <form method="post" class="row g-2">
                                        <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                        <div class="col-md-3">
                                            <select class="form-select" name="category" required>
                                                <option value="travel" <?= $t['category']=='travel'?'selected':'' ?>>Транспорт</option>
                                                <option value="diet" <?= $t['category']=='diet'?'selected':'' ?>>Питание</option>
                                                <option value="energy" <?= $t['category']=='energy'?'selected':'' ?>>Энергия</option>
                                                <option value="general" <?= $t['category']=='general'?'selected':'' ?>>Общее</option>
                                            </select>
                                        </div>
                                        <div class="col-md-7"><input type="text" class="form-control" name="tip" value="<?= htmlspecialchars($t['tip']) ?>" required></div>
                                        <div class="col-md-2"><button type="submit" class="btn btn-success" name="action" value="admin_tip_edit">Обновить</button></div>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <a href="?action=admin" class="btn btn-secondary">Назад</a>
            <?php
            $content = ob_get_clean();
            break;

        case 'admin_challenges':
            if (!isAdmin()) { header('Location: ?action=home'); exit; }
            $pageTitle = 'Управление челленджами - EcoTrack';
            $challenges = $pdo->query("SELECT * FROM challenges ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
            ob_start();
            if (isset($_SESSION['message'])) { echo '<div class="alert alert-success">' . htmlspecialchars($_SESSION['message']) . '</div>'; unset($_SESSION['message']); }
            if (isset($_SESSION['error'])) { echo '<div class="alert alert-danger">' . htmlspecialchars($_SESSION['error']) . '</div>'; unset($_SESSION['error']); }
            ?>
            <h2>Челленджи</h2>
            <div class="card mb-4">
                <div class="card-header">Добавить челлендж</div>
                <div class="card-body">
                    <form method="post">
                        <div class="row g-2">
                            <div class="col-md-3"><input type="text" class="form-control" name="name" placeholder="Название" required></div>
                            <div class="col-md-4"><input type="text" class="form-control" name="description" placeholder="Описание" required></div>
                            <div class="col-md-2">
                                <select class="form-select" name="category" required>
                                    <option value="travel">Транспорт</option><option value="diet">Питание</option><option value="energy">Энергия</option>
                                </select>
                            </div>
                            <div class="col-md-1"><input type="number" step="0.01" class="form-control" name="target" placeholder="Цель" required></div>
                            <div class="col-md-1"><input type="text" class="form-control" name="unit" placeholder="Ед." required></div>
                            <div class="col-md-1"><button type="submit" class="btn btn-primary" name="action" value="admin_challenge_add">Добавить</button></div>
                        </div>
                    </form>
                </div>
            </div>
            <table class="table table-striped">
                <thead><tr><th>ID</th><th>Название</th><th>Описание</th><th>Категория</th><th>Цель</th><th>Ед.</th><th>Действия</th></tr></thead>
                <tbody>
                    <?php foreach ($challenges as $c): ?>
                        <tr>
                            <td><?= $c['id'] ?></td>
                            <td><?= htmlspecialchars($c['name']) ?></td>
                            <td><?= htmlspecialchars($c['description']) ?></td>
                            <td><?= htmlspecialchars($c['category']) ?></td>
                            <td><?= $c['target'] ?></td>
                            <td><?= htmlspecialchars($c['unit']) ?></td>
                            <td>
                                <button class="btn btn-sm btn-warning" data-bs-toggle="collapse" data-bs-target="#editChall<?= $c['id'] ?>">Редактировать</button>
                                <form method="post" style="display:inline;" onsubmit="return confirm('Удалить челлендж?');">
                                    <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" name="action" value="admin_challenge_delete">Удалить</button>
                                </form>
                                <div class="collapse mt-2" id="editChall<?= $c['id'] ?>">
                                    <form method="post" class="row g-2">
                                        <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                        <div class="col-md-2"><input type="text" class="form-control" name="name" value="<?= htmlspecialchars($c['name']) ?>" required></div>
                                        <div class="col-md-3"><input type="text" class="form-control" name="description" value="<?= htmlspecialchars($c['description']) ?>" required></div>
                                        <div class="col-md-2">
                                            <select class="form-select" name="category" required>
                                                <option value="travel" <?= $c['category']=='travel'?'selected':'' ?>>Транспорт</option>
                                                <option value="diet" <?= $c['category']=='diet'?'selected':'' ?>>Питание</option>
                                                <option value="energy" <?= $c['category']=='energy'?'selected':'' ?>>Энергия</option>
                                            </select>
                                        </div>
                                        <div class="col-md-1"><input type="number" step="0.01" class="form-control" name="target" value="<?= $c['target'] ?>" required></div>
                                        <div class="col-md-1"><input type="text" class="form-control" name="unit" value="<?= htmlspecialchars($c['unit']) ?>" required></div>
                                        <div class="col-md-1"><button type="submit" class="btn btn-success" name="action" value="admin_challenge_edit">Обновить</button></div>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <a href="?action=admin" class="btn btn-secondary">Назад</a>
            <?php
            $content = ob_get_clean();
            break;

        default:
            header('Location: ?action=home');
            exit;
    }
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        #noInternetBanner {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 9999;
            background: #dc3545;
            color: white;
            text-align: center;
            padding: 10px;
            font-weight: bold;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
        }
        body {
            padding-top: 0;
        }
        #noInternetBanner.show {
            display: block;
        }
        /* Отступ для навбара, если баннер виден */
        .navbar {
            margin-top: 0;
        }
    </style>
</head>
<body>

<div id="noInternetBanner">
    ⚠️ Нет подключения к интернету. Некоторые функции могут быть недоступны.
</div>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand" href="?action=home">🌍 EcoTrack</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <?php if (isset($pdo) && isLoggedIn()): ?>
                    <li class="nav-item"><a class="nav-link" href="?action=home">Главная</a></li>
                    <li class="nav-item"><a class="nav-link" href="?action=add">Добавить активность</a></li>
                    <li class="nav-item"><a class="nav-link" href="?action=dashboard">Панель</a></li>
                    <li class="nav-item"><a class="nav-link" href="?action=tips">Советы</a></li>
                    <li class="nav-item"><a class="nav-link" href="?action=challenges">Челленджи</a></li>
                    <?php if (isAdmin()): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="adminDropdown" role="button" data-bs-toggle="dropdown">Админ</a>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="?action=admin">Панель</a></li>
                                <li><a class="dropdown-item" href="?action=admin_users">Пользователи</a></li>
                                <li><a class="dropdown-item" href="?action=admin_tips">Советы</a></li>
                                <li><a class="dropdown-item" href="?action=admin_challenges">Челленджи</a></li>
                            </ul>
                        </li>
                    <?php endif; ?>
                    <li class="nav-item"><a class="nav-link" href="?action=logout">Выход (<?= htmlspecialchars($_SESSION['username']) ?>)</a></li>
                <?php else: ?>
                    <?php if (!isset($config)): ?>
                    <?php else: ?>
                        <li class="nav-item"><a class="nav-link" href="?action=login">Вход</a></li>
                        <li class="nav-item"><a class="nav-link" href="?action=register">Регистрация</a></li>
                    <?php endif; ?>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<div class="container mt-4">
    <?= $content ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>

<?php if (isset($config) && isLoggedIn() && $action !== 'setup' && $action !== 'sse'): ?>
<script>
    let internetCheckInterval = null;
    const banner = document.getElementById('noInternetBanner');

    function checkInternet() {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 5000); // 5 секунд таймаут

        fetch('https://github.com/Nepegnik142/', {
            method: 'HEAD',
            mode: 'no-cors',
            signal: controller.signal
        })
        .then(() => {
            clearTimeout(timeoutId);
            banner.classList.remove('show');
        })
        .catch(() => {
            clearTimeout(timeoutId);
            banner.classList.add('show');
        });
    }

    checkInternet();
    internetCheckInterval = setInterval(checkInternet, 30000);

    let eventSource = null;

    function connectSSE() {
        if (eventSource) {
            eventSource.close();
        }
        eventSource = new EventSource('?action=sse');

        eventSource.addEventListener('update', function(e) {
            // При получении события "update" перезагружаем страницу через 1 секунду
            console.log('Обновление данных, перезагрузка...');
            setTimeout(() => {
                location.reload();
            }, 1000);
        });

        eventSource.onerror = function(e) {
            console.warn('SSE ошибка, переподключение через 5 секунд...');
            if (eventSource) {
                eventSource.close();
                eventSource = null;
            }
            setTimeout(connectSSE, 5000);
        };
    }

    // Запускаем SSE, если пользователь залогинен
    if (<?= isLoggedIn() ? 'true' : 'false' ?>) {
        connectSSE();
    }

    // Очистка при уходе со страницы
    window.addEventListener('beforeunload', function() {
        if (internetCheckInterval) {
            clearInterval(internetCheckInterval);
        }
        if (eventSource) {
            eventSource.close();
        }
    });
</script>
<?php endif; ?>

</body>
</html>
