# EcoTrack – README

## 🇷🇺 Русская версия

### Описание

**EcoTrack** – это веб-приложение для отслеживания повседневных привычек (поездки, питание, энергопотребление) с целью измерения и снижения вашего углеродного следа.  
Проект помогает пользователям осознанно подходить к экологии, даёт персонализированные советы и предлагает увлекательные челленджи для формирования «зелёных» привычек.

### Основные возможности

- **Регистрация и вход** – безопасная аутентификация с хешированием паролей.
- **Добавление активностей** – фиксируйте свои действия по трём категориям: транспорт, питание, энергия.
- **Расчёт углеродного следа** – автоматический подсчёт выбросов CO₂ в кг на основе введённых данных.
- **Персональные советы** – система подбирает рекомендации, исходя из вашей самой «грязной» категории.
- **Челленджи** – участвуйте в экологических вызовах, отслеживайте прогресс и выполняйте цели.
- **Админ-панель** – управляйте пользователями, советами и челленджами (доступна только администратору).
- **Автоустановка** – при первом запуске мастер установки поможет выбрать базу данных и создать учётную запись администратора.
- **Поддержка трёх СУБД** – SQLite (встроенная), MySQL/MariaDB, PostgreSQL.
- **Автообновление (SSE)** – страницы обновляются автоматически при изменении данных без необходимости ручной перезагрузки.
- **Проверка интернета** – если пропадает соединение, появляется информационный баннер.

---

### Требования к серверу

- PHP 8.3 или новее
- Расширения PDO для выбранной СУБД:
  - `pdo_sqlite` (для SQLite)
  - `pdo_mysql` (для MySQL)
  - `pdo_pgsql` (для PostgreSQL)
- Права на запись в папку со скриптом (для создания файлов `config.php` и `ecotrack.db` при использовании SQLite)
- Современный веб-сервер (Apache, Nginx) с поддержкой .htaccess или прямой маршрутизации

---

### Установка

1. **Скачайте** файл `index.php` и поместите его в корневую папку вашего сайта.
2. **Убедитесь**, что папка доступна для записи.
3. **Откройте** сайт в браузере – автоматически запустится мастер установки.
4. **Выберите** тип базы данных:
   - **SQLite** – укажите путь к файлу (по умолчанию `ecotrack.db` в той же папке).
   - **MySQL / PostgreSQL** – введите параметры подключения (хост, имя БД, пользователь, пароль).
5. **Заполните** поля для создания учётной записи администратора.
6. Нажмите **«Установить»** – система создаст таблицы, наполнит их начальными данными и автоматически выполнит вход.

После установки в корне появится файл `config.php` с настройками подключения. **Не удаляйте его** – он нужен для работы сайта.

---

### Использование

#### Для обычных пользователей

1. **Войдите** в систему (или зарегистрируйтесь, если вы не администратор).
2. **Добавьте активности** через раздел «Добавить активность». Выберите тип, подтип и укажите числовое значение (например, километры, порции, кВт·ч).
3. **Посмотрите** свой углеродный след на главной странице и в разделе «Панель».
4. **Получайте советы** – они подбираются на основе ваших привычек.
5. **Присоединяйтесь к челленджам** в одноимённом разделе и обновляйте прогресс.
6. **Следите за автообновлением** – после изменения данных страница перезагрузится сама через секунду.

#### Для администратора

- **Вход** выполняется с теми же данными, что вы указали при установке.
- В верхнем меню появляется выпадающий пункт **«Админ»**.
- Доступны три раздела:
  - **Пользователи** – просмотр, удаление (кроме себя), просмотр активностей.
  - **Советы** – добавление, редактирование и удаление зелёных советов по категориям.
  - **Челленджи** – добавление, редактирование и удаление челленджей с указанием цели и единиц измерения.

---

### Структура базы данных

- `users` – пользователи (id, username, password, role, created_at)
- `activities` – активности (id, user_id, type, subtype, value, date)
- `tips` – советы (id, category, tip)
- `challenges` – челленджи (id, name, description, category, target, unit)
- `user_challenges` – прогресс пользователей по челленджам (id, user_id, challenge_id, progress, completed, started_at)
- `user_updates` – временные метки последнего изменения данных пользователя (user_id, last_update) – используется для SSE.

---

### Настройка вручную (для опытных)

Вы можете отредактировать файл `config.php` вручную, изменив параметры DSN, пользователя или пароль.  
При необходимости вы можете добавить свои факторы эмиссии в функцию `getEmissionFactor()`, расширить список советов или челленджей в соответствующих массивах.

---

### Устранение неполадок

