# Автоматический деплой EduPlatform на edu.dagstudio.ru

EduPlatform публикуется из ветки `main` через GitHub Actions на обычный FTP-хостинг.

## DNS

Сохраняем существующую A-запись:

```
edu.dagstudio.ru -> 188.127.241.85
```

GitHub Pages для этого варианта не используется.

## GitHub Secrets

Откройте:

`Repository -> Settings -> Secrets and variables -> Actions -> New repository secret`

Создайте четыре секрета:

| Secret | Значение |
| --- | --- |
| `FTP_SERVER` | FTP-сервер хостинга. Если FTP работает на основном IP, используйте `188.127.241.85` |
| `FTP_USERNAME` | FTP-логин |
| `FTP_PASSWORD` | Новый FTP-пароль |
| `FTP_SERVER_DIR` | Папка сайта, например `/public_html/edu/` или значение из панели хостинга |

Пароли нельзя добавлять в файлы репозитория.

## Как работает публикация

Каждый push в `main` запускает workflow:

`.github/workflows/deploy.yml`

GitHub скачивает актуальную версию репозитория и синхронизирует файлы сайта с FTP.

Также workflow можно запустить вручную:

`Actions -> Deploy EduPlatform to hosting -> Run workflow`

## Что публикуется

На сервер передаются файлы сайта. Служебные файлы GitHub, документация и node_modules исключены.

## Важно

Перед первой публикацией нужно точно узнать корневую FTP-папку поддомена `edu.dagstudio.ru`. Не следует указывать корень аккаунта наугад.