- **Ошибка «папка недоступна для записи»** – установите права 755 или 777 на папку со скриптом.
- **SSE не работает** – убедитесь, что на сервере не отключены длительные соединения (проверьте настройки nginx `proxy_buffering` и `fastcgi_buffering`).
- **Баннер «Нет интернета»** – если он появляется при стабильном соединении, проверьте доступность `https://www.google.com` из вашей сети.
- **Не удаётся подключиться к MySQL/PostgreSQL** – проверьте правильность данных подключения и наличие созданной базы данных.

---

### Лицензия

Проект распространяется под лицензией MIT. Вы можете свободно использовать, модифицировать и распространять код.

---

### Контакты

Если у вас есть вопросы или предложения, создайте Issue в репозитории проекта или свяжитесь с автором.

---

## 🇬🇧 English Version

### Description

**EcoTrack** is a web application that helps you track daily habits – travel, diet, and energy use – to measure and reduce your carbon footprint.  
It provides personalised tips and fun challenges to encourage greener choices and make sustainable living simple and engaging.

### Key Features

- **Registration & Login** – secure authentication with password hashing.
- **Activity Logging** – record your actions in three categories: travel, diet, energy.
- **Carbon Footprint Calculation** – automatic CO₂ emission estimation (kg) based on entered data.
- **Personalised Tips** – the system picks recommendations based on your most impactful category.
- **Challenges** – take part in eco‑challenges, track progress, and achieve goals.
- **Admin Panel** – manage users, tips, and challenges (admin only).
- **Auto‑installation** – on first run, a setup wizard helps you choose a database and create an admin account.
- **Multi‑database support** – SQLite (built‑in), MySQL/MariaDB, PostgreSQL.
- **Auto‑refresh (SSE)** – pages update automatically when data changes, no manual reload needed.
- **Internet connectivity check** – a banner appears when the connection drops and disappears when it’s back.

---

### Server Requirements

- PHP 8.3 or newer
- PDO extensions for your chosen database:
  - `pdo_sqlite` (for SQLite)
  - `pdo_mysql` (for MySQL)
  - `pdo_pgsql` (for PostgreSQL)
- Write permissions to the script folder (to create `config.php` and `ecotrack.db` if using SQLite)
- Modern web server (Apache, Nginx) with support for .htaccess or direct routing

---

### Installation

1. **Download** `index.php` and place it in your website’s root directory.
2. **Ensure** the folder is writable.
3. **Open** the site in your browser – the setup wizard will start automatically.
4. **Choose** your database type:
   - **SQLite** – specify the file path (default: `ecotrack.db` in the same folder).
   - **MySQL / PostgreSQL** – enter connection details (host, database name, user, password).
5. **Fill in** the fields to create an administrator account.
6. Click **«Install»** – the system will create tables, populate them with initial data, and log you in automatically.

After installation, a `config.php` file will be created in the root with your connection settings. **Do not delete it** – it is required for the application to work.

---

### Usage

#### For regular users

1. **Log in** (or register if you are not an admin).
2. **Add activities** via the «Add Activity» section. Choose type, subtype, and enter a numeric value (e.g., kilometres, servings, kWh).
3. **View** your carbon footprint on the homepage and in the «Dashboard».
4. **Get tips** – they are tailored to your habits.
5. **Join challenges** in the «Challenges» section and update your progress.
6. **Watch auto‑refresh** – after any data change, the page will reload itself after one second.

#### For administrators

- **Log in** with the credentials you set during installation.
- An **«Admin»** dropdown appears in the top menu.
- Three sections are available:
  - **Users** – view, delete (except yourself), and view user activities.
  - **Tips** – add, edit, and delete green tips by category.
  - **Challenges** – add, edit, and delete challenges with target values and units.

---

### Database Structure

- `users` – user accounts (id, username, password, role, created_at)
- `activities` – logged activities (id, user_id, type, subtype, value, date)
- `tips` – green tips (id, category, tip)
- `challenges` – eco‑challenges (id, name, description, category, target, unit)
- `user_challenges` – user progress on challenges (id, user_id, challenge_id, progress, completed, started_at)
- `user_updates` – last update timestamps for each user (user_id, last_update) – used for SSE.

---

### Manual Configuration (for advanced users)

You can manually edit `config.php` to change DSN, username, or password.  
You can also extend emission factors in the `getEmissionFactor()` function, or add more tips/challenges in the respective arrays.

---

### Troubleshooting

- **Error «folder not writable»** – set permissions to 755 or 777 on the script folder.
- **SSE not working** – ensure your server allows long‑lived connections (check nginx `proxy_buffering` and `fastcgi_buffering` settings).
- **«No Internet» banner** – if it appears while you have a stable connection, verify that `https://www.google.com` is reachable from your network.
- **Cannot connect to MySQL/PostgreSQL** – double‑check connection details and make sure the database exists.

---

### License

This project is distributed under the MIT License. You are free to use, modify, and redistribute the code.

---

### Contact

If you have any questions or suggestions, please open an Issue in the project repository or contact the author.

---

**🌱 Start tracking your impact today with EcoTrack!**
